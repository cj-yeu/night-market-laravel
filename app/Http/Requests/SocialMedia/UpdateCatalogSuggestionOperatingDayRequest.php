<?php

namespace App\Http\Requests\SocialMedia;

use App\Models\MarketOperatingDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCatalogSuggestionOperatingDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'day_of_week' => ['required', Rule::in(MarketOperatingDay::DAYS)],
            'opening_time' => ['nullable', 'date_format:H:i'],
            'closing_time' => ['nullable', 'date_format:H:i'],
            'evidence_text' => ['nullable', 'string', 'max:1000'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
