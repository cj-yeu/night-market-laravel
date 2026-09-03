<?php

namespace App\Http\Requests\SocialMedia;

use App\Models\SocialMediaRecord;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkModerateSocialMediaRecordsRequest extends FormRequest
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
        return [
            'record_ids' => ['required', 'array', 'min:1', 'max:100'],
            'record_ids.*' => ['required', 'integer', 'distinct', 'exists:social_media_records,id'],
            'action' => ['required', Rule::in([
                SocialMediaRecord::STATUS_APPROVED,
                SocialMediaRecord::STATUS_REJECTED,
            ])],
            'rejection_reason' => [
                'exclude_unless:action,'.SocialMediaRecord::STATUS_REJECTED,
                'required',
                'string',
                'min:3',
                'max:500',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('rejection_reason');

        $this->merge([
            'rejection_reason' => is_string($reason) ? (trim(strip_tags($reason)) ?: null) : $reason,
        ]);
    }
}
