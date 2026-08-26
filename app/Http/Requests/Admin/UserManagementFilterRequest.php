<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserManagementFilterRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN, User::ROLE_CLIENT])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'verification' => ['nullable', Rule::in(['verified', 'pending'])],
            'auth_method' => ['nullable', Rule::in([
                User::AUTH_PASSWORD,
                User::AUTH_GOOGLE,
                User::AUTH_PASSWORD_AND_GOOGLE,
            ])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? str($this->search)->squish()->value() : null,
        ]);
    }
}
