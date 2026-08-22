<?php

namespace App\Http\Requests\StallFood;

use App\Models\Food;
use App\Models\Stall;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFoodRequest extends FormRequest
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
            'stall_id' => [
                'required',
                'integer',
                Rule::exists('stalls', 'id')
                    ->where(fn (Builder $query) => $query->where('status', Stall::STATUS_ACTIVE)),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('foods', 'name')
                    ->where(fn (Builder $query) => $query->where('stall_id', $this->stall_id)),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'price_min' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'price_max' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'gte:price_min'],
            'price_display' => ['nullable', 'string', 'max:255'],
            'is_must_try' => ['required', 'boolean'],
            'recommendation_reason' => ['nullable', 'string', 'max:5000'],
            'source_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'price_checked_at' => ['nullable', 'date', 'before_or_equal:today'],
            'verified_at' => ['nullable', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::in([Food::STATUS_ACTIVE, Food::STATUS_INACTIVE])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->name),
            'description' => $this->filled('description') ? trim((string) $this->description) : null,
            'category' => $this->filled('category') ? trim((string) $this->category) : null,
            'price_min' => $this->filled('price_min') ? trim((string) $this->price_min) : null,
            'price_max' => $this->filled('price_max') ? trim((string) $this->price_max) : null,
            'price_display' => $this->filled('price_display') ? trim((string) $this->price_display) : null,
            'is_must_try' => $this->boolean('is_must_try'),
            'recommendation_reason' => $this->filled('recommendation_reason') ? trim((string) $this->recommendation_reason) : null,
            'source_url' => $this->filled('source_url') ? trim((string) $this->source_url) : null,
            'price_checked_at' => $this->filled('price_checked_at') ? trim((string) $this->price_checked_at) : null,
            'verified_at' => $this->filled('verified_at') ? trim((string) $this->verified_at) : null,
        ]);
    }
}
