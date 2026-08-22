<?php

namespace App\Http\Requests\Review;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReviewManagementFilterRequest extends FormRequest
{
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
            'search' => ['nullable', 'string', 'max:255'],
            'market_id' => ['nullable', 'integer', 'exists:night_markets,id'],
            'stall_id' => ['nullable', 'integer', 'exists:stalls,id'],
            'food_id' => ['nullable', 'integer', 'exists:foods,id'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? trim((string) $this->search) : null,
            'market_id' => $this->filled('market_id') ? $this->market_id : null,
            'stall_id' => $this->filled('stall_id') ? $this->stall_id : null,
            'food_id' => $this->filled('food_id') ? $this->food_id : null,
            'rating' => $this->filled('rating') ? $this->rating : null,
            'date_from' => $this->filled('date_from') ? trim((string) $this->date_from) : null,
            'date_to' => $this->filled('date_to') ? trim((string) $this->date_to) : null,
        ]);
    }
}
