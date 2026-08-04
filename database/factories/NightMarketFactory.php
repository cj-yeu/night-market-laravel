<?php

namespace Database\Factories;

use App\Models\NightMarket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NightMarket>
 */
class NightMarketFactory extends Factory
{
    protected $model = NightMarket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Night Market',
            'address' => fake()->streetAddress(),
            'city' => fake()->randomElement(['Petaling', 'Klang', 'Gombak', 'Hulu Langat']),
            'state' => 'Selangor',
            'description' => fake()->paragraph(),
            'status' => NightMarket::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => NightMarket::STATUS_INACTIVE,
        ]);
    }
}
