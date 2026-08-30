<?php

namespace App\Services;

use App\Models\SocialMediaSource;
use InvalidArgumentException;

class YouTubeVideoUrlCanonicalizer
{
    private const MAXIMUM_URL_LENGTH = 2048;

    private const VIDEO_ID_PATTERN = '/\A[A-Za-z0-9_-]{11}\z/';

    /** @var array<string, bool> */
    private const HOSTS = [
        'www.youtube.com' => true,
        'youtube.com' => true,
        'm.youtube.com' => true,
        'youtu.be' => true,
    ];

    /**
     * @return array{platform: string, canonical_url: string, external_content_id: string, url_fingerprint: string}
     */
    public function canonicalize(string $url): array
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > self::MAXIMUM_URL_LENGTH || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Enter a valid HTTPS YouTube video URL.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if ($scheme !== 'https' || ! array_key_exists($host, self::HOSTS) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Enter a valid HTTPS YouTube video URL.');
        }

        $port = $parts['port'] ?? 443;
        if ((int) $port !== 443) {
            throw new InvalidArgumentException('Enter a valid HTTPS YouTube video URL.');
        }

        $videoId = $this->extractVideoId($host, (string) ($parts['path'] ?? ''), (string) ($parts['query'] ?? ''));

        if ($videoId === null || preg_match(self::VIDEO_ID_PATTERN, $videoId) !== 1) {
            throw new InvalidArgumentException('Enter a valid HTTPS YouTube video URL.');
        }

        $canonicalUrl = 'https://www.youtube.com/watch?v='.$videoId;

        return [
            'platform' => SocialMediaSource::PLATFORM_YOUTUBE,
            'canonical_url' => $canonicalUrl,
            'external_content_id' => $videoId,
            'url_fingerprint' => hash('sha256', $canonicalUrl),
        ];
    }

    private function extractVideoId(string $host, string $path, string $query): ?string
    {
        if ($host === 'youtu.be') {
            return $this->pathVideoId($path, '');
        }

        if ($path === '/watch') {
            parse_str($query, $parameters);
            $videoId = $parameters['v'] ?? null;

            return is_string($videoId) ? $videoId : null;
        }

        foreach (['/shorts/', '/embed/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $this->pathVideoId($path, $prefix);
            }
        }

        return null;
    }

    private function pathVideoId(string $path, string $prefix): ?string
    {
        $candidate = $prefix === '' ? ltrim($path, '/') : substr($path, strlen($prefix));

        if ($candidate === '' || str_contains($candidate, '/')) {
            return null;
        }

        return rawurldecode($candidate);
    }
}
