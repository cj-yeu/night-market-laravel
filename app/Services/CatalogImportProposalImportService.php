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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CatalogImportProposalImportService
{
    public const FAILURE_ALREADY_IMPORTED = 'catalog_already_imported';

    public const FAILURE_CONFLICT_MARKET = 'catalog_market_conflict';

    public const FAILURE_CONFLICT_STALL = 'catalog_stall_conflict';

    public const FAILURE_CONFLICT_FOOD = 'catalog_food_conflict';

    public const FAILURE_TARGET_INELIGIBLE = 'catalog_target_ineligible';

    public const FAILURE_PROPOSAL_INVALID = 'catalog_proposal_invalid';

    public const FAILURE_IMPORT_FAILED = 'catalog_import_failed';

    public function __construct(private readonly CatalogSuggestionExtractionService $catalogSuggestionExtractionService) {}

    public function submit(CatalogImportProposal $proposal): CatalogImportProposal
    {
        return DB::transaction(function () use ($proposal): CatalogImportProposal {
            $proposal = $this->lockedDetail($proposal->id);
            $this->assertStatus($proposal, CatalogImportProposal::STATUS_DRAFT);
            $this->assertReadyForReview($proposal);
            $this->lockedTargets($proposal);
            $this->assertNoExistingImport($proposal);

            $proposal->forceFill([
                'status' => CatalogImportProposal::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'failure_code' => null,
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
                $this->assertReadyForReview($proposal);
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
        } catch (Throwable) {
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

    private function assertReadyForReview(CatalogImportProposal $proposal): void
    {
        if ($proposal->socialMediaSource === null
            || $proposal->socialMediaSource->platform !== SocialMediaSource::PLATFORM_YOUTUBE
            || $proposal->socialMediaSource->metadata_status !== SocialMediaSource::METADATA_FETCHED
            || $proposal->extraction_status !== CatalogImportProposal::EXTRACTION_COMPLETED
            || ! $this->catalogSuggestionExtractionService->inputHashMatchesCurrentMetadata($proposal)) {
            $this->fail(self::FAILURE_PROPOSAL_INVALID);
        }

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
            $conflict = NightMarket::query()
                ->where('name', $this->normalizedRequired($market->name, 255))
                ->where('address', $this->normalizedRequired($market->address, 255))
                ->where('city', $this->normalizedRequired($market->city, 100))
                ->where('state', 'Selangor')
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
        $market = NightMarket::create([
            'name' => $this->normalizedRequired($suggestion->name, 255),
            'address' => $this->normalizedRequired($suggestion->address, 255),
            'city' => $this->normalizedRequired($suggestion->city, 100),
            'state' => 'Selangor',
            'description' => $this->nullableString($suggestion->description, 5000),
            'status' => NightMarket::STATUS_INACTIVE,
        ]);
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

    private function createFoodAndLink(CatalogImportProposal $proposal, CatalogImportProposalFood $suggestion, Stall $stall): void
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
        $this->createLink($proposal, CatalogSocialMediaSourceLink::TYPE_FOOD, $food);
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
