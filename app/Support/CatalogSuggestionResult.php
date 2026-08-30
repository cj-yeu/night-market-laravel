<?php

namespace App\Support;

final readonly class CatalogSuggestionResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload) {}
}
