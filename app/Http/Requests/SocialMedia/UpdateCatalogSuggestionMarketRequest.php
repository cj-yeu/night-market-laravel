<?php

namespace App\Http\Requests\SocialMedia;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCatalogSuggestionMarketRequest extends FormRequest
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
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'evidence_text' => ['nullable', 'string', 'max:1000'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
