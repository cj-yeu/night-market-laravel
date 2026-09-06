<?php

namespace App\Support;

final readonly class CatalogSuggestionInput
{
    /** @param array<string, string|null> $authoritativeTarget */
    public function __construct(
        public string $sourceTitle,
        public string $sourceDescription,
        public ?string $sourceCreator,
        public string $targetType,
        public array $authoritativeTarget,
        public string $model,
        public bool $moduleImport = false,
    ) {}
}
