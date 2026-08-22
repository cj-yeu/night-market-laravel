<?php

namespace App\Services;

use App\Exceptions\SocialMediaExtractionException;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SocialMediaMetadataExtractor
{
    private const MAX_RESPONSE_BYTES = 524288;

    private const MAX_REDIRECTS = 3;

    public function __construct(private readonly SocialMediaUrlPolicy $urlPolicy) {}

    /**
     * @return array{
     *   platform: string,
     *   original_post_url: string,
     *   extracted_title: string|null,
     *   content_summary: string|null,
     *   posted_date: string|null,
     *   external_image_url: string|null,
     *   extraction_status: string
     * }
     */
    public function extract(string $submittedUrl): array
    {
        $current = $this->urlPolicy->assertFetchableSourceUrl($submittedUrl);
        $platform = $current['platform'];

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $response = $this->request($current);

            if ($this->isRedirect($response)) {
                if ($redirects === self::MAX_REDIRECTS) {
                    throw new SocialMediaExtractionException('The social-media page redirected too many times.');
                }

                $location = trim($response->header('Location'));
                if ($location === '') {
                    throw new SocialMediaExtractionException('The social-media page returned an invalid redirect.');
                }

                $redirectUrl = $this->resolveUrl($current['url'], $location);
                $redirect = $this->urlPolicy->assertFetchableSourceUrl($redirectUrl);

                if ($redirect['platform'] !== $platform) {
                    throw new SocialMediaExtractionException('Redirects to a different social platform are not allowed.');
                }

                $current = $redirect;

                continue;
            }

            if (! $response->successful()) {
                throw new SocialMediaExtractionException('The social-media page could not be read. Enter the details manually.');
            }

            $contentType = strtolower($response->header('Content-Type'));
            if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
                throw new SocialMediaExtractionException('The source did not return a public HTML page.');
            }

            return $this->extractMetadata($this->readLimitedBody($response), $current);
        }

        throw new SocialMediaExtractionException('The social-media page could not be read. Enter the details manually.');
    }

    /** @param array{url: string, host: string, port: int, ip: string, platform: string} $safeUrl */
    private function request(array $safeUrl): Response
    {
        $options = [
            'allow_redirects' => false,
            'stream' => true,
        ];

        if (defined('CURLOPT_RESOLVE')) {
            $ip = str_contains($safeUrl['ip'], ':') ? '['.$safeUrl['ip'].']' : $safeUrl['ip'];
            $options['curl'] = [
                CURLOPT_RESOLVE => ["{$safeUrl['host']}:{$safeUrl['port']}:{$ip}"],
            ];
        }

        try {
            return Http::connectTimeout(3)
                ->timeout(6)
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml',
                    'User-Agent' => 'NightMarketMetadataReview/1.0',
                ])
                ->withOptions($options)
                ->get($safeUrl['url']);
        } catch (ConnectionException) {
            throw new SocialMediaExtractionException(
                'The social-media page timed out or could not be reached. Enter the details manually.',
            );
        }
    }

    private function readLimitedBody(Response $response): string
    {
        $contentLength = $response->header('Content-Length');
        if (ctype_digit($contentLength) && (int) $contentLength > self::MAX_RESPONSE_BYTES) {
            throw new SocialMediaExtractionException('The social-media response was too large to review safely.');
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (! $stream->eof() && strlen($body) <= self::MAX_RESPONSE_BYTES) {
            $body .= $stream->read(min(16384, self::MAX_RESPONSE_BYTES + 1 - strlen($body)));
        }

        if (strlen($body) > self::MAX_RESPONSE_BYTES || ! $stream->eof()) {
            throw new SocialMediaExtractionException('The social-media response was too large to review safely.');
        }

        return $body;
    }

    /**
     * @param  array{url: string, host: string, port: int, ip: string, platform: string}  $source
     * @return array<string, string|null>
     */
    private function extractMetadata(string $html, array $source): array
    {
        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if (! $loaded) {
            throw new SocialMediaExtractionException('No usable public metadata was found. Enter the details manually.');
        }

        $metadata = [];
        foreach ($document->getElementsByTagName('meta') as $meta) {
            if (! $meta instanceof DOMElement) {
                continue;
            }

            $key = strtolower(trim(
                $meta->getAttribute('property')
                ?: $meta->getAttribute('name')
                ?: $meta->getAttribute('itemprop'),
            ));
            $content = $this->cleanText($meta->getAttribute('content'), 5000);

            if ($key !== '' && $content !== null && ! array_key_exists($key, $metadata)) {
                $metadata[$key] = $content;
            }
        }

        $title = $this->cleanText(
            $metadata['og:title'] ?? $metadata['twitter:title'] ?? $document->getElementsByTagName('title')->item(0)?->textContent,
            500,
        );
        $description = $this->cleanText(
            $metadata['og:description'] ?? $metadata['twitter:description'] ?? $metadata['description'] ?? null,
            2000,
        );

        if ($title === null && $description === null) {
            throw new SocialMediaExtractionException('No usable public metadata was found. Enter the details manually.');
        }

        $canonicalUrl = $this->canonicalUrl($document, $metadata, $source['url'], $source['platform']);
        $imageUrl = $this->safeImageUrl(
            $metadata['og:image:secure_url'] ?? $metadata['og:image'] ?? $metadata['twitter:image'] ?? null,
            $source['url'],
        );

        return [
            'platform' => $source['platform'],
            'original_post_url' => $canonicalUrl,
            'extracted_title' => $title,
            'content_summary' => $description,
            'posted_date' => $this->reliableDate($metadata),
            'external_image_url' => $imageUrl,
            'extraction_status' => 'succeeded',
        ];
    }

    /** @param array<string, string> $metadata */
    private function canonicalUrl(DOMDocument $document, array $metadata, string $currentUrl, string $platform): string
    {
        $candidate = $metadata['og:url'] ?? null;

        foreach ($document->getElementsByTagName('link') as $link) {
            if ($link instanceof DOMElement && strtolower(trim($link->getAttribute('rel'))) === 'canonical') {
                $candidate = $link->getAttribute('href');
                break;
            }
        }

        if (! filled($candidate)) {
            return $currentUrl;
        }

        try {
            $safe = $this->urlPolicy->assertFetchableSourceUrl($this->resolveUrl($currentUrl, (string) $candidate));

            return $safe['platform'] === $platform ? $safe['url'] : $currentUrl;
        } catch (SocialMediaExtractionException) {
            return $currentUrl;
        }
    }

    private function safeImageUrl(?string $candidate, string $currentUrl): ?string
    {
        if (! filled($candidate)) {
            return null;
        }

        try {
            return $this->urlPolicy->assertSafeImageUrl(
                $this->resolveUrl($currentUrl, (string) $candidate),
            )['url'];
        } catch (SocialMediaExtractionException) {
            return null;
        }
    }

    /** @param array<string, string> $metadata */
    private function reliableDate(array $metadata): ?string
    {
        $candidate = $metadata['article:published_time']
            ?? $metadata['datepublished']
            ?? $metadata['uploaddate']
            ?? null;

        if (! is_string($candidate) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})/', $candidate, $matches)) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $date = CarbonImmutable::create($year, $month, $day)->startOfDay();

        return $date->isFuture() ? null : $date->toDateString();
    }

    private function cleanText(?string $value, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', trim($value));

        return filled($value) ? Str::limit((string) $value, $maximum, '') : null;
    }

    private function resolveUrl(string $baseUrl, string $candidate): string
    {
        try {
            return (string) UriResolver::resolve(new Uri($baseUrl), new Uri(trim($candidate)));
        } catch (\Throwable) {
            throw new SocialMediaExtractionException('The social-media page contained an invalid URL.');
        }
    }

    private function isRedirect(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308], true);
    }
}
