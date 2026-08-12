<?php

namespace App\Http\Requests\Review;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_CLIENT;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Please select a rating.',
            'rating.integer' => 'The rating must be a whole number.',
            'rating.between' => 'The rating must be between 1 and 5.',
            'comment.required' => 'Please enter your review.',
            'comment.string' => 'The review must be valid text.',
            'comment.min' => 'The review must be at least 10 characters.',
            'comment.max' => 'The review must not exceed 1,000 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'comment' => trim((string) $this->comment),
        ]);
    }
}
