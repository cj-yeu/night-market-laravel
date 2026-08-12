<?php

namespace App\Http\Requests\Review;

use App\Models\Review;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewManagementFilterRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'market_id' => ['nullable', 'integer', 'exists:night_markets,id'],
            'status' => ['nullable', Rule::in(Review::STATUSES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? trim((string) $this->search) : null,
            'market_id' => $this->filled('market_id') ? $this->market_id : null,
            'status' => $this->filled('status') ? trim((string) $this->status) : null,
        ]);
    }
}
