<?php

namespace App\Http\Requests\NightMarket;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\File;

class UpdateNightMarketImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                File::image()
                    ->types(['jpeg', 'jpg', 'png', 'webp'])
                    ->max(2 * 1024)
                    ->dimensions((new Dimensions)->maxWidth(4096)->maxHeight(4096)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'Please choose a Night Market cover image to upload.',
            'image.image' => 'The cover image must be a valid JPEG, PNG, or WebP image.',
            'image.mimes' => 'The cover image must be a JPEG, PNG, or WebP image.',
            'image.max' => 'The cover image must not be larger than 2 MB.',
            'image.dimensions' => 'The cover image dimensions must not exceed 4096 by 4096 pixels.',
        ];
    }
}
