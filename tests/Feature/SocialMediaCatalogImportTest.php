<?php

namespace Tests\Feature;

use App\Models\CatalogImportProposal;
use App\Models\CatalogImportProposalFood;
use App\Models\CatalogImportProposalMarket;
use App\Models\CatalogImportProposalOperatingDay;
use App\Models\CatalogImportProposalStall;
use App\Models\CatalogSocialMediaSourceLink;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\SocialMediaSource;
use App\Models\Stall;
use App\Models\User;
use App\Services\CatalogSuggestionExtractionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SocialMediaCatalogImportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    public function test_guests_clients_and_admin_roles_follow_the_review_authorization_matrix(): void
    {
        $proposal = $this->proposalWithGraph(CatalogImportProposal::TARGET_NEW_MARKET);
        $routes = [
            'submit' => route('admin.social-media.automation.proposals.submit', $proposal),
            'reject' => route('admin.social-media.automation.proposals.reject', $proposal),
            'import' => route('admin.social-media.automation.proposals.approve-import', $proposal),
        ];

        foreach ($routes as $route) {
            $this->post($route)->assertRedirect(route('login'));
        }

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        foreach ($routes as $route) {
            $this->actingAs($client)->post($route)->assertForbidden();
        }

        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->actingAs($superAdmin)
            ->post($routes['submit'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(CatalogImportProposal::STATUS_SUBMITTED, $proposal->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_valid_draft_submits_but_incomplete_or_stale_drafts_do_not(): void
    {
        $valid = $this->proposalWithGraph(CatalogImportProposal::TARGET_NEW_MARKET);
        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.submit', $valid))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(CatalogImportProposal::STATUS_SUBMITTED, $valid->fresh()->status);
        $this->assertNotNull($valid->fresh()->submitted_at);
        $this->assertSame($valid->fresh()->extraction_input_hash, $valid->fresh()->review_input_hash);
        $snapshotKeys = array_keys($valid->fresh()->review_metadata_snapshot);
        sort($snapshotKeys);
        $expectedSnapshotKeys = [
            'external_content_id',
            'title',
            'description_excerpt',
            'creator_name',
            'thumbnail_url',
            'published_at',
        ];
        sort($expectedSnapshotKeys);
        $this->assertSame($expectedSnapshotKeys, $snapshotKeys);

        $incomplete = $this->proposalWithGraph(CatalogImportProposal::TARGET_NEW_MARKET, ['with_food' => false]);
        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.submit', $incomplete))
            ->assertSessionHasErrors('proposal');
        $this->assertSame(CatalogImportProposal::STATUS_DRAFT, $incomplete->fresh()->status);

        $stale = $this->proposalWithGraph(CatalogImportProposal::TARGET_NEW_MARKET);
        $stale->socialMediaSource->update(['title' => 'Changed metadata after extraction']);
        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.submit', $stale))
            ->assertSessionHasErrors('proposal');
        $this->assertSame(CatalogImportProposal::STATUS_DRAFT, $stale->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_submitted_snapshot_is_immutable_and_import_does_not_recompute_mutable_source_metadata(): void
    {
        $proposal = $this->submittedProposal(CatalogImportProposal::TARGET_NEW_MARKET);
        $snapshot = $proposal->review_metadata_snapshot;
        $reviewInputHash = $proposal->review_input_hash;

        $proposal->socialMediaSource->update([
            'title' => 'A later draft revision changed the shared title',
            'description_excerpt' => 'A later draft revision changed the shared description.',
            'creator_name' => 'Different Channel',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.ai-import.show', $proposal))
            ->assertOk()
            ->assertSee('Review metadata is frozen.')
            ->assertSee($snapshot['title'])
            ->assertDontSee('A later draft revision changed the shared title')
            ->assertDontSee('Different Channel');

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $proposal->refresh();
        $this->assertSame(CatalogImportProposal::STATUS_IMPORTED, $proposal->status);
        $this->assertSame($snapshot, $proposal->review_metadata_snapshot);
        $this->assertSame($reviewInputHash, $proposal->review_input_hash);
        Http::assertNothingSent();
    }

    public function test_stale_submission_error_is_visible_on_the_proposal_page(): void
    {
        $proposal = $this->proposalWithGraph(CatalogImportProposal::TARGET_NEW_MARKET);
        $proposal->socialMediaSource->update(['title' => 'Changed metadata after extraction']);

        $this->actingAs($this->admin)
            ->from(route('admin.social-media.automation.show', $proposal))
            ->followingRedirects()
            ->post(route('admin.social-media.automation.proposals.submit', $proposal))
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('Please correct the following:')
            ->assertSee('The source metadata or selected catalog target changed after suggestions were generated. Generate suggestions again before submitting.');

        $this->assertSame(CatalogImportProposal::STATUS_DRAFT, $proposal->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_import_rejects_a_tampered_submitted_review_hash_without_catalog_writes(): void
    {
        $proposal = $this->submittedProposal(CatalogImportProposal::TARGET_NEW_MARKET);
        $counts = [
            NightMarket::query()->count(),
            Stall::query()->count(),
            Food::query()->count(),
            CatalogSocialMediaSourceLink::query()->count(),
        ];
        $proposal->forceFill(['review_input_hash' => str_repeat('0', 64)])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertSessionHasErrors('proposal');

        $this->assertSame(CatalogImportProposal::STATUS_SUBMITTED, $proposal->fresh()->status);
        $this->assertSame($counts, [
            NightMarket::query()->count(),
            Stall::query()->count(),
            Food::query()->count(),
            CatalogSocialMediaSourceLink::query()->count(),
        ]);
        Http::assertNothingSent();
    }

    public function test_import_rejects_non_whitelisted_review_snapshot_data(): void
    {
        $proposal = $this->submittedProposal(CatalogImportProposal::TARGET_NEW_MARKET);
        $snapshot = $proposal->review_metadata_snapshot;
        $snapshot['unexpected_field'] = 'This field was never approved for the review snapshot.';
        $proposal->forceFill(['review_metadata_snapshot' => $snapshot])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertSessionHasErrors('proposal');

        $this->assertSame(CatalogImportProposal::STATUS_SUBMITTED, $proposal->fresh()->status);
        $this->assertDatabaseMissing('night_markets', ['name' => 'Draft Market']);
        $this->assertDatabaseCount('catalog_social_media_source_links', 0);
        Http::assertNothingSent();
    }

    public function test_submitted_proposals_are_read_only_and_can_be_rejected_only_with_a_note(): void
    {
        $proposal = $this->submittedProposal(CatalogImportProposal::TARGET_NEW_MARKET);
        $food = $proposal->proposalMarket->stalls->first()->foods->first();

        $this->actingAs($this->admin)
            ->patch(route('admin.social-media.automation.proposals.foods.update', [$proposal, $food]), ['name' => 'Forged change'])
            ->assertSessionHasErrors('proposal');
        $this->assertNotSame('Forged change', $food->fresh()->name);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.reject', $proposal), ['review_note' => '   '])
            ->assertSessionHasErrors('review_note');
        $this->assertSame(CatalogImportProposal::STATUS_SUBMITTED, $proposal->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.reject', $proposal), ['review_note' => 'Needs reliable operating hours.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $proposal->refresh();
        $this->assertSame(CatalogImportProposal::STATUS_REJECTED, $proposal->status);
        $this->assertSame($this->admin->id, $proposal->reviewed_by);
        $this->assertSame('Needs reliable operating hours.', $proposal->review_note);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertSessionHasErrors('proposal');
        Http::assertNothingSent();
    }

    public function test_existing_market_import_creates_only_inactive_stalls_foods_and_provenance(): void
    {
        $market = NightMarket::factory()->create(['name' => 'Locked Existing Market']);
        $market->operatingDays()->create(['day_of_week' => 'Friday', 'opening_time' => '18:00', 'closing_time' => '23:00']);
        $proposal = $this->submittedProposal(CatalogImportProposal::TARGET_EXISTING_MARKET, ['market' => $market]);
        $before = [$market->name, $market->operatingDays()->count(), Stall::query()->count(), Food::query()->count()];

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $proposal->refresh();
        $createdStall = Stall::query()->where('night_market_id', $market->id)->where('name', 'Draft Stall')->firstOrFail();
        $createdFood = Food::query()->where('stall_id', $createdStall->id)->where('name', 'Draft Food')->firstOrFail();
        $this->assertSame(CatalogImportProposal::STATUS_IMPORTED, $proposal->status);
        $this->assertSame($before[0], $market->fresh()->name);
        $this->assertSame($before[1], $market->operatingDays()->count());
        $this->assertSame(Stall::STATUS_INACTIVE, $createdStall->status);
        $this->assertSame(Food::STATUS_INACTIVE, $createdFood->status);
        $this->assertSame($before[2] + 1, Stall::query()->count());
        $this->assertSame($before[3] + 1, Food::query()->count());
        $this->assertDatabaseCount('catalog_social_media_source_links', 3);
        $this->assertDatabaseHas('catalog_social_media_source_links', ['catalog_import_proposal_id' => $proposal->id, 'night_market_id' => $market->id]);
        $this->assertDatabaseHas('catalog_social_media_source_links', ['catalog_import_proposal_id' => $proposal->id, 'stall_id' => $createdStall->id]);
        $this->assertDatabaseHas('catalog_social_media_source_links', ['catalog_import_proposal_id' => $proposal->id, 'food_id' => $createdFood->id]);
        Http::assertNothingSent();
    }

    public function test_existing_market_target_must_remain_active_and_in_selangor(): void
    {
        $market = NightMarket::factory()->create();
        $proposal = $this->submittedProposal(CatalogImportProposal::TARGET_EXISTING_MARKET, ['market' => $market]);
        $market->update(['status' => NightMarket::STATUS_INACTIVE]);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertSessionHasErrors('proposal');
        $this->assertSame(CatalogImportProposal::STATUS_SUBMITTED, $proposal->fresh()->status);
        $this->assertDatabaseCount('catalog_social_media_source_links', 0);
        Http::assertNothingSent();
    }

    public function test_existing_stall_import_creates_only_inactive_food_under_the_locked_stall(): void
    {
        $market = NightMarket::factory()->create(['name' => 'Parent Existing Market']);
        $stall = Stall::factory()->create(['night_market_id' => $market->id, 'name' => 'Locked Existing Stall']);
        $proposal = $this->submittedProposal(CatalogImportProposal::TARGET_EXISTING_STALL, ['market' => $market, 'stall' => $stall]);
        $beforeStalls = Stall::query()->count();

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $createdFood = Food::query()->where('stall_id', $stall->id)->where('name', 'Draft Food')->firstOrFail();
        $this->assertSame($beforeStalls, Stall::query()->count());
        $this->assertSame(Food::STATUS_INACTIVE, $createdFood->status);
        $this->assertSame(Stall::HALAL_UNKNOWN, $stall->fresh()->halal_status);
        $this->assertDatabaseCount('catalog_social_media_source_links', 3);
        $this->assertSame(CatalogImportProposal::STATUS_IMPORTED, $proposal->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_new_market_import_creates_a_complete_inactive_graph_with_provenance(): void
    {
        $proposal = $this->submittedProposal(CatalogImportProposal::TARGET_NEW_MARKET);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $market = NightMarket::query()->where('name', 'Draft Market')->firstOrFail();
        $stall = Stall::query()->where('night_market_id', $market->id)->where('name', 'Draft Stall')->firstOrFail();
        $food = Food::query()->where('stall_id', $stall->id)->where('name', 'Draft Food')->firstOrFail();
        $this->assertSame(NightMarket::STATUS_INACTIVE, $market->status);
        $this->assertSame('Selangor', $market->state);
        $this->assertDatabaseHas('market_operating_days', ['night_market_id' => $market->id, 'day_of_week' => 'Saturday', 'opening_time' => '18:00:00', 'closing_time' => '23:00:00']);
        $this->assertSame(Stall::STATUS_INACTIVE, $stall->status);
        $this->assertSame(Stall::HALAL_UNKNOWN, $stall->halal_status);
        $this->assertSame(Food::STATUS_INACTIVE, $food->status);
        $this->assertSame('5.50', $food->price_min);
        $this->assertSame('8.00', $food->price_max);
        $this->assertTrue($food->is_must_try);
        $this->assertDatabaseCount('catalog_social_media_source_links', 3);
        Http::assertNothingSent();
    }

    public function test_reviewed_false_must_try_value_is_preserved_during_import(): void
    {
        $proposal = $this->submittedProposal(CatalogImportProposal::TARGET_NEW_MARKET, ['is_must_try' => false]);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $food = Food::query()->where('name', 'Draft Food')->firstOrFail();
        $this->assertFalse($food->is_must_try);
        Http::assertNothingSent();
    }

    public function test_admin_can_uncheck_must_try_and_the_imported_food_remains_false(): void
    {
        $proposal = $this->proposalWithGraph(CatalogImportProposal::TARGET_NEW_MARKET);
        $food = $proposal->proposalMarket->stalls->firstOrFail()->foods->firstOrFail();
        $this->assertTrue($food->is_must_try);

        $this->actingAs($this->admin)
            ->patch(route('admin.social-media.automation.proposals.foods.update', [$proposal, $food]), [
                'name' => $food->name,
                'is_must_try' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertFalse($food->fresh()->is_must_try);

        $this->post(route('admin.social-media.automation.proposals.submit', $proposal))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse(Food::query()->where('name', $food->name)->firstOrFail()->is_must_try);
        Http::assertNothingSent();
    }

    public function test_new_market_validation_and_catalog_conflicts_block_import_without_merging(): void
    {
        $invalid = $this->proposalWithGraph(CatalogImportProposal::TARGET_NEW_MARKET, ['market_state' => 'Perak']);
        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.submit', $invalid))
            ->assertSessionHasErrors('proposal');

        $conflictMarket = NightMarket::factory()->create([
            'name' => 'Draft Market',
            'address' => '10 Draft Road',
            'city' => 'Klang',
            'state' => 'Selangor',
        ]);
        $conflict = $this->submittedProposal(CatalogImportProposal::TARGET_NEW_MARKET);
        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $conflict))
            ->assertSessionHasErrors('proposal');
        $this->assertSame(CatalogImportProposal::STATUS_SUBMITTED, $conflict->fresh()->status);
        $this->assertSame('Draft Market', $conflictMarket->fresh()->name);

        $existing = NightMarket::factory()->create();
        Stall::factory()->create(['night_market_id' => $existing->id, 'name' => 'Draft Stall']);
        $stallConflict = $this->submittedProposal(CatalogImportProposal::TARGET_EXISTING_MARKET, ['market' => $existing]);
        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $stallConflict))
            ->assertSessionHasErrors('proposal');
        Http::assertNothingSent();
    }

    public function test_invalid_time_ranges_and_existing_food_conflicts_are_rejected_before_writes(): void
    {
        $invalidTime = $this->proposalWithGraph(CatalogImportProposal::TARGET_NEW_MARKET, ['closing_time' => '17:00']);
        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.submit', $invalidTime))
            ->assertSessionHasErrors('proposal');
        $this->assertSame(CatalogImportProposal::STATUS_DRAFT, $invalidTime->fresh()->status);

        $market = NightMarket::factory()->create();
        $stall = Stall::factory()->create(['night_market_id' => $market->id]);
        Food::factory()->create(['stall_id' => $stall->id, 'name' => 'Draft Food']);
        $foodConflict = $this->submittedProposal(CatalogImportProposal::TARGET_EXISTING_STALL, ['market' => $market, 'stall' => $stall]);
        $beforeFoods = Food::query()->count();

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $foodConflict))
            ->assertSessionHasErrors('proposal');
        $this->assertSame(CatalogImportProposal::STATUS_SUBMITTED, $foodConflict->fresh()->status);
        $this->assertSame($beforeFoods, Food::query()->count());
        Http::assertNothingSent();
    }

    public function test_duplicate_proposal_stall_names_and_invalid_direct_transitions_are_blocked(): void
    {
        $proposal = $this->proposalWithGraph(CatalogImportProposal::TARGET_NEW_MARKET);
        $market = $proposal->proposalMarket;
        $duplicate = CatalogImportProposalStall::create([
            'catalog_import_proposal_market_id' => $market->id,
            'name' => 'Draft Stall',
            'halal_status' => Stall::HALAL_UNKNOWN,
        ]);
        CatalogImportProposalFood::create([
            'catalog_import_proposal_stall_id' => $duplicate->id,
            'name' => 'Second Draft Food',
            'is_must_try' => false,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.submit', $proposal))
            ->assertSessionHasErrors('proposal');
        $this->assertSame(CatalogImportProposal::STATUS_DRAFT, $proposal->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertSessionHasErrors('proposal');
        $this->assertSame(CatalogImportProposal::STATUS_DRAFT, $proposal->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_import_is_idempotent_and_provenance_constraints_prevent_duplicate_links(): void
    {
        $proposal = $this->submittedProposal(CatalogImportProposal::TARGET_NEW_MARKET);
        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertRedirect();
        $counts = [NightMarket::query()->count(), Stall::query()->count(), Food::query()->count(), CatalogSocialMediaSourceLink::query()->count()];

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertRedirect()
            ->assertSessionHas('status', 'This proposal was already imported. No duplicate catalog records were created.');
        $this->assertSame($counts, [NightMarket::query()->count(), Stall::query()->count(), Food::query()->count(), CatalogSocialMediaSourceLink::query()->count()]);

        $link = CatalogSocialMediaSourceLink::query()->firstOrFail();
        $this->expectException(QueryException::class);
        CatalogSocialMediaSourceLink::create([
            'social_media_source_id' => $link->social_media_source_id,
            'catalog_import_proposal_id' => $link->catalog_import_proposal_id,
            'catalog_type' => $link->catalog_type,
            'night_market_id' => $link->night_market_id,
        ]);
    }

    public function test_transaction_rolls_back_catalog_writes_and_records_only_a_safe_failure(): void
    {
        $proposal = $this->submittedProposal(CatalogImportProposal::TARGET_NEW_MARKET);
        $marketCount = NightMarket::query()->count();
        CatalogSocialMediaSourceLink::creating(function (): void {
            throw new RuntimeException('forced internal database failure');
        });

        $response = $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal));

        $response->assertSessionHasErrors('proposal');
        $this->assertSame($marketCount, NightMarket::query()->count());
        $proposal->refresh();
        $this->assertSame(CatalogImportProposal::STATUS_FAILED, $proposal->status);
        $this->assertSame('catalog_import_failed', $proposal->failure_code);
        $this->get(route('admin.ai-import.show', $proposal))
            ->assertOk()
            ->assertSee('No partial catalog records were saved.')
            ->assertDontSee('forced internal database failure')
            ->assertDontSee('SQLSTATE');
        Http::assertNothingSent();
    }

    public function test_named_market_identity_unique_violation_is_a_safe_conflict_with_no_partial_graph(): void
    {
        $proposal = $this->submittedProposal(CatalogImportProposal::TARGET_NEW_MARKET);
        $counts = [
            NightMarket::query()->count(),
            Stall::query()->count(),
            Food::query()->count(),
            CatalogSocialMediaSourceLink::query()->count(),
        ];
        $injectedConcurrentIdentity = false;

        NightMarket::query();
        NightMarket::creating(function (NightMarket $market) use (&$injectedConcurrentIdentity): void {
            if ($injectedConcurrentIdentity || $market->name !== 'Draft Market') {
                return;
            }

            $injectedConcurrentIdentity = true;
            DB::table('night_markets')->insert([
                'name' => $market->name,
                'address' => $market->address,
                'city' => $market->city,
                'state' => $market->state,
                'catalog_identity_hash' => $market->catalog_identity_hash,
                'status' => NightMarket::STATUS_INACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.approve-import', $proposal))
            ->assertSessionHasErrors([
                'proposal' => 'A matching Night Market already exists. Review the draft instead of merging it automatically.',
            ]);

        $this->assertTrue($injectedConcurrentIdentity);
        $this->assertSame(CatalogImportProposal::STATUS_SUBMITTED, $proposal->fresh()->status);
        $this->assertSame($counts, [
            NightMarket::query()->count(),
            Stall::query()->count(),
            Food::query()->count(),
            CatalogSocialMediaSourceLink::query()->count(),
        ]);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides */
    private function submittedProposal(string $targetType, array $overrides = []): CatalogImportProposal
    {
        $proposal = $this->proposalWithGraph($targetType, $overrides);
        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.submit', $proposal))
            ->assertSessionHasNoErrors();

        return $proposal->fresh(['proposalMarket.operatingDays', 'proposalMarket.stalls.foods', 'socialMediaSource', 'matchedNightMarket', 'matchedStall.nightMarket']);
    }

    /** @param array<string, mixed> $overrides */
    private function proposalWithGraph(string $targetType, array $overrides = []): CatalogImportProposal
    {
        $market = $overrides['market'] ?? null;
        $stall = $overrides['stall'] ?? null;
        if ($targetType === CatalogImportProposal::TARGET_EXISTING_STALL && ! $stall) {
            $market ??= NightMarket::factory()->create();
            $stall = Stall::factory()->create(['night_market_id' => $market->id]);
        }
        if ($targetType === CatalogImportProposal::TARGET_EXISTING_MARKET && ! $market) {
            $market = NightMarket::factory()->create();
        }

        $source = SocialMediaSource::create([
            'platform' => SocialMediaSource::PLATFORM_YOUTUBE,
            'canonical_url' => 'https://www.youtube.com/watch?v='.fake()->unique()->regexify('[A-Za-z0-9_-]{11}'),
            'url_fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'external_content_id' => fake()->unique()->regexify('[A-Za-z0-9_-]{11}'),
            'title' => 'Draft Market official walkthrough',
            'description_excerpt' => 'Draft Market at 10 Draft Road, Klang, Selangor opens Saturday 18:00 to 23:00. Draft Stall serves must-try Draft Food for RM5.50 to RM8.00.',
            'creator_name' => 'Official Channel',
            'thumbnail_url' => 'https://i.ytimg.com/vi/draftmarket1/hqdefault.jpg',
            'published_at' => '2026-08-30 12:00:00',
            'metadata_status' => SocialMediaSource::METADATA_FETCHED,
            'metadata_fetched_at' => now(),
        ]);

        $proposal = CatalogImportProposal::create([
            'social_media_source_id' => $source->id,
            'target_type' => $targetType,
            'matched_night_market_id' => $targetType === CatalogImportProposal::TARGET_NEW_MARKET ? null : $market->id,
            'matched_stall_id' => $targetType === CatalogImportProposal::TARGET_EXISTING_STALL ? $stall->id : null,
            'status' => CatalogImportProposal::STATUS_DRAFT,
            'revision' => 1,
            'created_by' => $this->admin->id,
            'extraction_status' => CatalogImportProposal::EXTRACTION_COMPLETED,
        ]);

        $proposalMarket = CatalogImportProposalMarket::create([
            'catalog_import_proposal_id' => $proposal->id,
            'matched_night_market_id' => $targetType === CatalogImportProposal::TARGET_NEW_MARKET ? null : $market->id,
            'name' => $targetType === CatalogImportProposal::TARGET_NEW_MARKET ? 'Draft Market' : $market->name,
            'address' => $targetType === CatalogImportProposal::TARGET_NEW_MARKET ? '10 Draft Road' : $market->address,
            'city' => $targetType === CatalogImportProposal::TARGET_NEW_MARKET ? 'Klang' : $market->city,
            'state' => $targetType === CatalogImportProposal::TARGET_NEW_MARKET ? ($overrides['market_state'] ?? 'Selangor') : $market->state,
            'description' => 'Reviewed market description.',
            'evidence_text' => 'Draft Market',
        ]);

        CatalogImportProposalOperatingDay::create([
            'catalog_import_proposal_market_id' => $proposalMarket->id,
            'day_of_week' => 'Saturday',
            'opening_time' => '18:00',
            'closing_time' => $overrides['closing_time'] ?? '23:00',
            'evidence_text' => 'Saturday 18:00 to 23:00',
        ]);

        if ($targetType === CatalogImportProposal::TARGET_EXISTING_STALL) {
            $proposalStall = CatalogImportProposalStall::create([
                'catalog_import_proposal_market_id' => $proposalMarket->id,
                'matched_stall_id' => $stall->id,
                'name' => $stall->name,
                'halal_status' => Stall::HALAL_UNKNOWN,
            ]);
        } else {
            $proposalStall = CatalogImportProposalStall::create([
                'catalog_import_proposal_market_id' => $proposalMarket->id,
                'name' => 'Draft Stall',
                'description' => 'Reviewed Stall description.',
                'halal_status' => Stall::HALAL_UNKNOWN,
                'evidence_text' => 'Draft Stall',
            ]);
        }

        if (($overrides['with_food'] ?? true) === true) {
            CatalogImportProposalFood::create([
                'catalog_import_proposal_stall_id' => $proposalStall->id,
                'name' => 'Draft Food',
                'description' => 'Reviewed Food description.',
                'category' => 'Snack',
                'price_min' => '5.50',
                'price_max' => '8.00',
                'price_display' => 'RM5.50–RM8.00',
                'is_must_try' => (bool) ($overrides['is_must_try'] ?? true),
                'evidence_text' => 'must-try Draft Food for RM5.50 to RM8.00',
            ]);
        }

        $proposal->forceFill([
            'extraction_input_hash' => app(CatalogSuggestionExtractionService::class)->currentInputHash($proposal),
        ])->save();

        return $proposal->fresh(['proposalMarket.operatingDays', 'proposalMarket.stalls.foods', 'socialMediaSource', 'matchedNightMarket', 'matchedStall.nightMarket']);
    }
}
