<?php

namespace App\Http\Requests\SocialMedia;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSocialMediaRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', 'max:100'],
            'post_url' => ['nullable', 'url:http,https', 'max:2048'],
            'content' => ['required', 'string', 'max:50000'],
            'post_date' => ['required', 'date'],
            'engagement_count' => ['required', 'integer', 'min:0'],
            'mentioned_market_name' => ['nullable', 'string', 'max:255'],
            'mentioned_food_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'platform' => trim((string) $this->platform),
            'post_url' => $this->filled('post_url') ? trim((string) $this->post_url) : null,
            'content' => trim((string) $this->content),
            'mentioned_market_name' => $this->filled('mentioned_market_name')
                ? trim((string) $this->mentioned_market_name)
                : null,
            'mentioned_food_name' => $this->filled('mentioned_food_name')
                ? trim((string) $this->mentioned_food_name)
                : null,
        ]);
    }
}
