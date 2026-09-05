<?php

namespace App\Exceptions;

use RuntimeException;

final class PlannerAiUnavailable extends RuntimeException
{
    public function __construct()
    {
        // Never carry provider bodies, headers, user input or chained exceptions.
        parent::__construct('AI assistance is unavailable. You can still use the basic planner.');
    }
}
