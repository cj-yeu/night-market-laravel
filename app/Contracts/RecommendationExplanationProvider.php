<?php

namespace App\Contracts;

interface RecommendationExplanationProvider
{
    /** @param list<string> $factors */
    public function explain(array $factors): string;
}
