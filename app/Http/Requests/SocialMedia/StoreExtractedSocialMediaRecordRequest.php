<?php

namespace App\Http\Requests\SocialMedia;

use App\Models\SocialMediaRecord;
use Illuminate\Validation\Rule;

class StoreExtractedSocialMediaRecordRequest extends StoreSocialMediaRecordRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'extraction_status' => ['required', Rule::in([
                SocialMediaRecord::EXTRACTION_SUCCEEDED,
                SocialMediaRecord::EXTRACTION_FAILED,
                SocialMediaRecord::EXTRACTION_MANUAL,
            ])],
        ];
    }
}
