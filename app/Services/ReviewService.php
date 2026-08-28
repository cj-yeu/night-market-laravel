<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Review;
use App\Models\ReviewTag;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewService
{
    /** @return Collection<int, ReviewTag> */
    public function tagOptions(): Collection
    {
        return ReviewTag::query()->whereIn('name', ReviewTag::NAMES)->orderBy('name')->get(['id', 'name']);
    }

    public function findPubliclyVisibleFood(int $foodId): Food
    {
        return Food::query()
            ->publiclyVisible()
            ->with(['stall:id,night_market_id,name,halal_status,halal_evidence_url,status', 'stall.nightMarket:id,name,city,state,status'])
            ->findOrFail($foodId);
    }

    public function findPubliclyVisibleMarket(int $marketId): NightMarket
    {
        return NightMarket::query()
            ->publiclyVisible()
            ->select(['id', 'name', 'address', 'city', 'state', 'description', 'status', 'image_path'])
            ->findOrFail($marketId);
    }

    public function reviewForClient(Food $food, User $user): ?Review
    {
        return Review::query()
            ->where('food_id', $food->id)
            ->where('user_id', $user->id)
            ->latest('review_date')->latest('id')->first();
    }

    public function reviewForClientToday(Food $food, User $user): ?Review
    {
        return Review::query()->where('food_id', $food->id)->where('user_id', $user->id)
            ->where('review_date', now()->toDateString())->latest('id')->first();
    }

    public function marketReviewForClient(NightMarket $nightMarket, User $user): ?Review
    {
        return Review::query()
            ->where('night_market_id', $nightMarket->id)
            ->whereNull('food_id')
            ->where('user_id', $user->id)
            ->latest('review_date')->latest('id')->first();
    }

    public function marketReviewForClientToday(NightMarket $nightMarket, User $user): ?Review
    {
        return Review::query()->where('night_market_id', $nightMarket->id)->whereNull('food_id')
            ->where('user_id', $user->id)->where('review_date', now()->toDateString())->latest('id')->first();
    }

    /** @param array{rating: int, comment: string, tags?: array<int, int>} $data */
    public function createForClient(User $user, Food $food, array $data): Review
    {
        if ($this->hasReviewToday($user, foodId: $food->id)) {
            throw ValidationException::withMessages([
                'comment' => $this->dailyLimitMessage(),
            ]);
        }

        try {
            return DB::transaction(function () use ($user, $food, $data): Review {
                $review = Review::query()->create([
                    'user_id' => $user->id,
                    'night_market_id' => null,
                    'food_id' => $food->id,
                    'rating' => $data['rating'],
                    'comment' => $data['comment'],
                    'status' => Review::STATUS_APPROVED,
                    'review_date' => now()->toDateString(),
                ]);
                $review->tags()->sync($this->validTagIds($data['tags'] ?? []));

                return $review->load('tags');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'comment' => $this->dailyLimitMessage(),
            ]);
        }
    }

    /** @param array{rating: int, comment: string, tags?: array<int, int>} $data */
    public function createMarketForClient(User $user, NightMarket $nightMarket, array $data): Review
    {
        if ($this->hasReviewToday($user, marketId: $nightMarket->id)) {
            throw ValidationException::withMessages([
                'comment' => $this->dailyLimitMessage(),
            ]);
        }

        try {
            return DB::transaction(function () use ($user, $nightMarket, $data): Review {
                $review = Review::query()->create([
                    'user_id' => $user->id,
                    'night_market_id' => $nightMarket->id,
                    'food_id' => null,
                    'rating' => $data['rating'],
                    'comment' => $data['comment'],
                    'status' => Review::STATUS_APPROVED,
                    'review_date' => now()->toDateString(),
                ]);
                $review->tags()->sync($this->validTagIds($data['tags'] ?? []));

                return $review->load('tags');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'comment' => $this->dailyLimitMessage(),
            ]);
        }
    }

    /** @param array{rating: int, comment: string} $data */
    public function updateForClient(User $user, Food $food, Review $review, array $data): Review
    {
        abort_unless($review->user_id === $user->id, 403);
        abort_unless($review->food_id === $food->id, 404);

        $review->update([
            'rating' => $data['rating'],
            'comment' => $data['comment'],
            'status' => Review::STATUS_APPROVED,
        ]);
        $review->tags()->sync($this->validTagIds($data['tags'] ?? []));

        return $review->refresh()->load('tags');
    }

    /** @param array{rating: int, comment: string} $data */
    public function updateMarketForClient(User $user, NightMarket $nightMarket, Review $review, array $data): Review
    {
        abort_unless($review->user_id === $user->id, 403);
        abort_unless($review->night_market_id === $nightMarket->id && $review->food_id === null, 404);

        $review->update([
            'rating' => $data['rating'],
            'comment' => $data['comment'],
            'status' => Review::STATUS_APPROVED,
        ]);
        $review->tags()->sync($this->validTagIds($data['tags'] ?? []));

        return $review->refresh()->load('tags');
    }

    /**
     * @return array{reviews: LengthAwarePaginator<Review>, averageRating: float|null, reviewCount: int, ratingDistribution: array<int, int>, viewerReview: Review|null}
     */
    public function publicSummaryForFood(Food $food, ?User $viewer): array
    {
        $base = Review::query()->where('food_id', $food->id)->publiclyVisible();
        $statistics = (clone $base)
            ->selectRaw('COUNT(*) AS review_count, AVG(rating) AS average_rating')
            ->first();
        $countsByRating = (clone $base)
            ->selectRaw('rating, COUNT(*) AS aggregate')
            ->groupBy('rating')
            ->pluck('aggregate', 'rating');

        return [
            'reviews' => (clone $base)
                ->with(['user:id,name,avatar_path', 'tags:id,name'])
                ->latest('updated_at')
                ->latest('id')
                ->paginate(10)
                ->withQueryString(),
            'averageRating' => (int) $statistics->review_count === 0
                ? null
                : round((float) $statistics->average_rating, 1),
            'reviewCount' => (int) $statistics->review_count,
            'ratingDistribution' => collect(range(5, 1))->mapWithKeys(
                fn (int $rating) => [$rating => (int) ($countsByRating[$rating] ?? 0)]
            )->all(),
            'viewerReview' => $viewer?->role === User::ROLE_CLIENT
                ? $this->reviewForClientToday($food, $viewer)
                : null,
        ];
    }

    /**
     * @return array{reviews: LengthAwarePaginator<Review>, averageRating: float|null, reviewCount: int, ratingDistribution: array<int, int>, viewerReview: Review|null}
     */
    public function publicSummaryForMarket(NightMarket $nightMarket, ?User $viewer): array
    {
        $base = Review::query()->where('night_market_id', $nightMarket->id)->whereNull('food_id')->publiclyVisible();
        $statistics = (clone $base)->selectRaw('COUNT(*) AS review_count, AVG(rating) AS average_rating')->first();
        $countsByRating = (clone $base)->selectRaw('rating, COUNT(*) AS aggregate')->groupBy('rating')->pluck('aggregate', 'rating');

        return [
            'reviews' => (clone $base)->with(['user:id,name,avatar_path', 'tags:id,name'])->latest('updated_at')->latest('id')->paginate(10, ['*'], 'market_reviews')->withQueryString(),
            'averageRating' => (int) $statistics->review_count === 0 ? null : round((float) $statistics->average_rating, 1),
            'reviewCount' => (int) $statistics->review_count,
            'ratingDistribution' => collect(range(5, 1))->mapWithKeys(fn (int $rating) => [$rating => (int) ($countsByRating[$rating] ?? 0)])->all(),
            'viewerReview' => $viewer?->role === User::ROLE_CLIENT ? $this->marketReviewForClientToday($nightMarket, $viewer) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Review>
     */
    public function reviewsForManagement(array $filters): LengthAwarePaginator
    {
        $search = $this->literalLikePattern($filters['search'] ?? null);

        return Review::query()
            ->select(['id', 'user_id', 'night_market_id', 'food_id', 'rating', 'comment', 'created_at', 'updated_at'])
            ->with([
                'user:id,name,avatar_path',
                'food:id,stall_id,name',
                'food.stall:id,night_market_id,name',
                'food.stall.nightMarket:id,name',
                'nightMarket:id,name',
            ])
            ->when($search, fn ($query, string $pattern) => $query->where(function ($query) use ($pattern) {
                $query->where('comment', 'like', $pattern)
                    ->orWhereHas('user', fn ($query) => $query->where('name', 'like', $pattern));
            }))
            ->when($filters['food_id'] ?? null, fn ($query, int $foodId) => $query->where('food_id', $foodId))
            ->when($filters['stall_id'] ?? null, fn ($query, int $stallId) => $query
                ->whereHas('food', fn ($query) => $query->where('stall_id', $stallId)))
            ->when($filters['market_id'] ?? null, fn ($query, int $marketId) => $query
                ->where(function ($query) use ($marketId) {
                    $query->whereHas('food.stall', fn ($query) => $query->where('night_market_id', $marketId))
                        ->orWhere(fn ($query) => $query->whereNull('food_id')->where('night_market_id', $marketId));
                }))
            ->when($filters['rating'] ?? null, fn ($query, int $rating) => $query->where('rating', $rating))
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->latest()
            ->paginate(15)
            ->withQueryString();
    }

    /** @return array{markets: Collection<int, NightMarket>, stalls: Collection<int, Stall>, foods: Collection<int, Food>} */
    public function managementFilterOptions(): array
    {
        return [
            'markets' => NightMarket::query()->whereHas('reviews')->select(['id', 'name'])->orderBy('name')->get(),
            'stalls' => Stall::query()->whereHas('foods.reviews')->select(['id', 'name'])->orderBy('name')->get(),
            'foods' => Food::query()->whereHas('reviews')->select(['id', 'name'])->orderBy('name')->get(),
        ];
    }

    public function delete(Review $review): void
    {
        $review->delete();
    }

    /** @return array{marketReviews: Collection<int, Review>, foodReviews: Collection<int, Review>} */
    public function reviewsForProfile(User $user): array
    {
        $base = Review::query()->where('user_id', $user->id)->with('tags:id,name')->latest('created_at')->latest('id');

        return [
            'marketReviews' => (clone $base)->whereNotNull('night_market_id')->whereNull('food_id')->with('nightMarket:id,name')->get(),
            'foodReviews' => (clone $base)->whereNotNull('food_id')->with(['food:id,stall_id,name', 'food.stall:id,night_market_id,name', 'food.stall.nightMarket:id,name'])->get(),
        ];
    }

    protected function hasReviewToday(User $user, ?int $marketId = null, ?int $foodId = null): bool
    {
        return Review::query()->where('user_id', $user->id)->where('review_date', now()->toDateString())
            ->when($marketId !== null, fn ($query) => $query->where('night_market_id', $marketId)->whereNull('food_id'))
            ->when($foodId !== null, fn ($query) => $query->where('food_id', $foodId))->exists();
    }

    /** @param array<int, int|string> $tagIds */
    private function validTagIds(array $tagIds): array
    {
        return ReviewTag::query()->whereIn('id', $tagIds)->whereIn('name', ReviewTag::NAMES)->pluck('id')->all();
    }

    private function dailyLimitMessage(): string
    {
        return 'You have already submitted a review for this item today. Please try again tomorrow.';
    }

    private function literalLikePattern(?string $value): ?string
    {
        return $value ? '%'.addcslashes($value, '\\%_').'%' : null;
    }
}
