<?php

namespace App\Http\Requests\VisitPlan;

use App\Models\NightMarket;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVisitPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_CLIENT;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'night_market_id' => [
                'required',
                'integer',
                Rule::exists('night_markets', 'id')
                    ->where(fn (Builder $query) => $query
                        ->where('status', NightMarket::STATUS_ACTIVE)
                        ->where('state', 'Selangor')),
            ],
            'visit_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->title),
            'notes' => $this->filled('notes') ? trim((string) $this->notes) : null,
        ]);
    }
}
