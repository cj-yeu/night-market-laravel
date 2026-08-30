<?php

namespace App\Http\Requests\SocialMedia;

use App\Models\Stall;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCatalogSuggestionStallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'halal_status' => ['required', Rule::in(Stall::HALAL_STATUSES)],
            'evidence_text' => ['nullable', 'string', 'max:1000'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
