<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleCalendarIntegrationException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $disconnect = false)
    {
        parent::__construct($message);
    }
}
