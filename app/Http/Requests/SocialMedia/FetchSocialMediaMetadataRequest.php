<?php

namespace App\Http\Requests\SocialMedia;

use Illuminate\Foundation\Http\FormRequest;

class FetchSocialMediaMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'social_media_source_id' => ['prohibited'],
            'source_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'social_media_source_id.prohibited' => 'The metadata source is fixed by the selected proposal.',
            'source_id.prohibited' => 'The metadata source is fixed by the selected proposal.',
        ];
    }
}
