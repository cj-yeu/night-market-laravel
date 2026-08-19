<?php

namespace App\Http\Requests\VisitPlan;

use App\Models\NightMarket;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateVisitPlanRequest extends FormRequest
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
            'night_market_id' => [
                'nullable',
                'integer',
                Rule::exists('night_markets', 'id')->where(fn (Builder $query) => $query
                    ->where('status', NightMarket::STATUS_ACTIVE)
                    ->where('state', 'Selangor')),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'night_market_id' => $this->filled('night_market_id') ? $this->input('night_market_id') : null,
        ]);
    }
}
