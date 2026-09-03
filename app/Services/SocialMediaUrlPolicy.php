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

    /** @var list<string> */
    private const TRACKING_QUERY_PARAMETERS = [
        '_r', '_t', 'ab_channel', 'fbclid', 'feature', 'gclid', 'igsh', 'igshid', 'igsi',
        'is_copy_url', 'is_from_webapp', 'mibextid', 'pp', 'rdid', 'ref_src', 'ref_url',
        's', 'sender_device', 'si', 't', 'web_id',
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
        $parts = $this->parseHttpUrl($url, normaliseSourceQuery: true);
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
    private function parseHttpUrl(
        string $url,
        bool $requireHttps = false,
        bool $normaliseSourceQuery = false,
    ): array {
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

        $canonicalHost = $normaliseSourceQuery ? $this->canonicalSourceHost($host) : $host;
        $path = (string) ($parts['path'] ?? '/');
        $query = (string) ($parts['query'] ?? '');
        if ($normaliseSourceQuery) {
            $query = $this->normaliseSourceQuery($query);
        }

        $fragmentlessUrl = $scheme.'://'.$canonicalHost.$path.($query === '' ? '' : '?'.$query);

        return [
            'url' => (string) $fragmentlessUrl,
            'host' => $host,
            'port' => $port,
        ];
    }

    private function canonicalSourceHost(string $host): string
    {
        return match ($host) {
            'instagram.com' => 'www.instagram.com',
            'tiktok.com' => 'www.tiktok.com',
            'facebook.com' => 'www.facebook.com',
            'youtube.com' => 'www.youtube.com',
            'www.x.com', 'twitter.com', 'www.twitter.com' => 'x.com',
            default => $host,
        };
    }

    private function normaliseSourceQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $remaining = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            if (str_starts_with($name, 'utm_') || in_array($name, self::TRACKING_QUERY_PARAMETERS, true)) {
                continue;
            }

            $remaining[] = $pair;
        }

        sort($remaining, SORT_STRING);

        return implode('&', $remaining);
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
