<?php

namespace App\Http\Requests\NightMarket;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class DeleteNightMarketImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN;
    }

    /**
     * @return array<string, never>
     */
    public function rules(): array
    {
        return [];
    }
}
