<?php

namespace App\Rules;

use App\Services\YouTubeVideoUrlCanonicalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class CanonicalYouTubeVideoUrl implements ValidationRule
{
    public function __construct(private readonly YouTubeVideoUrlCanonicalizer $canonicalizer) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Enter a valid HTTPS YouTube video URL.');

            return;
        }

        try {
            $this->canonicalizer->canonicalize($value);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
