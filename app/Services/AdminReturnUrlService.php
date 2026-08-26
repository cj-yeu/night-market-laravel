<?php

namespace App\Services;

use Illuminate\Http\Request;

class AdminReturnUrlService
{
    public function catalogQualityUrl(Request $request): ?string
    {
        $value = $request->input('return_to');

        if (! is_string($value) || strlen($value) > 2048 || ! str_starts_with($value, '/admin/catalog-data-quality/')) {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'])) {
            return null;
        }

        return $value;
    }
}
