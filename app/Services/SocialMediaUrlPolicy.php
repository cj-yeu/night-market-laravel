<?php

namespace App\Services;

use App\Contracts\HostnameResolver;
use App\Exceptions\SocialMediaExtractionException;

class SocialMediaUrlPolicy
{
    /** @var array<string, string> */
    private const SOURCE_HOSTS = [
        'instagram.com' => 'Instagram',
        'www.instagram.com' => 'Instagram',
        'tiktok.com' => 'TikTok',
        'www.tiktok.com' => 'TikTok',
        'facebook.com' => 'Facebook',
        'www.facebook.com' => 'Facebook',
        'youtube.com' => 'YouTube',
        'www.youtube.com' => 'YouTube',
        'youtu.be' => 'YouTube',
        'x.com' => 'X / Twitter',
        'www.x.com' => 'X / Twitter',
        'twitter.com' => 'X / Twitter',
        'www.twitter.com' => 'X / Twitter',
    ];

    /** @var list<string> */
    private const IMAGE_HOST_SUFFIXES = [
        'cdninstagram.com',
        'fbcdn.net',
        'tiktokcdn.com',
        'tiktokcdn-us.com',
        'ytimg.com',
        'twimg.com',
    ];

    /**
     * Share links carry per-share tracking parameters, so the same post reaches
     * two administrators as two different URLs. These names are dropped when a
     * source URL is normalised, which keeps one post to one stored URL.
     *
     * Identity-bearing parameters are deliberately absent: YouTube's `v` and
     * `list` name the video and playlist, so removing them would break the link.
     *
     * @var list<string>
     */
    private const TRACKING_QUERY_PARAMETERS = [
        // TikTok
        '_r', '_t', 'is_from_webapp', 'sender_device', 'web_id', 'is_copy_url',
        // Instagram
        'igsh', 'igshid', 'igsi', 'img_index',
        // YouTube
        'si', 'feature', 'pp', 'ab_channel',
        // Facebook
        'fbclid', 'mibextid', 'rdid', 'share_url',
        // X / Twitter
        's', 't', 'ref_src', 'ref_url',
    ];

    public function __construct(private readonly HostnameResolver $hostnameResolver) {}

    /** @return array{url: string, host: string, port: int, ip: string, platform: string} */
    public function assertFetchableSourceUrl(string $url): array
    {
        $safe = $this->inspectSourceUrl($url);

        return [...$safe, 'ip' => $this->resolvePublicAddress($safe['host'])];
    }

    /** @return array{url: string, host: string, port: int, platform: string} */
    public function inspectSourceUrl(string $url): array
    {
        $parts = $this->parseHttpUrl($url, normaliseQuery: true);
        $platform = self::SOURCE_HOSTS[$parts['host']] ?? null;

        if ($platform === null) {
            throw new SocialMediaExtractionException('Only supported public social-media URLs can be used.');
        }

        return [...$parts, 'platform' => $platform];
    }

    /** @return array{url: string, host: string, port: int, ip: string} */
    public function assertSafeImageUrl(string $url): array
    {
        $parts = $this->parseHttpUrl($url, requireHttps: true);

        if (! $this->isAllowedImageHost($parts['host'])) {
            throw new SocialMediaExtractionException('The external image host is not supported.');
        }

        return [...$parts, 'ip' => $this->resolvePublicAddress($parts['host'])];
    }

    public function safeStoredSourceUrl(?string $url): ?string
    {
        try {
            return $url === null ? null : $this->inspectSourceUrl($url)['url'];
        } catch (SocialMediaExtractionException) {
            return null;
        }
    }

    public function safeStoredImageUrl(?string $url): ?string
    {
        try {
            if ($url === null) {
                return null;
            }

            $parts = $this->parseHttpUrl($url, requireHttps: true);

            return $this->isAllowedImageHost($parts['host']) ? $parts['url'] : null;
        } catch (SocialMediaExtractionException) {
            return null;
        }
    }

    private function resolvePublicAddress(string $hostname): string
    {
        $addresses = $this->hostnameResolver->resolve($hostname);

        if ($addresses === []) {
            throw new SocialMediaExtractionException('The public hostname could not be resolved safely.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new SocialMediaExtractionException('The URL resolves to a non-public network address.');
            }
        }

        return $addresses[0];
    }

    private function isPublicAddress(string $address): bool
    {
        if (! filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        )) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $firstOctet = (int) explode('.', $address, 2)[0];

            return $firstOctet < 224;
        }

        $packed = inet_pton($address);

        return $packed !== false && ord($packed[0]) !== 0xFF;
    }

    /** @return array{url: string, host: string, port: int} */
    private function parseHttpUrl(string $url, bool $requireHttps = false, bool $normaliseQuery = false): array
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new SocialMediaExtractionException('Enter a valid public HTTP or HTTPS URL.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($scheme, ['http', 'https'], true) || ($requireHttps && $scheme !== 'https')) {
            throw new SocialMediaExtractionException(
                $requireHttps ? 'The external image URL must use HTTPS.' : 'Only HTTP or HTTPS URLs are supported.',
            );
        }

        if ($host === '') {
            throw new SocialMediaExtractionException('A supported public hostname is required.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SocialMediaExtractionException('URLs containing credentials are not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false || ! preg_match('/\A[a-z0-9.-]+\z/', $host)) {
            throw new SocialMediaExtractionException('A supported public hostname is required.');
        }

        $expectedPort = $scheme === 'https' ? 443 : 80;
        $port = (int) ($parts['port'] ?? $expectedPort);
        if ($port !== $expectedPort) {
            throw new SocialMediaExtractionException('Only standard HTTP and HTTPS ports are supported.');
        }

        $normalisedUrl = (string) preg_replace('/#.*\z/s', '', $url);

        if ($normaliseQuery) {
            $normalisedUrl = $this->withoutTrackingParameters($normalisedUrl);
        }

        return [
            'url' => $normalisedUrl,
            'host' => $host,
            'port' => $port,
        ];
    }

    /**
     * Removes per-share tracking parameters while preserving the order and the
     * original encoding of every parameter that is kept. Parameters are matched
     * case-insensitively, and any `utm_*` campaign parameter is dropped.
     */
    private function withoutTrackingParameters(string $url): string
    {
        $position = strpos($url, '?');

        if ($position === false) {
            return $url;
        }

        $base = substr($url, 0, $position);
        $query = substr($url, $position + 1);
        $kept = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = strtolower(rawurldecode(explode('=', $pair, 2)[0]));

            if (str_starts_with($name, 'utm_') || in_array($name, self::TRACKING_QUERY_PARAMETERS, true)) {
                continue;
            }

            $kept[] = $pair;
        }

        return $kept === [] ? $base : $base.'?'.implode('&', $kept);
    }

    private function isAllowedImageHost(string $hostname): bool
    {
        if (array_key_exists($hostname, self::SOURCE_HOSTS)) {
            return true;
        }

        foreach (self::IMAGE_HOST_SUFFIXES as $suffix) {
            if ($hostname === $suffix || str_ends_with($hostname, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }
}
