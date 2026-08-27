<?php

namespace App\Http\Requests\SocialMedia;

use App\Models\SocialMediaRecord;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerateSocialMediaRecordRequest extends FormRequest
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
            'status' => [
                'required',
                Rule::in([
                    SocialMediaRecord::STATUS_APPROVED,
                    SocialMediaRecord::STATUS_REJECTED,
                ]),
            ],
            'rejection_reason' => [
                'exclude_unless:status,'.SocialMediaRecord::STATUS_REJECTED,
                'required',
                'string',
                'min:10',
                'max:500',
            ],
        ];
    }
}
