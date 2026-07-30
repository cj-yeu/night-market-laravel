<?php

namespace App\Services;

use App\Models\SocialMediaRecord;

class SocialMediaDataService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SocialMediaRecord
    {
        return SocialMediaRecord::create($data);
    }
}
