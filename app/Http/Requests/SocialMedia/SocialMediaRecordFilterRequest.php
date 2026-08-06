<?php

namespace App\Http\Requests\SocialMedia;

use App\Models\SocialMediaRecord;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SocialMediaRecordFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'night_market_id' => ['nullable', 'integer', 'exists:night_markets,id'],
            'platform' => ['nullable', Rule::in(SocialMediaRecord::PLATFORMS)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? trim((string) $this->search) : null,
        ]);
    }
}
