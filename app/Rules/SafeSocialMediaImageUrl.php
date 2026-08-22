<?php

namespace App\Rules;

use App\Exceptions\SocialMediaExtractionException;
use App\Services\SocialMediaUrlPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeSocialMediaImageUrl implements ValidationRule
{
    public function __construct(private readonly SocialMediaUrlPolicy $urlPolicy) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        try {
            $this->urlPolicy->assertSafeImageUrl($value);
        } catch (SocialMediaExtractionException $exception) {
            $fail($exception->getMessage());
        }
    }
}
