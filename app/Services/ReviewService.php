<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Review;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class ReviewService
{
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
            ->whereDate('review_date', Review::currentReviewDate())
            ->first();
    }

    public function marketReviewForClient(NightMarket $nightMarket, User $user): ?Review
    {
        return Review::query()
            ->where('night_market_id', $nightMarket->id)
            ->whereNull('food_id')
            ->where('user_id', $user->id)
            ->whereDate('review_date', Review::currentReviewDate())
            ->first();
    }

    /** @return LengthAwarePaginator<Review> */
    public function reviewHistoryForClient(User $user, string $type = 'all'): LengthAwarePaginator
    {
        return Review::query()
            ->where('user_id', $user->id)
            ->with([
                'food:id,stall_id,name',
                'food.stall:id,night_market_id,name',
                'nightMarket:id,name',
            ])
            ->when($type === 'food', fn ($query) => $query->whereNotNull('food_id'))
            ->when($type === 'market', fn ($query) => $query->whereNull('food_id')->whereNotNull('night_market_id'))
            ->orderByDesc('review_date')
            ->latest('updated_at')
            ->latest('id')
            ->paginate(10)
            ->withQueryString();
    }

    /** @param array{rating: int, comment: string} $data */
    public function createForClient(User $user, Food $food, array $data): Review
    {
        if ($this->reviewForClient($food, $user) !== null) {
            throw ValidationException::withMessages([
                'comment' => 'You have already reviewed this Food today. Edit your review or try again tomorrow.',
            ]);
        }

        try {
            return Review::query()->create([
                'user_id' => $user->id,
                'night_market_id' => null,
                'food_id' => $food->id,
                'review_date' => Review::currentReviewDate(),
                'rating' => $data['rating'],
                'comment' => $data['comment'],
                'tags' => Review::tagsForFood($data['tags'] ?? []),
                'status' => Review::STATUS_APPROVED,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'comment' => 'You have already reviewed this Food today. Edit your review or try again tomorrow.',
            ]);
        }
    }

    /** @param array{rating: int, comment: string} $data */
    public function createMarketForClient(User $user, NightMarket $nightMarket, array $data): Review
    {
        if ($this->marketReviewForClient($nightMarket, $user) !== null) {
            throw ValidationException::withMessages([
                'comment' => 'You have already reviewed this Night Market today. Edit your review or try again tomorrow.',
            ]);
        }

        try {
            return Review::query()->create([
                'user_id' => $user->id,
                'night_market_id' => $nightMarket->id,
                'food_id' => null,
                'review_date' => Review::currentReviewDate(),
                'rating' => $data['rating'],
                'comment' => $data['comment'],
                'tags' => Review::tagsForMarket($data['tags'] ?? []),
                'status' => Review::STATUS_APPROVED,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'comment' => 'You have already reviewed this Night Market today. Edit your review or try again tomorrow.',
            ]);
        }
    }

    /** @param array{rating: int, comment: string} $data */
    public function updateForClient(User $user, Food $food, Review $review, array $data): Review
    {
        abort_unless($review->user_id === $user->id, 403);
        abort_unless($review->food_id === $food->id, 404);
        abort_unless($review->review_date?->isSameDay(Review::currentReviewDate()), 403);

        $review->update([
            'rating' => $data['rating'],
            'comment' => $data['comment'],
            'tags' => Review::tagsForFood($data['tags'] ?? []),
            'status' => Review::STATUS_APPROVED,
        ]);

        return $review->refresh();
    }

    /** @param array{rating: int, comment: string} $data */
    public function updateMarketForClient(User $user, NightMarket $nightMarket, Review $review, array $data): Review
    {
        abort_unless($review->user_id === $user->id, 403);
        abort_unless($review->night_market_id === $nightMarket->id && $review->food_id === null, 404);
        abort_unless($review->review_date?->isSameDay(Review::currentReviewDate()), 403);

        $review->update([
            'rating' => $data['rating'],
            'comment' => $data['comment'],
            'tags' => Review::tagsForMarket($data['tags'] ?? []),
            'status' => Review::STATUS_APPROVED,
        ]);

        return $review->refresh();
    }

    public function deleteForClient(User $user, Food $food, Review $review): void
    {
        abort_unless($review->user_id === $user->id, 403);
        abort_unless($review->food_id === $food->id, 404);

        $review->delete();
    }

    public function deleteMarketForClient(User $user, NightMarket $nightMarket, Review $review): void
    {
        abort_unless($review->user_id === $user->id, 403);
        abort_unless($review->night_market_id === $nightMarket->id && $review->food_id === null, 404);

        $review->delete();
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
                ->with('user:id,name,avatar_path')
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
                ? $this->reviewForClient($food, $viewer)
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
            'reviews' => (clone $base)->with('user:id,name,avatar_path')->latest('updated_at')->latest('id')->paginate(10, ['*'], 'market_reviews')->withQueryString(),
            'averageRating' => (int) $statistics->review_count === 0 ? null : round((float) $statistics->average_rating, 1),
            'reviewCount' => (int) $statistics->review_count,
            'ratingDistribution' => collect(range(5, 1))->mapWithKeys(fn (int $rating) => [$rating => (int) ($countsByRating[$rating] ?? 0)])->all(),
            'viewerReview' => $viewer?->role === User::ROLE_CLIENT ? $this->marketReviewForClient($nightMarket, $viewer) : null,
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

    private function literalLikePattern(?string $value): ?string
    {
        return $value ? '%'.addcslashes($value, '\\%_').'%' : null;
    }
}
