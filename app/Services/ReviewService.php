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
    public function activeNightMarkets(): Collection
    {
        return NightMarket::where('status', NightMarket::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{night_market_id: int, rating: int, comment?: string|null}  $data
     */
    public function createForClient(User $user, array $data): Review
    {
        return $user->reviews()->create([
            'night_market_id' => $data['night_market_id'],
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
            'status' => Review::STATUS_PENDING,
        ]);
    }
}
