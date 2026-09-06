<?php

namespace App\Http\Requests;

use App\Models\MarketOperatingDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogAiImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() ?? false;
    }

    public function rules(): array
    {
        return [
            'module' => ['nullable', Rule::in(['night-markets', 'stalls', 'foods'])],
            'name' => ['nullable', 'string', 'max:255'], 'city' => ['nullable', 'string', 'max:100'],
            'market_id' => ['nullable', 'integer'], 'stall_id' => ['nullable', 'integer'],
            'search_id' => ['nullable', 'uuid'], 'source_ids' => ['nullable', 'array', 'max:3'],
            'search_kind' => ['nullable', Rule::in(['all', 'articles', 'videos'])],
            'video_start_seconds' => ['nullable', 'integer', 'min:0', 'max:43199'],
            'video_end_seconds' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'source_ids.*' => ['integer', 'min:0', 'max:7'], 'url' => ['nullable', 'url:https', 'max:2048'],
            'text' => ['nullable', 'string', 'max:30000'], 'screenshot' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
            'revision' => ['nullable', 'string', 'max:64'], 'confirm' => ['sometimes', 'accepted'],
            'market' => ['nullable', 'array'], 'market.name' => ['nullable', 'string', 'max:255'],
            'market.address' => ['nullable', 'string', 'max:255'], 'market.city' => ['nullable', 'string', 'max:100'],
            'market.matched_night_market_id' => ['nullable', 'integer'],
            'market.selected' => ['nullable', 'boolean'],
            'operating_days' => ['sometimes', 'array', 'max:7'],
            'operating_days.*.selected' => ['nullable', 'boolean'],
            'operating_days.*.day_of_week' => ['required', Rule::in(MarketOperatingDay::DAYS)],
            'operating_days.*.opening_time' => ['nullable', 'date_format:H:i'],
            'operating_days.*.closing_time' => ['nullable', 'date_format:H:i'],
            'operating_days.*.evidence_text' => ['nullable', 'string', 'max:2000'],
            'stalls' => ['nullable', 'array', 'max:30'], 'stalls.*' => ['array'],
            'stalls.*.name' => ['nullable', 'string', 'max:255'], 'stalls.*.matched_stall_id' => ['nullable', 'integer'],
            'stalls.*.selected' => ['nullable', 'boolean'], 'stalls.*.parent_confirmed' => ['nullable', 'boolean'],
            'stalls.*.foods' => ['nullable', 'array', 'max:30'], 'stalls.*.foods.*' => ['array'],
            'stalls.*.foods.*.name' => ['nullable', 'string', 'max:255'], 'stalls.*.foods.*.selected' => ['nullable', 'boolean'],
            'stalls.*.foods.*.matched_food_id' => ['nullable', 'integer'],
            'stalls.*.foods.*.category' => ['nullable', 'string', 'max:100'],
            'stalls.*.foods.*.description' => ['nullable', 'string', 'max:5000'],
            'stalls.*.foods.*.price_min' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'stalls.*.foods.*.price_max' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'stalls.*.foods.*.unit' => ['nullable', 'string', 'max:100'],
            'stalls.*.foods.*.currency' => ['nullable', Rule::in(['MYR'])],
            'stalls.*.foods.*.price_checked_at' => ['nullable', 'date', 'before_or_equal:today'],
            'stalls.*.foods.*.photo_confirmed' => ['nullable', 'boolean'],
            'stalls.*.foods.*.remove_image' => ['nullable', 'boolean'],
            'stalls.*.foods.*.candidate_image' => ['nullable', 'url:https', 'max:2048'],
            'stalls.*.foods.*.image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ];
    }
}
