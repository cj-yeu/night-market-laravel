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

    public function __construct(private readonly CatalogSuggestionProvider $provider) {}

    public function generate(CatalogImportProposal $proposal): CatalogSuggestionGenerationResult
    {
        $proposal = $this->detail($proposal);
        $this->assertReadyForExtraction($proposal);

        $input = $this->inputFor($proposal);
        $inputHash = $this->inputHash($input);

        if ($proposal->extraction_status === CatalogImportProposal::EXTRACTION_COMPLETED
            && hash_equals((string) $proposal->extraction_input_hash, $inputHash)) {
            return new CatalogSuggestionGenerationResult($proposal, true);
        }

        $hadCompletedSuggestions = $proposal->extraction_status === CatalogImportProposal::EXTRACTION_COMPLETED
            && $proposal->proposalMarket !== null;

        DB::transaction(function () use ($proposal): void {
            CatalogImportProposal::query()
                ->lockForUpdate()
                ->findOrFail($proposal->id)
                ->update([
                    'extraction_status' => CatalogImportProposal::EXTRACTION_PROCESSING,
                    'extraction_failure_code' => null,
                ]);
        }, 3);

        try {
            $graph = $this->validatedGraph($proposal, $this->provider->extract($input));

            if (! $this->hasSupportedSuggestions($proposal, $graph)) {
                throw new CatalogSuggestionException(self::FAILURE_NO_SUPPORTED_SUGGESTIONS);
            }

            $saved = $this->replaceGraph($proposal, $graph, $inputHash, $input->model);

            return new CatalogSuggestionGenerationResult($saved, false);
        } catch (CatalogSuggestionException $exception) {
            return $this->recordFailure($proposal, $exception->failureCode, $hadCompletedSuggestions);
        } catch (Throwable) {
            return $this->recordFailure($proposal, self::FAILURE_REQUEST_FAILED, $hadCompletedSuggestions);
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
        $this->assertMarketBelongsToProposal($proposal, $market);
        if ($proposal->target_type !== CatalogImportProposal::TARGET_NEW_MARKET) {
            throw ValidationException::withMessages(['proposal' => 'The selected production Market identity is locked for this proposal.']);
        }

        $market->update($this->cleanEditableData($data, [
            'name', 'address', 'city', 'state', 'description', 'evidence_text', 'confidence',
        ]));
    }

    /** @param array<string, mixed> $data */
    public function updateOperatingDay(
        CatalogImportProposal $proposal,
        CatalogImportProposalOperatingDay $operatingDay,
        array $data,
    ): void {
        $this->assertOperatingDayBelongsToProposal($proposal, $operatingDay);
        $operatingDay->update($this->cleanEditableData($data, [
            'day_of_week', 'opening_time', 'closing_time', 'evidence_text', 'confidence',
        ]));
    }

    /** @param array<string, mixed> $data */
    public function updateStall(
        CatalogImportProposal $proposal,
        CatalogImportProposalStall $stall,
        array $data,
    ): void {
        $this->assertStallBelongsToProposal($proposal, $stall);
        if ($stall->matched_stall_id !== null) {
            throw ValidationException::withMessages(['proposal' => 'The selected production Stall identity is locked for this proposal.']);
        }

        $stall->update($this->cleanEditableData($data, [
            'name', 'description', 'halal_status', 'evidence_text', 'confidence',
        ]));
    }

    /** @param array<string, mixed> $data */
    public function updateFood(
        CatalogImportProposal $proposal,
        CatalogImportProposalFood $food,
        array $data,
    ): void {
        $this->assertFoodBelongsToProposal($proposal, $food);
        $food->update($this->cleanEditableData($data, [
            'name', 'category', 'description', 'price_display', 'price_min', 'price_max',
            'is_must_try', 'evidence_text', 'confidence',
        ]));
    }

    public function deleteOperatingDay(CatalogImportProposal $proposal, CatalogImportProposalOperatingDay $operatingDay): void
    {
        $this->assertOperatingDayBelongsToProposal($proposal, $operatingDay);
        $operatingDay->delete();
    }

    public function deleteStall(CatalogImportProposal $proposal, CatalogImportProposalStall $stall): void
    {
        $this->assertStallBelongsToProposal($proposal, $stall);
        if ($stall->matched_stall_id !== null) {
            throw ValidationException::withMessages(['proposal' => 'The selected production Stall identity cannot be removed.']);
        }

        $stall->delete();
    }

    public function deleteFood(CatalogImportProposal $proposal, CatalogImportProposalFood $food): void
    {
        $this->assertFoodBelongsToProposal($proposal, $food);
        $food->delete();
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

    private function assertMarketBelongsToProposal(CatalogImportProposal $proposal, CatalogImportProposalMarket $market): void
    {
        if ($market->catalog_import_proposal_id !== $proposal->id) {
            abort(404);
        }
    }

    private function assertOperatingDayBelongsToProposal(CatalogImportProposal $proposal, CatalogImportProposalOperatingDay $operatingDay): void
    {
        if ($operatingDay->proposalMarket?->catalog_import_proposal_id !== $proposal->id) {
            abort(404);
        }
    }

    private function assertStallBelongsToProposal(CatalogImportProposal $proposal, CatalogImportProposalStall $stall): void
    {
        if ($stall->proposalMarket?->catalog_import_proposal_id !== $proposal->id) {
            abort(404);
        }
    }

    private function assertFoodBelongsToProposal(CatalogImportProposal $proposal, CatalogImportProposalFood $food): void
    {
        if ($food->proposalStall?->proposalMarket?->catalog_import_proposal_id !== $proposal->id) {
            abort(404);
        }
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

        return new CatalogSuggestionInput(
            (string) $proposal->socialMediaSource->title,
            (string) $proposal->socialMediaSource->description_excerpt,
            $proposal->socialMediaSource->creator_name,
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
            'target_type' => $input->targetType,
            'target' => $input->authoritativeTarget,
            'schema' => self::SCHEMA_VERSION,
            'model' => $input->model,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array{market: array<string, mixed>, operating_days: list<array<string, mixed>>, stalls: list<array<string, mixed>>} */
    private function validatedGraph(CatalogImportProposal $proposal, CatalogSuggestionResult $result): array
    {
        $payload = $result->payload;
        if (! array_key_exists('market', $payload)
            || ! is_array($payload['stalls'] ?? null)
            || ! is_array($payload['warnings'] ?? null)
            || ! is_bool($payload['insufficient_data'] ?? null)) {
            throw new CatalogSuggestionException(self::FAILURE_SCHEMA_MISMATCH);
        }

        $sourceText = $this->normalizeWhitespace($proposal->socialMediaSource->title."\n".$proposal->socialMediaSource->description_excerpt);
        $market = $this->marketSuggestion($proposal, $payload['market'], $sourceText);
        $operatingDays = $this->operatingDays($payload['market'], $sourceText);
        $stalls = $this->stalls($proposal, $payload['stalls'], $sourceText);

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

        $name = $this->supportedText($candidate['name'] ?? null, $sourceText, 255);
        $evidence = $this->evidence($candidate['evidence_text'] ?? null, $sourceText);
        if ($name === null || $evidence === null) {
            throw new CatalogSuggestionException(self::FAILURE_UNSUPPORTED_EVIDENCE);
        }

        $state = $this->cleanText($candidate['state'] ?? null, 255);

        return [
            'matched_night_market_id' => null,
            'name' => $name,
            'address' => $this->supportedText($candidate['address'] ?? null, $sourceText, 255),
            'city' => $this->supportedText($candidate['city'] ?? null, $sourceText, 255),
            'state' => $state !== null && strcasecmp($state, 'Selangor') === 0 ? 'Selangor' : null,
            'description' => $this->supportedText($candidate['description'] ?? null, $sourceText, 5000),
            'evidence_text' => $evidence,
            'confidence' => $this->confidence($candidate['confidence'] ?? null),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function operatingDays(mixed $market, string $sourceText): array
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
            $opening = $this->time($candidate['opening_time'] ?? null, $sourceText);
            $closing = $this->time($candidate['closing_time'] ?? null, $sourceText);

            if (! in_array($day, MarketOperatingDay::DAYS, true) || $evidence === null || ($opening === false || $closing === false)) {
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
    private function stalls(CatalogImportProposal $proposal, array $candidates, string $sourceText): array
    {
        if ($proposal->target_type === CatalogImportProposal::TARGET_EXISTING_STALL) {
            $foods = [];
            foreach ($candidates as $candidate) {
                if (is_array($candidate) && is_array($candidate['foods'] ?? null)) {
                    $foods = [...$foods, ...$candidate['foods']];
                }
            }
            $stall = $proposal->matchedStall;

            return [[
                'matched_stall_id' => $stall->id,
                'name' => $stall->name,
                'description' => $stall->description,
                'halal_status' => Stall::HALAL_UNKNOWN,
                'evidence_text' => null,
                'confidence' => null,
                'display_order' => 0,
                'foods' => $this->foods($foods, $sourceText),
            ]];
        }

        $stalls = [];
        foreach (array_slice($candidates, 0, self::MAX_STALLS) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $name = $this->supportedText($candidate['name'] ?? null, $sourceText, 255);
            $evidence = $this->evidence($candidate['evidence_text'] ?? null, $sourceText);
            if ($name === null || $evidence === null) {
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($stalls[$key])) {
                continue;
            }

            $stalls[$key] = [
                'matched_stall_id' => null,
                'name' => $name,
                'description' => $this->supportedText($candidate['description'] ?? null, $sourceText, 5000),
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
    private function foods(mixed $candidates, string $sourceText): array
    {
        if (! is_array($candidates)) {
            return [];
        }

        $foods = [];
        $amounts = $this->sourceAmounts($sourceText);
        foreach (array_slice($candidates, 0, self::MAX_FOODS_PER_STALL) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $name = $this->supportedText($candidate['name'] ?? null, $sourceText, 255);
            $evidence = $this->evidence($candidate['evidence_text'] ?? null, $sourceText);
            if ($name === null || $evidence === null || isset($foods[mb_strtolower($name)])) {
                continue;
            }

            $display = $this->supportedPriceDisplay($candidate['price_display'] ?? null, $sourceText);
            $minimum = $this->supportedPrice($candidate['price_min'] ?? null, $amounts);
            $maximum = $this->supportedPrice($candidate['price_max'] ?? null, $amounts);
            $mustTry = ($candidate['is_must_try'] ?? false) === true
                && preg_match('/\b(?:must[- ]?try|recommended|highly recommend|worth visiting)\b/i', $evidence) === 1;

            $foods[mb_strtolower($name)] = [
                'matched_food_id' => null,
                'name' => $name,
                'category' => $this->supportedText($candidate['category'] ?? null, $sourceText, 255),
                'description' => $this->supportedText($candidate['description'] ?? null, $sourceText, 5000),
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
            return $graph['stalls'][0]['foods'] !== [];
        }

        return $graph['operating_days'] !== [] || $graph['stalls'] !== [];
    }

    /** @param array{market: array<string, mixed>, operating_days: list<array<string, mixed>>, stalls: list<array<string, mixed>>} $graph */
    private function replaceGraph(CatalogImportProposal $proposal, array $graph, string $inputHash, string $model): CatalogImportProposal
    {
        return DB::transaction(function () use ($proposal, $graph, $inputHash, $model): CatalogImportProposal {
            $lockedProposal = CatalogImportProposal::query()->lockForUpdate()->findOrFail($proposal->id);
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
            ]);

            return $this->detail($lockedProposal);
        }, 3);
    }

    private function recordFailure(
        CatalogImportProposal $proposal,
        string $failureCode,
        bool $hadCompletedSuggestions,
    ): CatalogSuggestionGenerationResult {
        $saved = DB::transaction(function () use ($proposal, $failureCode, $hadCompletedSuggestions): CatalogImportProposal {
            $lockedProposal = CatalogImportProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $hasGraph = $lockedProposal->proposalMarket()->exists();

            $lockedProposal->update($hadCompletedSuggestions && $hasGraph
                ? [
                    'extraction_status' => CatalogImportProposal::EXTRACTION_COMPLETED,
                    'extraction_failure_code' => null,
                ]
                : [
                    'extraction_status' => CatalogImportProposal::EXTRACTION_FAILED,
                    'extraction_failure_code' => $failureCode,
                ]);

            return $this->detail($lockedProposal);
        }, 3);

        return new CatalogSuggestionGenerationResult($saved, false, $hadCompletedSuggestions && $saved->proposalMarket !== null);
    }

    private function evidence(mixed $value, string $sourceText): ?string
    {
        $evidence = $this->cleanText($value, 1000);

        return $evidence !== null && str_contains($sourceText, $evidence) ? $evidence : null;
    }

    private function supportedText(mixed $value, string $sourceText, int $limit): ?string
    {
        $value = $this->cleanText($value, $limit);

        return $value !== null && str_contains($sourceText, $value) ? $value : null;
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

        return str_contains($sourceText, $value) ? $value : false;
    }

    /** @return list<float> */
    private function sourceAmounts(string $sourceText): array
    {
        preg_match_all('/\b(?:RM|MYR)\s*([0-9]+(?:\.[0-9]{1,2})?)/i', $sourceText, $matches);

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

        return str_contains($sourceText, $display) ? $display : null;
    }
}
