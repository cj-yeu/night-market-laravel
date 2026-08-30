<?php

namespace App\Contracts;

use App\Models\SocialMediaSource;
use App\Support\SocialMediaMetadata;

interface SocialMediaMetadataProvider
{
    public function fetch(SocialMediaSource $source): SocialMediaMetadata;
}
