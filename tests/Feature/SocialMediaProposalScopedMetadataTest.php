<?php

namespace Tests\Feature;

use App\Models\CatalogImportProposal;
use App\Models\SocialMediaSource;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialMediaProposalScopedMetadataTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

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
            'services.youtube.data_api_key' => 'testing-youtube-key',
            'services.youtube.base_url' => 'https://www.googleapis.com/youtube/v3',
        ]);
    }

    public function test_draft_proposal_fetches_metadata_without_holding_a_service_transaction_during_http(): void
    {
        $proposal = $this->proposalFor($this->sourceFor($this->videoId('draft-fetch')));
        $baselineTransactionLevel = DB::transactionLevel();
        $providerTransactionLevel = null;

        Http::fake(function (Request $request) use (&$providerTransactionLevel) {
            $providerTransactionLevel = DB::transactionLevel();

            return Http::response($this->videoPayload($this->requestVideoId($request)));
        });

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.fetch-metadata', $proposal))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($baselineTransactionLevel, $providerTransactionLevel);
        $this->assertDatabaseHas('social_media_sources', [
            'id' => $proposal->social_media_source_id,
            'metadata_status' => SocialMediaSource::METADATA_FETCHED,
            'title' => 'Proposal-scoped metadata',
        ]);
        Http::assertSentCount(1);
    }

    public function test_draft_proposal_can_retry_a_safe_metadata_failure(): void
    {
        $proposal = $this->proposalFor($this->sourceFor($this->videoId('draft-retry')));
        config(['services.youtube.data_api_key' => null]);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.fetch-metadata', $proposal))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('social_media_sources', [
            'id' => $proposal->social_media_source_id,
            'metadata_status' => SocialMediaSource::METADATA_FAILED,
        ]);
        Http::assertNothingSent();

        config(['services.youtube.data_api_key' => 'testing-youtube-key']);
        Http::fake(function (Request $request) {
            return Http::response($this->videoPayload($this->requestVideoId($request)));
        });

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.proposals.fetch-metadata', $proposal))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('social_media_sources', [
            'id' => $proposal->social_media_source_id,
            'metadata_status' => SocialMediaSource::METADATA_FETCHED,
            'failure_code' => null,
        ]);
        Http::assertSentCount(1);
    }

    public function test_only_draft_proposals_can_fetch_or_retry_metadata(): void
    {
        Http::fake();

        foreach ([
            CatalogImportProposal::STATUS_SUBMITTED,
            CatalogImportProposal::STATUS_APPROVED,
            CatalogImportProposal::STATUS_REJECTED,
            CatalogImportProposal::STATUS_IMPORTING,
            CatalogImportProposal::STATUS_IMPORTED,
            CatalogImportProposal::STATUS_FAILED,
        ] as $status) {
            $proposal = $this->proposalFor($this->sourceFor($this->videoId('terminal-'.$status)), $status);

            $this->actingAs($this->admin)
                ->from(route('admin.social-media.automation.show', $proposal))
                ->post(route('admin.social-media.automation.proposals.fetch-metadata', $proposal))
                ->assertRedirect(route('admin.social-media.automation.show', $proposal))
                ->assertSessionHasErrors([
                    'proposal' => 'Only draft proposals can fetch official YouTube metadata.',
                ]);

            $this->assertDatabaseHas('social_media_sources', [
                'id' => $proposal->social_media_source_id,
                'metadata_status' => SocialMediaSource::METADATA_PENDING,
            ]);
        }

        Http::assertNothingSent();
    }

    public function test_request_cannot_substitute_a_source_owned_by_another_proposal(): void
    {
        Http::fake();
        $proposal = $this->proposalFor($this->sourceFor($this->videoId('owned')));
        $otherProposal = $this->proposalFor($this->sourceFor($this->videoId('substitute')));

        $this->actingAs($this->admin)
            ->from(route('admin.social-media.automation.show', $proposal))
            ->post(route('admin.social-media.automation.proposals.fetch-metadata', $proposal), [
                'social_media_source_id' => $otherProposal->social_media_source_id,
            ])
            ->assertRedirect(route('admin.social-media.automation.show', $proposal))
            ->assertSessionHasErrors([
                'social_media_source_id' => 'The metadata source is fixed by the selected proposal.',
            ]);

        $this->assertDatabaseHas('social_media_sources', [
            'id' => $proposal->social_media_source_id,
            'metadata_status' => SocialMediaSource::METADATA_PENDING,
        ]);
        $this->assertDatabaseHas('social_media_sources', [
            'id' => $otherProposal->social_media_source_id,
            'metadata_status' => SocialMediaSource::METADATA_PENDING,
        ]);
        $this->assertNull(app('router')->getRoutes()->getByName('admin.social-media.automation.sources.fetch-metadata'));
        Http::assertNothingSent();
    }

    public function test_proposal_source_relationship_is_rechecked_after_http_before_persistence(): void
    {
        $proposal = $this->proposalFor($this->sourceFor($this->videoId('before-http')));
        $originalSourceId = (int) $proposal->social_media_source_id;
        $replacementSource = $this->sourceFor($this->videoId('after-http'));

        Http::fake(function (Request $request) use ($proposal, $replacementSource) {
            CatalogImportProposal::query()
                ->whereKey($proposal->getKey())
                ->update(['social_media_source_id' => $replacementSource->getKey()]);

            return Http::response($this->videoPayload($this->requestVideoId($request)));
        });

        $this->actingAs($this->admin)
            ->from(route('admin.social-media.automation.show', $proposal))
            ->post(route('admin.social-media.automation.proposals.fetch-metadata', $proposal))
            ->assertRedirect(route('admin.social-media.automation.show', $proposal))
            ->assertSessionHasErrors([
                'proposal' => 'The metadata source no longer belongs to this proposal.',
            ]);

        $this->assertDatabaseHas('social_media_sources', [
            'id' => $originalSourceId,
            'metadata_status' => SocialMediaSource::METADATA_PENDING,
        ]);
        $this->assertDatabaseHas('social_media_sources', [
            'id' => $replacementSource->getKey(),
            'metadata_status' => SocialMediaSource::METADATA_PENDING,
        ]);
        Http::assertSentCount(1);
    }

    private function sourceFor(string $videoId): SocialMediaSource
    {
        $url = 'https://www.youtube.com/watch?v='.$videoId;

        return SocialMediaSource::query()->create([
            'platform' => SocialMediaSource::PLATFORM_YOUTUBE,
            'canonical_url' => $url,
            'url_fingerprint' => hash('sha256', $url),
            'external_content_id' => $videoId,
            'metadata_status' => SocialMediaSource::METADATA_PENDING,
        ]);
    }

    private function proposalFor(
        SocialMediaSource $source,
        string $status = CatalogImportProposal::STATUS_DRAFT,
    ): CatalogImportProposal {
        return CatalogImportProposal::query()->create([
            'social_media_source_id' => $source->id,
            'target_type' => CatalogImportProposal::TARGET_NEW_MARKET,
            'status' => $status,
            'revision' => 1,
            'created_by' => $this->admin->id,
        ]);
    }

    private function videoId(string $seed): string
    {
        return substr(hash('sha256', $seed), 0, 11);
    }

    private function requestVideoId(Request $request): string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return (string) ($query['id'] ?? '');
    }

    /** @return array<string, mixed> */
    private function videoPayload(string $videoId): array
    {
        return [
            'items' => [[
                'id' => $videoId,
                'snippet' => [
                    'title' => 'Proposal-scoped metadata',
                    'description' => 'A public video description.',
                    'channelId' => 'UCpublicChannel',
                    'channelTitle' => 'Night Market Channel',
                    'publishedAt' => '2026-08-30T12:00:00Z',
                    'thumbnails' => [
                        'default' => ['url' => 'https://i.ytimg.com/vi/'.$videoId.'/default.jpg'],
                    ],
                ],
            ]],
        ];
    }
}
