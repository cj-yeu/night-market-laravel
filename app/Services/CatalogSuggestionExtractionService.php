<?php

namespace App\Services;

use App\Contracts\CatalogSuggestionProvider;
use App\Exceptions\CatalogSuggestionException;
use App\Models\CatalogImportProposal;
use App\Models\CatalogImportProposalFood;
use App\Models\CatalogImportProposalMarket;
use App\Models\CatalogImportProposalOperatingDay;
use App\Models\CatalogImportProposalStall;
use App\Models\MarketOperatingDay;
use App\Models\SocialMediaSource;
use App\Models\Stall;
use App\Support\CatalogSuggestionGenerationResult;
use App\Support\CatalogSuggestionInput;
use App\Support\CatalogSuggestionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CatalogSuggestionExtractionService
{
    public const FAILURE_CONFIG_MISSING = 'gemini_config_missing';

    public const FAILURE_RATE_LIMITED = 'gemini_rate_limited';

    public const FAILURE_QUOTA_EXCEEDED = 'gemini_quota_exceeded';

    public const FAILURE_FORBIDDEN = 'gemini_forbidden';

    public const FAILURE_SAFETY_BLOCKED = 'gemini_safety_blocked';

    public const FAILURE_TIMEOUT = 'gemini_timeout';

    public const FAILURE_PROVIDER_UNAVAILABLE = 'gemini_provider_unavailable';

    public const FAILURE_INVALID_RESPONSE = 'gemini_invalid_response';

    public const FAILURE_SCHEMA_MISMATCH = 'gemini_schema_mismatch';

    public const FAILURE_UNSUPPORTED_EVIDENCE = 'gemini_unsupported_evidence';

    public const FAILURE_NO_SUPPORTED_SUGGESTIONS = 'gemini_no_supported_suggestions';

    public const FAILURE_REQUEST_FAILED = 'gemini_request_failed';

    private const SCHEMA_VERSION = 'catalog-suggestions-v1';

    private const MAX_STALLS = 10;

    private const MAX_FOODS_PER_STALL = 10;

    private const MAX_OPERATING_DAYS = 7;

    private const ATTEMPT_STALE_AFTER_MINUTES = 5;

    public function __construct(private readonly CatalogSuggestionProvider $provider) {}

    /** Analyse already-read content; never treat search snippets or metadata as body text. */
    public function extractReadContent(CatalogImportProposal $proposal, string $text): array
    {
        $proposal->loadMissing('matchedNightMarket', 'matchedStall.nightMarket');
        $market = $proposal->matchedNightMarket ?? $proposal->matchedStall?->nightMarket;
        $context = $proposal->review_metadata_snapshot['ai_import']['context'] ?? [];
        $input = new CatalogSuggestionInput($market?->name ?? 'Unconfirmed Selangor market', $text, null,
            $proposal->target_type, ['market_name' => $market?->name ?? $context['name'] ?? null, 'city' => $market?->city ?? $context['city'] ?? null,
                'state' => 'Selangor', 'stall_name' => $proposal->matchedStall?->name], app(CatalogGeminiConfiguration::class)->model(), true);

        return $this->validatedGraph($proposal, $input, $this->provider->extract($input));
    }

    public function generate(CatalogImportProposal $proposal): CatalogSuggestionGenerationResult
    {
        try {
            $attempt = $this->claimAttempt($proposal->id);
        } catch (CatalogSuggestionException $exception) {
            return $this->recordConfigurationFailure($proposal->id, $exception->failureCode);
        }

        if ($attempt['outcome'] === 'completed') {
            return new CatalogSuggestionGenerationResult($attempt['proposal'], true);
        }

        if ($attempt['outcome'] === 'processing') {
            return new CatalogSuggestionGenerationResult($attempt['proposal'], true, false, true);
        }

        $input = $attempt['input'];
        $inputHash = $attempt['input_hash'];
        $attemptToken = $attempt['attempt_token'];

        try {
            $graph = $this->validatedGraph($attempt['proposal'], $input, $this->provider->extract($input));

            if (! $this->hasSupportedSuggestions($attempt['proposal'], $graph)) {
                throw new CatalogSuggestionException(self::FAILURE_NO_SUPPORTED_SUGGESTIONS);
            }

            $saved = $this->replaceGraph(
                $attempt['proposal']->id,
                $graph,
                $inputHash,
                $input->model,
                $attemptToken,
            );

            if ($saved === null) {
                return new CatalogSuggestionGenerationResult($this->detail($attempt['proposal']), true);
            }

            return new CatalogSuggestionGenerationResult($saved, false);
        } catch (CatalogSuggestionException $exception) {
            return $this->recordFailure($attempt, $exception->failureCode);
        } catch (Throwable) {
            return $this->recordFailure($attempt, self::FAILURE_REQUEST_FAILED);
        }
    }

    public function detail(CatalogImportProposal $proposal): CatalogImportProposal
    {
        return CatalogImportProposal::query()
            ->with([
                'socialMediaSource',
                'matchedNightMarket:id,name,address,city,state,description,status',
                'matchedStall:id,night_market_id,name,description,status',
                'matchedStall.nightMarket:id,name,address,city,state,description,status',
                'proposalMarket.operatingDays',
                'proposalMarket.stalls.foods',
            ])
            ->findOrFail($proposal->id);
    }

    public function statusMessage(CatalogSuggestionGenerationResult $result): string
    {
        if ($result->wasAlreadyProcessing) {
            return 'Suggestion generation is already in progress for this input. Gemini was not called again.';
        }

        if ($result->wasSkipped) {
            return 'Existing suggestions already match the fetched metadata. Gemini was not called again.';
        }

        if ($result->retainedPreviousSuggestions) {
            return 'Suggestions could not be refreshed safely. The previous reviewed draft suggestions were kept.';
        }

        if ($result->proposal->extraction_status === CatalogImportProposal::EXTRACTION_COMPLETED) {
            return 'AI-generated draft suggestions were saved for Admin review. No catalog records were created.';
        }

        return $this->failureMessage($result->proposal->extraction_failure_code);
    }

    /** @param array<string, mixed> $data */
    public function updateMarket(
        CatalogImportProposal $proposal,
        CatalogImportProposalMarket $market,
        array $data,
    ): void {
        DB::transaction(function () use ($proposal, $market, $data): void {
            $lockedProposal = $this->lockedDraftProposal($proposal->id);
            $lockedMarket = $this->lockedMarket($lockedProposal, $market->id);
            if ($lockedProposal->target_type !== CatalogImportProposal::TARGET_NEW_MARKET) {
                throw ValidationException::withMessages(['proposal' => 'The selected production Market identity is locked for this proposal.']);
            }

            $lockedMarket->update($this->cleanEditableData($data, [
                'name', 'address', 'city', 'state', 'description', 'evidence_text', 'confidence',
            ]));
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateOperatingDay(
        CatalogImportProposal $proposal,
        CatalogImportProposalOperatingDay $operatingDay,
        array $data,
    ): void {
        DB::transaction(function () use ($proposal, $operatingDay, $data): void {
            $lockedProposal = $this->lockedDraftProposal($proposal->id);
            $lockedMarket = $this->lockedProposalMarket($lockedProposal);
            $lockedDay = CatalogImportProposalOperatingDay::query()
                ->where('catalog_import_proposal_market_id', $lockedMarket->id)
                ->lockForUpdate()
                ->find($operatingDay->id);
            if (! $lockedDay) {
                abort(404);
            }

            $lockedDay->update($this->cleanEditableData($data, [
                'day_of_week', 'opening_time', 'closing_time', 'evidence_text', 'confidence',
            ]));
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateStall(
        CatalogImportProposal $proposal,
        CatalogImportProposalStall $stall,
        array $data,
    ): void {
        DB::transaction(function () use ($proposal, $stall, $data): void {
            $lockedProposal = $this->lockedDraftProposal($proposal->id);
            $lockedStall = $this->lockedStall($lockedProposal, $stall->id);
            if ($lockedStall->matched_stall_id !== null) {
                throw ValidationException::withMessages(['proposal' => 'The selected production Stall identity is locked for this proposal.']);
            }

            $lockedStall->update($this->cleanEditableData($data, [
                'name', 'description', 'halal_status', 'evidence_text', 'confidence',
            ]));
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateFood(
        CatalogImportProposal $proposal,
        CatalogImportProposalFood $food,
        array $data,
    ): void {
        DB::transaction(function () use ($proposal, $food, $data): void {
            $lockedProposal = $this->lockedDraftProposal($proposal->id);
            $lockedFood = $this->lockedFood($lockedProposal, $food->id);
            $lockedFood->update($this->cleanEditableData($data, [
                'name', 'category', 'description', 'price_display', 'price_min', 'price_max',
                'is_must_try', 'evidence_text', 'confidence',
            ]));
        }, 3);
    }

    public function deleteOperatingDay(CatalogImportProposal $proposal, CatalogImportProposalOperatingDay $operatingDay): void
    {
        DB::transaction(function () use ($proposal, $operatingDay): void {
            $lockedProposal = $this->lockedDraftProposal($proposal->id);
            $lockedMarket = $this->lockedProposalMarket($lockedProposal);
            $lockedDay = CatalogImportProposalOperatingDay::query()
                ->where('catalog_import_proposal_market_id', $lockedMarket->id)
                ->lockForUpdate()
                ->find($operatingDay->id);
            if (! $lockedDay) {
                abort(404);
            }
            $lockedDay->delete();
        }, 3);
    }

    public function deleteStall(CatalogImportProposal $proposal, CatalogImportProposalStall $stall): void
    {
        DB::transaction(function () use ($proposal, $stall): void {
            $lockedProposal = $this->lockedDraftProposal($proposal->id);
            $lockedStall = $this->lockedStall($lockedProposal, $stall->id);
            if ($lockedStall->matched_stall_id !== null) {
                throw ValidationException::withMessages(['proposal' => 'The selected production Stall identity cannot be removed.']);
            }
            $lockedStall->delete();
        }, 3);
    }

    public function deleteFood(CatalogImportProposal $proposal, CatalogImportProposalFood $food): void
    {
        DB::transaction(function () use ($proposal, $food): void {
            $lockedProposal = $this->lockedDraftProposal($proposal->id);
            $this->lockedFood($lockedProposal, $food->id)->delete();
        }, 3);
    }

    public function inputHashMatchesCurrentMetadata(CatalogImportProposal $proposal): bool
    {
        try {
            return filled($proposal->extraction_input_hash)
                && hash_equals((string) $proposal->extraction_input_hash, $this->currentInputHash($proposal));
        } catch (CatalogSuggestionException) {
            return false;
        }
    }

    public function currentInputHash(CatalogImportProposal $proposal): string
    {
        $proposal = $this->detail($proposal);

        return $this->inputHash($this->inputFor($proposal));
    }

    public function failureMessage(?string $failureCode): string
    {
        return match ($failureCode) {
            self::FAILURE_CONFIG_MISSING => 'Gemini suggestions are not configured. Ask an administrator to configure the Gemini API key.',
            self::FAILURE_RATE_LIMITED,
            self::FAILURE_QUOTA_EXCEEDED => 'Gemini suggestions are temporarily rate limited. Please retry later.',
            self::FAILURE_FORBIDDEN => 'Gemini did not allow this suggestion request. Please retry later.',
            self::FAILURE_SAFETY_BLOCKED => 'Gemini could not safely process this source text. Review the source manually.',
            self::FAILURE_TIMEOUT,
            self::FAILURE_PROVIDER_UNAVAILABLE => 'Gemini suggestions are temporarily unavailable. Please retry later.',
            self::FAILURE_UNSUPPORTED_EVIDENCE,
            self::FAILURE_NO_SUPPORTED_SUGGESTIONS => 'No source-supported catalog suggestions were found. Review the source manually.',
            default => 'Gemini suggestions could not be generated safely. Please retry later.',
        };
    }

    private function assertReadyForExtraction(CatalogImportProposal $proposal): void
    {
        $this->assertDraft($proposal);
        if ($proposal->status !== CatalogImportProposal::STATUS_DRAFT) {
            throw ValidationException::withMessages(['proposal' => 'Only draft proposals can generate suggestions.']);
        }

        if ($proposal->socialMediaSource->metadata_status !== SocialMediaSource::METADATA_FETCHED) {
            throw ValidationException::withMessages(['proposal' => 'Fetch official YouTube metadata before generating suggestions.']);
        }

        if (! filled($proposal->socialMediaSource->title) || ! filled($proposal->socialMediaSource->description_excerpt)) {
            throw ValidationException::withMessages(['proposal' => 'Fetched metadata is incomplete and cannot generate suggestions.']);
        }
    }

    private function assertDraft(CatalogImportProposal $proposal): void
    {
        if (isset($proposal->review_metadata_snapshot['ai_import'])) {
            throw ValidationException::withMessages(['proposal' => 'Use Analyse Selected in the module draft for this source.']);
        }
        if ($proposal->status !== CatalogImportProposal::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'proposal' => 'Only draft proposals can be edited.',
            ]);
        }
    }

    private function lockedDraftProposal(int $proposalId): CatalogImportProposal
    {
        $proposal = CatalogImportProposal::query()->lockForUpdate()->findOrFail($proposalId);
        $this->assertDraft($proposal);

        return $proposal;
    }

    private function lockedProposalMarket(CatalogImportProposal $proposal): CatalogImportProposalMarket
    {
        $market = CatalogImportProposalMarket::query()
            ->where('catalog_import_proposal_id', $proposal->id)
            ->lockForUpdate()
            ->first();
        if (! $market) {
            abort(404);
        }

        return $market;
    }

    private function lockedMarket(CatalogImportProposal $proposal, int $marketId): CatalogImportProposalMarket
    {
        $market = CatalogImportProposalMarket::query()
            ->where('catalog_import_proposal_id', $proposal->id)
            ->lockForUpdate()
            ->find($marketId);
        if (! $market) {
            abort(404);
        }

        return $market;
    }

    private function lockedStall(CatalogImportProposal $proposal, int $stallId): CatalogImportProposalStall
    {
        $market = $this->lockedProposalMarket($proposal);
        $stall = CatalogImportProposalStall::query()
            ->where('catalog_import_proposal_market_id', $market->id)
            ->lockForUpdate()
            ->find($stallId);
        if (! $stall) {
            abort(404);
        }

        return $stall;
    }

    private function lockedFood(CatalogImportProposal $proposal, int $foodId): CatalogImportProposalFood
    {
        $stallId = CatalogImportProposalFood::query()
            ->whereKey($foodId)
            ->value('catalog_import_proposal_stall_id');
        if (! is_numeric($stallId)) {
            abort(404);
        }

        $stall = $this->lockedStall($proposal, (int) $stallId);
        $food = CatalogImportProposalFood::query()
            ->where('catalog_import_proposal_stall_id', $stall->id)
            ->lockForUpdate()
            ->find($foodId);
        if (! $food) {
            abort(404);
        }

        return $food;
    }

    /** @param array<string, mixed> $data @param list<string> $allowed @return array<string, mixed> */
    private function cleanEditableData(array $data, array $allowed): array
    {
        $cleaned = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $cleaned[$field] = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
            }
        }

        return $cleaned;
    }

    private function inputFor(CatalogImportProposal $proposal): CatalogSuggestionInput
    {
        $target = match ($proposal->target_type) {
            CatalogImportProposal::TARGET_EXISTING_MARKET => $this->existingMarketContext($proposal),
            CatalogImportProposal::TARGET_EXISTING_STALL => $this->existingStallContext($proposal),
            default => [],
        };
        $model = config('services.gemini.model');

        if (! is_string($model) || trim($model) === '') {
            throw new CatalogSuggestionException(self::FAILURE_CONFIG_MISSING);
        }

        $creator = is_string($proposal->socialMediaSource->creator_name)
            ? $this->normalizeWhitespace($proposal->socialMediaSource->creator_name)
            : null;

        return new CatalogSuggestionInput(
            $this->normalizeWhitespace((string) $proposal->socialMediaSource->title),
            $this->normalizeWhitespace((string) $proposal->socialMediaSource->description_excerpt),
            $creator === '' ? null : $creator,
            $proposal->target_type,
            $target,
            trim($model),
        );
    }

    /** @return array<string, string|null> */
    private function existingMarketContext(CatalogImportProposal $proposal): array
    {
        $market = $proposal->matchedNightMarket;
        if (! $market) {
            throw ValidationException::withMessages(['proposal' => 'The selected Night Market is no longer available.']);
        }

        return [
            'market_name' => $market->name,
            'market_address' => $market->address,
            'market_city' => $market->city,
            'market_state' => $market->state,
        ];
    }

    /** @return array<string, string|null> */
    private function existingStallContext(CatalogImportProposal $proposal): array
    {
        $stall = $proposal->matchedStall;
        $market = $stall?->nightMarket;
        if (! $stall || ! $market) {
            throw ValidationException::withMessages(['proposal' => 'The selected Stall is no longer available.']);
        }

        return [
            'market_name' => $market->name,
            'market_address' => $market->address,
            'market_city' => $market->city,
            'market_state' => $market->state,
            'stall_name' => $stall->name,
        ];
    }

    private function inputHash(CatalogSuggestionInput $input): string
    {
        return hash('sha256', (string) json_encode([
            'source_title' => $input->sourceTitle,
            'source_description' => $input->sourceDescription,
            'source_creator' => $input->sourceCreator,
            'target_type' => $input->targetType,
            'target' => $input->authoritativeTarget,
            'schema' => self::SCHEMA_VERSION,
            'model' => $input->model,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{
     *     outcome: 'claimed'|'completed'|'processing',
     *     proposal: CatalogImportProposal,
     *     input?: CatalogSuggestionInput,
     *     input_hash?: string,
     *     attempt_token?: string,
     *     had_completed?: bool,
     *     previous_hash?: string|null,
     *     previous_model?: string|null,
     *     previous_extracted_at?: mixed
     * }
     */
    private function claimAttempt(int $proposalId): array
    {
        return DB::transaction(function () use ($proposalId): array {
            $proposal = CatalogImportProposal::query()->lockForUpdate()->findOrFail($proposalId);
            $source = SocialMediaSource::query()->lockForUpdate()->findOrFail($proposal->social_media_source_id);
            $proposal->setRelation('socialMediaSource', $source);
            $proposal->load([
                'matchedNightMarket:id,name,address,city,state,description,status',
                'matchedStall:id,night_market_id,name,description,status',
                'matchedStall.nightMarket:id,name,address,city,state,description,status',
                'proposalMarket.operatingDays',
                'proposalMarket.stalls.foods',
            ]);

            $this->assertReadyForExtraction($proposal);
            $input = $this->inputFor($proposal);
            $inputHash = $this->inputHash($input);

            if ($proposal->extraction_status === CatalogImportProposal::EXTRACTION_COMPLETED
                && $proposal->proposalMarket !== null
                && is_string($proposal->extraction_input_hash)
                && hash_equals($proposal->extraction_input_hash, $inputHash)) {
                return [
                    'outcome' => 'completed',
                    'proposal' => $proposal,
                ];
            }

            $attemptStartedAt = $proposal->extraction_attempt_started_at;
            $hasActiveAttempt = $proposal->extraction_status === CatalogImportProposal::EXTRACTION_PROCESSING
                && is_string($proposal->extraction_attempt_token)
                && $proposal->extraction_attempt_token !== ''
                && is_string($proposal->extraction_input_hash)
                && hash_equals($proposal->extraction_input_hash, $inputHash)
                && $attemptStartedAt !== null
                && $attemptStartedAt->isAfter(now()->subMinutes(self::ATTEMPT_STALE_AFTER_MINUTES));

            if ($hasActiveAttempt) {
                return [
                    'outcome' => 'processing',
                    'proposal' => $proposal,
                ];
            }

            $attemptToken = (string) Str::uuid();
            $hadCompleted = $proposal->extraction_status === CatalogImportProposal::EXTRACTION_COMPLETED
                && $proposal->proposalMarket !== null;
            $previousHash = $proposal->extraction_input_hash;
            $previousModel = $proposal->extraction_model;
            $previousExtractedAt = $proposal->extracted_at;

            $proposal->forceFill([
                'extraction_status' => CatalogImportProposal::EXTRACTION_PROCESSING,
                'extraction_failure_code' => null,
                'extraction_model' => $input->model,
                'extraction_input_hash' => $inputHash,
                'extraction_attempt_token' => $attemptToken,
                'extraction_attempt_started_at' => now(),
            ])->save();

            return [
                'outcome' => 'claimed',
                'proposal' => $proposal,
                'input' => $input,
                'input_hash' => $inputHash,
                'attempt_token' => $attemptToken,
                'had_completed' => $hadCompleted,
                'previous_hash' => $previousHash,
                'previous_model' => $previousModel,
                'previous_extracted_at' => $previousExtractedAt,
            ];
        }, 3);
    }

    private function recordConfigurationFailure(int $proposalId, string $failureCode): CatalogSuggestionGenerationResult
    {
        return DB::transaction(function () use ($proposalId, $failureCode): CatalogSuggestionGenerationResult {
            $proposal = $this->lockedDraftProposal($proposalId);
            $hasCompletedGraph = $proposal->extraction_status === CatalogImportProposal::EXTRACTION_COMPLETED
                && $proposal->proposalMarket()->exists();

            $proposal->forceFill([
                'extraction_status' => $hasCompletedGraph
                    ? CatalogImportProposal::EXTRACTION_COMPLETED
                    : CatalogImportProposal::EXTRACTION_FAILED,
                'extraction_failure_code' => $failureCode,
                'extraction_attempt_token' => null,
                'extraction_attempt_started_at' => null,
            ])->save();

            return new CatalogSuggestionGenerationResult(
                $this->detail($proposal),
                false,
                $hasCompletedGraph,
            );
        }, 3);
    }

    /** @return array{market: array<string, mixed>, operating_days: list<array<string, mixed>>, stalls: list<array<string, mixed>>} */
    private function validatedGraph(
        CatalogImportProposal $proposal,
        CatalogSuggestionInput $input,
        CatalogSuggestionResult $result,
    ): array {
        $payload = $result->payload;
        if (! array_key_exists('market', $payload)
            || ! is_array($payload['stalls'] ?? null)
            || ! is_array($payload['warnings'] ?? null)
            || ! is_bool($payload['insufficient_data'] ?? null)) {
            throw new CatalogSuggestionException(self::FAILURE_SCHEMA_MISMATCH);
        }

        $sourceText = $this->normalizeWhitespace($input->sourceTitle."\n".$input->sourceDescription);
        $market = $this->marketSuggestion($proposal, $payload['market'], $sourceText);
        $operatingDays = $this->operatingDays($payload['market'], $sourceText, (string) $market['name']);
        $stalls = $this->stalls($proposal, $payload['stalls'], $sourceText, $input->moduleImport);

        return [
            'market' => $market,
            'operating_days' => $operatingDays,
            'stalls' => $stalls,
        ];
    }

    /** @return array<string, mixed> */
    private function marketSuggestion(CatalogImportProposal $proposal, mixed $candidate, string $sourceText): array
    {
        if ($proposal->target_type === CatalogImportProposal::TARGET_EXISTING_MARKET) {
            $market = $proposal->matchedNightMarket;

            return [
                'matched_night_market_id' => $market->id,
                'name' => $market->name,
                'address' => $market->address,
                'city' => $market->city,
                'state' => $market->state,
                'description' => $market->description,
                'evidence_text' => null,
                'confidence' => null,
            ];
        }

        if ($proposal->target_type === CatalogImportProposal::TARGET_EXISTING_STALL) {
            $market = $proposal->matchedStall->nightMarket;

            return [
                'matched_night_market_id' => $market->id,
                'name' => $market->name,
                'address' => $market->address,
                'city' => $market->city,
                'state' => $market->state,
                'description' => $market->description,
                'evidence_text' => null,
                'confidence' => null,
            ];
        }

        if (! is_array($candidate)) {
            throw new CatalogSuggestionException(self::FAILURE_SCHEMA_MISMATCH);
        }

        $evidence = $this->evidence($candidate['evidence_text'] ?? null, $sourceText);
        $name = $evidence === null ? null : $this->supportedText($candidate['name'] ?? null, $evidence, 255);
        $state = $this->cleanText($candidate['state'] ?? null, 255);
        if ($name === null
            || $evidence === null
            || $state === null
            || strcasecmp($state, 'Selangor') !== 0
            || ! $this->containsLiteral($evidence, 'Selangor')) {
            throw new CatalogSuggestionException(self::FAILURE_UNSUPPORTED_EVIDENCE);
        }

        return [
            'matched_night_market_id' => null,
            'name' => $name,
            'address' => $this->supportedText($candidate['address'] ?? null, $evidence, 255),
            'city' => $this->supportedText($candidate['city'] ?? null, $evidence, 255),
            'state' => 'Selangor',
            'description' => $this->supportedText($candidate['description'] ?? null, $evidence, 5000),
            'evidence_text' => $evidence,
            'confidence' => $this->confidence($candidate['confidence'] ?? null),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function operatingDays(mixed $market, string $sourceText, string $marketName): array
    {
        if (! is_array($market) || ! is_array($market['operating_days'] ?? null)) {
            return [];
        }

        $days = [];
        foreach (array_slice($market['operating_days'], 0, self::MAX_OPERATING_DAYS) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $day = $this->cleanText($candidate['day_of_week'] ?? null, 16);
            $evidence = $this->evidence($candidate['evidence_text'] ?? null, $sourceText);
            $opening = $this->time($candidate['opening_time'] ?? null, (string) $evidence);
            $closing = $this->time($candidate['closing_time'] ?? null, (string) $evidence);

            if (! in_array($day, MarketOperatingDay::DAYS, true)
                || $evidence === null
                || ! $this->containsLiteral($evidence, $marketName)
                || ! $this->containsTerm($evidence, (string) $day)
                || ! is_string($opening)
                || ! is_string($closing)
                || $opening >= $closing) {
                continue;
            }

            if (isset($days[$day])) {
                continue;
            }

            $days[$day] = [
                'day_of_week' => $day,
                'opening_time' => $opening,
                'closing_time' => $closing,
                'evidence_text' => $evidence,
                'confidence' => $this->confidence($candidate['confidence'] ?? null),
            ];
        }

        return array_values($days);
    }

    /** @return list<array<string, mixed>> */
    private function stalls(CatalogImportProposal $proposal, array $candidates, string $sourceText, bool $allowIncompleteDraft = false): array
    {
        if ($proposal->target_type === CatalogImportProposal::TARGET_EXISTING_STALL) {
            $stall = $proposal->matchedStall;
            $authoritativeName = $this->normalizedComparable((string) $stall->name);
            $matches = [];
            foreach (array_slice($candidates, 0, self::MAX_STALLS) as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $candidateName = $this->cleanText($candidate['name'] ?? null, 255);
                $evidence = $this->evidence($candidate['evidence_text'] ?? null, $sourceText);
                if ($candidateName !== null
                    && $evidence !== null
                    && $this->normalizedComparable($candidateName) === $authoritativeName
                    && $this->containsLiteral($evidence, (string) $stall->name)) {
                    $matches[] = ['candidate' => $candidate, 'evidence' => $evidence];
                }
            }

            if (count($matches) !== 1) {
                return [];
            }

            $candidate = $matches[0]['candidate'];

            return [[
                'matched_stall_id' => $stall->id,
                'name' => $stall->name,
                'description' => $stall->description,
                'halal_status' => Stall::HALAL_UNKNOWN,
                'evidence_text' => $matches[0]['evidence'],
                'confidence' => null,
                'display_order' => 0,
                'foods' => $this->foods(
                    $candidate['foods'] ?? [],
                    $sourceText,
                    (string) $matches[0]['evidence'],
                ),
            ]];
        }

        $stalls = [];
        foreach (array_slice($candidates, 0, self::MAX_STALLS) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $evidence = $this->evidence($candidate['evidence_text'] ?? null, $sourceText);
            $name = $evidence === null ? null : $this->supportedText($candidate['name'] ?? null, $evidence, 255);
            if ($allowIncompleteDraft && is_string($name) && preg_match('/\A(?:unnamed|unknown|unidentified)\b/i', $name)) {
                $name = null;
            }
            if ($evidence === null || ($name === null && ! $allowIncompleteDraft)) {
                continue;
            }

            // Module drafts may retain supported foods under an unnamed, unconfirmed parent.
            // Never replace a missing brand with a model-invented stall identity.
            $key = $name === null ? 'unnamed:'.count($stalls) : mb_strtolower($name);
            if (isset($stalls[$key])) {
                continue;
            }

            $stalls[$key] = [
                'matched_stall_id' => null,
                'name' => $name ?? '',
                'description' => $this->supportedText($candidate['description'] ?? null, $evidence, 5000),
                'halal_status' => Stall::HALAL_UNKNOWN,
                'evidence_text' => $evidence,
                'confidence' => $this->confidence($candidate['confidence'] ?? null),
                'display_order' => count($stalls),
                'foods' => $this->foods($candidate['foods'] ?? [], $sourceText),
            ];
        }

        return array_values($stalls);
    }

    /** @param mixed $candidates @return list<array<string, mixed>> */
    private function foods(mixed $candidates, string $sourceText, ?string $requiredParentEvidence = null): array
    {
        if (! is_array($candidates)) {
            return [];
        }

        $foods = [];
        foreach (array_slice($candidates, 0, self::MAX_FOODS_PER_STALL) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $evidence = $this->evidence($candidate['evidence_text'] ?? null, $sourceText);
            $name = $evidence === null ? null : $this->supportedText($candidate['name'] ?? null, $evidence, 255);
            if ($name === null
                || $evidence === null
                || ($requiredParentEvidence !== null && ! $this->containsLiteral($requiredParentEvidence, $name))
                || isset($foods[mb_strtolower($name)])) {
                continue;
            }

            $amounts = $this->sourceAmounts($evidence);
            $display = $this->supportedPriceDisplay($candidate['price_display'] ?? null, $evidence);
            $minimum = $this->supportedPrice($candidate['price_min'] ?? null, $amounts);
            $maximum = $this->supportedPrice($candidate['price_max'] ?? null, $amounts);
            $mustTry = ($candidate['is_must_try'] ?? false) === true
                && preg_match('/\b(?:must[- ]?try|recommended|highly recommend|signature|best[- ]seller)\b/i', $evidence) === 1;

            $foods[mb_strtolower($name)] = [
                'matched_food_id' => null,
                'name' => $name,
                'category' => $this->supportedText($candidate['category'] ?? null, $evidence, 255),
                'description' => $this->supportedText($candidate['description'] ?? null, $evidence, 5000),
                'price_display' => $display,
                'price_min' => $minimum,
                'price_max' => $maximum,
                'is_must_try' => $mustTry,
                'evidence_text' => $evidence,
                'confidence' => $this->confidence($candidate['confidence'] ?? null),
                'display_order' => count($foods),
            ];
        }

        return array_values($foods);
    }

    /** @param array{market: array<string, mixed>, operating_days: list<array<string, mixed>>, stalls: list<array<string, mixed>>} $graph */
    private function hasSupportedSuggestions(CatalogImportProposal $proposal, array $graph): bool
    {
        if ($proposal->target_type === CatalogImportProposal::TARGET_NEW_MARKET) {
            return true;
        }

        if ($proposal->target_type === CatalogImportProposal::TARGET_EXISTING_STALL) {
            return isset($graph['stalls'][0]) && $graph['stalls'][0]['foods'] !== [];
        }

        return $graph['operating_days'] !== [] || $graph['stalls'] !== [];
    }

    /** @param array{market: array<string, mixed>, operating_days: list<array<string, mixed>>, stalls: list<array<string, mixed>>} $graph */
    private function replaceGraph(
        int $proposalId,
        array $graph,
        string $inputHash,
        string $model,
        string $attemptToken,
    ): ?CatalogImportProposal {
        return DB::transaction(function () use ($proposalId, $graph, $inputHash, $model, $attemptToken): ?CatalogImportProposal {
            $lockedProposal = CatalogImportProposal::query()->lockForUpdate()->findOrFail($proposalId);
            if ($lockedProposal->status !== CatalogImportProposal::STATUS_DRAFT
                || ! is_string($lockedProposal->extraction_attempt_token)
                || ! hash_equals($lockedProposal->extraction_attempt_token, $attemptToken)) {
                return null;
            }

            $lockedProposal->proposalMarket()->delete();

            $market = $lockedProposal->proposalMarket()->create($graph['market']);
            foreach ($graph['operating_days'] as $day) {
                $market->operatingDays()->create($day);
            }
            foreach ($graph['stalls'] as $stallData) {
                $foods = $stallData['foods'];
                unset($stallData['foods']);
                $stall = $market->stalls()->create($stallData);
                foreach ($foods as $food) {
                    $stall->foods()->create($food);
                }
            }

            $lockedProposal->update([
                'extraction_status' => CatalogImportProposal::EXTRACTION_COMPLETED,
                'extraction_failure_code' => null,
                'extraction_model' => $model,
                'extraction_input_hash' => $inputHash,
                'extracted_at' => now(),
                'extraction_attempt_token' => null,
                'extraction_attempt_started_at' => null,
            ]);

            return $this->detail($lockedProposal);
        }, 3);
    }

    /** @param array<string, mixed> $attempt */
    private function recordFailure(
        array $attempt,
        string $failureCode,
    ): CatalogSuggestionGenerationResult {
        return DB::transaction(function () use ($attempt, $failureCode): CatalogSuggestionGenerationResult {
            $lockedProposal = CatalogImportProposal::query()->lockForUpdate()->findOrFail($attempt['proposal']->id);
            if ($lockedProposal->status !== CatalogImportProposal::STATUS_DRAFT
                || ! is_string($lockedProposal->extraction_attempt_token)
                || ! hash_equals($lockedProposal->extraction_attempt_token, $attempt['attempt_token'])) {
                return new CatalogSuggestionGenerationResult($this->detail($lockedProposal), true);
            }

            $hasGraph = $lockedProposal->proposalMarket()->exists();
            $retainPrevious = ($attempt['had_completed'] ?? false) && $hasGraph;

            $lockedProposal->forceFill($retainPrevious
                ? [
                    'extraction_status' => CatalogImportProposal::EXTRACTION_COMPLETED,
                    'extraction_failure_code' => $failureCode,
                    'extraction_model' => $attempt['previous_model'],
                    'extraction_input_hash' => $attempt['previous_hash'],
                    'extracted_at' => $attempt['previous_extracted_at'],
                    'extraction_attempt_token' => null,
                    'extraction_attempt_started_at' => null,
                ]
                : [
                    'extraction_status' => CatalogImportProposal::EXTRACTION_FAILED,
                    'extraction_failure_code' => $failureCode,
                    'extraction_attempt_token' => null,
                    'extraction_attempt_started_at' => null,
                ])->save();

            return new CatalogSuggestionGenerationResult(
                $this->detail($lockedProposal),
                false,
                $retainPrevious,
            );
        }, 3);
    }

    private function evidence(mixed $value, string $sourceText): ?string
    {
        $evidence = $this->cleanText($value, 1000);

        return $evidence !== null && $this->containsLiteral($sourceText, $evidence) ? $evidence : null;
    }

    private function supportedText(mixed $value, string $sourceText, int $limit): ?string
    {
        $value = $this->cleanText($value, $limit);

        return $value !== null && $this->containsLiteral($sourceText, $value) ? $value : null;
    }

    private function cleanText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function normalizedComparable(string $value): string
    {
        return Str::lower($this->normalizeWhitespace($value));
    }

    private function containsLiteral(string $haystack, string $needle): bool
    {
        $needle = $this->normalizedComparable($needle);

        return $needle !== '' && str_contains($this->normalizedComparable($haystack), $needle);
    }

    private function containsTerm(string $haystack, string $term): bool
    {
        return preg_match(
            '/(?<![\pL\pN])'.preg_quote($term, '/').'(?![\pL\pN])/iu',
            $this->normalizeWhitespace($haystack),
        ) === 1;
    }

    private function confidence(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function time(mixed $value, string $sourceText): string|false|null
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $value) !== 1) {
            return false;
        }

        return preg_match(
            '/(?<![0-9:])'.preg_quote($value, '/').'(?![0-9:])/',
            $this->normalizeWhitespace($sourceText),
        ) === 1 ? $value : false;
    }

    /** @return list<float> */
    private function sourceAmounts(string $sourceText): array
    {
        preg_match_all(
            '/(?<![\pL\pN.])(?:RM|MYR)\s*([0-9]+(?:\.[0-9]{1,2})?)(?![0-9.])/iu',
            $sourceText,
            $matches,
        );

        return array_map('floatval', $matches[1] ?? []);
    }

    private function supportedPrice(mixed $value, array $amounts): ?float
    {
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 99999999.99) {
            return null;
        }

        $value = round((float) $value, 2);
        foreach ($amounts as $amount) {
            if (abs($amount - $value) < 0.001) {
                return $value;
            }
        }

        return null;
    }

    private function supportedPriceDisplay(mixed $value, string $sourceText): ?string
    {
        $display = $this->cleanText($value, 255);
        if ($display === null || preg_match('/\b(?:RM|MYR)\s*[0-9]/i', $display) !== 1) {
            return null;
        }

        return preg_match(
            '/(?<![\pL\pN.])'.preg_quote($display, '/').'(?![\pL\pN.])/iu',
            $this->normalizeWhitespace($sourceText),
        ) === 1 ? $display : null;
    }
}
