<?php

namespace App\Http\Requests\NightMarket;

use App\Models\MarketOperatingDay;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNightMarketRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'operating_days' => ['required', 'array', 'min:1'],
            'operating_days.*.day_of_week' => ['required', 'distinct', Rule::in(MarketOperatingDay::DAYS)],
            'operating_days.*.opening_time' => ['required', 'date_format:H:i'],
            'operating_days.*.closing_time' => ['required', 'date_format:H:i'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => str((string) $this->name)->squish()->value(),
            'address' => str((string) $this->address)->squish()->value(),
            'city' => str((string) $this->city)->squish()->value(),
            'description' => $this->filled('description') ? trim((string) $this->description) : null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'operating_days.required' => 'Select at least one operating day.',
            'operating_days.min' => 'Select at least one operating day.',
            'operating_days.*.day_of_week.distinct' => 'Each operating day may only be selected once.',
        ];
    }
}
