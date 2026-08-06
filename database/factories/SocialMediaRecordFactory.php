<?php

namespace Database\Factories;

use App\Models\NightMarket;
use App\Models\SocialMediaRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialMediaRecord>
 */
class SocialMediaRecordFactory extends Factory
{
    protected $model = SocialMediaRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'night_market_id' => NightMarket::factory(),
            'food_id' => null,
            'platform' => fake()->randomElement(SocialMediaRecord::PLATFORMS),
            'original_post_url' => fake()->url(),
            'content_summary' => fake()->paragraph(),
            'posted_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'likes' => fake()->numberBetween(0, 5000),
            'comments' => fake()->numberBetween(0, 500),
            'shares' => fake()->numberBetween(0, 250),
        ];
    }
}
