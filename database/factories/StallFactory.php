<?php

namespace Database\Factories;

use App\Models\NightMarket;
use App\Models\Stall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stall>
 */
class StallFactory extends Factory
{
    protected $model = Stall::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'night_market_id' => NightMarket::factory(),
            'name' => fake()->unique()->company().' Stall',
            'description' => fake()->paragraph(),
            'status' => Stall::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => Stall::STATUS_INACTIVE,
        ]);
    }
}