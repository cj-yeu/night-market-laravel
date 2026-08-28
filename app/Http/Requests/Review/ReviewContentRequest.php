<?php

namespace App\Http\Requests\Review;

use App\Models\ReviewTag;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

abstract class ReviewContentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['required', 'string', 'min:10', 'max:1000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', 'distinct', Rule::exists('review_tags', 'id')->where(fn ($query) => $query->whereIn('name', ReviewTag::NAMES))],
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
            'tags.*.exists' => 'Please choose a valid review tag.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $comment = $this->input('comment');

        $this->merge([
            'comment' => is_string($comment) ? trim($comment) : $comment,
        ]);
    }

    /**
     * Only validated submissions consume the shared create/update limit.
     */
    protected function passedValidation(): void
    {
        $key = 'review-submissions:'.$this->user()->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'comment' => 'Too many review submissions. Please wait a minute and try again.',
            ]);
        }

        RateLimiter::hit($key, 60);
    }
}
