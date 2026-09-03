<?php

namespace App\Services;

use App\Contracts\SocialMediaMetadataProvider;
use App\Exceptions\SocialMediaMetadataException;
use App\Models\SocialMediaSource;
use App\Support\SocialMediaMetadata;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class YouTubeMetadataProvider implements SocialMediaMetadataProvider
{
    private const VIDEOS_PATH = '/videos';

    private const PART = 'snippet';

    private const FIELDS = 'items(id,snippet(title,description,channelId,channelTitle,publishedAt,thumbnails))';

    /** @var list<string> */
    private const THUMBNAIL_SIZES = ['maxres', 'standard', 'high', 'medium', 'default'];

    public function __construct(private readonly SocialMediaUrlPolicy $urlPolicy) {}

    public function fetch(SocialMediaSource $source): SocialMediaMetadata
    {
        $apiKey = config('services.youtube.data_api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new SocialMediaMetadataException(SocialMediaMetadataService::FAILURE_YOUTUBE_CONFIG_MISSING);
        }

        if ($source->platform !== SocialMediaSource::PLATFORM_YOUTUBE || ! is_string($source->external_content_id)) {
            throw new SocialMediaMetadataException(SocialMediaMetadataService::FAILURE_YOUTUBE_INVALID_RESPONSE);
        }

        $baseUrl = config('services.youtube.base_url');
        if (! is_string($baseUrl) || $baseUrl !== 'https://www.googleapis.com/youtube/v3') {
            throw new SocialMediaMetadataException(SocialMediaMetadataService::FAILURE_YOUTUBE_CONFIG_MISSING);
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(8)
                ->get($baseUrl.self::VIDEOS_PATH, [
                    'id' => $source->external_content_id,
                    'part' => self::PART,
                    'fields' => self::FIELDS,
                    'key' => trim($apiKey),
                ]);
        } catch (ConnectionException) {
            throw new SocialMediaMetadataException(SocialMediaMetadataService::FAILURE_YOUTUBE_TIMEOUT);
        } catch (Throwable) {
            throw new SocialMediaMetadataException(SocialMediaMetadataService::FAILURE_YOUTUBE_REQUEST_FAILED);
        }

        $this->throwForFailedResponse($response);

        try {
            $payload = $response->json();
        } catch (Throwable) {
            throw new SocialMediaMetadataException(SocialMediaMetadataService::FAILURE_YOUTUBE_INVALID_RESPONSE);
        }

        if (! is_array($payload) || ! is_array($payload['items'] ?? null)) {
            throw new SocialMediaMetadataException(SocialMediaMetadataService::FAILURE_YOUTUBE_INVALID_RESPONSE);
        }

        $items = $payload['items'];
        if ($items === []) {
            throw new SocialMediaMetadataException(SocialMediaMetadataService::FAILURE_YOUTUBE_VIDEO_NOT_FOUND);
        }

        if (count($items) !== 1 || ! is_array($items[0] ?? null)) {
            throw new SocialMediaMetadataException(SocialMediaMetadataService::FAILURE_YOUTUBE_INVALID_RESPONSE);
        }

        $item = $items[0];
        if (($item['id'] ?? null) !== $source->external_content_id || ! is_array($item['snippet'] ?? null)) {
            throw new SocialMediaMetadataException(SocialMediaMetadataService::FAILURE_YOUTUBE_INVALID_RESPONSE);
        }

        $snippet = $item['snippet'];
        $title = $this->plainText($snippet['title'] ?? null, 500);
        $description = $this->plainText($snippet['description'] ?? null, 5000);
        $creator = $this->plainText($snippet['channelTitle'] ?? null, 255);
        $thumbnail = $this->thumbnailUrl($snippet['thumbnails'] ?? null);

        if ($title === null || $description === null || $creator === null || $thumbnail === null) {
            throw new SocialMediaMetadataException(SocialMediaMetadataService::FAILURE_YOUTUBE_INVALID_RESPONSE);
        }

        $publishedAt = $this->publishedAt($snippet['publishedAt'] ?? null);
        if ($publishedAt === null) {
            throw new SocialMediaMetadataException(SocialMediaMetadataService::FAILURE_YOUTUBE_INVALID_RESPONSE);
        }

        return new SocialMediaMetadata($title, $description, $creator, $thumbnail, $publishedAt);
    }

    private function throwForFailedResponse(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $reason = $response->json('error.errors.0.reason');

        $failureCode = match (true) {
            $response->status() === 403 && in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true) => SocialMediaMetadataService::FAILURE_YOUTUBE_QUOTA_EXCEEDED,
            $response->status() === 403 => SocialMediaMetadataService::FAILURE_YOUTUBE_API_FORBIDDEN,
            $response->status() === 404 => SocialMediaMetadataService::FAILURE_YOUTUBE_VIDEO_NOT_FOUND,
            $response->status() === 410 => SocialMediaMetadataService::FAILURE_YOUTUBE_VIDEO_UNAVAILABLE,
            $response->status() === 429 => SocialMediaMetadataService::FAILURE_YOUTUBE_RATE_LIMITED,
            $response->status() >= 500 => SocialMediaMetadataService::FAILURE_YOUTUBE_PROVIDER_UNAVAILABLE,
            default => SocialMediaMetadataService::FAILURE_YOUTUBE_REQUEST_FAILED,
        };

        throw new SocialMediaMetadataException($failureCode);
    }

    private function thumbnailUrl(mixed $thumbnails): ?string
    {
        if (! is_array($thumbnails)) {
            return null;
        }

        foreach (self::THUMBNAIL_SIZES as $size) {
            $url = $thumbnails[$size]['url'] ?? null;
            if (! is_string($url)) {
                continue;
            }

            $safeUrl = $this->urlPolicy->safeStoredImageUrl($url);
            if ($safeUrl !== null) {
                return $safeUrl;
            }
        }

        return null;
    }

    private function plainText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function publishedAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '' || trim($value) !== $value) {
            return null;
        }

        $matched = preg_match(
            '/\A(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})T(?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})(?:\.(?<fraction>\d+))?(?<offset>Z|[+-]\d{2}:\d{2})\z/D',
            $value,
            $parts,
        );

        if ($matched !== 1
            || ! checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])
            || (int) $parts['hour'] > 23
            || (int) $parts['minute'] > 59
            || (int) $parts['second'] > 59) {
            return null;
        }

        $offset = $parts['offset'] === 'Z' ? '+00:00' : $parts['offset'];
        if ($parts['offset'] !== 'Z') {
            [$offsetHour, $offsetMinute] = array_map('intval', explode(':', substr($offset, 1)));
            if ($offsetHour > 23 || $offsetMinute > 59) {
                return null;
            }
        }

        $fraction = isset($parts['fraction'])
            ? '.'.str_pad(substr($parts['fraction'], 0, 6), 6, '0')
            : '';
        $format = $fraction === '' ? '!Y-m-d\TH:i:sP' : '!Y-m-d\TH:i:s.uP';
        $normalized = sprintf(
            '%s-%s-%sT%s:%s:%s%s%s',
            $parts['year'],
            $parts['month'],
            $parts['day'],
            $parts['hour'],
            $parts['minute'],
            $parts['second'],
            $fraction,
            $offset,
        );

        $parsed = DateTimeImmutable::createFromFormat($format, $normalized);
        $parseErrors = DateTimeImmutable::getLastErrors();

        if ($parsed === false
            || (is_array($parseErrors)
                && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))) {
            return null;
        }

        return CarbonImmutable::instance($parsed)->setTimezone((string) config('app.timezone', 'UTC'));
    }
}
