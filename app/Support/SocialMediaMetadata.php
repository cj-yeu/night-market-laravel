<?php

namespace App\Support;

use Carbon\CarbonInterface;

final readonly class SocialMediaMetadata
{
    public function __construct(
        public string $title,
        public string $descriptionExcerpt,
        public string $creatorName,
        public string $thumbnailUrl,
        public CarbonInterface $publishedAt,
    ) {}
}
