<?php

namespace Database\Factories;

use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketOperatingDay>
 */
class MarketOperatingDayFactory extends Factory
{
    protected $model = MarketOperatingDay::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'night_market_id' => NightMarket::factory(),
            'day_of_week' => fake()->randomElement(MarketOperatingDay::DAYS),
            'opening_time' => '18:00',
            'closing_time' => '22:00',
        ];
    }
}
