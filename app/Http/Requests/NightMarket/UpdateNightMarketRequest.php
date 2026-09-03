<?php

namespace App\Http\Requests\NightMarket;

use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Support\SelangorCities;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNightMarketRequest extends FormRequest
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
        $market = $this->route('nightMarket');
        $cities = SelangorCities::CANONICAL;
        if ($market instanceof NightMarket && filled($market->city)) {
            $cities[] = SelangorCities::normalize((string) $market->city);
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100', Rule::in($cities)],
            'description' => ['nullable', 'string', 'max:5000'],
            'source_url' => ['nullable', 'url', 'max:255'],
            'verified_at' => ['nullable', 'date', 'before_or_equal:today'],
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
            'city' => SelangorCities::normalize((string) $this->city),
            'description' => $this->filled('description') ? trim((string) $this->description) : null,
            'source_url' => $this->filled('source_url') ? trim((string) $this->source_url) : null,
            'verified_at' => $this->filled('verified_at') ? $this->verified_at : null,
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
