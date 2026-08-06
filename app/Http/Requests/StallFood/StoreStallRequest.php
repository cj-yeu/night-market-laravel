<?php

namespace App\Http\Requests\StallFood;

use App\Models\NightMarket;
use App\Models\Stall;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'night_market_id' => [
                'required',
                'integer',
                Rule::exists('night_markets', 'id')
                    ->where(fn (Builder $query) => $query->where('status', NightMarket::STATUS_ACTIVE)),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('stalls', 'name')
                    ->where(fn (Builder $query) => $query->where('night_market_id', $this->night_market_id)),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in([Stall::STATUS_ACTIVE, Stall::STATUS_INACTIVE])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->name),
            'description' => $this->filled('description') ? trim((string) $this->description) : null,
        ]);
    }
}
