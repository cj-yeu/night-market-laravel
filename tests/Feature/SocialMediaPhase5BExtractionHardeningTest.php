<?php

namespace Tests\Feature;

use App\Contracts\CatalogSuggestionProvider;
use App\Exceptions\CatalogSuggestionException;
use App\Models\CatalogImportProposal;
use App\Models\CatalogImportProposalFood;
use App\Models\CatalogImportProposalMarket;
use App\Models\CatalogImportProposalOperatingDay;
use App\Models\CatalogImportProposalStall;
use App\Models\NightMarket;
use App\Models\SocialMediaSource;
use App\Models\Stall;
use App\Models\User;
use App\Services\CatalogSuggestionExtractionService;
use App\Support\CatalogSuggestionInput;
use App\Support\CatalogSuggestionResult;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class SocialMediaPhase5BExtractionHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Phase5BCatalogSuggestionProviderFake $provider;

    private int $sourceSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        config([
            'services.gemini.api_key' => 'testing-gemini-key',
            'services.gemini.model' => 'gemini-3.5-flash',
        ]);

        $this->provider = new Phase5BCatalogSuggestionProviderFake;
        $this->app->instance(CatalogSuggestionProvider::class, $this->provider);
    }

    public function test_completed_matching_input_skips_the_provider(): void
    {
        $proposal = $this->proposalFor($this->sourceFor($this->positiveSourceText()));
        $service = $this->service();
        $inputHash = $service->currentInputHash($proposal);
        $this->seedGraph($proposal);
        $proposal->forceFill([
            'extraction_status' => CatalogImportProposal::EXTRACTION_COMPLETED,
            'extraction_model' => 'gemini-3.5-flash',
            'extraction_input_hash' => $inputHash,
            'extracted_at' => now(),
        ])->save();

        $result = $service->generate($proposal);

        $this->assertTrue($result->wasSkipped);
        $this->assertFalse($result->wasAlreadyProcessing);
        $this->assertSame(0, $this->provider->calls);
        Http::assertNothingSent();
    }

    public function test_active_matching_attempt_prevents_a_second_provider_call(): void
    {
        $proposal = $this->proposalFor($this->sourceFor($this->positiveSourceText()));
        $service = $this->service();
        $inputHash = $service->currentInputHash($proposal);
        $attemptToken = (string) Str::uuid();
        $proposal->forceFill([
            'extraction_status' => CatalogImportProposal::EXTRACTION_PROCESSING,
            'extraction_model' => 'gemini-3.5-flash',
            'extraction_input_hash' => $inputHash,
            'extraction_attempt_token' => $attemptToken,
            'extraction_attempt_started_at' => now()->subMinute(),
        ])->save();

        $result = $service->generate($proposal);

        $this->assertTrue($result->wasSkipped);
        $this->assertTrue($result->wasAlreadyProcessing);
        $this->assertSame($attemptToken, $proposal->fresh()->extraction_attempt_token);
        $this->assertSame(0, $this->provider->calls);
        Http::assertNothingSent();
    }

    public function test_stale_attempt_is_reclaimed_and_completed_by_one_provider_call(): void
    {
        $proposal = $this->proposalFor($this->sourceFor($this->positiveSourceText()));
        $service = $this->service();
        $oldToken = (string) Str::uuid();
        $proposal->forceFill([
            'extraction_status' => CatalogImportProposal::EXTRACTION_PROCESSING,
            'extraction_model' => 'gemini-3.5-flash',
            'extraction_input_hash' => $service->currentInputHash($proposal),
            'extraction_attempt_token' => $oldToken,
            'extraction_attempt_started_at' => now()->subMinutes(6),
        ])->save();
        $this->provider->respondWith($this->positivePayload());

        $result = $service->generate($proposal);

        $proposal->refresh();
        $this->assertFalse($result->wasSkipped);
        $this->assertSame(1, $this->provider->calls);
        $this->assertSame(CatalogImportProposal::EXTRACTION_COMPLETED, $proposal->extraction_status);
        $this->assertNull($proposal->extraction_attempt_token);
        $this->assertNull($proposal->extraction_attempt_started_at);
        $this->assertNotNull($proposal->proposalMarket);
        Http::assertNothingSent();
    }

    public function test_obsolete_success_cannot_overwrite_a_newer_attempt_token_or_state(): void
    {
        $proposal = $this->proposalFor($this->sourceFor($this->positiveSourceText()));
        $newerToken = (string) Str::uuid();
        $this->provider->respondUsing(function () use ($proposal, $newerToken): CatalogSuggestionResult {
            CatalogImportProposal::query()->whereKey($proposal->id)->update([
                'extraction_status' => CatalogImportProposal::EXTRACTION_PROCESSING,
                'extraction_attempt_token' => $newerToken,
                'extraction_attempt_started_at' => now(),
            ]);

            return new CatalogSuggestionResult($this->positivePayload());
        });

        $result = $this->service()->generate($proposal);

        $proposal->refresh();
        $this->assertTrue($result->wasSkipped);
        $this->assertSame(1, $this->provider->calls);
        $this->assertSame(CatalogImportProposal::EXTRACTION_PROCESSING, $proposal->extraction_status);
        $this->assertSame($newerToken, $proposal->extraction_attempt_token);
        $this->assertFalse($proposal->proposalMarket()->exists());
        Http::assertNothingSent();
    }

    public function test_obsolete_failure_cannot_overwrite_a_newer_success(): void
    {
        $proposal = $this->proposalFor($this->sourceFor($this->positiveSourceText()));
        $newerHash = str_repeat('f', 64);
        $this->provider->respondUsing(function () use ($proposal, $newerHash): CatalogSuggestionResult {
            $this->seedGraph($proposal, 'Newer Successful Market');
            CatalogImportProposal::query()->whereKey($proposal->id)->update([
                'extraction_status' => CatalogImportProposal::EXTRACTION_COMPLETED,
                'extraction_failure_code' => null,
                'extraction_model' => 'newer-model',
                'extraction_input_hash' => $newerHash,
                'extracted_at' => now(),
                'extraction_attempt_token' => null,
                'extraction_attempt_started_at' => null,
            ]);

            throw new CatalogSuggestionException(CatalogSuggestionExtractionService::FAILURE_TIMEOUT);
        });

        $result = $this->service()->generate($proposal);

        $proposal->refresh();
        $this->assertTrue($result->wasSkipped);
        $this->assertSame(CatalogImportProposal::EXTRACTION_COMPLETED, $proposal->extraction_status);
        $this->assertSame($newerHash, $proposal->extraction_input_hash);
        $this->assertSame('newer-model', $proposal->extraction_model);
        $this->assertNull($proposal->extraction_failure_code);
        $this->assertSame('Newer Successful Market', $proposal->proposalMarket()->value('name'));
        Http::assertNothingSent();
    }

    public function test_failed_replacement_preserves_the_complete_previous_graph_and_hash(): void
    {
        $source = $this->sourceFor($this->positiveSourceText());
        $proposal = $this->proposalFor($source);
        $service = $this->service();
        $previousHash = $service->currentInputHash($proposal);
        $graph = $this->seedGraph($proposal, 'Reviewed Previous Market');
        $previousExtractedAt = now()->subHour()->startOfSecond();
        $proposal->forceFill([
            'extraction_status' => CatalogImportProposal::EXTRACTION_COMPLETED,
            'extraction_model' => 'gemini-3.5-flash',
            'extraction_input_hash' => $previousHash,
            'extracted_at' => $previousExtractedAt,
        ])->save();
        $source->update(['description_excerpt' => $this->positiveSourceText().' Changed after review.']);
        $this->provider->respondUsing(function (): CatalogSuggestionResult {
            throw new CatalogSuggestionException(CatalogSuggestionExtractionService::FAILURE_PROVIDER_UNAVAILABLE);
        });

        $result = $service->generate($proposal);

        $proposal->refresh();
        $this->assertTrue($result->retainedPreviousSuggestions);
        $this->assertSame(CatalogImportProposal::EXTRACTION_COMPLETED, $proposal->extraction_status);
        $this->assertSame($previousHash, $proposal->extraction_input_hash);
        $this->assertSame('gemini-3.5-flash', $proposal->extraction_model);
        $this->assertTrue($previousExtractedAt->equalTo($proposal->extracted_at));
        $this->assertSame($graph['market']->id, $proposal->proposalMarket()->value('id'));
        $this->assertSame('Reviewed Previous Market', $proposal->proposalMarket()->value('name'));
        $this->assertSame(CatalogSuggestionExtractionService::FAILURE_PROVIDER_UNAVAILABLE, $proposal->extraction_failure_code);
        Http::assertNothingSent();
    }

    public function test_blank_or_null_model_records_safe_configuration_failure_without_provider_or_http(): void
    {
        foreach ([null, '   '] as $model) {
            config(['services.gemini.model' => $model]);
            $proposal = $this->proposalFor($this->sourceFor($this->positiveSourceText()));

            $result = $this->service()->generate($proposal);

            $proposal->refresh();
            $this->assertFalse($result->wasSkipped);
            $this->assertSame(CatalogImportProposal::EXTRACTION_FAILED, $proposal->extraction_status);
            $this->assertSame(CatalogSuggestionExtractionService::FAILURE_CONFIG_MISSING, $proposal->extraction_failure_code);
            $this->assertNull($proposal->extraction_attempt_token);
        }

        $this->assertSame(0, $this->provider->calls);
        Http::assertNothingSent();
    }

    public function test_configuration_failure_preserves_an_existing_complete_graph(): void
    {
        $proposal = $this->proposalFor($this->sourceFor($this->positiveSourceText()));
        $graph = $this->seedGraph($proposal, 'Reviewed Configuration Graph');
        $proposal->forceFill([
            'extraction_status' => CatalogImportProposal::EXTRACTION_COMPLETED,
            'extraction_model' => 'previous-model',
            'extraction_input_hash' => str_repeat('a', 64),
            'extracted_at' => now()->subHour(),
        ])->save();
        config(['services.gemini.model' => null]);

        $result = $this->service()->generate($proposal);

        $proposal->refresh();
        $this->assertTrue($result->retainedPreviousSuggestions);
        $this->assertSame(CatalogImportProposal::EXTRACTION_COMPLETED, $proposal->extraction_status);
        $this->assertSame(CatalogSuggestionExtractionService::FAILURE_CONFIG_MISSING, $proposal->extraction_failure_code);
        $this->assertSame($graph['market']->id, $proposal->proposalMarket()->value('id'));
        $this->assertSame(0, $this->provider->calls);
        Http::assertNothingSent();
    }

    public function test_creator_and_authoritative_target_context_changes_alter_the_input_hash(): void
    {
        $source = $this->sourceFor($this->positiveSourceText(), 'Creator One');
        $newMarketProposal = $this->proposalFor($source);
        $service = $this->service();
        $creatorHash = $service->currentInputHash($newMarketProposal);

        $source->update(['creator_name' => 'Creator Two']);
        $this->assertNotSame($creatorHash, $service->currentInputHash($newMarketProposal));

        $market = NightMarket::factory()->create([
            'name' => 'Authoritative Market',
            'address' => '1 Original Road',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
        ]);
        $marketProposal = $this->proposalFor(
            $this->sourceFor($this->positiveSourceText()),
            CatalogImportProposal::TARGET_EXISTING_MARKET,
            $market,
        );
        $targetHash = $service->currentInputHash($marketProposal);

        $market->update(['address' => '2 Corrected Road']);
        $this->assertNotSame($targetHash, $service->currentInputHash($marketProposal));
        $this->assertSame(0, $this->provider->calls);
        Http::assertNothingSent();
    }

    public function test_existing_stall_accepts_only_the_single_authoritative_candidate_and_its_foods(): void
    {
        [$market, $stall] = $this->existingStallFixture('Locked Stall');
        $source = $this->sourceFor(
            'Locked Stall serves Curry Mee. Curry Mee RM8 is recommended. '
            .'Other Stall serves Burger. Burger RM12 is a must-try.',
        );
        $proposal = $this->proposalFor(
            $source,
            CatalogImportProposal::TARGET_EXISTING_STALL,
            $market,
            $stall,
        );
        $this->provider->respondWith($this->existingStallPayload('Locked Stall', true));

        $result = $this->service()->generate($proposal);

        $proposalStall = $proposal->proposalMarket()->firstOrFail()->stalls()->firstOrFail();
        $this->assertFalse($result->wasSkipped);
        $this->assertSame($stall->id, $proposalStall->matched_stall_id);
        $this->assertSame(['Curry Mee'], $proposalStall->foods()->pluck('name')->all());
        $this->assertDatabaseMissing('catalog_import_proposal_foods', ['name' => 'Burger']);
        Http::assertNothingSent();
    }

    public function test_existing_stall_zero_or_ambiguous_authoritative_candidates_fail_safely(): void
    {
        foreach (['zero', 'ambiguous'] as $case) {
            [$market, $stall] = $this->existingStallFixture('Locked Stall '.$case);
            $source = $this->sourceFor(
                "Locked Stall {$case} serves Curry Mee. Curry Mee RM8 is recommended. Other Stall serves Burger.",
            );
            $proposal = $this->proposalFor(
                $source,
                CatalogImportProposal::TARGET_EXISTING_STALL,
                $market,
                $stall,
            );
            $payload = $case === 'zero'
                ? $this->existingStallPayload('Other Stall', false)
                : $this->ambiguousExistingStallPayload($stall->name);
            $this->provider->respondWith($payload);

            $this->service()->generate($proposal);

            $proposal->refresh();
            $this->assertSame(CatalogImportProposal::EXTRACTION_FAILED, $proposal->extraction_status);
            $this->assertSame(CatalogSuggestionExtractionService::FAILURE_NO_SUPPORTED_SUGGESTIONS, $proposal->extraction_failure_code);
            $this->assertFalse($proposal->proposalMarket()->exists());
        }

        Http::assertNothingSent();
    }

    public function test_existing_stall_discards_a_food_misnested_from_another_stall(): void
    {
        [$market, $stall] = $this->existingStallFixture('Locked Stall');
        $proposal = $this->proposalFor(
            $this->sourceFor(
                'Locked Stall serves Curry Mee. Curry Mee RM8 is recommended. '
                .'Other Stall serves Burger. Burger RM12 is a must-try.',
            ),
            CatalogImportProposal::TARGET_EXISTING_STALL,
            $market,
            $stall,
        );
        $payload = $this->existingStallPayload('Locked Stall', false);
        $payload['stalls'][0]['foods'][] = [
            'name' => 'Burger', 'category' => null, 'description' => null,
            'price_display' => 'RM12', 'price_min' => 12, 'price_max' => 12,
            'is_must_try' => true, 'evidence_text' => 'Other Stall serves Burger. Burger RM12 is a must-try', 'confidence' => 90,
        ];
        $this->provider->respondWith($payload);

        $this->service()->generate($proposal);

        $foods = $proposal->proposalMarket()->firstOrFail()->stalls()->firstOrFail()->foods()->pluck('name')->all();
        $this->assertSame(['Curry Mee'], $foods);
        $this->assertDatabaseMissing('catalog_import_proposal_foods', ['name' => 'Burger']);
        Http::assertNothingSent();
    }

    public function test_food_cannot_borrow_another_food_price_or_must_try_wording(): void
    {
        $sourceText = 'Night Market One in Selangor. Stall A serves Food Alpha and Food Beta. '
            .'Food Alpha is available. Food Beta RM20 is a must-try recommendation.';
        $proposal = $this->proposalFor($this->sourceFor($sourceText));
        $payload = $this->minimalNewMarketPayload('Night Market One in Selangor');
        $payload['stalls'] = [[
            'name' => 'Stall A',
            'description' => null,
            'evidence_text' => 'Stall A serves Food Alpha and Food Beta',
            'confidence' => 90,
            'foods' => [
                [
                    'name' => 'Food Alpha', 'category' => null, 'description' => null,
                    'price_display' => 'RM20', 'price_min' => 20, 'price_max' => 20,
                    'is_must_try' => true, 'evidence_text' => 'Food Alpha is available', 'confidence' => 90,
                ],
                [
                    'name' => 'Food Beta', 'category' => null, 'description' => null,
                    'price_display' => 'RM20', 'price_min' => 20, 'price_max' => 20,
                    'is_must_try' => true, 'evidence_text' => 'Food Beta RM20 is a must-try recommendation', 'confidence' => 90,
                ],
            ],
        ]];
        $this->provider->respondWith($payload);

        $this->service()->generate($proposal);

        $foods = $proposal->proposalMarket()->firstOrFail()->stalls()->firstOrFail()->foods()->get()->keyBy('name');
        $this->assertNull($foods->get('Food Alpha')?->price_display);
        $this->assertNull($foods->get('Food Alpha')?->price_min);
        $this->assertNull($foods->get('Food Alpha')?->price_max);
        $this->assertFalse($foods->get('Food Alpha')->is_must_try);
        $this->assertSame('RM20', $foods->get('Food Beta')?->price_display);
        $this->assertSame('20.00', $foods->get('Food Beta')?->price_min);
        $this->assertTrue($foods->get('Food Beta')->is_must_try);
        Http::assertNothingSent();
    }

    public function test_market_operating_day_cannot_borrow_another_market_evidence(): void
    {
        $sourceText = 'Night Market One in Selangor. Night Market Two operates Sunday 18:00 to 23:00.';
        $proposal = $this->proposalFor($this->sourceFor($sourceText));
        $payload = $this->minimalNewMarketPayload('Night Market One in Selangor');
        $payload['market']['operating_days'] = [[
            'day_of_week' => 'Sunday',
            'opening_time' => '18:00',
            'closing_time' => '23:00',
            'evidence_text' => 'Night Market Two operates Sunday 18:00 to 23:00',
            'confidence' => 90,
        ]];
        $this->provider->respondWith($payload);

        $this->service()->generate($proposal);

        $market = $proposal->proposalMarket()->firstOrFail();
        $this->assertSame('Night Market One', $market->name);
        $this->assertCount(0, $market->operatingDays);
        Http::assertNothingSent();
    }

    public function test_time_and_price_evidence_require_exact_numeric_boundaries(): void
    {
        $sourceText = 'Night Market One in Selangor. '
            .'Night Market One Saturday 118:00 to 123:00. '
            .'Stall A serves Nasi Lemak. Nasi Lemak costs RM5.999.';
        $proposal = $this->proposalFor($this->sourceFor($sourceText));
        $payload = $this->minimalNewMarketPayload('Night Market One in Selangor');
        $payload['market']['operating_days'] = [[
            'day_of_week' => 'Saturday',
            'opening_time' => '18:00',
            'closing_time' => '23:00',
            'evidence_text' => 'Night Market One Saturday 118:00 to 123:00',
            'confidence' => 90,
        ]];
        $payload['stalls'] = [[
            'name' => 'Stall A',
            'description' => null,
            'evidence_text' => 'Stall A serves Nasi Lemak',
            'confidence' => 90,
            'foods' => [[
                'name' => 'Nasi Lemak', 'category' => null, 'description' => null,
                'price_display' => null, 'price_min' => 5.99, 'price_max' => 5.99,
                'is_must_try' => false, 'evidence_text' => 'Nasi Lemak costs RM5.999', 'confidence' => 90,
            ]],
        ]];
        $this->provider->respondWith($payload);

        $this->service()->generate($proposal);

        $market = $proposal->proposalMarket()->firstOrFail();
        $food = $market->stalls()->firstOrFail()->foods()->firstOrFail();
        $this->assertCount(0, $market->operatingDays);
        $this->assertNull($food->price_min);
        $this->assertNull($food->price_max);
        Http::assertNothingSent();
    }

    public function test_new_market_requires_literal_selangor_in_its_own_evidence(): void
    {
        $proposal = $this->proposalFor($this->sourceFor('Night Market One opens nightly.'));
        $payload = $this->minimalNewMarketPayload('Night Market One opens nightly');
        $this->provider->respondWith($payload);

        $this->service()->generate($proposal);

        $proposal->refresh();
        $this->assertSame(CatalogImportProposal::EXTRACTION_FAILED, $proposal->extraction_status);
        $this->assertSame(CatalogSuggestionExtractionService::FAILURE_UNSUPPORTED_EVIDENCE, $proposal->extraction_failure_code);
        $this->assertFalse($proposal->proposalMarket()->exists());
        Http::assertNothingSent();
    }

    public function test_correctly_colocated_market_day_food_price_and_recommendation_evidence_passes(): void
    {
        $proposal = $this->proposalFor($this->sourceFor($this->positiveSourceText()));
        $this->provider->respondWith($this->positivePayload());

        $this->service()->generate($proposal);

        $market = $proposal->proposalMarket()->firstOrFail();
        $day = $market->operatingDays()->firstOrFail();
        $food = $market->stalls()->firstOrFail()->foods()->firstOrFail();
        $this->assertSame('Selangor', $market->state);
        $this->assertSame('Saturday', $day->day_of_week);
        $this->assertSame('18:00', $day->opening_time?->format('H:i'));
        $this->assertSame('23:00', $day->closing_time?->format('H:i'));
        $this->assertSame('RM5', $food->price_display);
        $this->assertSame('5.00', $food->price_max);
        $this->assertTrue($food->is_must_try);
        Http::assertNothingSent();
    }

    public function test_stale_bound_proposal_cannot_update_or_delete_after_terminal_transition(): void
    {
        foreach ([
            CatalogImportProposal::STATUS_SUBMITTED,
            CatalogImportProposal::STATUS_REJECTED,
            CatalogImportProposal::STATUS_IMPORTED,
            CatalogImportProposal::STATUS_FAILED,
        ] as $status) {
            $proposal = $this->proposalFor($this->sourceFor($this->positiveSourceText()));
            $graph = $this->seedGraph($proposal);
            $staleBoundProposal = $proposal->fresh();
            CatalogImportProposal::query()->whereKey($proposal->id)->update(['status' => $status]);

            $this->assertProposalValidationFailure(fn () => $this->service()->updateFood(
                $staleBoundProposal,
                $graph['food'],
                ['name' => 'Forbidden Edit'],
            ));
            $this->assertProposalValidationFailure(fn () => $this->service()->deleteFood(
                $staleBoundProposal,
                $graph['food'],
            ));
            $this->assertDatabaseHas('catalog_import_proposal_foods', [
                'id' => $graph['food']->id,
                'name' => 'Existing Food',
            ]);
        }

        Http::assertNothingSent();
    }

    public function test_cross_proposal_children_cannot_be_updated_or_deleted(): void
    {
        $proposal = $this->proposalFor($this->sourceFor($this->positiveSourceText()));
        $this->seedGraph($proposal, 'First Proposal Market');
        $otherProposal = $this->proposalFor($this->sourceFor($this->positiveSourceText()));
        $other = $this->seedGraph($otherProposal, 'Other Proposal Market');
        $service = $this->service();

        $operations = [
            fn () => $service->updateMarket($proposal, $other['market'], ['name' => 'Forged']),
            fn () => $service->updateOperatingDay($proposal, $other['day'], ['day_of_week' => 'Monday']),
            fn () => $service->updateStall($proposal, $other['stall'], ['name' => 'Forged']),
            fn () => $service->updateFood($proposal, $other['food'], ['name' => 'Forged']),
            fn () => $service->deleteOperatingDay($proposal, $other['day']),
            fn () => $service->deleteStall($proposal, $other['stall']),
            fn () => $service->deleteFood($proposal, $other['food']),
        ];

        foreach ($operations as $operation) {
            $this->assertNotFound($operation);
        }

        $this->assertDatabaseHas('catalog_import_proposal_markets', ['id' => $other['market']->id]);
        $this->assertDatabaseHas('catalog_import_proposal_operating_days', ['id' => $other['day']->id]);
        $this->assertDatabaseHas('catalog_import_proposal_stalls', ['id' => $other['stall']->id]);
        $this->assertDatabaseHas('catalog_import_proposal_foods', ['id' => $other['food']->id]);
        Http::assertNothingSent();
    }

    private function service(): CatalogSuggestionExtractionService
    {
        return $this->app->make(CatalogSuggestionExtractionService::class);
    }

    private function sourceFor(string $description, ?string $creator = 'Public Channel'): SocialMediaSource
    {
        $this->sourceSequence++;
        $videoId = substr(hash('sha256', $description.'|'.$this->sourceSequence), 0, 11);
        $url = 'https://www.youtube.com/watch?v='.$videoId;

        return SocialMediaSource::query()->create([
            'platform' => SocialMediaSource::PLATFORM_YOUTUBE,
            'canonical_url' => $url,
            'url_fingerprint' => hash('sha256', $url),
            'external_content_id' => $videoId,
            'title' => 'Night Market One',
            'description_excerpt' => $description,
            'creator_name' => $creator,
            'metadata_status' => SocialMediaSource::METADATA_FETCHED,
            'metadata_provider' => 'youtube_data_api',
            'metadata_fetched_at' => now(),
        ]);
    }

    private function proposalFor(
        SocialMediaSource $source,
        string $targetType = CatalogImportProposal::TARGET_NEW_MARKET,
        ?NightMarket $market = null,
        ?Stall $stall = null,
    ): CatalogImportProposal {
        return CatalogImportProposal::query()->create([
            'social_media_source_id' => $source->id,
            'target_type' => $targetType,
            'matched_night_market_id' => $targetType === CatalogImportProposal::TARGET_NEW_MARKET
                ? null
                : $market?->id,
            'matched_stall_id' => $targetType === CatalogImportProposal::TARGET_EXISTING_STALL
                ? $stall?->id
                : null,
            'status' => CatalogImportProposal::STATUS_DRAFT,
            'revision' => 1,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * @return array{
     *     market: CatalogImportProposalMarket,
     *     day: CatalogImportProposalOperatingDay,
     *     stall: CatalogImportProposalStall,
     *     food: CatalogImportProposalFood
     * }
     */
    private function seedGraph(CatalogImportProposal $proposal, string $marketName = 'Existing Proposal Market'): array
    {
        $market = CatalogImportProposalMarket::query()->create([
            'catalog_import_proposal_id' => $proposal->id,
            'name' => $marketName,
            'state' => 'Selangor',
            'evidence_text' => $marketName.' in Selangor',
        ]);
        $day = CatalogImportProposalOperatingDay::query()->create([
            'catalog_import_proposal_market_id' => $market->id,
            'day_of_week' => 'Saturday',
            'opening_time' => '18:00',
            'closing_time' => '23:00',
            'evidence_text' => $marketName.' Saturday 18:00 to 23:00',
        ]);
        $stall = CatalogImportProposalStall::query()->create([
            'catalog_import_proposal_market_id' => $market->id,
            'name' => 'Existing Stall',
            'halal_status' => Stall::HALAL_UNKNOWN,
            'evidence_text' => 'Existing Stall serves Existing Food',
            'display_order' => 0,
        ]);
        $food = CatalogImportProposalFood::query()->create([
            'catalog_import_proposal_stall_id' => $stall->id,
            'name' => 'Existing Food',
            'price_display' => 'RM5',
            'price_min' => 5,
            'price_max' => 5,
            'is_must_try' => false,
            'evidence_text' => 'Existing Food RM5',
            'display_order' => 0,
        ]);

        return compact('market', 'day', 'stall', 'food');
    }

    /** @return array{0: NightMarket, 1: Stall} */
    private function existingStallFixture(string $stallName): array
    {
        $market = NightMarket::factory()->create([
            'name' => 'Locked Parent Market '.$this->sourceSequence,
            'state' => 'Selangor',
            'status' => NightMarket::STATUS_ACTIVE,
        ]);
        $stall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => $stallName,
            'status' => Stall::STATUS_ACTIVE,
        ]);

        return [$market, $stall];
    }

    private function positiveSourceText(): string
    {
        return 'Night Market One in Selangor. '
            .'Night Market One operates Saturday 18:00 to 23:00. '
            .'Stall A serves Nasi Lemak. '
            .'Nasi Lemak Malay RM5 is a must-try recommendation.';
    }

    /** @return array<string, mixed> */
    private function positivePayload(): array
    {
        $payload = $this->minimalNewMarketPayload('Night Market One in Selangor');
        $payload['market']['operating_days'] = [[
            'day_of_week' => 'Saturday',
            'opening_time' => '18:00',
            'closing_time' => '23:00',
            'evidence_text' => 'Night Market One operates Saturday 18:00 to 23:00',
            'confidence' => 90,
        ]];
        $payload['stalls'] = [[
            'name' => 'Stall A',
            'description' => null,
            'evidence_text' => 'Stall A serves Nasi Lemak',
            'confidence' => 90,
            'foods' => [[
                'name' => 'Nasi Lemak',
                'category' => 'Malay',
                'description' => null,
                'price_display' => 'RM5',
                'price_min' => 5,
                'price_max' => 5,
                'is_must_try' => true,
                'evidence_text' => 'Nasi Lemak Malay RM5 is a must-try recommendation',
                'confidence' => 90,
            ]],
        ]];

        return $payload;
    }

    /** @return array<string, mixed> */
    private function minimalNewMarketPayload(string $marketEvidence): array
    {
        return [
            'market' => [
                'name' => 'Night Market One',
                'address' => null,
                'city' => null,
                'state' => 'Selangor',
                'description' => null,
                'evidence_text' => $marketEvidence,
                'confidence' => 90,
                'operating_days' => [],
            ],
            'stalls' => [],
            'warnings' => [],
            'insufficient_data' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function existingStallPayload(string $stallName, bool $includeOtherStall): array
    {
        $stalls = [[
            'name' => $stallName,
            'description' => null,
            'evidence_text' => $stallName.' serves Curry Mee',
            'confidence' => 90,
            'foods' => [[
                'name' => 'Curry Mee', 'category' => null, 'description' => null,
                'price_display' => 'RM8', 'price_min' => 8, 'price_max' => 8,
                'is_must_try' => true, 'evidence_text' => 'Curry Mee RM8 is recommended', 'confidence' => 90,
            ]],
        ]];

        if ($includeOtherStall) {
            $stalls[] = [
                'name' => 'Other Stall',
                'description' => null,
                'evidence_text' => 'Other Stall serves Burger',
                'confidence' => 90,
                'foods' => [[
                    'name' => 'Burger', 'category' => null, 'description' => null,
                    'price_display' => 'RM12', 'price_min' => 12, 'price_max' => 12,
                    'is_must_try' => true, 'evidence_text' => 'Burger RM12 is a must-try', 'confidence' => 90,
                ]],
            ];
        }

        return [
            'market' => ['operating_days' => []],
            'stalls' => $stalls,
            'warnings' => [],
            'insufficient_data' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function ambiguousExistingStallPayload(string $stallName): array
    {
        $payload = $this->existingStallPayload($stallName, false);
        $payload['stalls'][] = $payload['stalls'][0];

        return $payload;
    }

    private function assertProposalValidationFailure(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the stale proposal mutation to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Only draft proposals can be edited.',
                $exception->errors()['proposal'][0] ?? null,
            );
        }
    }

    private function assertNotFound(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a child from another proposal to be rejected.');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }
    }
}

final class Phase5BCatalogSuggestionProviderFake implements CatalogSuggestionProvider
{
    public int $calls = 0;

    /** @var list<CatalogSuggestionInput> */
    public array $inputs = [];

    private ?Closure $handler = null;

    /** @param array<string, mixed> $payload */
    public function respondWith(array $payload): void
    {
        $this->respondUsing(
            static fn (CatalogSuggestionInput $_input, int $_call): CatalogSuggestionResult => new CatalogSuggestionResult($payload),
        );
    }

    public function respondUsing(Closure $handler): void
    {
        $this->handler = $handler;
    }

    public function extract(CatalogSuggestionInput $input): CatalogSuggestionResult
    {
        $this->calls++;
        $this->inputs[] = $input;

        if (! $this->handler) {
            throw new LogicException('The Phase 5B provider fake received an unexpected call.');
        }

        return ($this->handler)($input, $this->calls);
    }
}
