<?php

namespace App\Http\Requests\VisitPlan;

use App\Models\User;
use App\Support\SmartPlannerTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SmartPlannerTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_CLIENT;
    }

    public function rules(): array
    {
        return [
            'template' => ['nullable', 'string', Rule::in(SmartPlannerTemplate::KEYS)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $template = $this->input('template');

        $this->merge([
            'template' => is_string($template) && trim($template) !== '' ? trim($template) : null,
        ]);
    }
}
