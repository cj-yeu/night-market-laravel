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
            'extracted_title' => fake()->sentence(),
            'content_summary' => fake()->paragraph(),
            'external_image_url' => null,
            'extraction_status' => SocialMediaRecord::EXTRACTION_MANUAL,
            'posted_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'likes' => fake()->numberBetween(0, 5000),
            'comments' => fake()->numberBetween(0, 500),
            'shares' => fake()->numberBetween(0, 250),
            'engagement_count' => 0,
            'status' => SocialMediaRecord::STATUS_PENDING,
            'extracted_hashtags' => [],
            'extracted_location_mentions' => [],
            'extracted_market_mentions' => [],
            'extracted_food_mentions' => [],
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => SocialMediaRecord::STATUS_APPROVED,
            'approved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => SocialMediaRecord::STATUS_REJECTED,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }
}
