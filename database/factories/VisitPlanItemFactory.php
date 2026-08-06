<?php

namespace Database\Factories;

use App\Models\VisitPlan;
use App\Models\VisitPlanItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitPlanItem>
 */
class VisitPlanItemFactory extends Factory
{
    protected $model = VisitPlanItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visit_plan_id' => VisitPlan::factory(),
            'item_type' => 'stall',
            'item_name' => fake()->company().' Stall',
            'notes' => fake()->optional()->sentence(),
            'sort_order' => 1,
        ];
    }
}
