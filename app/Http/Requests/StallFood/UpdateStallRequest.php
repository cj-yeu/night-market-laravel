<?php

namespace App\Http\Requests\StallFood;

use App\Models\NightMarket;
use App\Models\Stall;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStallRequest extends FormRequest
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
        /** @var Stall $stall */
        $stall = $this->route('stall');

        return [
            'night_market_id' => [
                'required',
                'integer',
                Rule::exists('night_markets', 'id')
                    ->where(fn (Builder $query) => $query
                        ->where('status', NightMarket::STATUS_ACTIVE)
                        ->where('state', 'Selangor')),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('stalls', 'name')
                    ->where(fn ($query) => $query->where('night_market_id', $this->integer('night_market_id')))
                    ->ignore($stall),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'halal_status' => ['required', Rule::in(Stall::HALAL_STATUSES)],
            'halal_evidence_url' => [
                'nullable',
                'string',
                'max:255',
                'url:http,https',
                Rule::requiredIf(fn () => in_array($this->halal_status, [
                    Stall::HALAL_CERTIFIED,
                    Stall::HALAL_MUSLIM_OWNED_OR_CLAIMED,
                ], true) || ($this->halal_status === Stall::HALAL_NON_HALAL && ! $this->filled('halal_notes'))),
            ],
            'halal_notes' => [
                'nullable',
                'string',
                'max:5000',
                Rule::requiredIf(fn () => $this->halal_status === Stall::HALAL_NON_HALAL
                    && ! $this->filled('halal_evidence_url')),
            ],
            'source_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'verified_at' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'night_market_id.exists' => 'The selected Night Market must be active and located in Selangor.',
        ];
    }

    protected function prepareForValidation(): void
    {
        /** @var Stall $stall */
        $stall = $this->route('stall');

        $this->merge([
            'name' => str((string) $this->name)->squish()->value(),
            'description' => $this->filled('description') ? trim((string) $this->description) : null,
            'category' => $this->has('category')
                ? ($this->filled('category') ? str($this->category)->squish()->value() : null)
                : $stall->category,
            'halal_status' => $this->has('halal_status')
                ? trim((string) $this->halal_status)
                : $stall->halal_status,
            'halal_evidence_url' => $this->has('halal_evidence_url')
                ? ($this->filled('halal_evidence_url') ? trim((string) $this->halal_evidence_url) : null)
                : $stall->halal_evidence_url,
            'halal_notes' => $this->has('halal_notes')
                ? ($this->filled('halal_notes') ? trim((string) $this->halal_notes) : null)
                : $stall->halal_notes,
            'source_url' => $this->has('source_url')
                ? ($this->filled('source_url') ? trim((string) $this->source_url) : null)
                : $stall->source_url,
            'verified_at' => $this->has('verified_at')
                ? ($this->filled('verified_at') ? trim((string) $this->verified_at) : null)
                : $stall->verified_at?->format('Y-m-d'),
        ]);
    }
}
