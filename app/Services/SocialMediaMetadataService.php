<?php

namespace App\Services;

use App\Contracts\SocialMediaMetadataProvider;
use App\Exceptions\SocialMediaMetadataException;
use App\Models\CatalogImportProposal;
use App\Models\SocialMediaSource;
use App\Support\SocialMediaMetadata;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SocialMediaMetadataService
{
    public const FAILURE_YOUTUBE_CONFIG_MISSING = 'youtube_config_missing';

    public const FAILURE_YOUTUBE_VIDEO_NOT_FOUND = 'youtube_video_not_found';

    public const FAILURE_YOUTUBE_VIDEO_UNAVAILABLE = 'youtube_video_unavailable';

    public const FAILURE_YOUTUBE_QUOTA_EXCEEDED = 'youtube_quota_exceeded';

    public const FAILURE_YOUTUBE_API_FORBIDDEN = 'youtube_api_forbidden';

    public const FAILURE_YOUTUBE_RATE_LIMITED = 'youtube_rate_limited';

    public const FAILURE_YOUTUBE_INVALID_RESPONSE = 'youtube_invalid_response';

    public const FAILURE_YOUTUBE_TIMEOUT = 'youtube_timeout';

    public const FAILURE_YOUTUBE_PROVIDER_UNAVAILABLE = 'youtube_provider_unavailable';

    public const FAILURE_YOUTUBE_REQUEST_FAILED = 'youtube_request_failed';

    private const PROVIDER_YOUTUBE_DATA_API = 'youtube_data_api';

    private const FRESH_FOR_HOURS = 24;

    public function __construct(private readonly SocialMediaMetadataProvider $metadataProvider) {}

    public function fetch(CatalogImportProposal $proposal): SocialMediaSource
    {
        [$proposalId, $sourceId, $source] = $this->prepareDraftFetch($proposal);

        if ($this->isFresh($source)) {
            return $source;
        }

        try {
            $metadata = $this->metadataProvider->fetch($source);
        } catch (SocialMediaMetadataException $exception) {
            return $this->persistFailure($proposalId, $sourceId, $exception->failureCode);
        } catch (Throwable) {
            return $this->persistFailure($proposalId, $sourceId, self::FAILURE_YOUTUBE_REQUEST_FAILED);
        }

        return $this->persistSuccess($proposalId, $sourceId, $metadata);
    }

    public function isFresh(SocialMediaSource $source): bool
    {
        return $source->metadata_status === SocialMediaSource::METADATA_FETCHED
            && $source->metadata_fetched_at !== null
            && $source->metadata_fetched_at->greaterThanOrEqualTo(now()->subHours(self::FRESH_FOR_HOURS));
    }

    public function statusMessage(SocialMediaSource $source): string
    {
        if ($source->metadata_status === SocialMediaSource::METADATA_FETCHED) {
            return 'Official YouTube metadata was retrieved. This proposal remains a draft; no catalog records were created.';
        }

        return $this->failureMessage($source->failure_code)
            .' This proposal remains a draft; no catalog records were created.';
    }

    public function failureMessage(?string $failureCode): string
    {
        return match ($failureCode) {
            self::FAILURE_YOUTUBE_CONFIG_MISSING => 'Official YouTube metadata is not configured. Ask an administrator to configure the YouTube Data API key.',
            self::FAILURE_YOUTUBE_VIDEO_NOT_FOUND,
            self::FAILURE_YOUTUBE_VIDEO_UNAVAILABLE => 'The YouTube video is unavailable or could not be found.',
            self::FAILURE_YOUTUBE_QUOTA_EXCEEDED,
            self::FAILURE_YOUTUBE_RATE_LIMITED => 'YouTube metadata is temporarily rate limited. Please retry later.',
            self::FAILURE_YOUTUBE_API_FORBIDDEN => 'YouTube did not allow this metadata request. Please retry later.',
            self::FAILURE_YOUTUBE_TIMEOUT,
            self::FAILURE_YOUTUBE_PROVIDER_UNAVAILABLE => 'YouTube metadata is temporarily unavailable. Please retry later.',
            default => 'YouTube metadata could not be retrieved safely. Please retry later.',
        };
    }

    /** @return array{0: int, 1: int, 2: SocialMediaSource} */
    private function prepareDraftFetch(CatalogImportProposal $proposal): array
    {
        return DB::transaction(function () use ($proposal): array {
            $lockedProposal = CatalogImportProposal::query()
                ->lockForUpdate()
                ->findOrFail($proposal->getKey());
            $this->assertDraft($lockedProposal);

            $lockedSource = $this->lockOwnedSource($lockedProposal);

            return [
                (int) $lockedProposal->getKey(),
                (int) $lockedSource->getKey(),
                $lockedSource,
            ];
        }, 3);
    }

    private function persistSuccess(int $proposalId, int $sourceId, SocialMediaMetadata $metadata): SocialMediaSource
    {
        return DB::transaction(function () use ($proposalId, $sourceId, $metadata): SocialMediaSource {
            $lockedProposal = CatalogImportProposal::query()
                ->lockForUpdate()
                ->findOrFail($proposalId);
            $this->assertDraft($lockedProposal);

            $lockedSource = $this->lockOwnedSource($lockedProposal, $sourceId);

            if ($this->isFresh($lockedSource)) {
                return $lockedSource;
            }

            $lockedSource->fill([
                'title' => $metadata->title,
                'description_excerpt' => $metadata->descriptionExcerpt,
                'creator_name' => $metadata->creatorName,
                'thumbnail_url' => $metadata->thumbnailUrl,
                'published_at' => $metadata->publishedAt,
                'metadata_provider' => self::PROVIDER_YOUTUBE_DATA_API,
                'metadata_status' => SocialMediaSource::METADATA_FETCHED,
                'failure_code' => null,
                'metadata_fetched_at' => now(),
            ]);
            $lockedSource->save();

            return $lockedSource->refresh();
        }, 3);
    }

    private function persistFailure(int $proposalId, int $sourceId, string $failureCode): SocialMediaSource
    {
        return DB::transaction(function () use ($proposalId, $sourceId, $failureCode): SocialMediaSource {
            $lockedProposal = CatalogImportProposal::query()
                ->lockForUpdate()
                ->findOrFail($proposalId);
            $this->assertDraft($lockedProposal);

            $lockedSource = $this->lockOwnedSource($lockedProposal, $sourceId);

            if ($this->isFresh($lockedSource)) {
                return $lockedSource;
            }

            $lockedSource->fill([
                'metadata_status' => SocialMediaSource::METADATA_FAILED,
                'failure_code' => $failureCode,
                'metadata_fetched_at' => now(),
            ]);
            $lockedSource->save();

            return $lockedSource->refresh();
        }, 3);
    }

    private function assertDraft(CatalogImportProposal $proposal): void
    {
        if ($proposal->status !== CatalogImportProposal::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'proposal' => 'Only draft proposals can fetch official YouTube metadata.',
            ]);
        }
    }

    private function lockOwnedSource(
        CatalogImportProposal $proposal,
        ?int $expectedSourceId = null,
    ): SocialMediaSource {
        if ($expectedSourceId !== null
            && (int) $proposal->social_media_source_id !== $expectedSourceId) {
            throw ValidationException::withMessages([
                'proposal' => 'The metadata source no longer belongs to this proposal.',
            ]);
        }

        $source = $proposal->socialMediaSource()
            ->lockForUpdate()
            ->first();

        if (! $source
            || (int) $source->getKey() !== (int) $proposal->social_media_source_id
            || ($expectedSourceId !== null && (int) $source->getKey() !== $expectedSourceId)) {
            throw ValidationException::withMessages([
                'proposal' => 'The metadata source no longer belongs to this proposal.',
            ]);
        }

        return $source;
    }
}
