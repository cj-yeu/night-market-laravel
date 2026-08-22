<?php

namespace App\Http\Requests\StallFood;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class DeleteCatalogImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN;
    }

    public function rules(): array
    {
        return [];
    }
}
