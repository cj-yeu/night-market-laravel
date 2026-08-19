<?php

namespace App\Http\Requests\VisitPlan;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SmartPlannerRecommendationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_CLIENT;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'visit_date' => ['required', 'date', 'after_or_equal:today'],
            'city' => [
                'nullable',
                'string',
                'max:100',
                Rule::exists('night_markets', 'city')->where(fn (Builder $query) => $query
                    ->where('status', NightMarket::STATUS_ACTIVE)
                    ->where('state', 'Selangor')),
            ],
            'night_market_id' => [
                'nullable',
                'integer',
                Rule::exists('night_markets', 'id')->where(fn (Builder $query) => $query
                    ->where('status', NightMarket::STATUS_ACTIVE)
                    ->where('state', 'Selangor')),
            ],
            'budget_min' => ['nullable', 'required_with:budget_max', 'numeric', 'min:0', 'max:10000', 'lte:budget_max'],
            'budget_max' => ['nullable', 'required_with:budget_min', 'numeric', 'min:0', 'max:10000', 'gte:budget_min'],
            'categories' => ['nullable', 'array', 'max:10'],
            'categories.*' => [
                'required',
                'string',
                'max:100',
                'distinct',
                Rule::in(Food::query()
                    ->publiclyVisible()
                    ->whereNotNull('category')
                    ->distinct()
                    ->pluck('category')
                    ->all()),
            ],
            'halal_preference' => ['required', Rule::in([
                'any',
                Stall::HALAL_CERTIFIED,
                Stall::HALAL_MUSLIM_OWNED_OR_CLAIMED,
                Stall::HALAL_NON_HALAL,
                Stall::HALAL_UNKNOWN,
            ])],
            'must_try' => ['required', 'boolean'],
            'max_markets' => ['required', 'integer', 'between:1,3'],
            'preference_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $city = $this->input('city');
        $categories = $this->input('categories');
        $notes = $this->input('preference_notes');

        $this->merge([
            'city' => is_string($city) ? (trim($city) !== '' ? trim($city) : null) : $city,
            'categories' => is_array($categories)
                ? array_map(fn ($category) => is_string($category) ? trim($category) : $category, $categories)
                : $categories,
            'halal_preference' => $this->input('halal_preference', 'any'),
            'must_try' => $this->has('must_try') ? $this->input('must_try') : false,
            'max_markets' => $this->input('max_markets', 1),
            'preference_notes' => is_string($notes) ? (trim($notes) !== '' ? trim($notes) : null) : $notes,
        ]);
    }
}
