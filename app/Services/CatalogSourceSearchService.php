<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class CatalogSourceSearchService
{
    public function __construct(private readonly CatalogSourceReader $reader) {}

    public function status(): array
    {
        $articles = filled(config('services.catalog_search.tavily_key')) && config('services.catalog_search.tavily_free_confirmed');
        $videos = filled(config('services.youtube.data_api_key'));

        return ['articles' => (bool) $articles, 'videos' => $videos, 'available' => $articles || $videos];
    }

    public function search(string $name, string $city, string $kind = 'all'): array
    {
        if (! in_array($kind, ['all', 'articles', 'videos'], true)) {
            throw ValidationException::withMessages(['search_kind' => 'Choose All, Articles or Videos.']);
        }
        $status = $this->status();
        $query = mb_substr(trim($name).' '.trim($city).' Selangor night market pasar malam', 0, 450);
        $sources = [];
        $notices = [];
        $attempted = false;
        foreach (['articles', 'videos'] as $type) {
            if ($kind !== 'all' && $kind !== $type) {
                continue;
            }
            if (! $status[$type]) {
                $notices[] = $type === 'articles' ? 'Article search is not configured. A Tavily Free account key and free-plan confirmation are required.' : 'Video search is not configured. A YouTube Data API key is required.';

                continue;
            }
            $attempted = true;
            try {
                $limit = $kind === 'all' ? 4 : 8;
                if ($type === 'articles') {
                    $response = Http::acceptJson()->withToken(config('services.catalog_search.tavily_key'))
                        ->connectTimeout(4)->timeout(20)->withoutRedirecting()->post('https://api.tavily.com/search', [
                            'query' => $query, 'search_depth' => 'basic', 'topic' => 'general', 'max_results' => $limit,
                            'auto_parameters' => false, 'include_answer' => false, 'include_raw_content' => false,
                            'include_images' => false, 'include_usage' => true, 'exclude_domains' => ['youtube.com', 'youtu.be'],
                        ]);
                } else {
                    $response = Http::acceptJson()->withHeaders(['x-goog-api-key' => config('services.youtube.data_api_key')])
                        ->connectTimeout(4)->timeout(20)->withoutRedirecting()->get('https://www.googleapis.com/youtube/v3/search', [
                            'part' => 'snippet', 'type' => 'video', 'q' => $query, 'maxResults' => $limit,
                            'regionCode' => 'MY', 'safeSearch' => 'moderate',
                        ]);
                }
                if (! $response->successful()) {
                    $notices[] = ucfirst($type).' search failed (HTTP '.$response->status().'). Check API access and free quota. No retry or paid fallback was attempted.';

                    continue;
                }
                $rows = $response->json($type === 'articles' ? 'results' : 'items');
                if (! is_array($rows)) {
                    $notices[] = ucfirst($type).' search returned an invalid response. No sources were invented.';

                    continue;
                }
                foreach (array_slice($rows, 0, $limit) as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $card = $this->card($row, $type);
                    if ($card) {
                        $sources[$card['url']] = $card;
                    }
                }
            } catch (\Throwable) {
                // Never expose authentication, arbitrary provider errors or raw responses.
                $notices[] = ucfirst($type).' search could not be completed. Check connectivity. No automatic retry was made.';
            }
        }
        if (! $attempted) {
            throw ValidationException::withMessages(['search' => implode(' ', $notices).' You can still add a source link, analyse it and review a draft.']);
        }

        return ['sources' => array_values($sources), 'search_suggestions' => null, 'notices' => $notices];
    }

    private function card(array $row, string $type): ?array
    {
        $video = $type === 'videos';
        $id = data_get($row, 'id.videoId');
        if ($video && (! is_string($id) || ! preg_match('/\A[A-Za-z0-9_-]{11}\z/', $id))) {
            return null;
        }
        $rawUrl = $video ? 'https://www.youtube.com/watch?v='.$id : ($row['url'] ?? null);
        if (! is_string($rawUrl)) {
            return null;
        }
        try {
            $url = $this->reader->url($rawUrl);
        } catch (ValidationException) {
            return null;
        }
        if (! $video && in_array(parse_url($url, PHP_URL_HOST), ['youtube.com', 'www.youtube.com', 'youtu.be'], true)) {
            return null;
        }
        $text = static fn ($value, $max) => is_string($value) ? mb_substr(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, $max) : '';
        $thumbnail = $video ? data_get($row, 'snippet.thumbnails.medium.url') : null;
        if (! is_string($thumbnail) || ! preg_match('~\Ahttps://i\.ytimg\.com/vi/[A-Za-z0-9_-]{11}/[A-Za-z0-9_.-]+\z~', $thumbnail)) {
            $thumbnail = null;
        }
        $date = $video ? data_get($row, 'snippet.publishedAt') : ($row['published_date'] ?? null);

        return ['url' => $url, 'title' => $text($video ? data_get($row, 'snippet.title') : ($row['title'] ?? ''), 500),
            'publisher' => $video ? $text(data_get($row, 'snippet.channelTitle'), 255) : parse_url($url, PHP_URL_HOST),
            'type' => $video ? 'video' : 'article', 'description' => $text($video ? data_get($row, 'snippet.description') : ($row['content'] ?? ''), 500),
            'published_at' => is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}(?:T[0-9:.+Z-]+)?\z/', $date) ? $date : null,
            'thumbnail' => $thumbnail, 'status' => 'Not analysed'];
    }
}
