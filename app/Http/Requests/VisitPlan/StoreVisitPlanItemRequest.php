<?php

namespace App\Http\Requests\VisitPlan;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVisitPlanItemRequest extends FormRequest
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
            'item_type' => ['required', Rule::in(['stall', 'food'])],
            'item_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $notes = $this->input('notes');

        $this->merge([
            'notes' => is_string($notes) ? (trim($notes) !== '' ? trim($notes) : null) : $notes,
        ]);
    }
}
