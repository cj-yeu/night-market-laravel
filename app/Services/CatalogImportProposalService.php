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
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function createSourceDraft(User $user, array $data, array $canonicalSource, bool $allowSharedSource = false): CatalogImportProposal
    {

        return DB::transaction(function () use ($user, $data, $canonicalSource, $allowSharedSource): CatalogImportProposal {
            $source = $this->findOrCreateSource($canonicalSource);
            $target = $this->resolveTarget($data);

            $existingDraft = CatalogImportProposal::query()
                ->where('social_media_source_id', $source->id)
                ->where('status', CatalogImportProposal::STATUS_DRAFT)
                ->lockForUpdate()
                ->first();

            if ($existingDraft && ! $allowSharedSource) {
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
    /** @param array{status?: string|null, draft_search?: string|null, draft_type?: string|null, draft_condition?: string|null, draft_sort?: string|null} $filters */
    public function proposals(array $filters = []): LengthAwarePaginator
    {
        $items = CatalogImportProposal::query()
            ->with([
                'socialMediaSource:id,platform,canonical_url,metadata_status',
                'matchedNightMarket:id,name,city,status',
                'matchedStall:id,night_market_id,name,status',
                'matchedStall.nightMarket:id,name,city,status',
                'createdBy:id,name',
            ])
            ->get()
            ->each(function (CatalogImportProposal $proposal): void {
                $summary = $this->draftSummary($proposal);
                foreach ($summary as $key => $value) {
                    $proposal->setAttribute($key, $value);
                }
            });

        if (filled($filters['draft_search'] ?? null)) {
            $needle = Str::lower(trim($filters['draft_search']));
            $items = $items->filter(fn (CatalogImportProposal $proposal) => str_contains((string) $proposal->id, $needle)
                || str_contains(Str::lower((string) $proposal->draft_market_name), $needle));
        }
        if (($filters['draft_type'] ?? null) === 'new_market') {
            $items = $items->where('target_type', CatalogImportProposal::TARGET_NEW_MARKET);
        } elseif (($filters['draft_type'] ?? null) === 'existing') {
            $items = $items->whereIn('target_type', [CatalogImportProposal::TARGET_EXISTING_MARKET, CatalogImportProposal::TARGET_EXISTING_STALL]);
        }
        $status = $filters['status'] ?? 'active';
        $items = match ($status) {
            'imported' => $items->where('status', CatalogImportProposal::STATUS_IMPORTED),
            'archived' => $items->where('draft_archived', true),
            'attention' => $items->where('status', CatalogImportProposal::STATUS_DRAFT)
                ->where('draft_archived', false)->filter(fn ($item) => in_array($item->draft_display_status, ['Analysis Failed', 'Missing Information'], true)),
            'ready' => $items->where('status', CatalogImportProposal::STATUS_DRAFT)
                ->where('draft_archived', false)->where('draft_display_status', 'Ready to Import'),
            default => $items->where('status', CatalogImportProposal::STATUS_DRAFT)->where('draft_archived', false)
                ->filter(fn ($item) => ! in_array($item->draft_display_status, ['Analysis Failed', 'Missing Information'], true)),
        };
        $items = match ($filters['draft_condition'] ?? null) {
            'ready' => $items->where('draft_display_status', 'Ready to Import'),
            'failed' => $items->where('draft_display_status', 'Analysis Failed'),
            'incomplete' => $items->filter(fn ($item) => in_array($item->draft_display_status, ['Missing Information', 'Needs Review', 'Searching Sources'], true)),
            default => $items,
        };

        $items = ($filters['draft_sort'] ?? 'updated_desc') === 'updated_asc'
            ? $items->sortBy('updated_at') : $items->sortByDesc('updated_at');
        $draftGroups = $items->where('status', CatalogImportProposal::STATUS_DRAFT)
            ->groupBy(function (CatalogImportProposal $proposal): string {
                $name = $proposal->draft_market_name ?? '';
                $city = $proposal->draft_market_city ?? '';

                return mb_strtolower(trim($name)).'|'.mb_strtolower(trim($city));
            });
        foreach ($draftGroups as $key => $group) {
            if ($key !== '|' && $group->count() > 1) {
                $group->each->setAttribute('duplicate_draft_count', $group->count());
            }
        }

        $page = Paginator::resolveCurrentPage();
        $perPage = 15;
        $proposals = new ConcreteLengthAwarePaginator($items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => request()->query()]);

        return $proposals;
    }

    /** @return array<string, mixed> */
    private function draftSummary(CatalogImportProposal $proposal): array
    {
        $data = is_array($proposal->review_metadata_snapshot['ai_import'] ?? null)
            ? $proposal->review_metadata_snapshot['ai_import'] : [];
        $context = is_array($data['context'] ?? null) ? $data['context'] : [];
        $graph = is_array($data['graph'] ?? null) ? $data['graph'] : [];
        $market = is_array($graph['market'] ?? null) ? $graph['market'] : [];
        $sources = is_array($data['sources'] ?? null) ? array_filter($data['sources'], 'is_array') : [];
        $stalls = is_array($graph['stalls'] ?? null) ? array_filter($graph['stalls'], 'is_array') : [];
        $foodCount = collect($stalls)->sum(fn ($stall) => is_array($stall['foods'] ?? null) ? count($stall['foods']) : 0);
        $missing = [];
        $marketName = $this->summaryString($market['name'] ?? $context['name'] ?? null);
        $marketCity = $this->summaryString($market['city'] ?? $context['city'] ?? null);
        if ($marketName === '') {
            $missing[] = 'Market name';
        }
        if ($marketCity === '') {
            $missing[] = 'City';
        }
        if ($proposal->status === CatalogImportProposal::STATUS_DRAFT && $sources === []) {
            $missing[] = 'Source';
        }
        if (($context['import_mode'] ?? null) === 'new_market' && $this->summaryString($market['address'] ?? null) === '') {
            $missing[] = 'Address';
        }
        if (($context['import_mode'] ?? null) === 'new_market' && empty($graph['operating_days'])) {
            $missing[] = 'Operating schedule';
        }
        $missingStallNames = collect($stalls)->filter(fn ($stall) => $this->summaryString($stall['name'] ?? null) === '')->count();
        $missingFoodNames = collect($stalls)->sum(fn ($stall) => collect(is_array($stall['foods'] ?? null) ? $stall['foods'] : [])
            ->filter(fn ($food) => ! is_array($food) || $this->summaryString($food['name'] ?? null) === '')->count());
        $missingFoodCategories = collect($stalls)->sum(fn ($stall) => collect(is_array($stall['foods'] ?? null) ? $stall['foods'] : [])
            ->filter(fn ($food) => is_array($food) && $this->summaryString($food['category'] ?? null) === '')->count());
        if ($missingStallNames) {
            $missing[] = $missingStallNames.' Stall name'.($missingStallNames === 1 ? '' : 's');
        }
        if ($missingFoodNames) {
            $missing[] = $missingFoodNames.' Food name'.($missingFoodNames === 1 ? '' : 's');
        }
        if ($missingFoodCategories) {
            $missing[] = $missingFoodCategories.' Food categor'.($missingFoodCategories === 1 ? 'y' : 'ies');
        }
        $failed = collect($sources)->contains(fn ($source) => Str::contains(Str::lower($this->summaryString($source['status'] ?? null)), ['failed', 'unavailable', 'needs repair']));
        $repairs = is_array($data['repair_warnings'] ?? null)
            ? array_values(array_filter(array_map(fn ($repair) => $this->summaryString($repair), $data['repair_warnings']))) : [];
        $displayStatus = match (true) {
            $proposal->status === CatalogImportProposal::STATUS_IMPORTED => 'Imported',
            $failed => 'Analysis Failed',
            $missing !== [] || $repairs !== [] => 'Missing Information',
            $stalls !== [] || ! empty($market['selected']) => 'Ready to Import',
            collect($sources)->contains(fn ($source) => ! empty($source['analysed_hash'])) => 'Needs Review',
            default => 'Searching Sources',
        };

        return [
            'draft_name' => $this->summaryString($data['draft_name'] ?? null) ?: null,
            'draft_market_name' => $marketName ?: $proposal->matchedNightMarket?->name ?? $proposal->matchedStall?->nightMarket?->name ?? 'Market identity incomplete',
            'draft_market_city' => $marketCity ?: $proposal->matchedNightMarket?->city ?? $proposal->matchedStall?->nightMarket?->city ?? '',
            'draft_display_status' => $displayStatus,
            'draft_source_count' => count($sources), 'draft_stall_count' => count($stalls), 'draft_food_count' => $foodCount,
            'draft_missing' => [...$missing, ...$repairs], 'draft_archived' => filled($data['archived_at'] ?? null),
        ];
    }

    private function summaryString(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
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
                ->where('state', 'Selangor')
                ->withCount([
                    'stalls as active_stalls_count' => fn (Builder $query) => $query
                        ->where('status', Stall::STATUS_ACTIVE),
                ])
                ->orderBy('active_stalls_count')
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'city', 'state', 'status']),
            'stalls' => Stall::query()
                ->whereHas('nightMarket', fn (Builder $query) => $query->where('state', 'Selangor'))
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
            ->where('state', 'Selangor')
            ->lockForUpdate()
            ->find($data['matched_night_market_id']);

        if (! $market) {
            throw ValidationException::withMessages([
                'matched_night_market_id' => 'The selected Night Market must be located in Selangor.',
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
            ->lockForUpdate()
            ->find($data['matched_stall_id']);

        if (! $stall) {
            throw ValidationException::withMessages([
                'matched_stall_id' => 'The selected Stall must belong to a Selangor Night Market.',
            ]);
        }

        $market = NightMarket::query()
            ->where('state', 'Selangor')
            ->lockForUpdate()
            ->find($stall->night_market_id);

        if (! $market) {
            throw ValidationException::withMessages([
                'matched_stall_id' => 'The selected Stall must belong to a Selangor Night Market.',
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
