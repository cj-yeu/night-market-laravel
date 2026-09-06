<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class CatalogGeminiConfiguration
{
    public function model(): string
    {
        if (! filled(config('services.gemini.api_key'))) {
            throw ValidationException::withMessages(['source' => 'GEMINI_API_KEY is not configured on this server. No Gemini request was sent.']);
        }
        if (! config('services.catalog_ai.free_tier_confirmed')) {
            throw ValidationException::withMessages(['source' => 'Confirm the key project shows Free in AI Studio, then set CATALOG_AI_FREE_TIER_CONFIRMED=true. Paid-tier free search allowances do not make input/output tokens free. No request was sent.']);
        }
        if (! in_array(config('services.catalog_ai.model'), ['gemini-2.5-flash', 'gemini-3.5-flash-lite'], true)) {
            throw ValidationException::withMessages(['source' => 'Configure a supported Catalog analysis model. No model switch or paid fallback was made.']);
        }

        return config('services.catalog_ai.model');
    }

    public function generationConfig(): array
    {
        // Do not send Gemini 2.5 thinkingBudget parameters to Gemini 3.x.
        return $this->model() === 'gemini-2.5-flash' ? ['thinkingConfig' => ['thinkingBudget' => 0]] : [];
    }
}
