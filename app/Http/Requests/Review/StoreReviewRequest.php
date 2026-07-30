<?php

namespace App\Http\Requests\Review;

use App\Models\NightMarket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'client';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'night_market_id' => [
                'required',
                'integer',
                Rule::exists('night_markets', 'id')
                    ->where(fn (Builder $query) => $query->where('status', NightMarket::STATUS_ACTIVE)),
            ],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'comment' => $this->filled('comment') ? trim((string) $this->comment) : null,
        ]);
    }
}
