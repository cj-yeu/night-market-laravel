<?php

namespace App\Services;

use App\Exceptions\SocialMediaExtractionException;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\SocialMediaRecord;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialMediaDataService
{
    public function __construct(private readonly SocialMediaUrlPolicy $urlPolicy) {}

    /**
     * @param  array{search?: string|null, night_market_id?: int|null, platform?: string|null, status?: string|null, posted_from?: string|null, posted_to?: string|null}  $filters
     */
    public function records(array $filters): LengthAwarePaginator
    {
        $records = SocialMediaRecord::query()
            ->with(['nightMarket:id,name', 'food:id,name', 'approvedBy:id,name', 'rejectedBy:id,name'])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this
                ->applyKeywordSearch($query, $search))
            ->when($filters['night_market_id'] ?? null, fn (Builder $query, int $marketId) => $query
                ->where('night_market_id', $marketId))
            ->when($filters['platform'] ?? null, fn (Builder $query, string $platform) => $query
                ->where('platform', $platform))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query
                ->where('status', $status))
            ->when($filters['posted_from'] ?? null, fn (Builder $query, string $date) => $query
                ->whereDate('posted_date', '>=', $date))
            ->when($filters['posted_to'] ?? null, fn (Builder $query, string $date) => $query
                ->whereDate('posted_date', '<=', $date))
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        $this->decorateSafeUrls($records->getCollection());

        return $records;
    }

    /**
     * @return array{nightMarkets: Collection<int, NightMarket>, foods: Collection<int, Food>, platforms: array<int, string>}
     */
    public function formOptions(): array
    {
        return [
            'nightMarkets' => $this->activeSelangorMarkets(),
            'foods' => $this->eligibleFoods(),
            'platforms' => SocialMediaRecord::PLATFORMS,
        ];
    }

    /**
     * @return Collection<int, NightMarket>
     */
    public function activeSelangorMarkets(): Collection
    {
        return NightMarket::query()
            ->where('status', NightMarket::STATUS_ACTIVE)
            ->where('state', 'Selangor')
            ->orderBy('name')
            ->get(['id', 'name', 'city', 'address']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SocialMediaRecord
    {
        $this->validateEligibility($data);
        $source = $this->validatedSourceUrl($data);
        $this->ensureUniqueSourceUrl($source['url']);

        try {
            return SocialMediaRecord::create([
                ...$this->recordData($data, $source['url']),
                'status' => SocialMediaRecord::STATUS_PENDING,
                'extraction_status' => $data['extraction_status'] ?? SocialMediaRecord::EXTRACTION_MANUAL,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateSourceUrlException();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SocialMediaRecord $socialMediaRecord, array $data): SocialMediaRecord
    {
        $this->validateEligibility($data);
        $source = $this->validatedSourceUrl($data);
        $this->ensureUniqueSourceUrl($source['url'], $socialMediaRecord);

        try {
            $socialMediaRecord->update([
                ...$this->recordData($data, $source['url']),
                'status' => SocialMediaRecord::STATUS_PENDING,
                'approved_by' => null,
                'approved_at' => null,
                'rejection_reason' => null,
                'rejected_by' => null,
                'rejected_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateSourceUrlException();
        }

        return $socialMediaRecord->refresh();
    }

    public function moderate(
        SocialMediaRecord $socialMediaRecord,
        User $admin,
        string $status,
        ?string $rejectionReason = null,
    ): SocialMediaRecord {
        return DB::transaction(function () use ($socialMediaRecord, $admin, $status, $rejectionReason): SocialMediaRecord {
            $record = SocialMediaRecord::query()->lockForUpdate()->findOrFail($socialMediaRecord->id);

            return $this->moderateLocked($record, $admin, $status, $rejectionReason);
        });
    }

    /**
     * @param  list<int>  $recordIds
     * @return array{approved: int, rejected: int, skipped: int}
     */
    public function bulkModerate(array $recordIds, User $admin, string $status, ?string $rejectionReason = null): array
    {
        return DB::transaction(function () use ($recordIds, $admin, $status, $rejectionReason): array {
            $records = SocialMediaRecord::query()
                ->whereIn('id', $recordIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $result = ['approved' => 0, 'rejected' => 0, 'skipped' => 0];
            foreach ($recordIds as $recordId) {
                $record = $records->get($recordId);

                if (! $record || $record->status !== SocialMediaRecord::STATUS_PENDING) {
                    $result['skipped']++;

                    continue;
                }

                try {
                    $this->moderateLocked($record, $admin, $status, $rejectionReason);
                    $result[$status]++;
                } catch (ValidationException) {
                    $result['skipped']++;
                }
            }

            return $result;
        });
    }

    public function delete(SocialMediaRecord $socialMediaRecord): void
    {
        $socialMediaRecord->delete();
    }

    /**
     * @param  array{search?: string|null}  $filters
     */
    public function publicHighlights(array $filters): LengthAwarePaginator
    {
        $records = $this->publiclyVisibleQuery()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this
                ->applyKeywordSearch($query, $search))
            ->latest('posted_date')
            ->paginate(12)
            ->withQueryString();

        $this->decorateSafeUrls($records->getCollection());

        return $records;
    }

    /**
     * @param  array{search?: string|null}  $filters
     * @return array{
     *   recordsByPlatform: array<string, int>,
     *   engagementByPlatform: array<string, int>,
     *   mostMentionedMarket: array{name: string, count: int}|null,
     *   mostMentionedFood: array{name: string, count: int}|null,
     *   topEngagementPosts: Collection<int, SocialMediaRecord>
     * }
     */
    public function publicInsights(array $filters): array
    {
        $records = $this->publiclyVisibleQuery()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this
                ->applyKeywordSearch($query, $search))
            ->get();

        $this->decorateSafeUrls($records);

        $recordsByPlatform = $records->countBy('platform')->sortKeys()->all();
        $engagementByPlatform = $records
            ->groupBy('platform')
            ->map(fn ($platformRecords) => (int) $platformRecords->sum('engagement_count'))
            ->sortKeys()
            ->all();

        return [
            'recordsByPlatform' => $recordsByPlatform,
            'engagementByPlatform' => $engagementByPlatform,
            'mostMentionedMarket' => $this->mostFrequentRelatedName($records, 'nightMarket'),
            'mostMentionedFood' => $this->mostFrequentRelatedName($records, 'food'),
            'topEngagementPosts' => $records
                ->sortByDesc('engagement_count')
                ->take(5)
                ->values(),
        ];
    }

    /**
     * @return Collection<int, object{platform: string, record_count: int, total_engagement: int, percentage: int}>
     */
    public function adminPlatformInsights(): Collection
    {
        $insights = SocialMediaRecord::query()
            ->selectRaw('platform, COUNT(*) as record_count, COALESCE(SUM(engagement_count), 0) as total_engagement')
            ->groupBy('platform')
            ->orderByDesc('record_count')
            ->orderBy('platform')
            ->get();
        $highestCount = max(1, (int) $insights->max('record_count'));

        return $insights->each(function (object $insight) use ($highestCount): void {
            $insight->percentage = (int) round(((int) $insight->record_count / $highestCount) * 100);
        });
    }

    /**
     * @return array{
     *   extracted_hashtags: array<int, string>,
     *   extracted_location_mentions: array<int, string>,
     *   extracted_market_mentions: array<int, string>,
     *   extracted_food_mentions: array<int, string>
     * }
     */
    public function extractFromText(string $text): array
    {
        preg_match_all('/(?<![\pL\pN_])#([\pL\pN_]+)/u', $text, $hashtagMatches);

        $hashtags = collect($hashtagMatches[1] ?? [])
            ->map(fn (string $hashtag) => '#'.Str::lower($hashtag))
            ->unique()
            ->values()
            ->all();

        $normalizedText = Str::lower($text);
        $markets = $this->activeSelangorMarkets();
        $foods = $this->eligibleFoods();

        $marketMentions = $markets
            ->filter(fn (NightMarket $market) => $this->textContains($normalizedText, $market->name))
            ->pluck('name')
            ->values()
            ->all();

        $locationMentions = $markets
            ->flatMap(fn (NightMarket $market) => [$market->city, $market->address])
            ->filter(fn (?string $location) => filled($location)
                && $this->textContains($normalizedText, $location))
            ->unique(fn (string $location) => Str::lower($location))
            ->values()
            ->all();

        $foodMentions = $foods
            ->filter(fn (Food $food) => $this->textContains($normalizedText, $food->name))
            ->pluck('name')
            ->unique(fn (string $name) => Str::lower($name))
            ->values()
            ->all();

        return [
            'extracted_hashtags' => $hashtags,
            'extracted_location_mentions' => $locationMentions,
            'extracted_market_mentions' => $marketMentions,
            'extracted_food_mentions' => $foodMentions,
        ];
    }

    /**
     * @return Collection<int, Food>
     */
    private function eligibleFoods(): Collection
    {
        return Food::query()
            ->where('status', Food::STATUS_ACTIVE)
            ->whereHas('stall', fn (Builder $query) => $query->where('status', Stall::STATUS_ACTIVE))
            ->whereHas('stall.nightMarket', fn (Builder $query) => $query
                ->where('status', NightMarket::STATUS_ACTIVE)
                ->where('state', 'Selangor'))
            ->with('stall:id,night_market_id,name')
            ->orderBy('name')
            ->get(['id', 'stall_id', 'name']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateEligibility(array $data, bool $requireMarket = false): void
    {
        $marketId = $data['night_market_id'] ?? null;
        $foodId = $data['food_id'] ?? null;

        if (! $marketId) {
            if ($requireMarket) {
                throw ValidationException::withMessages([
                    'night_market_id' => 'Select a valid active Selangor night market before approval.',
                ]);
            }

            if ($foodId) {
                throw ValidationException::withMessages([
                    'food_id' => 'Select a confirmed night market before linking a food.',
                ]);
            }

            return;
        }

        $marketIsEligible = NightMarket::query()
            ->whereKey($marketId)
            ->where('status', NightMarket::STATUS_ACTIVE)
            ->where('state', 'Selangor')
            ->exists();

        if (! $marketIsEligible) {
            throw ValidationException::withMessages([
                'night_market_id' => 'The selected night market must be active and located in Selangor.',
            ]);
        }

        if ($foodId) {
            $foodIsEligible = Food::query()
                ->whereKey($foodId)
                ->where('status', Food::STATUS_ACTIVE)
                ->whereHas('stall', fn (Builder $query) => $query
                    ->where('status', Stall::STATUS_ACTIVE)
                    ->where('night_market_id', $marketId))
                ->exists();

            if (! $foodIsEligible) {
                throw ValidationException::withMessages([
                    'food_id' => 'The selected food must be active and belong to the selected night market.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function recordData(array $data, string $canonicalSourceUrl): array
    {
        $extracted = $this->extractFromText($data['content_summary']);

        foreach (array_keys($extracted) as $field) {
            if (array_key_exists($field, $data) && filled($data[$field])) {
                $extracted[$field] = $this->normalizeEditableList(
                    $data[$field],
                    hashtags: $field === 'extracted_hashtags',
                );
            }
        }

        $likes = (int) $data['likes'];
        $comments = (int) $data['comments'];
        $shares = (int) $data['shares'];

        return [
            'night_market_id' => $data['night_market_id'] ?? null,
            'food_id' => $data['food_id'] ?? null,
            'platform' => $data['platform'],
            'original_post_url' => $canonicalSourceUrl,
            'source_url_fingerprint' => hash('sha256', $canonicalSourceUrl),
            'extracted_title' => $data['extracted_title'] ?? null,
            'content_summary' => $data['content_summary'],
            'external_image_url' => $data['external_image_url'] ?? null,
            'posted_date' => $data['posted_date'],
            'likes' => $likes,
            'comments' => $comments,
            'shares' => $shares,
            'engagement_count' => $likes + $comments + $shares,
            ...$extracted,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeEditableList(mixed $value, bool $hashtags = false): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return collect($values)
            ->map(function ($item) use ($hashtags) {
                $item = trim((string) $item);

                if ($hashtags && $item !== '') {
                    $item = '#'.ltrim(Str::lower($item), '#');
                }

                return $item;
            })
            ->filter()
            ->unique(fn (string $item) => Str::lower($item))
            ->values()
            ->all();
    }

    private function publiclyVisibleQuery(): Builder
    {
        return SocialMediaRecord::query()
            ->publiclyVisible()
            ->with([
                'nightMarket:id,name,city,state,status',
                'food' => fn ($query) => $query
                    ->where('status', Food::STATUS_ACTIVE)
                    ->whereHas('stall', fn (Builder $query) => $query
                        ->where('status', Stall::STATUS_ACTIVE))
                    ->with('stall:id,night_market_id,status'),
            ]);
    }

    private function applyKeywordSearch(Builder $query, string $search): void
    {
        $pattern = $this->literalLikePattern($search);

        $query->where(function (Builder $query) use ($pattern) {
            $query->where('extracted_title', 'like', $pattern)
                ->orWhere('content_summary', 'like', $pattern)
                ->orWhere('original_post_url', 'like', $pattern)
                ->orWhere('extracted_hashtags', 'like', $pattern)
                ->orWhereHas('nightMarket', fn (Builder $query) => $query
                    ->where('name', 'like', $pattern))
                ->orWhereHas('food', fn (Builder $query) => $query
                    ->where('name', 'like', $pattern));
        });
    }

    /**
     * @param  Collection<int, SocialMediaRecord>  $records
     */
    private function decorateSafeUrls(Collection $records): void
    {
        $records->each(function (SocialMediaRecord $record): void {
            $record->setAttribute(
                'safe_source_url',
                $this->urlPolicy->safeStoredSourceUrl($record->original_post_url),
            );
            $record->setAttribute(
                'safe_image_url',
                $this->urlPolicy->safeStoredImageUrl($record->external_image_url),
            );
        });
    }

    /** @param array<string, mixed> $data
     * @return array{url: string, host: string, port: int, platform: string}
     */
    private function validatedSourceUrl(array $data): array
    {
        try {
            $source = $this->urlPolicy->inspectSourceUrl($data['original_post_url']);
        } catch (SocialMediaExtractionException $exception) {
            throw ValidationException::withMessages([
                'original_post_url' => $exception->getMessage(),
            ]);
        }

        if ($source['platform'] !== $data['platform']) {
            throw ValidationException::withMessages([
                'platform' => 'The selected platform must match the original post URL.',
            ]);
        }

        return $source;
    }

    private function ensureUniqueSourceUrl(string $canonicalSourceUrl, ?SocialMediaRecord $ignore = null): void
    {
        $fingerprint = hash('sha256', $canonicalSourceUrl);
        $duplicate = SocialMediaRecord::query()
            ->where('source_url_fingerprint', $fingerprint)
            ->when($ignore, fn (Builder $query) => $query->where('id', '!=', $ignore->id))
            ->exists();

        if (! $duplicate) {
            $legacyRecords = SocialMediaRecord::query()
                ->select(['id', 'original_post_url'])
                ->whereNull('source_url_fingerprint')
                ->when($ignore, fn (Builder $query) => $query->where('id', '!=', $ignore->id))
                ->orderBy('id')
                ->get();

            $duplicate = $legacyRecords->contains(function (SocialMediaRecord $record) use ($canonicalSourceUrl): bool {
                return $this->urlPolicy->safeStoredSourceUrl($record->original_post_url) === $canonicalSourceUrl;
            });
        }

        if ($duplicate) {
            throw $this->duplicateSourceUrlException();
        }
    }

    private function duplicateSourceUrlException(): ValidationException
    {
        return ValidationException::withMessages([
            'original_post_url' => 'This social media post URL has already been recorded.',
        ]);
    }

    private function moderateLocked(
        SocialMediaRecord $socialMediaRecord,
        User $admin,
        string $status,
        ?string $rejectionReason,
    ): SocialMediaRecord {
        if ($socialMediaRecord->status !== SocialMediaRecord::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'This record has already been reviewed. Edit it to return it to Pending before reviewing again.']);
        }
        if (! in_array($status, [SocialMediaRecord::STATUS_APPROVED, SocialMediaRecord::STATUS_REJECTED], true)
            || ($status === SocialMediaRecord::STATUS_REJECTED && mb_strlen(trim((string) $rejectionReason)) < 3)) {
            throw ValidationException::withMessages(['status' => 'Choose Approve or Reject, with a reason when rejecting.']);
        }

        if ($status === SocialMediaRecord::STATUS_APPROVED) {
            $this->validateEligibility([
                'night_market_id' => $socialMediaRecord->night_market_id,
                'food_id' => $socialMediaRecord->food_id,
            ], requireMarket: true);

            $socialMediaRecord->update([
                'status' => SocialMediaRecord::STATUS_APPROVED,
                'approved_by' => $admin->id,
                'approved_at' => now(),
                'rejection_reason' => null,
                'rejected_by' => null,
                'rejected_at' => null,
            ]);
        } else {
            $socialMediaRecord->update([
                'status' => SocialMediaRecord::STATUS_REJECTED,
                'approved_by' => null,
                'approved_at' => null,
                'rejection_reason' => $rejectionReason,
                'rejected_by' => $admin->id,
                'rejected_at' => now(),
            ]);
        }

        return $socialMediaRecord->refresh();
    }

    private function literalLikePattern(string $value): string
    {
        return '%'.str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        ).'%';
    }

    /**
     * @param  Collection<int, SocialMediaRecord>  $records
     * @return array{name: string, count: int}|null
     */
    private function mostFrequentRelatedName(Collection $records, string $relation): ?array
    {
        $counts = $records
            ->map(fn (SocialMediaRecord $record) => $record->{$relation}?->name)
            ->filter()
            ->countBy()
            ->sortDesc();

        if ($counts->isEmpty()) {
            return null;
        }

        return [
            'name' => (string) $counts->keys()->first(),
            'count' => (int) $counts->first(),
        ];
    }

    private function textContains(string $normalizedText, ?string $needle): bool
    {
        return filled($needle) && Str::contains($normalizedText, Str::lower($needle));
    }
}
