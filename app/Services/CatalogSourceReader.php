<?php

namespace App\Services;

use App\Contracts\HostnameResolver;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class CatalogSourceReader
{
    public function __construct(private readonly HostnameResolver $resolver) {}

    public function url(string $url): string
    {
        $p = parse_url(trim($url));
        if (! $p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])
            || (isset($p['port']) && $p['port'] !== 443) || ! str_contains($p['host'], '.')
            || filter_var($p['host'], FILTER_VALIDATE_IP) || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw ValidationException::withMessages(['source' => 'Use a public HTTPS source URL without credentials.']);
        }

        return 'https://'.strtolower($p['host']).($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '');
    }

    private function options(string $url, int $limit): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $addresses = $this->resolver->resolve($host);
        if (! $addresses || collect($addresses)->contains(fn ($ip) => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            throw ValidationException::withMessages(['source' => 'This source cannot be read safely. Open it externally or provide text and images.']);
        }
        $ip = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];

        return ['proxy' => '', 'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$ip}"], CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($handle, $total, $downloaded) => $total > $limit || $downloaded > $limit ? 1 : 0]];
    }

    public function groundedUrl(string $url): string
    {
        $url = $this->url($url);
        // Google may return a citation redirect rather than the publisher URL.
        // Resolve only that official wrapper, rechecking each public HTTPS hop.
        if (parse_url($url, PHP_URL_HOST) !== 'vertexaisearch.cloud.google.com') {
            return $url;
        }
        for ($hop = 0; $hop < 3; $hop++) {
            try {
                $response = Http::timeout(6)->connectTimeout(3)->withoutRedirecting()->withOptions($this->options($url, 32768))->head($url);
                if (! $response->redirect() || ! $response->header('Location')) {
                    return $url;
                }
                $url = $this->url($response->header('Location'));
                if (parse_url($url, PHP_URL_HOST) !== 'vertexaisearch.cloud.google.com') {
                    return $url;
                }
            } catch (\Throwable) {
                break;
            }
        }

        return $url;
    }

    public function fetch(string $url, bool $image = false): array
    {
        $url = $this->url($url);
        $limit = $image ? 5 * 1024 * 1024 : 2 * 1024 * 1024;
        try {
            for ($hop = 0; $hop <= 3; $hop++) {
                $r = Http::timeout(12)->connectTimeout(4)->withoutRedirecting()->withOptions($this->options($url, $limit))->get($url);
                if (! $r->redirect()) {
                    break;
                }
                if ($hop === 3 || ! $r->header('Location')) {
                    throw new \RuntimeException;
                }
                $next = (string) UriResolver::resolve(new Uri($url), new Uri($r->header('Location')));
                // Each hop revalidates HTTPS, public DNS and pinned destination IP.
                // No credentials/cookies are forwarded and HTTP redirects stay disabled.
                $url = $this->url($next);
            }
            $mime = strtolower(trim(explode(';', $r->header('Content-Type'))[0]));
            if (! $r->successful() || strlen($r->body()) > $limit || ($image
                ? ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
                : ! in_array($mime, ['text/html', 'text/plain'], true))) {
                throw new \RuntimeException;
            }

            return ['body' => $r->body(), 'mime' => $mime, 'url' => $url];
        } catch (\Throwable) {
            throw ValidationException::withMessages(['source' => 'Source could not be read (blocked, unsafe redirect, unsupported or too large). Open the source or provide text/images.']);
        }
    }

    public function article(string $url): array
    {
        $data = $this->fetch($url);
        $url = $data['url'];
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$data['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        $images = [];
        foreach ($xpath->query('//article//img | //main//img') as $img) {
            $src = $img->getAttribute('src');
            if (trim($src) === '') {
                continue;
            }
            try {
                $src = (string) UriResolver::resolve(new Uri($url), new Uri($src));
                $images[] = ['url' => $this->url($src), 'caption' => mb_substr($img->getAttribute('alt'), 0, 255)];
            } catch (\InvalidArgumentException|ValidationException) {
            }
            if (count($images) >= 8) {
                break;
            }
        }
        foreach ($xpath->query('//script|//style|//nav|//footer|//header') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $content = $xpath->query('//article|//main')->item(0) ?? $dom->documentElement;
        $text = mb_substr(trim(preg_replace('/\s+/u', ' ', $content->textContent) ?? ''), 0, 30000);
        if (mb_strlen($text) < 40) {
            throw ValidationException::withMessages(['source' => 'No readable article body was found. Provide source text instead.']);
        }

        return ['text' => $text, 'images' => $images, 'mode' => 'Article body read'];
    }
}
