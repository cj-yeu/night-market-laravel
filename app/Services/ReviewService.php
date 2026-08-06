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
     * @return Collection<int, Review>
     */
    public function pendingReviews(): Collection
    {
        return Review::query()
            ->where('status', Review::STATUS_PENDING)
            ->with(['user:id,name,email', 'nightMarket:id,name,city'])
            ->oldest()
            ->get();
    }

    public function moderate(Review $review, string $status): Review
    {
        abort_unless($review->status === Review::STATUS_PENDING, 404);

        $review->update(['status' => $status]);

        return $review->refresh();
    }
}
