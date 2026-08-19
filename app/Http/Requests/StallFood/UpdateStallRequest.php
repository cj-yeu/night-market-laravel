<?php

namespace App\Http\Requests\StallFood;

use App\Models\Stall;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStallRequest extends FormRequest
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
        /** @var Stall $stall */
        $stall = $this->route('stall');

        return [
            'night_market_id' => ['required', 'integer', Rule::exists('night_markets', 'id')],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('stalls', 'name')
                    ->where(fn ($query) => $query->where('night_market_id', $this->integer('night_market_id')))
                    ->ignore($stall),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => str((string) $this->name)->squish()->value(),
            'description' => $this->filled('description') ? trim((string) $this->description) : null,
        ]);
    }
}
