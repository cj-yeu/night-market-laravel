<?php

namespace App\Http\Requests\VisitPlan;

use App\Support\SmartPlannerTemplate;
use Illuminate\Validation\Rule;

class SmartPlannerCreatePlanRequest extends SmartPlannerRecommendationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['night_market_id'][0] = 'required';

        return [
            ...$rules,
            'requested_date' => ['required', 'date', 'after_or_equal:today'],
            'confirmed_fallback_date' => ['nullable', 'boolean'],
            'title' => ['required', 'string', 'max:255'],
            'stall_ids' => [
                Rule::requiredIf(fn () => ! SmartPlannerTemplate::isKnown($this->input('template'))),
                'nullable',
                'array',
                'max:20',
            ],
            'stall_ids.*' => ['required', 'integer', 'distinct'],
            'food_ids' => ['required', 'array', 'min:1', 'max:20'],
            'food_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $title = $this->input('title');
        $this->merge([
            'title' => is_string($title) ? trim($title) : $title,
        ]);
    }
}
