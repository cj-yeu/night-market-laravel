<?php

namespace App\Http\Requests\NightMarket;

use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminNightMarketFilterRequest extends FormRequest
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
            'city' => ['nullable', 'string', 'max:100'],
            'operating_day' => ['nullable', Rule::in(MarketOperatingDay::DAYS)],
            'status' => ['nullable', Rule::in([NightMarket::STATUS_ACTIVE, NightMarket::STATUS_INACTIVE])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? str($this->search)->squish()->value() : null,
            'city' => $this->filled('city') ? str($this->city)->squish()->value() : null,
        ]);
    }
}
