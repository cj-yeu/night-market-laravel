<?php

namespace App\Http\Requests\StallFood;

use App\Http\Requests\Concerns\ValidatesCatalogSelection;
use App\Models\Stall;
use App\Support\CatalogCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublicStallDiscoveryRequest extends FormRequest
{
    use ValidatesCatalogSelection;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'night_market_id' => ['nullable', 'integer', 'min:1'],
            'city' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'halal_status' => ['nullable', Rule::in(Stall::HALAL_STATUSES)],
            'sort' => ['nullable', Rule::in(['name_asc', 'name_desc', 'market_asc'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? Str::squish((string) $this->search) : null,
            'city' => $this->filled('city') ? Str::squish((string) $this->city) : null,
            'category' => $this->filled('category') ? CatalogCategory::main((string) $this->category) : null,
            'halal_status' => $this->filled('halal_status') ? trim((string) $this->halal_status) : null,
            'sort' => $this->filled('sort') ? trim((string) $this->sort) : null,
        ]);
    }
}
