<?php

namespace App\Http\Requests\StallFood;

use App\Models\Stall;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminStallFilterRequest extends FormRequest
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
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'night_market_id' => ['nullable', 'integer', Rule::exists('night_markets', 'id')],
            'status' => ['nullable', Rule::in([Stall::STATUS_ACTIVE, Stall::STATUS_INACTIVE])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? str($this->search)->squish()->value() : null,
        ]);
    }
}
