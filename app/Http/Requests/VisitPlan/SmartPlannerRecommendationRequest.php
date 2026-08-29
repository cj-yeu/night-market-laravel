<?php

namespace App\Http\Requests\VisitPlan;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use App\Support\SmartPlannerTemplate;
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
            'template' => ['nullable', 'string', Rule::in(SmartPlannerTemplate::KEYS)],
            'visit_date' => ['required', 'date', 'after_or_equal:today'],
            'city' => [
                'nullable',
                'string',
                'max:100',
                Rule::exists('night_markets', 'city')->where(fn (Builder $query) => $query
                    ->whereIn('id', NightMarket::query()->eligibleForPlanning()->select('id'))),
            ],
            'night_market_id' => [
                'nullable',
                'integer',
                Rule::exists('night_markets', 'id')->where(fn (Builder $query) => $query
                    ->whereIn('id', NightMarket::query()->eligibleForPlanning()->select('id'))),
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

    public function messages(): array
    {
        return [
            'city.exists' => 'The selected city no longer has a Market with schedule, stall, and food data for planning.',
            'night_market_id.exists' => 'The selected Night Market no longer has enough schedule, stall, and food data for planning.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $city = $this->input('city');
        $categories = $this->input('categories');
        $notes = $this->input('preference_notes');
        $template = $this->input('template');
        $budgetMinimum = $this->input('budget_min');
        $budgetMaximum = $this->input('budget_max');
        $isBudgetTemplate = $template === SmartPlannerTemplate::BUDGET;

        $this->merge([
            'template' => is_string($template) && trim($template) !== '' ? trim($template) : null,
            'city' => is_string($city) ? (trim($city) !== '' ? trim($city) : null) : $city,
            'categories' => is_array($categories)
                ? array_map(fn ($category) => is_string($category) ? trim($category) : $category, $categories)
                : $categories,
            'halal_preference' => $this->input('halal_preference', 'any'),
            'must_try' => $this->has('must_try') ? $this->input('must_try') : false,
            'max_markets' => $this->input('max_markets', 1),
            'budget_min' => $isBudgetTemplate && ! filled($budgetMinimum) ? 0 : $budgetMinimum,
            'budget_max' => $isBudgetTemplate && ! filled($budgetMaximum) ? 30 : $budgetMaximum,
            'preference_notes' => is_string($notes) ? (trim($notes) !== '' ? trim($notes) : null) : $notes,
        ]);
    }
}
