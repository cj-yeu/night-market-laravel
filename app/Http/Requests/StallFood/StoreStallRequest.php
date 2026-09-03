<?php

namespace App\Http\Requests\StallFood;

use App\Models\CatalogCategory;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Services\CatalogCategoryService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStallRequest extends FormRequest
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
                    ->where(fn (Builder $query) => $query->where('night_market_id', $this->night_market_id)),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'new_category' => ['nullable', 'string', 'max:100', 'not_regex:/[\\x00-\\x1F\\x7F]/', 'not_regex:/<[^>]*>/'],
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
            'status' => ['required', Rule::in([Stall::STATUS_ACTIVE, Stall::STATUS_INACTIVE])],
        ];
    }

    public function messages(): array
    {
        return [
            'night_market_id.exists' => 'The selected Night Market must be active and located in Selangor.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('new_category')) {
                return;
            }

            if (! app(CatalogCategoryService::class)->isPermittedSelection(
                CatalogCategory::TYPE_STALL,
                $this->input('category'),
            )) {
                $validator->errors()->add('category', 'Choose an active stall category or add a new one.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => str((string) $this->name)->squish()->value(),
            'description' => $this->filled('description') ? trim((string) $this->description) : null,
            'category' => $this->filled('category') ? str($this->category)->squish()->value() : null,
            'new_category' => $this->filled('new_category') ? (string) $this->new_category : null,
            'halal_status' => $this->filled('halal_status')
                ? trim((string) $this->halal_status)
                : Stall::HALAL_UNKNOWN,
            'halal_evidence_url' => $this->filled('halal_evidence_url') ? trim((string) $this->halal_evidence_url) : null,
            'halal_notes' => $this->filled('halal_notes') ? trim((string) $this->halal_notes) : null,
            'source_url' => $this->filled('source_url') ? trim((string) $this->source_url) : null,
            'verified_at' => $this->filled('verified_at') ? trim((string) $this->verified_at) : null,
        ]);
    }
}
