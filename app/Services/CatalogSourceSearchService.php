<?php

namespace App\Services;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogSourceSearchService
{
    public function __construct(private readonly CatalogSourceReader $reader) {}

    public function status(): array
    {
        $articles = is_string(config('services.catalog_search.tavily_key'))
            && trim(config('services.catalog_search.tavily_key')) !== ''
            && config('services.catalog_search.tavily_free_confirmed');
        $videos = filled(config('services.youtube.data_api_key'));

        return ['articles' => (bool) $articles, 'videos' => $videos, 'available' => $articles || $videos,
            'article_key_present' => filled(trim((string) config('services.catalog_search.tavily_key'))),
            'article_free_confirmed' => (bool) config('services.catalog_search.tavily_free_confirmed')];
    }

    public function search(string $name, string $city, string $kind = 'all'): array
    {
        if (! in_array($kind, ['all', 'articles', 'videos'], true)) {
            throw ValidationException::withMessages(['search_kind' => 'Choose All, Articles or Videos.']);
        }
        $status = $this->status();
        // Search for the place first. Requiring every desired catalog field in
        // the query can exclude articles that only cover some of those fields.
        $query = mb_substr(trim($name).' '.trim($city).' Selangor', 0, 450);
        $sources = [];
        $notices = [];
        $irrelevant = 0;
        $attempted = false;
        foreach (['articles', 'videos'] as $type) {
            if ($kind !== 'all' && $kind !== $type) {
                continue;
            }
            if (! $status[$type]) {
                $notices[] = $type === 'articles'
                    ? (filled(trim((string) config('services.catalog_search.tavily_key')))
                        ? 'Article search is not configured: TAVILY_API_KEY is present, but CATALOG_SEARCH_TAVILY_FREE_CONFIRMED must be true after checking the account plan. No article request was sent.'
                        : 'Article search is not configured: set TAVILY_API_KEY on the running Laravel service. No article request was sent.')
                    : 'Video search is not configured. A YouTube Data API key is required.';

                continue;
            }
            $attempted = true;
            try {
                $limit = $kind === 'all' ? 4 : 8;
                if ($type === 'articles') {
                    $response = Http::acceptJson()->withToken(trim(config('services.catalog_search.tavily_key')))
                        ->connectTimeout(4)->timeout(20)->withoutRedirecting()->post('https://api.tavily.com/search', [
                            'query' => $query, 'search_depth' => 'basic', 'topic' => 'general', 'max_results' => $limit,
                            'auto_parameters' => false, 'include_answer' => false, 'include_raw_content' => false,
                            'include_images' => false, 'include_usage' => true, 'country' => 'malaysia',
                            'exclude_domains' => ['youtube.com', 'youtu.be', 'instagram.com', 'facebook.com', 'tiktok.com'],
                        ]);
                } else {
                    $response = Http::acceptJson()->withHeaders(['x-goog-api-key' => config('services.youtube.data_api_key')])
                        ->connectTimeout(4)->timeout(20)->withoutRedirecting()->get('https://www.googleapis.com/youtube/v3/search', [
                            'part' => 'snippet', 'type' => 'video', 'q' => $query, 'maxResults' => $limit,
                            'regionCode' => 'MY', 'safeSearch' => 'moderate',
                        ]);
                }
                if (! $response->successful()) {
                    $hint = match ($response->status()) {
                        401 => $type === 'articles'
                            ? 'Tavily rejected TAVILY_API_KEY. This Laravel process has a key and sent Bearer authentication. Correct TAVILY_API_KEY on the Railway Laravel service (not MySQL), then redeploy so its configuration cache receives the updated value. Do not share the key in chat.'
                            : 'The provider rejected the API key. Check the key on the running Laravel service.',
                        403 => 'The provider denied access. Check API restrictions and permissions.',
                        429 => 'The provider rate limit was reached. Wait before trying again.',
                        432, 433 => 'The provider usage or credit limit was reached. Check free quota; no paid fallback is used.',
                        400, 422 => 'The provider rejected the search parameters.',
                        default => 'The search provider is unavailable. Check its service status.',
                    };
                    $notices[] = ucfirst($type).' search failed (HTTP '.$response->status().'). '.$hint.' No retry or paid fallback was attempted.';

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
                    if ($card && $this->isRelevant($card, $name, $city)) {
                        $card['relevance'] = $this->relevance($card, $name, $city);
                        $sources[$card['url']] = $card;
                    } elseif ($card) {
                        $irrelevant++;
                    }
                }
                if (! collect($sources)->contains('type', $type === 'articles' ? 'article' : 'video')) {
                    $notices[] = ucfirst($type).' search succeeded (HTTP 200), but returned no usable sources. Refine the Market name and city; no sources were invented.';
                }
            } catch (\Throwable $exception) {
                // Never expose authentication, arbitrary provider errors or raw responses.
                $curlCode = 0;
                for ($cause = $exception; $cause; $cause = $cause->getPrevious()) {
                    if ($cause instanceof RequestException || $cause instanceof ConnectException) {
                        $curlCode = (int) ($cause->getHandlerContext()['errno'] ?? 0);
                    }
                }
                $reason = match ($curlCode) {
                    5, 6 => 'DNS resolution failed.',
                    7 => 'The outbound HTTPS connection was refused or blocked.',
                    28 => 'The connection or search response timed out.',
                    35, 51, 58, 60, 77 => 'TLS verification failed. Check the server CA certificates; do not disable verification.',
                    default => 'Check server connectivity and search configuration.',
                };
                $notices[] = ucfirst($type).' search could not be completed'.($curlCode ? ' (cURL '.$curlCode.')' : '').'. '.$reason.' No automatic retry was made.';
            }
        }
        if (! $attempted) {
            throw ValidationException::withMessages(['search' => implode(' ', $notices).' You can still add a source link, analyse it and review a draft.']);
        }
        if ($irrelevant) {
            $notices[] = $irrelevant.' result(s) were excluded because they did not identify the requested Market or city.';
        }

        $sources = collect($sources)->sortBy(function (array $source): string {
            $priority = match (true) {
                Str::contains($source['relevance'] ?? '', 'government/local authority') => '0',
                Str::contains($source['relevance'] ?? '', 'exact Market and city') => '1',
                default => '2',
            };
            $old = ! empty($source['old_source']) ? '1' : '0';
            $date = is_string($source['published_at'] ?? null) ? $source['published_at'] : '0000-00-00';

            return $priority.'|'.$old.'|'.(9999999999 - (int) strtotime($date ?: '1970-01-01'));
        })->values()->all();

        return ['sources' => $sources, 'search_suggestions' => null, 'notices' => $notices];
    }

