<?php

namespace App\Http\Requests\UserAccount;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\File;

class UpdateProfileImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
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
            'avatar.required' => 'Please choose a profile image to upload.',
            'avatar.image' => 'The profile image must be a valid JPEG, PNG, or WebP image.',
            'avatar.mimes' => 'The profile image must be a JPEG, PNG, or WebP image.',
            'avatar.max' => 'The profile image must not be larger than 2 MB.',
            'avatar.dimensions' => 'The profile image dimensions must not exceed 4096 by 4096 pixels.',
        ];
    }
}
