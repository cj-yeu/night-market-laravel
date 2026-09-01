<?php

namespace App\Http\Requests\SocialMedia;

/**
 * Bulk moderation reuses the single-record rules — same status values, same
 * rejection-reason requirement — and only adds the list of records to act on.
 * Extending keeps authorisation and the reason rule in one place.
 */
class BulkModerateSocialMediaRecordsRequest extends ModerateSocialMediaRecordRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', 'exists:social_media_records,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ids' => 'selected records',
            'ids.*' => 'selected record',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one record before applying a bulk action.',
            'ids.max' => 'A maximum of 100 records can be moderated at once.',
        ];
    }
}
