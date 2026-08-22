<?php

namespace App\Http\Requests\StallFood;

use Illuminate\Foundation\Http\FormRequest;

abstract class DeleteCatalogImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
