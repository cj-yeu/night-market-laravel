<?php

namespace App\Http\Requests\StallFood;

use App\Models\Food;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFoodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Food $food */
        $food = $this->route('food');

        return [
            'stall_id' => ['required', 'integer', Rule::exists('stalls', 'id')],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('foods', 'name')
                    ->where(fn ($query) => $query->where('stall_id', $this->integer('stall_id')))
                    ->ignore($food),
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
        ];
    }

    protected function prepareForValidation(): void
    {
        /** @var Food $food */
        $food = $this->route('food');

        $this->merge([
            'name' => str((string) $this->name)->squish()->value(),
            'description' => $this->filled('description') ? trim((string) $this->description) : null,
            'category' => $this->filled('category') ? str($this->category)->squish()->value() : null,
            'price_min' => $this->has('price_min')
                ? ($this->filled('price_min') ? trim((string) $this->price_min) : null)
                : $food->price_min,
            'price_max' => $this->has('price_max')
                ? ($this->filled('price_max') ? trim((string) $this->price_max) : null)
                : $food->price_max,
            'price_display' => $this->has('price_display')
                ? ($this->filled('price_display') ? trim((string) $this->price_display) : null)
                : $food->price_display,
            'is_must_try' => $this->boolean('is_must_try'),
            'recommendation_reason' => $this->has('recommendation_reason')
                ? ($this->filled('recommendation_reason') ? trim((string) $this->recommendation_reason) : null)
                : $food->recommendation_reason,
            'source_url' => $this->has('source_url')
                ? ($this->filled('source_url') ? trim((string) $this->source_url) : null)
                : $food->source_url,
            'price_checked_at' => $this->has('price_checked_at')
                ? ($this->filled('price_checked_at') ? trim((string) $this->price_checked_at) : null)
                : $food->price_checked_at?->format('Y-m-d'),
            'verified_at' => $this->has('verified_at')
                ? ($this->filled('verified_at') ? trim((string) $this->verified_at) : null)
                : $food->verified_at?->format('Y-m-d'),
        ]);
    }
}
