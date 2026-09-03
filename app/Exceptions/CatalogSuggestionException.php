<?php

namespace App\Exceptions;

use RuntimeException;

class CatalogSuggestionException extends RuntimeException
{
    public function __construct(public readonly string $failureCode, public readonly ?int $httpStatus = null)
    {
        parent::__construct($failureCode);
    }
}
