<?php

namespace App\Http\Requests\Admin;

use App\Models\CatalogAuditLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogActivityFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() ?? false;
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['nullable', Rule::in([CatalogAuditLog::ENTITY_MARKET, CatalogAuditLog::ENTITY_STALL, CatalogAuditLog::ENTITY_FOOD, CatalogAuditLog::ENTITY_USER])],
            'action' => ['nullable', Rule::in(['created', 'updated', 'activated', 'deactivated', 'image_updated', 'image_removed', 'role_promoted', 'role_demoted'])],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['search' => $this->filled('search') ? str((string) $this->search)->squish()->value() : null]);
    }
}
