<?php

namespace App\Services;

use App\Exceptions\CatalogSuggestionException;
use App\Exceptions\SocialMediaMetadataException;
use App\Models\CatalogImportProposal;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\SocialMediaSource;
use App\Models\Stall;
use App\Models\User;
use App\Support\CatalogCategory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogAiImportService
{
    public function __construct(private readonly CatalogImportProposalService $proposals,
        private readonly GeminiCatalogSourceService $sources, private readonly CatalogSourceReader $reader,
        private readonly CatalogSuggestionExtractionService $extraction, private readonly CatalogCategoryService $categories,
        private readonly CatalogSourceSearchService $searchProvider) {}

    public function context(array $input): array
    {
        $market = empty($input['market_id']) ? null : NightMarket::query()->publiclyVisible()->find($input['market_id']);
        $stall = empty($input['stall_id']) ? null : Stall::query()->where('status', 'active')->find($input['stall_id']);
        if ((! empty($input['market_id']) && ! $market) || (! empty($input['stall_id']) && (! $stall || $stall->night_market_id !== $market?->id))) {
            throw ValidationException::withMessages(['market_id' => 'Choose an active Selangor Market and a Stall belonging to it.']);
        }

        return ['module' => $input['module'] ?? 'night-markets', 'market_id' => $market?->id, 'stall_id' => $stall?->id,
            'name' => $market?->name ?? trim($input['name'] ?? ''), 'city' => $market?->city ?? trim($input['city'] ?? ''), 'state' => 'Selangor'];
    }

    public function search(User $user, array $input): string
    {
        $context = $this->context($input);
        if (! $context['name'] || ! $context['city']) {
            throw ValidationException::withMessages(['name' => 'Enter a market name and city to distinguish places with similar names.']);
        }
        $kind = $input['search_kind'] ?? 'all';
        $key = 'ai-import-search:'.$user->id.':'.hash('sha256', json_encode([$context, $kind, $this->searchProvider->status()]));

        return Cache::lock($key.':lock', 60)->get(function () use ($key, $context, $user, $kind) {
            if ($cached = Cache::get($key)) {
                return $cached;
            }
            $result = $this->searchProvider->search($context['name'], $context['city'], $kind);
            $id = (string) Str::uuid();
            Cache::put('ai-import-results:'.$user->id.':'.$id, [...$result, 'context' => $context, 'search_kind' => $kind], 1800);
            Cache::put($key, $id, 300);

            return $id;
        }) ?: throw ValidationException::withMessages(['search' => 'Search is already running. Please wait.']);
    }

    public function results(User $user, ?string $id): ?array
    {
        return $id ? Cache::get('ai-import-results:'.$user->id.':'.$id) : null;
    }

    public function start(User $user, array $input): CatalogImportProposal
    {
        $result = $this->results($user, $input['search_id'] ?? null);
        if (! empty($input['search_id']) && ! $result) {
            throw ValidationException::withMessages(['source' => 'Search results expired or are unavailable in this account. Search again.']);
        }
        $context = $result['context'] ?? $this->context($input);
        if ($context['module'] !== 'night-markets' && ! $context['market_id']) {
            throw ValidationException::withMessages(['market_id' => 'Choose the target Night Market before importing Stalls or Foods.']);
        }
        if (! $context['name'] || ! $context['city']) {
            throw ValidationException::withMessages(['name' => 'Provide the Night Market name and city so source ownership can be reviewed.']);
        }
        $selected = [];
        foreach (array_unique($input['source_ids'] ?? []) as $i) {
            if (! isset($result['sources'][$i])) {
                throw ValidationException::withMessages(['source' => 'Search results expired. Search again before selecting a source.']);
            }
            $selected[] = $result['sources'][$i];
        }
        if (! empty($input['url'])) {
            $selected[] = $this->sourceCard($input['url']);
        }
        if (! $selected) {
            throw ValidationException::withMessages(['source' => 'Select a source or paste its URL.']);
        }
        $url = $selected[0]['url'];
        $canonical = ['platform' => $selected[0]['type'] === 'video' ? 'youtube' : 'web', 'canonical_url' => $url,
            'url_fingerprint' => hash('sha256', $url), 'external_content_id' => null];
        if ($canonical['platform'] === 'youtube') {
            $canonical = app(YouTubeVideoUrlCanonicalizer::class)->canonicalize($url);
        }
        $proposal = $this->proposals->createSourceDraft($user, [
            'target_type' => $context['stall_id'] ? 'existing_stall' : ($context['market_id'] ? 'existing_market' : 'new_market'),
            'matched_night_market_id' => $context['market_id'], 'matched_stall_id' => $context['stall_id'],
        ], $canonical);
        if ($proposal->matched_night_market_id !== $context['market_id'] || $proposal->matched_stall_id !== $context['stall_id']) {
            throw ValidationException::withMessages(['source' => 'This source already has a draft for another target. Open Drafts to review it.']);
        }
        if (! $this->data($proposal)) {
            if (! $proposal->wasRecentlyCreated) {
                // Reopen legacy drafts intact; never replace their existing review snapshot.
                return $proposal;
            }
            $this->persist($proposal, ['context' => $context, 'sources' => $selected, 'graph' => ['market' => ['name' => $context['name'], 'city' => $context['city'], 'state' => 'Selangor'], 'operating_days' => [], 'stalls' => []]]);
        }

        return $proposal;
    }

    public function sourceCard(string $url): array
    {
        $url = $this->reader->url($url);
        $video = in_array(parse_url($url, PHP_URL_HOST), ['youtube.com', 'www.youtube.com', 'youtu.be'], true);
        if ($video) {
            $url = app(YouTubeVideoUrlCanonicalizer::class)->canonicalize($url)['canonical_url'];
        }

        return ['url' => $url, 'title' => parse_url($url, PHP_URL_HOST), 'publisher' => parse_url($url, PHP_URL_HOST),
            'type' => $video ? 'video' : 'article', 'status' => 'Not analysed', 'thumbnail' => null, 'published_at' => null, 'description' => 'Admin-provided source'];
    }

    public function data(CatalogImportProposal $proposal): ?array
    {
        return $proposal->review_metadata_snapshot['ai_import'] ?? null;
    }

    public function revision(CatalogImportProposal $proposal): string
    {
        return hash('sha256', json_encode($this->data($proposal)));
    }

    private function persist(CatalogImportProposal $proposal, array $data): void
    {
        // Namespaced, bounded source/review metadata. No new schema or raw provider response.
        $proposal->forceFill(['review_metadata_snapshot' => ['ai_import' => $data]])->save();
    }

    private function editable(CatalogImportProposal $proposal): void
    {
        if ($proposal->status !== 'draft' || ! $this->data($proposal)) {
            throw ValidationException::withMessages(['draft' => 'This draft is not editable. Its history is preserved.']);
        }
    }

    private function persistAnalysis(CatalogImportProposal $proposal, array $data, string $revision): void
    {
        DB::transaction(function () use ($proposal, $data, $revision) {
            $current = CatalogImportProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $this->editable($current);
            if (! hash_equals($this->revision($current), $revision)) {
                throw ValidationException::withMessages(['draft' => 'This draft changed while analysis was running. Your saved edits were preserved. Reload before analysing again.']);
            }
            $this->persist($current, $data);
        });
    }

    public function analyse(CatalogImportProposal $proposal, array $input, ?UploadedFile $screenshot = null): void
    {
        try {
            Cache::lock('ai-import-analysis:'.$proposal->id, 240)->block(1, function () use ($proposal, $input, $screenshot) {
                $proposal->refresh();
                $this->editable($proposal);
                $data = $this->data($proposal);
                $revision = $this->revision($proposal);
                $indices = $input['source_ids'] ?? [];
                if (! empty($input['url'])) {
                    $card = $this->sourceCard($input['url']);
                    if (! collect($data['sources'])->contains('url', $card['url'])) {
                        $data['sources'][] = $card;
                    }
                    $indices[] = collect($data['sources'])->search(fn ($s) => $s['url'] === $card['url']);
                }
                if (count($data['sources']) > 8) {
                    throw ValidationException::withMessages(['source' => 'Use at most eight sources per draft.']);
                }
                $indices = array_unique($indices);
                if (! $indices || count($indices) > 3 || ((filled($input['text'] ?? null) || $screenshot) && count($indices) !== 1)) {
                    throw ValidationException::withMessages(['source' => 'Select one to three sources. For text or a screenshot, select exactly one source.']);
                }
                if (! filled($input['text'] ?? null) && ! $screenshot
                    && collect($indices)->contains(fn ($i) => ($data['sources'][$i]['type'] ?? null) === 'video')) {
                    if (count($indices) !== 1) {
                        throw ValidationException::withMessages(['source' => 'Analyse one video segment at a time. Other sources and draft edits are preserved.']);
                    }
                    $this->sources->videoRange($input);
                }
                foreach (array_unique($indices) as $i) {
                    if (! isset($data['sources'][$i])) {
                        throw ValidationException::withMessages(['source' => 'Select an existing source.']);
                    }
                    $source = &$data['sources'][$i];
                    $videoRange = $source['type'] === 'video' && ! filled($input['text'] ?? null) && ! $screenshot ? $this->sources->videoRange($input) : null;
                    $hash = hash('sha256', $source['url'].($input['text'] ?? '').($screenshot ? hash_file('sha256', $screenshot->getRealPath()) : '').($videoRange ? json_encode($videoRange) : ''));
                    if (($source['analysed_hash'] ?? null) === $hash) {
                        continue;
                    }
                    try {
                        if ($source['type'] === 'video' && empty($source['metadata_checked'])) {
                            // Optional display metadata never supplies video evidence or Food photos.
                            $source['metadata_checked'] = true;
                            if (filled(config('services.youtube.data_api_key'))) {
                                try {
                                    $canonical = app(YouTubeVideoUrlCanonicalizer::class)->canonicalize($source['url']);
                                    $metadata = app(YouTubeMetadataProvider::class)->fetch(new SocialMediaSource($canonical));
                                    $source['title'] = $metadata->title;
                                    $source['publisher'] = $metadata->creatorName;
                                    $source['thumbnail'] = $metadata->thumbnailUrl;
                                    $source['published_at'] = $metadata->publishedAt->toDateString();
                                } catch (SocialMediaMetadataException) {
                                    $source['metadata_status'] = 'Preview metadata unavailable; content analysis is separate.';
                                }
                            }
                        }
                        $read = filled($input['text'] ?? null) ? ['text' => $input['text'], 'images' => [], 'mode' => 'Admin-provided text analysed']
                            : $this->sources->read($source['url'], $screenshot ? ['mime' => $screenshot->getMimeType(), 'body' => $screenshot->get()] : null, $input);
                        $source['text'] = $read['text'];
                        $source['images'] = $read['images'];
                        $source['status'] = $read['mode'];
                        $source['video_range'] = $read['video_range'] ?? null;
                        $graph = $this->extraction->extractReadContent($proposal, $read['text']);
                        if (count($data['graph']['stalls']) + count($graph['stalls']) > 30
                            || collect([...$data['graph']['stalls'], ...$graph['stalls']])->sum(fn ($s) => count($s['foods'])) > 30) {
                            throw ValidationException::withMessages(['source' => 'A draft supports at most 30 Stalls and 30 Foods, including source variants. Review this draft and use a separate draft for additional sources.']);
                        }
                        $source['analysed_hash'] = $hash;
                        if (empty($data['market_reviewed']) && ! $data['graph']['stalls']) {
                            $data['graph']['market'] = $graph['market'];
                            $data['graph']['operating_days'] = $graph['operating_days'];
                        }
                        foreach ($graph['stalls'] as $stall) {
                            $stall['selected'] = false;
                            $stall['parent_confirmed'] = false;
                            $stall['source_url'] = $source['url'];
                            foreach ($stall['foods'] as &$food) {
                                $food['selected'] = false;
                                $food['currency'] = 'MYR';
                                $food['source_url'] = $source['url'];
                                $food['category'] = CatalogCategory::canonical($food['category'], 'food');
                            } unset($food);
                            // Keep source variants separate; Admin explicitly links or skips duplicates.
                            $data['graph']['stalls'][] = $stall;
                        }
                    } catch (\Throwable $e) {
                        $source['status'] = isset($source['text']) ? 'Source read; catalog suggestions unavailable. Review the extracted text.' : 'Analysis unavailable. Open source or provide text/screenshots.';
                        $this->persistAnalysis($proposal, $data, $revision);
                        throw ValidationException::withMessages(['source' => $e instanceof ValidationException ? $e->validator->errors()->first()
                            : ($e instanceof CatalogSuggestionException ? $this->extraction->failureMessage($e->failureCode)
                                .($e->httpStatus ? ' (HTTP '.$e->httpStatus.', '.$e->failureCode.').' : '') : $source['status'])]);
                    } unset($source);
                }
                $this->persistAnalysis($proposal, $data, $revision);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['source' => 'Analysis is already running for this draft. Wait for it to finish before trying again.']);
        }
    }

    public function saveDraft(CatalogImportProposal $proposal, array $input): void
    {
        $newImages = [];
        $removedImages = [];
        try {
            DB::transaction(function () use ($proposal, $input, &$newImages, &$removedImages) {
                $proposal = CatalogImportProposal::query()->lockForUpdate()->findOrFail($proposal->id);
                $this->editable($proposal);
                if (! hash_equals($this->revision($proposal), $input['revision'] ?? '')) {
                    throw ValidationException::withMessages(['draft' => 'This draft changed in another tab. Reload before editing.']);
                }
                $data = $this->data($proposal);
                if (isset($input['market']) || isset($input['operating_days'])) {
                    $data['market_reviewed'] = true;
                }
                foreach (['name', 'address', 'city', 'matched_night_market_id', 'selected'] as $key) {
                    if (array_key_exists($key, $input['market'] ?? [])) {
                        $data['graph']['market'][$key] = $input['market'][$key];
                    }
                }
                if (! $data['context']['market_id'] && array_key_exists('operating_days', $input)) {
                    $data['graph']['operating_days'] = collect($input['operating_days'])->filter(fn ($d) => ! empty($d['selected']))
                        ->map(fn ($d) => Arr::only($d, ['day_of_week', 'opening_time', 'closing_time', 'evidence_text']))->values()->all();
                }
                foreach ($data['graph']['stalls'] as $i => &$stall) {
                    $edit = $input['stalls'][$i] ?? [];
                    foreach (['name', 'matched_stall_id'] as $key) {
                        if (array_key_exists($key, $edit)) {
                            $stall[$key] = $edit[$key];
                        }
                    }
                    $stall['selected'] = (bool) ($edit['selected'] ?? false);
                    $stall['parent_confirmed'] = (bool) ($edit['parent_confirmed'] ?? false);
                    foreach ($stall['foods'] as $j => &$food) {
                        $row = $edit['foods'][$j] ?? [];
                        $oldImage = $food['image_path'] ?? null;
                        foreach (['name', 'category', 'description', 'price_min', 'price_max', 'currency', 'unit', 'price_checked_at', 'matched_food_id'] as $key) {
                            if (array_key_exists($key, $row)) {
                                $food[$key] = $row[$key];
                            }
                        }
                        $food['category'] = CatalogCategory::canonical($food['category'] ?? null, 'food');
                        $food['selected'] = (bool) ($row['selected'] ?? false);
                        $food['photo_confirmed'] = (bool) ($row['photo_confirmed'] ?? false);
                        if (! empty($row['remove_image'])) {
                            unset($food['image_path'], $food['image_source']);
                            $food['photo_confirmed'] = false;
                        }
                        $upload = $row['image'] ?? null;
                        if ($upload instanceof UploadedFile) {
                            $food['image_path'] = app(CatalogDraftImageStorage::class)->disk()->putFile('ai-import/'.$proposal->id, $upload);
                            unset($food['image_source']);
                        } elseif (! empty($row['candidate_image'])) {
                            $allowed = collect($data['sources'])->flatMap(fn ($s) => $s['images'] ?? [])->pluck('url');
                            if (! $allowed->contains($row['candidate_image']) || ! $food['photo_confirmed']) {
                                throw ValidationException::withMessages(['image' => 'Choose a source image and confirm its relevance and permission.']);
                            }
                            $image = $this->reader->fetch($row['candidate_image'], true);
                            if (@getimagesizefromstring($image['body']) === false) {
                                throw ValidationException::withMessages(['image' => 'The source did not return a usable image.']);
                            }
                            $path = 'ai-import/'.$proposal->id.'/'.Str::uuid().'.'.(['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$image['mime']]);
                            app(CatalogDraftImageStorage::class)->disk()->put($path, $image['body']);
                            $food['image_path'] = $path;
                            $food['image_source'] = $row['candidate_image'];
                        }
                        if (($food['image_path'] ?? null) !== $oldImage) {
                            if ($oldImage) {
                                $removedImages[] = $oldImage;
                            }
                            if (! empty($food['image_path'])) {
                                $newImages[] = $food['image_path'];
                            }
                        }
                    } unset($food);
                } unset($stall);
                $this->persist($proposal, $data);
                DB::afterCommit(function () use ($proposal, $removedImages) {
                    foreach ($removedImages as $path) {
                        $this->deleteDraftImage($proposal, $path);
                    }
                });
            });
        } catch (\Throwable $e) {
            foreach ($newImages as $path) {
                $this->deleteDraftImage($proposal, $path);
            }
            throw $e;
        }
    }

    private function deleteDraftImage(CatalogImportProposal $proposal, string $path): void
    {
        if (preg_match('~\Aai-import/'.preg_quote((string) $proposal->id, '~').'/[a-zA-Z0-9-]+\.(?:jpg|jpeg|png|webp)\z~', $path)) {
            app(CatalogDraftImageStorage::class)->disk()->delete($path);
        }
    }

    public function review(CatalogImportProposal $proposal): array
    {
        $data = $this->data($proposal);
        abort_unless($data, 404);
        $categories = $this->categories->activeForType('food')->pluck('name')->all();
        $marketId = $data['context']['market_id'] ?? $data['graph']['market']['matched_night_market_id'] ?? null;
        $targetMarket = $marketId ? NightMarket::query()->find($marketId) : null;
        $existingStalls = $marketId ? Stall::query()->where('night_market_id', $marketId)->with('foods')->get() : collect();
        foreach ($data['graph']['stalls'] as &$stall) {
            $stall['missing'] = array_values(array_filter([empty($stall['name']) ? 'Stall name' : null, empty($stall['parent_confirmed']) ? 'Confirm Market ownership' : null]));
            $stall['duplicates'] = $existingStalls->filter(fn ($s) => CatalogCategory::key($s->name) === CatalogCategory::key($stall['name']))->pluck('id')->all();
            foreach ($stall['foods'] as &$food) {
                $food['missing'] = array_values(array_filter([empty($food['name']) ? 'Food name' : null,
                    ! in_array($food['category'] ?? null, $categories, true) ? 'Category' : null,
                    ! is_numeric($food['price_min'] ?? null) || ! is_numeric($food['price_max'] ?? null) || $food['price_min'] <= 0 || $food['price_max'] < $food['price_min'] ? 'Valid price / range' : null,
                    empty($food['image_path']) || ! app(CatalogDraftImageStorage::class)->disk()->exists($food['image_path']) || empty($food['photo_confirmed']) ? 'Confirmed photo' : null]));
                $target = $existingStalls->firstWhere('id', $stall['matched_stall_id'] ?? null);
                $food['duplicates'] = $target ? $target->foods->filter(fn ($f) => CatalogCategory::key($f->name) === CatalogCategory::key($food['name']))->pluck('id')->all() : [];
            } unset($food);
        } unset($stall);

        return [...$data, 'existingStalls' => $existingStalls, 'categories' => $categories,
            'marketName' => $targetMarket?->name ?? $data['graph']['market']['name'] ?? 'Market identity incomplete',
            'marketCity' => $targetMarket?->city ?? $data['graph']['market']['city'] ?? ''];
    }

    public function candidateImage(CatalogImportProposal $proposal, int $source, int $image): array
    {
        $url = $this->data($proposal)['sources'][$source]['images'][$image]['url'] ?? null;
        abort_unless(is_string($url), 404);

        return $this->reader->fetch($url, true);
    }

    public function import(User $user, CatalogImportProposal $proposal, array $input): array
    {
        return DB::transaction(function () use ($user, $proposal, $input) {
            $proposal = CatalogImportProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($proposal->status === 'imported' && isset($this->data($proposal)['import_result'])) {
                return $this->data($proposal)['import_result'];
            }
            $this->editable($proposal);
            if (empty($input['confirm']) || ! hash_equals($this->revision($proposal), $input['revision'] ?? '')) {
                throw ValidationException::withMessages(['draft' => 'Confirm the current review before importing.']);
            }
            $review = $this->review($proposal);
            $selected = [];
            foreach ($review['graph']['stalls'] as $i => $stall) {
                if (empty($stall['selected'])) {
                    continue;
                }
                $foods = collect($stall['foods'])->where('selected', true)->values()->all();
                if ($stall['missing'] || collect($foods)->contains(fn ($f) => $f['missing'] && empty($f['matched_food_id']))) {
                    throw ValidationException::withMessages(['draft' => 'Selected items have missing fields. Complete them or deselect only those items; other drafts are preserved.']);
                }
                $stall['foods'] = $foods;
                $selected[] = $stall;
            }
            if (! $selected && (! empty($review['context']['market_id']) || ! empty($review['graph']['market']['matched_night_market_id']) || empty($review['graph']['market']['selected']))) {
                throw ValidationException::withMessages(['draft' => 'Select complete Stalls/Foods or explicitly select a complete new Market.']);
            }
            $graph = $review['graph'];
            $graph['stalls'] = $selected;

            return app(CatalogImportProposalImportService::class)->importReviewedSelection($user, $proposal, $graph);
        });
    }
}
