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
            'is_must_try' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => str((string) $this->name)->squish()->value(),
            'description' => $this->filled('description') ? trim((string) $this->description) : null,
            'category' => $this->filled('category') ? str($this->category)->squish()->value() : null,
            'is_must_try' => $this->boolean('is_must_try'),
        ]);
    }
}
