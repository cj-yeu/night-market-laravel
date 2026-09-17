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
        $mode = $input['import_mode'] ?? null;
        if ($mode === 'new_market' && (! empty($input['market_id']) || ! empty($input['stall_id']))) {
            throw ValidationException::withMessages(['market_id' => 'New Market mode cannot target an existing Market or Stall. Choose the intended mode.']);
        }
        if ($mode === 'existing_market' && empty($input['market_id'])) {
            throw ValidationException::withMessages(['market_id' => 'Select the exact existing Night Market.']);
        }
        $market = empty($input['market_id']) ? null : NightMarket::query()->publiclyVisible()->find($input['market_id']);
        $stall = empty($input['stall_id']) ? null : Stall::query()->where('status', 'active')->find($input['stall_id']);
        if ((! empty($input['market_id']) && ! $market) || (! empty($input['stall_id']) && (! $stall || $stall->night_market_id !== $market?->id))) {
            throw ValidationException::withMessages(['market_id' => 'Choose an active Selangor Market and a Stall belonging to it.']);
        }

        return ['import_mode' => $mode, 'module' => $input['module'] ?? 'night-markets', 'market_id' => $market?->id, 'stall_id' => $stall?->id,
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
            if ($result['sources'] !== [] && $result['notices'] === []) {
                Cache::put($key, $id, 300);
            }

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
        if ($context['module'] !== 'night-markets' && ! $context['market_id'] && ($context['import_mode'] ?? null) !== 'new_market') {
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
        $previous = $this->data($proposal);
        if ($previous && ! $context['market_id'] &&
            (CatalogCategory::key($previous['context']['name']) !== CatalogCategory::key($context['name'])
                || CatalogCategory::key($previous['context']['city']) !== CatalogCategory::key($context['city']))) {
            throw ValidationException::withMessages(['source' => 'This source already has saved work for a different Market identity. Review it in Import History & Saved Work; it has not been overwritten.']);
        }
        if (! $this->data($proposal)) {
            if (! $proposal->wasRecentlyCreated) {
                // Reopen legacy drafts intact; never replace their existing review snapshot.
                return $proposal;
            }
            $this->persist($proposal, ['context' => $context, 'sources' => $selected, 'graph' => ['market' => ['name' => $context['name'], 'city' => $context['city'], 'state' => 'Selangor', 'selected' => ! $context['market_id']], 'operating_days' => [], 'stalls' => []]]);
        }

        return $proposal;
    }

    public function prepare(User $user, array $input, ?UploadedFile $screenshot = null): array
    {
        $result = $this->results($user, $input['search_id'] ?? null);
        if (! empty($input['search_id']) && ! $result) {
            throw ValidationException::withMessages(['source' => 'Search results expired. Search again before analysing.']);
        }
        $context = $result['context'] ?? $this->context($input);
        if (! in_array($context['import_mode'] ?? null, ['new_market', 'existing_market'], true)) {
            throw ValidationException::withMessages(['import_mode' => 'Choose whether to create a new Market or add to an existing Market.']);
        }
        $cards = [];
        foreach (array_unique($input['source_ids'] ?? []) as $index) {
            if (! isset($result['sources'][$index])) {
                throw ValidationException::withMessages(['source' => 'Select a source from your current search results.']);
            }
            $cards[] = $result['sources'][$index];
        }
        if (! empty($input['url'])) {
            $cards[] = $this->sourceCard($input['url']);
        }
        $cards = collect($cards)->unique('url')->values();
        if ($cards->isEmpty() || $cards->count() > 3 || (($screenshot || filled($input['text'] ?? null)) && $cards->count() !== 1)) {
            throw ValidationException::withMessages(['source' => 'Select one to three sources, or exactly one source for supplied text or a screenshot.']);
        }
        if (! $screenshot && ! filled($input['text'] ?? null) && $cards->contains('type', 'video')) {
            if ($cards->count() !== 1) {
                throw ValidationException::withMessages(['source' => 'Analyse one video segment at a time. You can add more sources from Review Import.']);
            }
            $this->sources->videoRange($input);
        }
        $key = 'ai-import-prepare:'.$user->id.':'.hash('sha256', json_encode([$context, $cards->pluck('url')->all()]));

        return Cache::lock($key.':lock', 240)->get(function () use ($user, $input, $screenshot, $cards, $key) {
            $proposal = Cache::get($key) ? CatalogImportProposal::find(Cache::get($key)) : null;
            $proposal ??= $this->start($user, $input);
            if (! $this->data($proposal)) {
                throw ValidationException::withMessages(['source' => 'This source has a legacy draft. Open it in Import History & Saved Work.']);
            }
            Cache::put($key, $proposal->id, 1800);
            if ($proposal->status === 'imported') {
                return ['proposal' => $proposal, 'errors' => []];
            }
            $data = $this->data($proposal);
            $indices = [];
            foreach ($cards as $card) {
                $index = collect($data['sources'])->search(fn ($s) => $s['url'] === $card['url']);
                if ($index === false) {
                    throw ValidationException::withMessages(['source' => 'This source already has saved work. Open it in Import History & Saved Work to add another source without replacing edits.']);
                }
                $indices[] = $index;
            }
            try {
                $this->analyse($proposal, [...$input, 'url' => null, 'source_ids' => $indices, 'select_extracted' => true], $screenshot);
                $errors = [];
            } catch (ValidationException $e) {
                // Preserve recoverable work and expose the failure, never a false success.
                $errors = $e->errors();
            }

            return ['proposal' => $proposal->refresh(), 'errors' => $errors];
        }) ?: throw ValidationException::withMessages(['source' => 'Analysis is already running. Please wait.']);
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
                $analysisErrors = [];
                $analysed = 0;
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
                            : $this->sources->read($source['url'], $screenshot ? ['mime' => $screenshot->getMimeType(), 'body' => $screenshot->get()] : null, $input, $data['context']);
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
                        $this->mergeMarketAnalysis($data, $graph, $source['url']);
                        foreach ($graph['stalls'] as $stall) {
                            $stall['selected'] = ! empty($input['select_extracted']);
                            $stall['parent_confirmed'] = false;
                            $stall['source_url'] = $source['url'];
                            foreach ($stall['foods'] as &$food) {
                                $food['selected'] = ! empty($input['select_extracted']);
                                $food['currency'] = 'MYR';
                                $food['source_url'] = $source['url'];
                                $food['category'] = CatalogCategory::canonical($food['category'], 'food');
                            } unset($food);
                            // Keep source variants separate; Admin explicitly links or skips duplicates.
                            $data['graph']['stalls'][] = $stall;
                        }
                        $analysed++;
                    } catch (\Throwable $e) {
                        $source['status'] = isset($source['text']) ? 'Source read; catalog suggestions unavailable. Review the extracted text.' : 'Analysis unavailable. Open source or provide text/screenshots.';
                        $analysisErrors[] = '“'.($source['title'] ?: parse_url($source['url'], PHP_URL_HOST)).'”: '.($e instanceof ValidationException ? $e->validator->errors()->first()
                            : ($e instanceof CatalogSuggestionException ? $this->extraction->failureMessage($e->failureCode)
                                .($e->httpStatus ? ' (HTTP '.$e->httpStatus.', '.$e->failureCode.').' : '') : $source['status']));
                    } unset($source);
                }
                $this->persistAnalysis($proposal, $data, $revision);
                if ($analysisErrors) {
                    $summary = $analysed
                        ? $analysed.' selected source(s) were analysed. '.count($analysisErrors).' source(s) failed independently: '
                        : 'The selected source(s) could not be analysed: ';
                    throw ValidationException::withMessages(['source' => $summary.implode(' ', $analysisErrors)]);
                }
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['source' => 'Analysis is already running for this draft. Wait for it to finish before trying again.']);
        }
    }

    private function mergeMarketAnalysis(array &$data, array $graph, string $sourceUrl): void
    {
        if (! empty($data['context']['market_id']) || ! empty($data['graph']['market']['matched_night_market_id'])) {
            return;
        }

        $current = $data['graph']['market'] ?? [];
        $incoming = $graph['market'] ?? [];
        foreach (['name', 'address', 'city', 'description', 'evidence_text', 'confidence'] as $field) {
            if (in_array($field, $data['market_edited_fields'] ?? [], true)) {
                continue;
            }
            if (($current[$field] ?? null) === null || trim((string) ($current[$field] ?? '')) === '') {
                if (($incoming[$field] ?? null) !== null && trim((string) $incoming[$field]) !== '') {
                    $current[$field] = $incoming[$field];
                }
            }
        }
        $current['state'] = 'Selangor';
        $data['graph']['market'] = $current;

        if (filled($incoming['evidence_text'] ?? null)) {
            $data['market_sources'][$sourceUrl] = $incoming['evidence_text'];
        }
        if (filled($current['address'] ?? null) && filled($incoming['address'] ?? null)
            && CatalogCategory::key($current['address']) !== CatalogCategory::key($incoming['address'])) {
            $data['market_conflicts']['address'] = 'Sources report different Market addresses. Compare the source evidence before importing.';
        }
        if (! empty($data['operating_days_reviewed'])) {
            return;
        }

        $days = collect($data['graph']['operating_days'] ?? [])->keyBy('day_of_week');
        $existingSchedule = $days->map(fn ($day) => [$day['opening_time'] ?? null, $day['closing_time'] ?? null])->all();
        $incomingSchedule = collect($graph['operating_days'] ?? [])->keyBy('day_of_week')
            ->map(fn ($day) => [$day['opening_time'] ?? null, $day['closing_time'] ?? null])->all();
        if ($existingSchedule && $incomingSchedule && $existingSchedule !== $incomingSchedule) {
            $data['market_conflicts']['schedule'] = 'Sources report different operating days or times. Compare each source before selecting the schedule.';
        }
        foreach ($graph['operating_days'] ?? [] as $day) {
            if (! empty($day['day_of_week']) && ! $days->has($day['day_of_week'])) {
                $days->put($day['day_of_week'], $day);
            }
        }
        $data['graph']['operating_days'] = $days->values()->all();
    }

    public function saveDraft(CatalogImportProposal $proposal, array $input): string
    {
        $newImages = [];
        $removedImages = [];
        try {
            return DB::transaction(function () use ($proposal, $input, &$newImages, &$removedImages) {
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
                        if (($data['graph']['market'][$key] ?? null) !== $input['market'][$key]) {
                            $data['market_edited_fields'] = array_values(array_unique([...($data['market_edited_fields'] ?? []), $key]));
                        }
                        $data['graph']['market'][$key] = $input['market'][$key];
                    }
                }
                if (! $data['context']['market_id'] && array_key_exists('operating_days', $input)) {
                    $data['operating_days_reviewed'] = true;
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

                return $this->revision($proposal->fresh());
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

    public function complete(User $user, CatalogImportProposal $proposal, array $input): ?array
    {
        try {
            return Cache::lock('ai-import-complete:'.$proposal->id, 120)->block(1, function () use ($user, $proposal, $input) {
                // Catalog writes remain atomic; saved edits survive an import validation error.
                $proposal->refresh();
                if ($proposal->status === 'imported' && isset($this->data($proposal)['import_result'])) {
                    return $this->data($proposal)['import_result'];
                }
                $action = $input['action'] ?? 'import';
                if ($action === 'import' && empty($input['confirm'])) {
                    throw ValidationException::withMessages(['confirm' => 'Check the review confirmation box before creating catalog records.']);
                }
                $revision = $this->saveDraft($proposal, $input);
                if ($action === 'save') {
                    return null;
                }

                $proposal->refresh();

                return $this->import($user, $proposal, [...$input, 'confirm' => true, 'revision' => $revision]);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['draft' => 'This import is already being processed. Wait, then open its saved result.']);
        }
    }

    public function deleteEmpty(CatalogImportProposal $proposal): void
    {
        DB::transaction(function () use ($proposal) {
            $proposal = CatalogImportProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $this->editable($proposal);
            $data = $this->data($proposal);
            if (! $this->isUnusedEmpty($proposal, $data)) {
                throw ValidationException::withMessages(['draft' => 'Only unused empty drafts can be removed. Analysed, edited or imported work is preserved.']);
            }
            // Keep the source and any other proposals or catalog provenance intact.
            $proposal->delete();
        });
    }

    private function isUnusedEmpty(CatalogImportProposal $proposal, array $data): bool
    {
        return $proposal->status === 'draft' && empty($data['graph']['stalls']) && empty($data['market_reviewed']) && empty($data['market_sources'])
            && empty($data['graph']['operating_days']) && ! $proposal->proposalMarket()->exists() && ! $proposal->catalogSourceLinks()->exists()
            && ! collect($data['sources'])->contains(fn ($source) => ! empty($source['text']) || ! empty($source['analysed_hash']) || ! empty($source['images']));
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

        return [...$data, 'existingStalls' => $existingStalls, 'categories' => $categories, 'canDeleteEmpty' => $this->isUnusedEmpty($proposal, $data),
            'marketIncomplete' => $marketId ? [] : array_values(array_filter([
                empty($data['graph']['market']['address']) ? 'Address not yet provided' : null,
                empty($data['graph']['operating_days']) ? 'Operating schedule not yet provided' : null,
            ])),
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
            if (empty($input['confirm'])) {
                throw ValidationException::withMessages(['confirm' => 'Check the review confirmation box before creating catalog records.']);
            }
            if (! hash_equals($this->revision($proposal), $input['revision'] ?? '')) {
                throw ValidationException::withMessages(['draft' => 'This draft changed after the review page loaded. Reload the latest review, check the confirmation box again, and retry.']);
            }
            $review = $this->review($proposal);
            if (empty($review['context']['market_id']) && empty($review['graph']['market']['matched_night_market_id']) && empty($review['graph']['market']['selected'])) {
                throw ValidationException::withMessages(['draft' => 'Include the new inactive Market or explicitly link an existing Market before importing its Stalls and Foods.']);
            }
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
                throw ValidationException::withMessages(['draft' => 'Select complete Stalls/Foods or include the new inactive Market in the draft.']);
            }
            $graph = $review['graph'];
            $graph['stalls'] = $selected;

            return app(CatalogImportProposalImportService::class)->importReviewedSelection($user, $proposal, $graph);
        });
    }
}
