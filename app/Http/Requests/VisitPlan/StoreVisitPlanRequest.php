<?php

namespace App\Http\Requests\VisitPlan;

use App\Http\Requests\Concerns\ValidatesCatalogSelection;
use App\Models\NightMarket;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVisitPlanRequest extends FormRequest
{
    use ValidatesCatalogSelection;

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
            'city' => ['nullable', 'string', 'max:100'],
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
        $title = $this->input('title');
        $notes = $this->input('notes');

        $this->merge([
            'title' => is_string($title) ? trim($title) : $title,
            'notes' => is_string($notes) ? (trim($notes) !== '' ? trim($notes) : null) : $notes,
        ]);
    }
}