    private function isRelevant(array $card, string $name, string $city): bool
    {
        $normalize = static fn (string $value): string => trim(preg_replace('/\s+/u', ' ', mb_strtolower(preg_replace('/[^\pL\pN]+/u', ' ', $value))));
        $haystack = $normalize(implode(' ', [$card['title'], $card['description'], $card['url']]));
        $market = $normalize($name);
        $city = $normalize($city);
        $tokens = array_values(array_filter(explode(' ', $market), fn ($token) => mb_strlen($token) >= 2
            && ! in_array($token, ['pasar', 'malam', 'night', 'market'], true)));

        $marketMatch = $market !== '' && str_contains($haystack, $market);
        if (! $marketMatch && $tokens) {
            $marketMatch = collect($tokens)->every(fn ($token) => preg_match('/(?<![\pL\pN])'.preg_quote($token, '/').'(?![\pL\pN])/u', $haystack) === 1);
        }

        // A short search preview may omit the target name. Exclude only a result
        // that explicitly names another night market; verify all evidence later.
        $title = $normalize($card['title']);
        if (preg_match('/\b(?:ghost|haunted|kemalangan|accident|murder|seram)\b/u', $haystack) === 1) {
            return false;
        }
        $namesAnotherMarket = preg_match('/\b(?:pasar malam|night market)\s+(?:di |at )?(.+)/u', $title, $matches) === 1
            && ! $marketMatch && $tokens !== []
            && ! collect($tokens)->every(fn ($token) => preg_match('/(?<![\pL\pN])'.preg_quote($token, '/').'(?![\pL\pN])/u', $matches[1]) === 1);

        $cityTokens = array_values(array_filter(explode(' ', $city), fn ($token) => mb_strlen($token) >= 3));
        $cityMatch = $cityTokens === [] || collect($cityTokens)->every(fn ($token) => str_contains($haystack, $token));

        return ! $namesAnotherMarket && ($marketMatch || ($cityMatch && $tokens !== []));
    }

    private function relevance(array $card, string $name, string $city): string
    {
        $text = Str::lower($card['title'].' '.$card['description'].' '.$card['url']);
        $host = Str::lower((string) parse_url($card['url'], PHP_URL_HOST));
        $official = str_ends_with($host, '.gov.my') || str_ends_with($host, '.gov') || Str::contains($host, ['mbpj', 'mbsa', 'mpkj', 'mpsj', 'mpklang']);
        $exact = Str::contains($text, Str::lower(trim($name))) && Str::contains($text, Str::lower(trim($city)));

        return $official ? 'High · government/local authority' : ($exact ? 'High · exact Market and city' : 'Medium · verify location before analysis');
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
        if (! $video) {
            $host = parse_url($url, PHP_URL_HOST);
            foreach (['youtube.com', 'youtu.be', 'instagram.com', 'facebook.com', 'tiktok.com'] as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return null;
                }
            }
        }
        $text = static fn ($value, $max) => is_string($value) ? mb_substr(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, $max) : '';
        $thumbnail = $video ? data_get($row, 'snippet.thumbnails.medium.url') : null;
        if (! is_string($thumbnail) || ! preg_match('~\Ahttps://i\.ytimg\.com/vi/[A-Za-z0-9_-]{11}/[A-Za-z0-9_.-]+\z~', $thumbnail)) {
            $thumbnail = null;
        }
        $date = $video ? data_get($row, 'snippet.publishedAt') : ($row['published_date'] ?? null);

        $old = false;
        if (is_string($date)) {
            try {
                $old = Carbon::parse($date)->lt(now()->subYears(5));
            } catch (\Throwable) {
            }
        }

        return ['url' => $url, 'title' => $text($video ? data_get($row, 'snippet.title') : ($row['title'] ?? ''), 500),
            'publisher' => $video ? $text(data_get($row, 'snippet.channelTitle'), 255) : parse_url($url, PHP_URL_HOST),
            'type' => $video ? 'video' : 'article', 'description' => $text($video ? data_get($row, 'snippet.description') : ($row['content'] ?? ''), 500),
            'published_at' => is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}(?:T[0-9:.+Z-]+)?\z/', $date) ? $date : null,
            'thumbnail' => $thumbnail, 'status' => 'Not analysed', 'old_source' => $old];
    }
}
