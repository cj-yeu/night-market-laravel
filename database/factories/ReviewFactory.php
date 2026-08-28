<?php

namespace Database\Factories;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'night_market_id' => NightMarket::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->sentence(12),
            'status' => Review::STATUS_PENDING,
            'review_date' => now()->toDateString(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => Review::STATUS_APPROVED]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => Review::STATUS_REJECTED]);
    }

    public function forFood(Food $food): static
    {
        return $this->state(fn () => [
            'food_id' => $food->id,
            'night_market_id' => null,
        ]);
    }
}
