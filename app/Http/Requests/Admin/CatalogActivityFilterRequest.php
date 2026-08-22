<?php

namespace App\Http\Requests\Admin;

use App\Models\CatalogAuditLog;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogActivityFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN;
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['nullable', Rule::in([CatalogAuditLog::ENTITY_MARKET, CatalogAuditLog::ENTITY_STALL, CatalogAuditLog::ENTITY_FOOD])],
            'action' => ['nullable', Rule::in(['created', 'updated', 'activated', 'deactivated', 'image_updated', 'image_removed'])],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['search' => $this->filled('search') ? str((string) $this->search)->squish()->value() : null]);
    }
}
