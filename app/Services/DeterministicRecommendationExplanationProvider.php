<?php

namespace App\Services;

use App\Contracts\RecommendationExplanationProvider;

class DeterministicRecommendationExplanationProvider implements RecommendationExplanationProvider
{
    /** @param list<string> $factors */
    public function explain(array $factors): string
    {
        return ucfirst(implode(', ', $factors)).'.';
    }
}
