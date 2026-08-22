<?php

namespace App\Http\Requests\SocialMedia;

use App\Rules\SupportedSocialMediaSourceUrl;
use App\Services\SocialMediaUrlPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExtractSocialMediaMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'source_url' => [
                'required',
                'string',
                'max:2048',
                new SupportedSocialMediaSourceUrl(app(SocialMediaUrlPolicy::class)),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $sourceUrl = $this->input('source_url');
        $this->merge([
            'source_url' => is_string($sourceUrl) ? trim($sourceUrl) : $sourceUrl,
        ]);
    }
}
