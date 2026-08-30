<?php

namespace App\Contracts;

use App\Support\CatalogSuggestionInput;
use App\Support\CatalogSuggestionResult;

interface CatalogSuggestionProvider
{
    public function extract(CatalogSuggestionInput $input): CatalogSuggestionResult;
}
