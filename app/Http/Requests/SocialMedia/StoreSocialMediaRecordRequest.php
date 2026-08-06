<?php

namespace App\Http\Requests\SocialMedia;

use App\Models\SocialMediaRecord;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSocialMediaRecordRequest extends FormRequest
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
            'night_market_id' => ['required', 'integer', 'exists:night_markets,id'],
            'food_id' => ['nullable', 'integer', 'exists:foods,id'],
            'platform' => ['required', Rule::in(SocialMediaRecord::PLATFORMS)],
            'original_post_url' => ['required', 'url:http,https', 'max:2048'],
            'content_summary' => ['required', 'string', 'max:2000'],
            'posted_date' => ['required', 'date', 'before_or_equal:today'],
            'likes' => ['required', 'integer', 'min:0'],
            'comments' => ['required', 'integer', 'min:0'],
            'shares' => ['required', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'platform' => trim((string) $this->platform),
            'original_post_url' => trim((string) $this->original_post_url),
            'content_summary' => trim((string) $this->content_summary),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'night_market_id' => 'night market',
            'food_id' => 'related food',
            'original_post_url' => 'original post URL',
            'content_summary' => 'caption / content summary',
            'posted_date' => 'posted date',
        ];
    }
}
