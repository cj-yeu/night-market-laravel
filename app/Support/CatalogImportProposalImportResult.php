<?php

namespace App\Support;

use App\Models\CatalogImportProposal;

class CatalogImportProposalImportResult
{
    public function __construct(
        public readonly CatalogImportProposal $proposal,
        public readonly bool $wasAlreadyImported = false,
    ) {}
}
