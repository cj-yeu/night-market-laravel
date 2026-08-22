<?php

namespace App\Http\Requests\StallFood;

use App\Models\Stall;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublicFoodDiscoveryRequest extends FormRequest
{
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
            'stall_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', 'max:100'],
            'halal_status' => ['nullable', Rule::in(Stall::HALAL_STATUSES)],
            'is_must_try' => ['nullable', Rule::in(['0', '1'])],
            'min_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'max_price' => [
                'nullable',
                'numeric',
                'decimal:0,2',
                'min:0',
                Rule::when($this->filled('min_price'), ['gte:min_price']),
            ],
            'sort' => ['nullable', Rule::in([
                'name_asc',
                'name_desc',
                'price_low_high',
                'price_high_low',
                'must_try_first',
            ])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? Str::squish((string) $this->search) : null,
            'category' => $this->filled('category') ? Str::squish((string) $this->category) : null,
            'halal_status' => $this->filled('halal_status') ? trim((string) $this->halal_status) : null,
            'is_must_try' => $this->normalizeBooleanFilter($this->input('is_must_try')),
            'sort' => $this->filled('sort') ? trim((string) $this->sort) : null,
        ]);
    }

    private function normalizeBooleanFilter(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes' => '1',
            '0', 'false', 'no' => '0',
            default => $value,
        };
    }
}
