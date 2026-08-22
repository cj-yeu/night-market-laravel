<?php

namespace App\Http\Requests\NightMarket;

use Illuminate\Foundation\Http\FormRequest;

class DeleteNightMarketImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() ?? false;
    }

    /**
     * @return array<string, never>
     */
    public function rules(): array
    {
        return [];
    }
}
