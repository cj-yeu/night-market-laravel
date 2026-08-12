<?php

namespace App\Services;

use App\Models\NightMarket;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ReviewService
{
    /**
     * @return Collection<int, NightMarket>
     */
    public function findActiveMarketForClient(int $nightMarketId): NightMarket
    {
        return NightMarket::query()
            ->where('status', NightMarket::STATUS_ACTIVE)
            ->where('state', 'Selangor')
            ->findOrFail($nightMarketId);
    }

    /**
     * @param  array{rating: int, comment: string}  $data
     */
    public function createForClient(User $user, NightMarket $nightMarket, array $data): Review
    {
        return $user->reviews()->create([
            'night_market_id' => $nightMarket->id,
            'rating' => $data['rating'],
            'comment' => $data['comment'],
            'status' => Review::STATUS_PENDING,
        ]);
    }

    /**
     * @return array{reviews: Collection<int, Review>, averageRating: float|null, reviewCount: int}
     */
    public function approvedSummaryForMarket(NightMarket $nightMarket): array
    {
        $reviews = Review::query()
            ->where('night_market_id', $nightMarket->id)
            ->where('status', Review::STATUS_APPROVED)
            ->with('user:id,name')
            ->latest()
            ->get();

        return [
            'reviews' => $reviews,
            'averageRating' => $reviews->isEmpty() ? null : round((float) $reviews->avg('rating'), 1),
            'reviewCount' => $reviews->count(),
        ];
    }

    /**
     * @param  array{search?: string|null, market_id?: int|null, status?: string|null}  $filters
     * @return Collection<int, Review>
     */
    public function reviewsForModeration(array $filters): Collection
    {
        return Review::query()
            ->with(['user:id,name,email', 'nightMarket:id,name,city'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('comment', 'like', '%'.$search.'%')
                        ->orWhereHas('user', function ($query) use ($search) {
                            $query->where('name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('nightMarket', fn ($query) => $query
                            ->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($filters['market_id'] ?? null, fn ($query, int $marketId) => $query
                ->where('night_market_id', $marketId))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query
                ->where('status', $status))
            ->latest()
            ->get();
    }

    /**
     * @return Collection<int, NightMarket>
     */
    public function marketsWithReviews(): Collection
    {
        return NightMarket::query()
            ->whereHas('reviews')
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function moderationStatusOptions(): array
    {
        return [
            Review::STATUS_PENDING => 'Pending',
            Review::STATUS_APPROVED => 'Approved',
            Review::STATUS_REJECTED => 'Rejected',
        ];
    }

    public function moderate(Review $review, string $status): Review
    {
        $review->update(['status' => $status]);

        return $review->refresh();
    }
}
