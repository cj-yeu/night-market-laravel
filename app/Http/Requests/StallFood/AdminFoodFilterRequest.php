<?php

namespace App\Http\Requests\StallFood;

use App\Http\Requests\Concerns\ValidatesCatalogSelection;
use App\Models\Food;
use App\Support\CatalogCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminFoodFilterRequest extends FormRequest
{
    use ValidatesCatalogSelection;

    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'night_market_id' => ['nullable', 'integer', Rule::exists('night_markets', 'id')],
            'stall_id' => ['nullable', 'integer', Rule::exists('stalls', 'id')],
            'category' => ['nullable', 'string', 'max:100'],
            'is_must_try' => ['nullable', Rule::in(['0', '1'])],
            'status' => ['nullable', Rule::in([Food::STATUS_ACTIVE, Food::STATUS_INACTIVE])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? str($this->search)->squish()->value() : null,
            'category' => $this->filled('category') ? CatalogCategory::canonical((string) $this->category, 'food') : null,
        ]);
    }
}
