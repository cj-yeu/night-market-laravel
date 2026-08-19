<?php

namespace App\Http\Requests\SocialMedia;

use App\Models\SocialMediaRecord;
use App\Models\User;
use App\Rules\SafeSocialMediaImageUrl;
use App\Rules\SupportedSocialMediaSourceUrl;
use App\Services\SocialMediaUrlPolicy;
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
            'original_post_url' => [
                'required',
                'string',
                'max:2048',
                new SupportedSocialMediaSourceUrl(app(SocialMediaUrlPolicy::class)),
            ],
            'extracted_title' => ['nullable', 'string', 'max:500'],
            'content_summary' => ['required', 'string', 'max:50000'],
            'external_image_url' => [
                'nullable',
                'string',
                'max:2048',
                new SafeSocialMediaImageUrl(app(SocialMediaUrlPolicy::class)),
            ],
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
        $platform = $this->input('platform');
        $sourceUrl = $this->input('original_post_url');
        $title = $this->input('extracted_title');
        $summary = $this->input('content_summary');
        $imageUrl = $this->input('external_image_url');
        $hashtags = $this->input('extracted_hashtags');
        $locations = $this->input('extracted_location_mentions');
        $markets = $this->input('extracted_market_mentions');
        $foods = $this->input('extracted_food_mentions');

        $this->merge([
            'platform' => is_string($platform) ? trim($platform) : $platform,
            'original_post_url' => is_string($sourceUrl) ? trim($sourceUrl) : $sourceUrl,
            'extracted_title' => is_string($title) ? (trim($title) ?: null) : $title,
            'content_summary' => is_string($summary) ? trim($summary) : $summary,
            'external_image_url' => is_string($imageUrl) ? (trim($imageUrl) ?: null) : $imageUrl,
            'extracted_hashtags' => is_string($hashtags) ? (trim($hashtags) ?: null) : $hashtags,
            'extracted_location_mentions' => is_string($locations) ? (trim($locations) ?: null) : $locations,
            'extracted_market_mentions' => is_string($markets) ? (trim($markets) ?: null) : $markets,
            'extracted_food_mentions' => is_string($foods) ? (trim($foods) ?: null) : $foods,
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
