<?php

namespace App\Http\Requests\SocialMedia;

use App\Models\CatalogImportProposal;
use App\Rules\CanonicalYouTubeVideoUrl;
use App\Services\YouTubeVideoUrlCanonicalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCatalogImportProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'youtube_url' => [
                'required',
                'string',
                'max:2048',
                new CanonicalYouTubeVideoUrl(app(YouTubeVideoUrlCanonicalizer::class)),
            ],
            'target_type' => ['required', Rule::in(CatalogImportProposal::TARGET_TYPES)],
            'matched_night_market_id' => ['nullable', 'integer', 'exists:night_markets,id'],
            'matched_stall_id' => ['nullable', 'integer', 'exists:stalls,id'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $targetType = $this->input('target_type');
            $marketId = $this->input('matched_night_market_id');
            $stallId = $this->input('matched_stall_id');

            if ($targetType === CatalogImportProposal::TARGET_EXISTING_MARKET) {
                if (! $marketId) {
                    $validator->errors()->add('matched_night_market_id', 'Select one eligible Night Market.');
                }

                if ($stallId) {
                    $validator->errors()->add('matched_stall_id', 'An existing Market proposal cannot select a Stall.');
                }
            }

            if ($targetType === CatalogImportProposal::TARGET_EXISTING_STALL && ! $stallId) {
                $validator->errors()->add('matched_stall_id', 'Select one eligible Stall.');
            }

            if ($targetType === CatalogImportProposal::TARGET_NEW_MARKET && ($marketId || $stallId)) {
                $validator->errors()->add('target_type', 'A new Market proposal cannot be linked to an existing Market or Stall.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'youtube_url' => is_string($this->input('youtube_url')) ? trim($this->input('youtube_url')) : $this->input('youtube_url'),
            'target_type' => is_string($this->input('target_type')) ? trim($this->input('target_type')) : $this->input('target_type'),
            'matched_night_market_id' => $this->filled('matched_night_market_id') ? $this->input('matched_night_market_id') : null,
            'matched_stall_id' => $this->filled('matched_stall_id') ? $this->input('matched_stall_id') : null,
        ]);
    }
}
