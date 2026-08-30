<?php

namespace App\Http\Requests\SocialMedia;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCatalogSuggestionFoodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price_display' => ['nullable', 'string', 'max:255'],
            'price_min' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'price_max' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'gte:price_min'],
            'is_must_try' => ['nullable', 'boolean'],
            'evidence_text' => ['nullable', 'string', 'max:1000'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
