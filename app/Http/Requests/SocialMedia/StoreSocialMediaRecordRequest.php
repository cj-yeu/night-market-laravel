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
            'night_market_id' => ['nullable', 'integer', 'exists:night_markets,id'],
            'food_id' => ['nullable', 'integer', 'exists:foods,id'],
            'platform' => ['required', Rule::in(SocialMediaRecord::PLATFORMS)],
            'original_post_url' => ['required', 'url:http,https', 'max:2048'],
            'content_summary' => ['required', 'string', 'max:50000'],
            'posted_date' => ['required', 'date', 'before_or_equal:today'],
            'likes' => ['required', 'integer', 'min:0'],
            'comments' => ['required', 'integer', 'min:0'],
            'shares' => ['required', 'integer', 'min:0'],
            'extracted_hashtags' => ['nullable', 'string', 'max:10000'],
            'extracted_location_mentions' => ['nullable', 'string', 'max:10000'],
            'extracted_market_mentions' => ['nullable', 'string', 'max:10000'],
            'extracted_food_mentions' => ['nullable', 'string', 'max:10000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'platform' => trim((string) $this->platform),
            'original_post_url' => trim((string) $this->original_post_url),
            'content_summary' => trim((string) $this->content_summary),
            'extracted_hashtags' => $this->filled('extracted_hashtags')
                ? trim((string) $this->extracted_hashtags)
                : null,
            'extracted_location_mentions' => $this->filled('extracted_location_mentions')
                ? trim((string) $this->extracted_location_mentions)
                : null,
            'extracted_market_mentions' => $this->filled('extracted_market_mentions')
                ? trim((string) $this->extracted_market_mentions)
                : null,
            'extracted_food_mentions' => $this->filled('extracted_food_mentions')
                ? trim((string) $this->extracted_food_mentions)
                : null,
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
            'extracted_hashtags' => 'extracted hashtags',
            'extracted_location_mentions' => 'extracted location mentions',
            'extracted_market_mentions' => 'extracted market mentions',
            'extracted_food_mentions' => 'extracted food mentions',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'original_post_url.required' => 'Please enter the original public post URL.',
            'original_post_url.url' => 'The original post URL must be a valid HTTP or HTTPS URL.',
            'content_summary.required' => 'Please paste the public caption, description, or transcript.',
            'content_summary.max' => 'The pasted public text must not exceed 50,000 characters.',
            'posted_date.before_or_equal' => 'The posted date cannot be in the future.',
            'likes.min' => 'Likes cannot be negative.',
            'comments.min' => 'Comments cannot be negative.',
            'shares.min' => 'Shares cannot be negative.',
        ];
    }
}
