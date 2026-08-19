<?php

namespace App\Http\Requests\StallFood;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\File;

abstract class UpdateCatalogImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN;
    }

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

    public function messages(): array
    {
        return [
            'image.required' => 'Choose one image to upload.',
            'image.image' => 'The image must be a valid JPEG, PNG, or WebP image.',
            'image.mimes' => 'The image must be a JPEG, PNG, or WebP image.',
            'image.max' => 'The image must not be larger than 2 MB.',
            'image.dimensions' => 'The image dimensions must not exceed 4096 by 4096 pixels.',
        ];
    }
}
