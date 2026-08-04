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
        return $this->user()?->role === 'admin';
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
            'is_must_try' => ['required', 'boolean'],
            'status' => ['required', Rule::in([Food::STATUS_ACTIVE, Food::STATUS_INACTIVE])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->name),
            'description' => $this->filled('description') ? trim((string) $this->description) : null,
            'category' => $this->filled('category') ? trim((string) $this->category) : null,
            'is_must_try' => $this->boolean('is_must_try'),
        ]);
    }
}
