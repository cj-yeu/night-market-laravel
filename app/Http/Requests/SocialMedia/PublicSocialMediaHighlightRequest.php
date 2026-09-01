<?php

namespace App\Http\Requests\SocialMedia;

use App\Models\SocialMediaRecord;
use App\Services\SocialMediaDataService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicSocialMediaHighlightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', Rule::in(SocialMediaRecord::PLATFORMS)],
            'night_market_id' => ['nullable', 'integer', 'exists:night_markets,id'],
            'hashtag' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(array_keys(SocialMediaDataService::PUBLIC_SORTS))],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? trim((string) $this->search) : null,
            'platform' => $this->filled('platform') ? trim((string) $this->platform) : null,
            'night_market_id' => $this->filled('night_market_id') ? (int) $this->night_market_id : null,
            'hashtag' => $this->filled('hashtag') ? $this->normalizeHashtag((string) $this->hashtag) : null,
            'sort' => $this->filled('sort') ? trim((string) $this->sort) : null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'night_market_id' => 'night market',
        ];
    }

    /**
     * Stored hashtags are always lowercase and prefixed with "#", so incoming
     * values are normalised the same way before they reach the query.
     */
    private function normalizeHashtag(string $hashtag): ?string
    {
        $hashtag = '#'.ltrim(mb_strtolower(trim($hashtag)), '#');

        return $hashtag === '#' ? null : $hashtag;
    }
}
