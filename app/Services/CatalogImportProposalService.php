<?php

namespace App\Services;

use App\Models\CatalogImportProposal;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\SocialMediaSource;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CatalogImportProposalService
{
    public function __construct(private readonly YouTubeVideoUrlCanonicalizer $canonicalizer) {}

    /**
     * @param  array{youtube_url: string, target_type: string, matched_night_market_id?: int|null, matched_stall_id?: int|null}  $data
     */
    public function createDraft(User $user, array $data): CatalogImportProposal
    {
        $canonicalSource = $this->canonicalizer->canonicalize($data['youtube_url']);

        return $this->createSourceDraft($user, $data, $canonicalSource);
    }

    public function createSourceDraft(User $user, array $data, array $canonicalSource): CatalogImportProposal
    {

        return DB::transaction(function () use ($user, $data, $canonicalSource): CatalogImportProposal {
            $source = $this->findOrCreateSource($canonicalSource);
            $target = $this->resolveTarget($data);

            $existingDraft = CatalogImportProposal::query()
                ->where('social_media_source_id', $source->id)
                ->where('status', CatalogImportProposal::STATUS_DRAFT)
                ->lockForUpdate()
                ->first();

            if ($existingDraft) {
                return $existingDraft;
            }

            $nextRevision = (int) CatalogImportProposal::query()
                ->where('social_media_source_id', $source->id)
                ->lockForUpdate()
                ->max('revision') + 1;

            try {
                return CatalogImportProposal::create([
                    'social_media_source_id' => $source->id,
                    'target_type' => $data['target_type'],
                    'matched_night_market_id' => $target['night_market_id'],
                    'matched_stall_id' => $target['stall_id'],
                    'status' => CatalogImportProposal::STATUS_DRAFT,
                    'revision' => $nextRevision,
                    'created_by' => $user->id,
                ]);
            } catch (QueryException $exception) {
                $existingDraft = CatalogImportProposal::query()
                    ->where('social_media_source_id', $source->id)
                    ->where('status', CatalogImportProposal::STATUS_DRAFT)
                    ->first();

                if ($existingDraft) {
                    return $existingDraft;
                }

                throw $exception;
            }
        });
    }

    /**
     * @return LengthAwarePaginator<CatalogImportProposal>
     */
    public function proposals(): LengthAwarePaginator
    {
        return CatalogImportProposal::query()
            ->with([
                'socialMediaSource:id,platform,canonical_url,metadata_status',
                'matchedNightMarket:id,name',
                'matchedStall:id,night_market_id,name',
                'matchedStall.nightMarket:id,name',
                'createdBy:id,name',
            ])
            ->latest()
            ->paginate(15);
    }

    public function detail(CatalogImportProposal $proposal): CatalogImportProposal
    {
        return CatalogImportProposal::query()
            ->with([
                'socialMediaSource',
                'matchedNightMarket:id,name,city,state,status',
                'matchedStall:id,night_market_id,name,status',
                'matchedStall.nightMarket:id,name,city,state,status',
                'createdBy:id,name',
                'reviewedBy:id,name',
                'proposalMarket.operatingDays',
                'proposalMarket.stalls.foods',
                'catalogSourceLinks.nightMarket:id,name',
                'catalogSourceLinks.stall:id,name',
                'catalogSourceLinks.food:id,name',
            ])
            ->findOrFail($proposal->id);
    }

    /**
     * @return array{uses_snapshot: bool, external_content_id: string|null, title: string|null, description_excerpt: string|null, creator_name: string|null, thumbnail_url: string|null, published_at_label: string|null}
     */
    public function metadataForDisplay(CatalogImportProposal $proposal): array
    {
        $usesSnapshot = $proposal->status !== CatalogImportProposal::STATUS_DRAFT;
        $metadata = $usesSnapshot
            ? (is_array($proposal->review_metadata_snapshot) ? $proposal->review_metadata_snapshot : [])
            : [
                'external_content_id' => $proposal->socialMediaSource?->external_content_id,
                'title' => $proposal->socialMediaSource?->title,
                'description_excerpt' => $proposal->socialMediaSource?->description_excerpt,
                'creator_name' => $proposal->socialMediaSource?->creator_name,
                'thumbnail_url' => $proposal->socialMediaSource?->thumbnail_url,
                'published_at' => $proposal->socialMediaSource?->published_at,
            ];

        $publishedAtLabel = null;
        if (filled($metadata['published_at'] ?? null)) {
            try {
                $publishedAtLabel = Carbon::parse($metadata['published_at'])->format('d M Y');
            } catch (Throwable) {
                $publishedAtLabel = null;
            }
        }

        return [
            'uses_snapshot' => $usesSnapshot,
            'external_content_id' => $this->nullableDisplayValue($metadata['external_content_id'] ?? null),
            'title' => $this->nullableDisplayValue($metadata['title'] ?? null),
            'description_excerpt' => $this->nullableDisplayValue($metadata['description_excerpt'] ?? null),
            'creator_name' => $this->nullableDisplayValue($metadata['creator_name'] ?? null),
            'thumbnail_url' => $this->nullableDisplayValue($metadata['thumbnail_url'] ?? null),
            'published_at_label' => $publishedAtLabel,
        ];
    }

    /**
     * @return array{nightMarkets: Collection<int, NightMarket>, stalls: Collection<int, Stall>}
     */
    public function formOptions(): array
    {
        return [
            'nightMarkets' => NightMarket::query()
                ->publiclyVisible()
                ->withCount([
                    'stalls as active_stalls_count' => fn (Builder $query) => $query
                        ->where('status', Stall::STATUS_ACTIVE),
                ])
                ->orderBy('active_stalls_count')
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'city', 'state', 'status']),
            'stalls' => Stall::query()
                ->where('status', Stall::STATUS_ACTIVE)
                ->whereHas('nightMarket', fn (Builder $query) => $query->publiclyVisible())
                ->with([
                    'nightMarket:id,name,city,state,status',
                ])
                ->withCount([
                    'foods as active_foods_count' => fn (Builder $query) => $query
                        ->where('status', Food::STATUS_ACTIVE),
                ])
                ->orderBy('active_foods_count')
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'night_market_id', 'name', 'status']),
        ];
    }

    /**
     * @param  array{platform: string, canonical_url: string, external_content_id: string, url_fingerprint: string}  $sourceData
     */
    public function findOrCreateSource(array $sourceData): SocialMediaSource
    {
        $source = SocialMediaSource::query()
            ->where('url_fingerprint', $sourceData['url_fingerprint'])
            ->lockForUpdate()
            ->first();

        if ($source) {
            return $source;
        }

        try {
            return SocialMediaSource::create([
                ...$sourceData,
                'metadata_status' => SocialMediaSource::METADATA_PENDING,
            ]);
        } catch (QueryException $exception) {
            $source = SocialMediaSource::query()
                ->where('url_fingerprint', $sourceData['url_fingerprint'])
                ->orWhere(function (Builder $query) use ($sourceData) {
                    $query->whereNotNull('external_content_id')->where('platform', $sourceData['platform'])
                        ->where('external_content_id', $sourceData['external_content_id']);
                })
                ->first();

            if ($source) {
                return $source;
            }

            throw $exception;
        }
    }

    /**
     * @param  array{target_type: string, matched_night_market_id?: int|null, matched_stall_id?: int|null}  $data
     * @return array{night_market_id: int|null, stall_id: int|null}
     */
    private function resolveTarget(array $data): array
    {
        return match ($data['target_type']) {
            CatalogImportProposal::TARGET_EXISTING_MARKET => $this->existingMarketTarget($data),
            CatalogImportProposal::TARGET_EXISTING_STALL => $this->existingStallTarget($data),
            CatalogImportProposal::TARGET_NEW_MARKET => $this->newMarketTarget($data),
            default => throw ValidationException::withMessages([
                'target_type' => 'Select a valid automation import target.',
            ]),
        };
    }

    /**
     * @param  array{matched_night_market_id?: int|null, matched_stall_id?: int|null}  $data
     * @return array{night_market_id: int, stall_id: null}
     */
    private function existingMarketTarget(array $data): array
    {
        if (empty($data['matched_night_market_id']) || ! empty($data['matched_stall_id'])) {
            throw ValidationException::withMessages([
                'matched_night_market_id' => 'Select one eligible Night Market for this target.',
            ]);
        }

        $market = NightMarket::query()
            ->publiclyVisible()
            ->lockForUpdate()
            ->find($data['matched_night_market_id']);

        if (! $market) {
            throw ValidationException::withMessages([
                'matched_night_market_id' => 'The selected Night Market must be active and located in Selangor.',
            ]);
        }

        return ['night_market_id' => $market->id, 'stall_id' => null];
    }

    /**
     * @param  array{matched_stall_id?: int|null}  $data
     * @return array{night_market_id: int, stall_id: int}
     */
    private function existingStallTarget(array $data): array
    {
        if (empty($data['matched_stall_id'])) {
            throw ValidationException::withMessages([
                'matched_stall_id' => 'Select one eligible Stall for this target.',
            ]);
        }

        $stall = Stall::query()
            ->where('status', Stall::STATUS_ACTIVE)
            ->lockForUpdate()
            ->find($data['matched_stall_id']);

        if (! $stall) {
            throw ValidationException::withMessages([
                'matched_stall_id' => 'The selected Stall must be active and belong to an active Selangor Night Market.',
            ]);
        }

        $market = NightMarket::query()
            ->publiclyVisible()
            ->lockForUpdate()
            ->find($stall->night_market_id);

        if (! $market) {
            throw ValidationException::withMessages([
                'matched_stall_id' => 'The selected Stall must be active and belong to an active Selangor Night Market.',
            ]);
        }

        return ['night_market_id' => $market->id, 'stall_id' => $stall->id];
    }

    /**
     * @param  array{matched_night_market_id?: int|null, matched_stall_id?: int|null}  $data
     * @return array{night_market_id: null, stall_id: null}
     */
    private function newMarketTarget(array $data): array
    {
        if (! empty($data['matched_night_market_id']) || ! empty($data['matched_stall_id'])) {
            throw ValidationException::withMessages([
                'target_type' => 'A new Market proposal cannot be linked to an existing Market or Stall.',
            ]);
        }

        return ['night_market_id' => null, 'stall_id' => null];
    }

    private function nullableDisplayValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
