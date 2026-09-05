<?php

namespace App\Http\Requests\VisitPlan;

class SavePlannerSnapshotRequest extends PlannerSnapshotRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'title' => ['required', 'string', 'max:255'],
            'night_market_id' => ['required', 'integer', 'min:1'],
            'food_ids' => ['required', 'array', 'min:1', 'max:5'],
            'food_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'confirmed_fallback_date' => ['nullable', 'boolean'],
        ];
    }
}
