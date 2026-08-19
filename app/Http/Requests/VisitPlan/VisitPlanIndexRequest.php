<?php

namespace App\Http\Requests\VisitPlan;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VisitPlanIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_CLIENT;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['upcoming', 'today', 'past'])],
            'item_type' => ['nullable', 'required_with:item_id', Rule::in(['stall', 'food'])],
            'item_id' => ['nullable', 'required_with:item_type', 'integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $search = $this->input('search');

        $this->merge([
            'search' => is_string($search) ? (trim($search) !== '' ? trim($search) : null) : $search,
            'status' => $this->filled('status') ? $this->input('status') : null,
            'item_type' => $this->filled('item_type') ? $this->input('item_type') : null,
            'item_id' => $this->filled('item_id') ? $this->input('item_id') : null,
        ]);
    }
}
