<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\SocialMediaRecord;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialMediaDataService
{
    /**
     * @param  array{search?: string|null, night_market_id?: int|null, platform?: string|null, status?: string|null}  $filters
     */
    public function records(array $filters): LengthAwarePaginator
    {
        return SocialMediaRecord::query()
            ->with(['nightMarket:id,name', 'food:id,name', 'approvedBy:id,name'])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this
                ->applyKeywordSearch($query, $search))
            ->when($filters['night_market_id'] ?? null, fn (Builder $query, int $marketId) => $query
                ->where('night_market_id', $marketId))
            ->when($filters['platform'] ?? null, fn (Builder $query, string $platform) => $query
                ->where('platform', $platform))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query
                ->where('status', $status))
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();
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
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SocialMediaRecord
    {
        $this->validateEligibility($data);

        return SocialMediaRecord::create([
            ...$this->recordData($data),
            'status' => SocialMediaRecord::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SocialMediaRecord $socialMediaRecord, array $data): SocialMediaRecord
    {
        $this->validateEligibility($data);
        $socialMediaRecord->update([
            ...$this->recordData($data),
            'status' => SocialMediaRecord::STATUS_PENDING,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return $socialMediaRecord->refresh();
    }

    public function moderate(SocialMediaRecord $socialMediaRecord, User $admin, string $status): SocialMediaRecord
    {
        if ($status === SocialMediaRecord::STATUS_APPROVED) {
            $this->validateEligibility([
                'night_market_id' => $socialMediaRecord->night_market_id,
                'food_id' => $socialMediaRecord->food_id,
            ], requireMarket: true);

            $socialMediaRecord->update([
                'status' => SocialMediaRecord::STATUS_APPROVED,
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);
        } else {
            $socialMediaRecord->update([
                'status' => SocialMediaRecord::STATUS_REJECTED,
                'approved_by' => null,
                'approved_at' => null,
            ]);
        }

        return $socialMediaRecord->refresh();
    }

    public function delete(SocialMediaRecord $socialMediaRecord): void
    {
        $socialMediaRecord->delete();
    }

    /**
     * @param  array{search?: string|null}  $filters
     */
    public function clientHighlights(array $filters): LengthAwarePaginator
    {
        $records = $this->clientVisibleQuery()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this
                ->applyKeywordSearch($query, $search))
            ->latest('posted_date')
            ->paginate(12)
            ->withQueryString();

        $this->hideIneligibleFoodRelations($records->getCollection());

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
    public function clientInsights(array $filters): array
    {
        $records = $this->clientVisibleQuery()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this
                ->applyKeywordSearch($query, $search))
            ->get();

        $this->hideIneligibleFoodRelations($records);

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
            ->get();
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
    private function recordData(array $data): array
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
            'original_post_url' => $data['original_post_url'],
            'content_summary' => $data['content_summary'],
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

    private function clientVisibleQuery(): Builder
    {
        return SocialMediaRecord::query()
            ->where('status', SocialMediaRecord::STATUS_APPROVED)
            ->whereNotNull('night_market_id')
            ->whereHas('nightMarket', fn (Builder $query) => $query
                ->where('status', NightMarket::STATUS_ACTIVE)
                ->where('state', 'Selangor'))
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
        $query->where(function (Builder $query) use ($search) {
            $query->where('content_summary', 'like', '%'.$search.'%')
                ->orWhere('original_post_url', 'like', '%'.$search.'%')
                ->orWhere('extracted_hashtags', 'like', '%'.$search.'%')
                ->orWhereHas('nightMarket', fn (Builder $query) => $query
                    ->where('name', 'like', '%'.$search.'%'))
                ->orWhereHas('food', fn (Builder $query) => $query
                    ->where('name', 'like', '%'.$search.'%'));
        });
    }

    /**
     * @param  Collection<int, SocialMediaRecord>  $records
     */
    private function hideIneligibleFoodRelations(Collection $records): void
    {
        $records->each(function (SocialMediaRecord $record) {
            if ($record->food
                && $record->food->stall?->night_market_id !== $record->night_market_id) {
                $record->setRelation('food', null);
            }
        });
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
