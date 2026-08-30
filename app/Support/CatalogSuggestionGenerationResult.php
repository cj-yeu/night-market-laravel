<?php

namespace App\Support;

use App\Models\CatalogImportProposal;

final readonly class CatalogSuggestionGenerationResult
{
    public function __construct(
        public CatalogImportProposal $proposal,
        public bool $wasSkipped,
        public bool $retainedPreviousSuggestions = false,
    ) {}
}
