<?php

namespace App\Exceptions;

use RuntimeException;

class SocialMediaMetadataException extends RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct($failureCode);
    }
}
