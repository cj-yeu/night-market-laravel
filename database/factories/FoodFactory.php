<?php

namespace Database\Factories;

use App\Models\Food;
use App\Models\Stall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Food>
 */
class FoodFactory extends Factory
{
    protected $model = Food::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stall_id' => Stall::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement(['Grilled', 'Dessert', 'Drinks', 'Snacks']),
            'is_must_try' => false,
            'status' => Food::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => Food::STATUS_INACTIVE,
        ]);
    }

    public function mustTry(): static
    {
        return $this->state(fn () => [
            'is_must_try' => true,
        ]);
    }
}