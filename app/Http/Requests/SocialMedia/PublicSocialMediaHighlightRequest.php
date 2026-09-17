<?php

namespace App\Http\Requests\SocialMedia;

use App\Models\NightMarket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicSocialMediaHighlightRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'night_market_id' => [
                'nullable',
                'integer',
                Rule::exists('night_markets', 'id')->where(fn ($query) => $query
                    ->where('status', NightMarket::STATUS_ACTIVE)
                    ->where('state', 'Selangor')),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? trim((string) $this->search) : null,
            'night_market_id' => $this->filled('night_market_id') ? $this->night_market_id : null,
        ]);
    }
}
