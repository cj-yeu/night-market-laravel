<?php

namespace App\Services;

use App\Models\CatalogImportProposal;
use App\Models\CatalogImportProposalFood;
use App\Models\CatalogImportProposalMarket;
use App\Models\CatalogImportProposalOperatingDay;
use App\Models\CatalogSocialMediaSourceLink;
use App\Models\Food;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\SocialMediaSource;
use App\Models\Stall;
use App\Models\User;
use App\Support\CatalogImportProposalImportResult;
use App\Support\CatalogMarketIdentity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CatalogImportProposalImportService
{
    private const REVIEW_METADATA_FIELDS = [
        'external_content_id',
        'title',
        'description_excerpt',
        'creator_name',
        'thumbnail_url',
        'published_at',
    ];

    public const FAILURE_ALREADY_IMPORTED = 'catalog_already_imported';

    public const FAILURE_CONFLICT_MARKET = 'catalog_market_conflict';

    public const FAILURE_CONFLICT_STALL = 'catalog_stall_conflict';

    public const FAILURE_CONFLICT_FOOD = 'catalog_food_conflict';

    public const FAILURE_TARGET_INELIGIBLE = 'catalog_target_ineligible';

    public const FAILURE_PROPOSAL_INVALID = 'catalog_proposal_invalid';

    public const FAILURE_IMPORT_FAILED = 'catalog_import_failed';

    public function __construct(
        private readonly CatalogSuggestionExtractionService $catalogSuggestionExtractionService,
        private readonly CatalogMarketIdentity $catalogMarketIdentity,
    ) {}

    public function submit(CatalogImportProposal $proposal): CatalogImportProposal
    {
        return DB::transaction(function () use ($proposal): CatalogImportProposal {
            $proposal = $this->lockedDetail($proposal->id);
            if (isset($proposal->review_metadata_snapshot['ai_import'])) {
                $this->fail(self::FAILURE_PROPOSAL_INVALID, 'Use the module Review Import page for this draft.');
            }
            $this->assertStatus($proposal, CatalogImportProposal::STATUS_DRAFT);
            $source = $this->lockedSource($proposal);
            $this->lockedTargets($proposal);
            $currentInputHash = $this->assertReadyForSubmission($proposal, $source);
            $this->assertNoExistingImport($proposal);
            $reviewMetadataSnapshot = $this->reviewMetadataSnapshot($source);
            $this->assertReviewMetadataSnapshot($reviewMetadataSnapshot);

            $proposal->forceFill([
                'status' => CatalogImportProposal::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'failure_code' => null,
                'review_metadata_snapshot' => $reviewMetadataSnapshot,
                'review_input_hash' => $currentInputHash,
            ])->save();

            return $proposal->refresh();
        }, 3);
    }

    public function reject(User $reviewer, CatalogImportProposal $proposal, string $reviewNote): CatalogImportProposal
    {
        return DB::transaction(function () use ($reviewer, $proposal, $reviewNote): CatalogImportProposal {
            $proposal = $this->lockedDetail($proposal->id);
            $this->assertStatus($proposal, CatalogImportProposal::STATUS_SUBMITTED);

            $note = trim($reviewNote);
            if ($note === '') {
                $this->fail(self::FAILURE_PROPOSAL_INVALID, 'A review note is required to reject a proposal.', 'review_note');
            }

            $proposal->forceFill([
                'status' => CatalogImportProposal::STATUS_REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'failure_code' => null,
            ])->save();

            return $proposal->refresh();
        }, 3);
    }

    public function approveAndImport(User $reviewer, CatalogImportProposal $proposal): CatalogImportProposalImportResult
    {
        try {
            return DB::transaction(function () use ($reviewer, $proposal): CatalogImportProposalImportResult {
                $proposal = $this->lockedDetail($proposal->id);

                if ($proposal->status === CatalogImportProposal::STATUS_IMPORTED) {
                    return new CatalogImportProposalImportResult($proposal, true);
                }

                $this->assertStatus($proposal, CatalogImportProposal::STATUS_SUBMITTED);
                $this->assertReadyForImport($proposal);
                $targets = $this->lockedTargets($proposal);
                $this->assertNoExistingImport($proposal);
                $this->preflightConflicts($proposal, $targets);

                $proposal->forceFill([
                    'status' => CatalogImportProposal::STATUS_APPROVED,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'review_note' => null,
                    'failure_code' => null,
                ])->save();

                $proposal->forceFill(['status' => CatalogImportProposal::STATUS_IMPORTING])->save();
                $this->importGraph($proposal, $targets);

                $proposal->forceFill([
                    'status' => CatalogImportProposal::STATUS_IMPORTED,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'imported_at' => now(),
                    'failure_code' => null,
                ])->save();

                return new CatalogImportProposalImportResult($proposal->refresh());
            }, 3);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->isMarketIdentityUniqueViolation($exception)) {
                $this->fail(self::FAILURE_CONFLICT_MARKET, $this->failureMessage(self::FAILURE_CONFLICT_MARKET));
            }

            $this->markImportFailure($proposal->id);

            $this->fail(self::FAILURE_IMPORT_FAILED, $this->failureMessage(self::FAILURE_IMPORT_FAILED));
        }
    }

    public function failureMessage(?string $failureCode): string
    {
        return match ($failureCode) {
            self::FAILURE_ALREADY_IMPORTED => 'This proposal has already been imported and cannot create catalog records again.',
            self::FAILURE_CONFLICT_MARKET => 'A matching Night Market already exists. Review the draft instead of merging it automatically.',
            self::FAILURE_CONFLICT_STALL => 'A proposed Stall conflicts with the current catalog. Review the draft before importing.',
            self::FAILURE_CONFLICT_FOOD => 'A proposed Food conflicts with the current catalog. Review the draft before importing.',
            self::FAILURE_TARGET_INELIGIBLE => 'The selected catalog target is no longer active and eligible in Selangor.',
            self::FAILURE_IMPORT_FAILED => 'The catalog import could not be completed safely. No partial catalog records were saved.',
            default => 'The proposal is incomplete or is no longer valid for import.',
        };
    }

    /** Called from the locked, validated module review workflow; never from request payloads. */
    public function importReviewedSelection(User $reviewer, CatalogImportProposal $proposal, array $graph): array
    {
        $createdImages = [];
        try {
            return DB::transaction(function () use ($reviewer, $proposal, $graph, &$createdImages) {
                $proposal = $this->lockedDetail($proposal->id);
                $data = $proposal->review_metadata_snapshot['ai_import'] ?? null;
                if ($proposal->status === CatalogImportProposal::STATUS_IMPORTED && isset($data['import_result'])) {
                    return $data['import_result'];
                }
                if (! $reviewer->hasAdminAccess() || ! $data || $proposal->status !== CatalogImportProposal::STATUS_DRAFT) {
                    $this->fail(self::FAILURE_PROPOSAL_INVALID);
                }
                $marketId = $data['context']['market_id'] ?? $graph['market']['matched_night_market_id'] ?? null;
                $market = $marketId ? $this->eligibleMarket($marketId) : null;
                $counts = ['markets' => 0, 'stalls' => 0, 'foods' => 0, 'linked' => 0];
                if (! $market) {
                    $suggestion = new CatalogImportProposalMarket($graph['market']);
                    $suggestion->setRelation('operatingDays', new Collection(collect($graph['operating_days'])->map(fn ($d) => new CatalogImportProposalOperatingDay($d))->all()));
                    $this->assertOperatingDays($suggestion->operatingDays);
                    $this->assertNewMarketDraft($proposal, $suggestion);
                    $proposal->setRelation('proposalMarket', $suggestion);
                    $this->preflightConflicts($proposal, ['market' => null, 'stall' => null]);
                    $market = NightMarket::create(['name' => $suggestion->name, 'address' => $suggestion->address,
                        'city' => $suggestion->city, 'state' => 'Selangor', 'description' => $suggestion->description,
                        'source_url' => $proposal->socialMediaSource->canonical_url, 'status' => NightMarket::STATUS_INACTIVE]);
                    foreach ($suggestion->operatingDays as $day) {
                        $market->operatingDays()->create(['day_of_week' => $day->day_of_week, 'opening_time' => $this->timeValue($day->opening_time), 'closing_time' => $this->timeValue($day->closing_time)]);
                    }
                    $counts['markets']++;
                }
                $linked = function ($record, string $type, string $column, ?string $url = null) use ($proposal, $data) {
                    $sourceId = $proposal->social_media_source_id;
                    if ($url && collect($data['sources'])->contains('url', $url)) {
                        $canonical = in_array(parse_url($url, PHP_URL_HOST), ['youtube.com', 'www.youtube.com', 'youtu.be'], true)
                            ? app(YouTubeVideoUrlCanonicalizer::class)->canonicalize($url)
                            : ['platform' => 'web', 'canonical_url' => $url, 'url_fingerprint' => hash('sha256', $url), 'external_content_id' => null];
                        $sourceId = app(CatalogImportProposalService::class)->findOrCreateSource($canonical)->id;
                    }
                    CatalogSocialMediaSourceLink::firstOrCreate(['social_media_source_id' => $sourceId, $column => $record->id],
                        ['catalog_import_proposal_id' => $proposal->id, 'catalog_type' => $type]);
                };
                $linked($market, CatalogSocialMediaSourceLink::TYPE_NIGHT_MARKET, 'night_market_id');
                $records = $counts['markets'] ? [['type' => 'market', 'id' => $market->id, 'name' => $market->name]] : [];
                $seenStalls = [];
                $seenFoods = [];
                foreach ($graph['stalls'] as $row) {
                    $name = $this->normalizedRequired($row['name'], 255);
                    if (empty($row['parent_confirmed'])) {
                        $this->fail(self::FAILURE_PROPOSAL_INVALID, 'Confirm each selected Stall belongs to this Market.');
                    }
                    $stall = empty($row['matched_stall_id']) ? null : Stall::query()->lockForUpdate()->find($row['matched_stall_id']);
                    if (! empty($row['matched_stall_id']) && (! $stall || $stall->night_market_id !== $market->id || $stall->status !== 'active')) {
                        $this->fail(self::FAILURE_TARGET_INELIGIBLE);
                    }
                    if (! empty($data['context']['stall_id']) && $stall?->id !== $data['context']['stall_id']) {
                        $this->fail(self::FAILURE_TARGET_INELIGIBLE);
                    }
                    if (! $stall) {
                        $key = Str::lower(Str::squish($name));
                        if (isset($seenStalls[$key]) || Stall::where('night_market_id', $market->id)->whereRaw('LOWER(TRIM(name)) = ?', [$key])->exists()) {
                            $this->fail(self::FAILURE_CONFLICT_STALL, 'A Stall with this name already exists. Explicitly link it or deselect this duplicate.');
                        }
                        $seenStalls[$key] = true;
                        $stall = Stall::create(['night_market_id' => $market->id, 'name' => $name, 'description' => $row['description'] ?? null,
                            'halal_status' => Stall::HALAL_UNKNOWN, 'status' => Stall::STATUS_INACTIVE]);
                        $counts['stalls']++;
                    } else {
                        $counts['linked']++;
                    }
                    $linked($stall, CatalogSocialMediaSourceLink::TYPE_STALL, 'stall_id', $row['source_url'] ?? null);
                    $records[] = ['type' => 'stall', 'id' => $stall->id, 'name' => $stall->name];
                    foreach ($row['foods'] as $item) {
                        $name = $this->normalizedRequired($item['name'], 255);
                        $food = empty($item['matched_food_id']) ? null : Food::query()->lockForUpdate()->find($item['matched_food_id']);
                        if (! empty($item['matched_food_id'])) {
                            if (! $food || $food->stall_id !== $stall->id) {
                                $this->fail(self::FAILURE_TARGET_INELIGIBLE);
                            }
                            $counts['linked']++;
                            $linked($food, CatalogSocialMediaSourceLink::TYPE_FOOD, 'food_id', $item['source_url'] ?? null);
                        } else {
                            $key = $stall->id.':'.Str::lower(Str::squish($name));
                            if (isset($seenFoods[$key]) || Food::where('stall_id', $stall->id)->where('name', $name)->exists()) {
                                $this->fail(self::FAILURE_CONFLICT_FOOD, 'A Food with this name already exists. Explicitly link it or deselect it.');
                            }
                            $seenFoods[$key] = true;
                            $suggestion = new CatalogImportProposalFood($item);
                            $this->assertFoodDraft($suggestion);
                            if (empty($item['photo_confirmed']) || empty($item['image_path']) || ! str_starts_with($item['image_path'], 'ai-import/'.$proposal->id.'/')
                                || ! app(CatalogDraftImageStorage::class)->disk()->exists($item['image_path'])
                                || ! $suggestion->category || ! app(CatalogCategoryService::class)->isPermittedSelection('food', $suggestion->category)
                                || $suggestion->price_min === null || $suggestion->price_max === null || $suggestion->price_min <= 0) {
                                $this->fail(self::FAILURE_PROPOSAL_INVALID, 'Selected Food requires a category, valid numeric price and confirmed photo.');
                            }
                            $food = $this->createFoodAndLink($proposal, $suggestion, $stall, false);
                            $upload = new UploadedFile(app(CatalogDraftImageStorage::class)->disk()->path($item['image_path']), basename($item['image_path']), null, null, true);
                            app(StallFoodImageService::class)->updateFoodImage($food, $upload);
                            $createdImages[] = $food->image_path;
                            $food->forceFill(['source_url' => $item['source_url'] ?? $proposal->socialMediaSource->canonical_url,
                                'price_checked_at' => $item['price_checked_at'] ?? null,
                                'price_display' => 'RM'.number_format((float) $food->price_min, 2).($food->price_max != $food->price_min ? '–RM'.number_format((float) $food->price_max, 2) : '').(! empty($item['unit']) ? ' / '.$item['unit'] : '')])->save();
                            $counts['foods']++;
                            $linked($food, CatalogSocialMediaSourceLink::TYPE_FOOD, 'food_id', $item['source_url'] ?? null);
                        }
                        $records[] = ['type' => 'food', 'id' => $food->id, 'name' => $food->name];
                    }
                }
                $result = ['market_id' => $market->id, 'stall_id' => $data['context']['stall_id'], 'counts' => $counts, 'records' => $records];
                $data['import_result'] = $result;
                $proposal->forceFill(['status' => 'imported', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'imported_at' => now(),
                    'review_metadata_snapshot' => ['ai_import' => $data]])->save();

                return $result;
            });
        } catch (Throwable $e) {
            foreach ($createdImages as $path) {
                if (Food::isOwnedImagePath($path)) {
                    Storage::disk('public')->delete($path);
                }
            }
            if ($e instanceof ValidationException) {
                throw $e;
            }
            $this->fail($this->isMarketIdentityUniqueViolation($e) ? self::FAILURE_CONFLICT_MARKET : self::FAILURE_IMPORT_FAILED,
                $this->failureMessage($this->isMarketIdentityUniqueViolation($e) ? self::FAILURE_CONFLICT_MARKET : self::FAILURE_IMPORT_FAILED));
        }
    }

    private function lockedDetail(int $proposalId): CatalogImportProposal
    {
        return CatalogImportProposal::query()
            ->lockForUpdate()
            ->with([
                'socialMediaSource',
                'matchedNightMarket',
                'matchedStall.nightMarket',
                'proposalMarket.operatingDays',
                'proposalMarket.stalls.foods',
                'catalogSourceLinks',
            ])
            ->findOrFail($proposalId);
    }

    private function lockedSource(CatalogImportProposal $proposal): SocialMediaSource
    {
        $source = SocialMediaSource::query()
            ->lockForUpdate()
            ->find($proposal->social_media_source_id);

        if (! $source || $source->id !== $proposal->social_media_source_id) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        $proposal->setRelation('socialMediaSource', $source);

        return $source;
    }

    /** @return array{market: NightMarket|null, stall: Stall|null} */
    private function lockedTargets(CatalogImportProposal $proposal): array
    {
        return match ($proposal->target_type) {
            CatalogImportProposal::TARGET_EXISTING_MARKET => [
                'market' => $this->eligibleMarket($proposal->matched_night_market_id),
                'stall' => null,
            ],
            CatalogImportProposal::TARGET_EXISTING_STALL => $this->eligibleStallTarget($proposal),
            CatalogImportProposal::TARGET_NEW_MARKET => $this->newMarketTargets($proposal),
            default => $this->invalidTarget(),
        };
    }

    /** @return array{market: NightMarket, stall: Stall} */
    private function eligibleStallTarget(CatalogImportProposal $proposal): array
    {
        if ($proposal->matched_stall_id === null || $proposal->matched_night_market_id === null) {
            $this->fail(self::FAILURE_TARGET_INELIGIBLE);
        }

        $stall = Stall::query()->lockForUpdate()->find($proposal->matched_stall_id);
        if (! $stall || $stall->status !== Stall::STATUS_ACTIVE || $stall->night_market_id !== $proposal->matched_night_market_id) {
            $this->fail(self::FAILURE_TARGET_INELIGIBLE);
        }

        return [
            'market' => $this->eligibleMarket($stall->night_market_id),
            'stall' => $stall,
        ];
    }

    /** @return array{market: null, stall: null} */
    private function newMarketTargets(CatalogImportProposal $proposal): array
    {
        if ($proposal->matched_night_market_id !== null || $proposal->matched_stall_id !== null) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        return ['market' => null, 'stall' => null];
    }

    private function invalidTarget(): never
    {
        $this->fail(self::FAILURE_PROPOSAL_INVALID);
    }

    private function eligibleMarket(?int $marketId): NightMarket
    {
        if ($marketId === null) {
            $this->fail(self::FAILURE_TARGET_INELIGIBLE);
        }

        $market = NightMarket::query()->lockForUpdate()->find($marketId);
        if (! $market || $market->status !== NightMarket::STATUS_ACTIVE || $market->state !== 'Selangor') {
            $this->fail(self::FAILURE_TARGET_INELIGIBLE);
        }

        return $market;
    }

    private function assertReadyForSubmission(CatalogImportProposal $proposal, SocialMediaSource $source): string
    {
        if ($source->platform !== SocialMediaSource::PLATFORM_YOUTUBE
            || $source->metadata_status !== SocialMediaSource::METADATA_FETCHED
            || $proposal->extraction_status !== CatalogImportProposal::EXTRACTION_COMPLETED
            || ! filled($proposal->extraction_input_hash)) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        try {
            $currentInputHash = $this->catalogSuggestionExtractionService->currentInputHash($proposal);
        } catch (Throwable) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        if (! hash_equals((string) $proposal->extraction_input_hash, $currentInputHash)) {
            $this->fail(
                self::FAILURE_PROPOSAL_INVALID,
                'The source metadata or selected catalog target changed after suggestions were generated. Generate suggestions again before submitting.',
            );
        }

        $this->assertReviewGraph($proposal);

        return $currentInputHash;
    }

    private function assertReadyForImport(CatalogImportProposal $proposal): void
    {
        if ($proposal->extraction_status !== CatalogImportProposal::EXTRACTION_COMPLETED
            || ! is_string($proposal->review_input_hash)
            || preg_match('/\A[0-9a-f]{64}\z/', $proposal->review_input_hash) !== 1
            || ! is_string($proposal->extraction_input_hash)
            || ! hash_equals($proposal->extraction_input_hash, $proposal->review_input_hash)) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        $this->assertReviewMetadataSnapshot($proposal->review_metadata_snapshot);
        $this->assertReviewGraph($proposal);
    }

    private function assertReviewGraph(CatalogImportProposal $proposal): void
    {
        $market = $proposal->proposalMarket;
        if (! $market) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        $this->assertOperatingDays($market->operatingDays);
        $this->assertStallsAndFoods($proposal, $market);

        if ($proposal->target_type === CatalogImportProposal::TARGET_NEW_MARKET) {
            $this->assertNewMarketDraft($proposal, $market);
        }

        if ($proposal->target_type === CatalogImportProposal::TARGET_EXISTING_MARKET
            && $market->matched_night_market_id !== $proposal->matched_night_market_id) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        if ($proposal->target_type === CatalogImportProposal::TARGET_EXISTING_STALL) {
            if ($market->stalls->count() !== 1) {
                $this->fail(self::FAILURE_PROPOSAL_INVALID);
            }

            $lockedStall = $market->stalls->first();
            if ($market->matched_night_market_id !== $proposal->matched_night_market_id
                || $lockedStall->matched_stall_id !== $proposal->matched_stall_id) {
                $this->fail(self::FAILURE_PROPOSAL_INVALID);
            }
        }
    }

    /** @return array<string, string|null> */
    private function reviewMetadataSnapshot(SocialMediaSource $source): array
    {
        return [
            'external_content_id' => $this->snapshotText($source->external_content_id, 255),
            'title' => $this->snapshotText($source->title, 500),
            'description_excerpt' => $this->snapshotText($source->description_excerpt, 5000),
            'creator_name' => $this->snapshotText($source->creator_name, 255),
            'thumbnail_url' => $this->snapshotText($source->thumbnail_url, 2048),
            'published_at' => $source->published_at?->toISOString(),
        ];
    }

    private function assertReviewMetadataSnapshot(mixed $snapshot): void
    {
        if (! is_array($snapshot)) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        $keys = array_keys($snapshot);
        sort($keys);
        $expected = self::REVIEW_METADATA_FIELDS;
        sort($expected);

        if ($keys !== $expected) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        foreach (self::REVIEW_METADATA_FIELDS as $field) {
            if (! is_string($snapshot[$field]) || trim($snapshot[$field]) === '') {
                $this->fail(self::FAILURE_PROPOSAL_INVALID);
            }
        }
    }

    private function snapshotText(?string $value, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : Str::limit($value, $maximum, '');
    }

    /** @param iterable<CatalogImportProposalOperatingDay> $days */
    private function assertOperatingDays(iterable $days): void
    {
        $seen = [];
        foreach ($days as $day) {
            if (! in_array($day->day_of_week, MarketOperatingDay::DAYS, true)
                || isset($seen[$day->day_of_week])
                || ! $this->validTime($day->opening_time)
                || ! $this->validTime($day->closing_time)
                || $this->timeValue($day->opening_time) >= $this->timeValue($day->closing_time)) {
                $this->fail(self::FAILURE_PROPOSAL_INVALID);
            }
            $seen[$day->day_of_week] = true;
        }
    }

    private function assertStallsAndFoods(CatalogImportProposal $proposal, CatalogImportProposalMarket $market): void
    {
        if ($market->stalls->isEmpty()) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        $stallNames = [];
        foreach ($market->stalls as $stall) {
            $name = $this->normalizedRequired($stall->name, 255);
            $key = mb_strtolower($name);
            if (isset($stallNames[$key])) {
                $this->fail(self::FAILURE_CONFLICT_STALL);
            }
            $stallNames[$key] = true;

            if ($proposal->target_type !== CatalogImportProposal::TARGET_EXISTING_STALL && $stall->matched_stall_id !== null) {
                $this->fail(self::FAILURE_PROPOSAL_INVALID);
            }

            if ($stall->foods->isEmpty()) {
                $this->fail(self::FAILURE_PROPOSAL_INVALID);
            }

            $foodNames = [];
            foreach ($stall->foods as $food) {
                $foodName = $this->normalizedRequired($food->name, 255);
                $foodKey = mb_strtolower($foodName);
                if (isset($foodNames[$foodKey]) || $food->matched_food_id !== null) {
                    $this->fail(self::FAILURE_CONFLICT_FOOD);
                }
                $foodNames[$foodKey] = true;
                $this->assertFoodDraft($food);
            }
        }
    }

    private function assertNewMarketDraft(CatalogImportProposal $proposal, CatalogImportProposalMarket $market): void
    {
        if ($proposal->matched_night_market_id !== null || $proposal->matched_stall_id !== null
            || $market->matched_night_market_id !== null
            || $this->normalizedRequired($market->name, 255) === ''
            || $this->normalizedRequired($market->address, 255) === ''
            || $this->normalizedRequired($market->city, 100) === ''
            || $market->state !== 'Selangor'
            || $market->operatingDays->isEmpty()) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }
    }

    private function assertFoodDraft(CatalogImportProposalFood $food): void
    {
        $this->normalizedRequired($food->name, 255);
        $this->nullableString($food->category, 100);
        $this->nullableString($food->description, 5000);
        $this->nullableString($food->price_display, 255);
        if (($food->price_min !== null && ! $this->validMoney($food->price_min))
            || ($food->price_max !== null && ! $this->validMoney($food->price_max))
            || ($food->price_min !== null && $food->price_max !== null && (float) $food->price_max < (float) $food->price_min)) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }
    }

    /** @param array{market: NightMarket|null, stall: Stall|null} $targets */
    private function preflightConflicts(CatalogImportProposal $proposal, array $targets): void
    {
        $market = $proposal->proposalMarket;
        if (! $market) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        if ($proposal->target_type === CatalogImportProposal::TARGET_NEW_MARKET) {
            $name = $this->normalizedRequired($market->name, 255);
            $address = $this->normalizedRequired($market->address, 255);
            $city = $this->normalizedRequired($market->city, 100);
            $state = 'Selangor';
            $identityHash = $this->catalogMarketIdentity->hash($name, $address, $city, $state);
            $conflict = NightMarket::query()
                ->where('catalog_identity_hash', $identityHash)
                ->orWhere(function ($query) use ($name, $address, $city, $state): void {
                    $query->whereNull('catalog_identity_hash')
                        ->where('name', $name)
                        ->where('address', $address)
                        ->where('city', $city)
                        ->where('state', $state);
                })
                ->exists();
            if ($conflict) {
                $this->fail(self::FAILURE_CONFLICT_MARKET);
            }

            return;
        }

        if ($proposal->target_type === CatalogImportProposal::TARGET_EXISTING_MARKET) {
            $target = $targets['market'];
            foreach ($market->stalls as $stall) {
                if (Stall::query()->where('night_market_id', $target->id)->where('name', $this->normalizedRequired($stall->name, 255))->exists()) {
                    $this->fail(self::FAILURE_CONFLICT_STALL);
                }
            }

            $this->assertSourceNotLinked($proposal, CatalogSocialMediaSourceLink::TYPE_NIGHT_MARKET, $target->id);

            return;
        }

        $target = $targets['stall'];
        $lockedSuggestion = $market->stalls->first();
        if (! $lockedSuggestion) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        foreach ($lockedSuggestion->foods as $food) {
            if (Food::query()->where('stall_id', $target->id)->where('name', $this->normalizedRequired($food->name, 255))->exists()) {
                $this->fail(self::FAILURE_CONFLICT_FOOD);
            }
        }

        $this->assertSourceNotLinked($proposal, CatalogSocialMediaSourceLink::TYPE_NIGHT_MARKET, $targets['market']->id);
        $this->assertSourceNotLinked($proposal, CatalogSocialMediaSourceLink::TYPE_STALL, $target->id);
    }

    private function assertNoExistingImport(CatalogImportProposal $proposal): void
    {
        if ($proposal->catalogSourceLinks->isNotEmpty()
            || CatalogSocialMediaSourceLink::query()->where('catalog_import_proposal_id', $proposal->id)->exists()) {
            $this->fail(self::FAILURE_ALREADY_IMPORTED);
        }
    }

    private function assertSourceNotLinked(CatalogImportProposal $proposal, string $type, int $id): void
    {
        $column = match ($type) {
            CatalogSocialMediaSourceLink::TYPE_NIGHT_MARKET => 'night_market_id',
            CatalogSocialMediaSourceLink::TYPE_STALL => 'stall_id',
            CatalogSocialMediaSourceLink::TYPE_FOOD => 'food_id',
        };

        if (CatalogSocialMediaSourceLink::query()
            ->where('social_media_source_id', $proposal->social_media_source_id)
            ->where($column, $id)
            ->exists()) {
            $this->fail(self::FAILURE_ALREADY_IMPORTED);
        }
    }

    /** @param array{market: NightMarket|null, stall: Stall|null} $targets */
    private function importGraph(CatalogImportProposal $proposal, array $targets): void
    {
        $marketSuggestion = $proposal->proposalMarket;
        if (! $marketSuggestion) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        match ($proposal->target_type) {
            CatalogImportProposal::TARGET_EXISTING_MARKET => $this->importIntoMarket($proposal, $targets['market'], $marketSuggestion),
            CatalogImportProposal::TARGET_EXISTING_STALL => $this->importIntoStall($proposal, $targets['market'], $targets['stall'], $marketSuggestion),
            CatalogImportProposal::TARGET_NEW_MARKET => $this->importNewMarket($proposal, $marketSuggestion),
            default => $this->invalidTarget(),
        };
    }

    private function importIntoMarket(CatalogImportProposal $proposal, NightMarket $market, CatalogImportProposalMarket $suggestion): void
    {
        $this->createLink($proposal, CatalogSocialMediaSourceLink::TYPE_NIGHT_MARKET, $market);
        $this->createStallsAndFoods($proposal, $suggestion, $market);
    }

    private function importIntoStall(CatalogImportProposal $proposal, NightMarket $market, Stall $stall, CatalogImportProposalMarket $suggestion): void
    {
        $this->createLink($proposal, CatalogSocialMediaSourceLink::TYPE_NIGHT_MARKET, $market);
        $this->createLink($proposal, CatalogSocialMediaSourceLink::TYPE_STALL, $stall);

        $lockedSuggestion = $suggestion->stalls->first();
        if (! $lockedSuggestion) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        foreach ($lockedSuggestion->foods as $foodSuggestion) {
            $this->createFoodAndLink($proposal, $foodSuggestion, $stall);
        }
    }

    private function importNewMarket(CatalogImportProposal $proposal, CatalogImportProposalMarket $suggestion): void
    {
        $name = $this->normalizedRequired($suggestion->name, 255);
        $address = $this->normalizedRequired($suggestion->address, 255);
        $city = $this->normalizedRequired($suggestion->city, 100);
        $state = 'Selangor';
        $identityHash = $this->catalogMarketIdentity->hash($name, $address, $city, $state);

        $market = new NightMarket([
            'name' => $name,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'description' => $this->nullableString($suggestion->description, 5000),
            'status' => NightMarket::STATUS_INACTIVE,
        ]);
        $market->catalog_identity_hash = $identityHash;
        $market->save();
        $this->createLink($proposal, CatalogSocialMediaSourceLink::TYPE_NIGHT_MARKET, $market);

        foreach ($suggestion->operatingDays as $day) {
            $market->operatingDays()->create([
                'day_of_week' => $day->day_of_week,
                'opening_time' => $this->timeValue($day->opening_time),
                'closing_time' => $this->timeValue($day->closing_time),
            ]);
        }

        $this->createStallsAndFoods($proposal, $suggestion, $market);
    }

    private function createStallsAndFoods(CatalogImportProposal $proposal, CatalogImportProposalMarket $suggestion, NightMarket $market): void
    {
        foreach ($suggestion->stalls as $stallSuggestion) {
            $stall = Stall::create([
                'night_market_id' => $market->id,
                'name' => $this->normalizedRequired($stallSuggestion->name, 255),
                'description' => $this->nullableString($stallSuggestion->description, 5000),
                'halal_status' => Stall::HALAL_UNKNOWN,
                'status' => Stall::STATUS_INACTIVE,
            ]);
            $this->createLink($proposal, CatalogSocialMediaSourceLink::TYPE_STALL, $stall);

            foreach ($stallSuggestion->foods as $foodSuggestion) {
                $this->createFoodAndLink($proposal, $foodSuggestion, $stall);
            }
        }
    }

    private function createFoodAndLink(CatalogImportProposal $proposal, CatalogImportProposalFood $suggestion, Stall $stall, bool $linkSource = true): Food
    {
        $food = Food::create([
            'stall_id' => $stall->id,
            'name' => $this->normalizedRequired($suggestion->name, 255),
            'description' => $this->nullableString($suggestion->description, 5000),
            'category' => $this->nullableString($suggestion->category, 100),
            'price_min' => $suggestion->price_min,
            'price_max' => $suggestion->price_max,
            'price_display' => $this->nullableString($suggestion->price_display, 255),
            'is_must_try' => (bool) $suggestion->is_must_try,
            'status' => Food::STATUS_INACTIVE,
        ]);
        if ($linkSource) {
            $this->createLink($proposal, CatalogSocialMediaSourceLink::TYPE_FOOD, $food);
        }

        return $food;
    }

    private function createLink(CatalogImportProposal $proposal, string $type, NightMarket|Stall|Food $catalog): void
    {
        $attributes = [
            'social_media_source_id' => $proposal->social_media_source_id,
            'catalog_import_proposal_id' => $proposal->id,
            'catalog_type' => $type,
            'night_market_id' => null,
            'stall_id' => null,
            'food_id' => null,
        ];

        if ($type === CatalogSocialMediaSourceLink::TYPE_NIGHT_MARKET && $catalog instanceof NightMarket) {
            $attributes['night_market_id'] = $catalog->id;
        } elseif ($type === CatalogSocialMediaSourceLink::TYPE_STALL && $catalog instanceof Stall) {
            $attributes['stall_id'] = $catalog->id;
        } elseif ($type === CatalogSocialMediaSourceLink::TYPE_FOOD && $catalog instanceof Food) {
            $attributes['food_id'] = $catalog->id;
        } else {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        if (count(array_filter([
            $attributes['night_market_id'],
            $attributes['stall_id'],
            $attributes['food_id'],
        ], fn ($id) => $id !== null)) !== 1) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        CatalogSocialMediaSourceLink::create($attributes);
    }

    private function markImportFailure(int $proposalId): void
    {
        DB::transaction(function () use ($proposalId): void {
            $proposal = CatalogImportProposal::query()->lockForUpdate()->find($proposalId);
            if ($proposal && in_array($proposal->status, [
                CatalogImportProposal::STATUS_SUBMITTED,
                CatalogImportProposal::STATUS_APPROVED,
                CatalogImportProposal::STATUS_IMPORTING,
            ], true)) {
                $proposal->forceFill([
                    'status' => CatalogImportProposal::STATUS_FAILED,
                    'failure_code' => self::FAILURE_IMPORT_FAILED,
                ])->save();
            }
        }, 3);
    }

    private function isMarketIdentityUniqueViolation(Throwable $exception): bool
    {
        if (! $exception instanceof QueryException) {
            return false;
        }

        $errorInfo = $exception->errorInfo ?? [];

        return ($errorInfo[0] ?? null) === '23000'
            && (int) ($errorInfo[1] ?? 0) === 1062
            && str_contains($exception->getMessage(), 'night_markets_catalog_identity_hash_unique');
    }

    private function assertStatus(CatalogImportProposal $proposal, string $expected): void
    {
        if ($proposal->status !== $expected) {
            $this->fail(
                $proposal->status === CatalogImportProposal::STATUS_IMPORTED
                    ? self::FAILURE_ALREADY_IMPORTED
                    : self::FAILURE_PROPOSAL_INVALID,
            );
        }
    }

    private function normalizedRequired(?string $value, int $maximum): string
    {
        $cleaned = Str::squish((string) $value);
        if ($cleaned === '' || mb_strlen($cleaned) > $maximum) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        return $cleaned;
    }

    private function nullableString(?string $value, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }

        $cleaned = trim($value);
        if ($cleaned === '') {
            return null;
        }

        if (mb_strlen($cleaned) > $maximum) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

        return $cleaned;
    }

    private function validMoney(mixed $value): bool
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value <= 99999999.99;
    }

    private function validTime(mixed $value): bool
    {
        return $value !== null && preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $this->timeValue($value)) === 1;
    }

    private function timeValue(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('H:i') : substr((string) $value, 0, 5);
    }

    private function fail(string $code, ?string $message = null, string $field = 'proposal'): never
    {
        throw ValidationException::withMessages([
            $field => $message ?? $this->failureMessage($code),
        ]);
    }
}
