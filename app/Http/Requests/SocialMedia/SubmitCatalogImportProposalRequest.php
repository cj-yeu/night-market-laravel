<?php

namespace App\Http\Requests\SocialMedia;

use Illuminate\Foundation\Http\FormRequest;

class SubmitCatalogImportProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
