<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserStatusRequest extends FormRequest
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
            'is_active' => ['required', 'boolean'],
            'redirect_to' => ['nullable', Rule::in(['index', 'show'])],
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in([User::ROLE_ADMIN, User::ROLE_CLIENT])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'verification' => ['nullable', Rule::in(['verified', 'pending'])],
            'auth_method' => ['nullable', Rule::in([
                User::AUTH_PASSWORD,
                User::AUTH_GOOGLE,
                User::AUTH_PASSWORD_AND_GOOGLE,
            ])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? str($this->search)->squish()->value() : null,
        ]);
    }
}
