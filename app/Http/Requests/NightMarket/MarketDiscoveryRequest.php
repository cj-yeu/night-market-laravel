<?php

namespace App\Http\Requests\NightMarket;

use App\Models\MarketOperatingDay;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketDiscoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:100'],
            'operating_day' => ['nullable', Rule::in(MarketOperatingDay::DAYS)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? trim((string) $this->search) : null,
            'district' => $this->filled('district') ? trim((string) $this->district) : null,
            'operating_day' => $this->filled('operating_day')
                ? trim((string) $this->operating_day)
                : null,
        ]);
    }
}
