<?php

namespace Database\Factories;

use App\Models\NightMarket;
use App\Models\User;
use App\Models\VisitPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitPlan>
 */
class VisitPlanFactory extends Factory
{
    protected $model = VisitPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'night_market_id' => NightMarket::factory(),
            'title' => fake()->sentence(3),
            'visit_date' => now()->addWeek()->toDateString(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
