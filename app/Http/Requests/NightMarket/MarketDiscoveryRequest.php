<?php

namespace App\Http\Requests\NightMarket;

use App\Models\MarketOperatingDay;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
            'search' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'operating_day' => ['nullable', Rule::in(MarketOperatingDay::DAYS)],
            'sort' => ['nullable', Rule::in(['name_asc', 'name_desc', 'city_asc'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? Str::squish((string) $this->search) : null,
            'city' => $this->filled('city') ? Str::squish((string) $this->city) : null,
            'district' => $this->filled('district') ? Str::squish((string) $this->district) : null,
            'operating_day' => $this->filled('operating_day')
                ? trim((string) $this->operating_day)
                : null,
            'sort' => $this->filled('sort') ? trim((string) $this->sort) : null,
        ]);
    }
}
