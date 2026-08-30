<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialMediaSource extends Model
{
    use HasFactory;

    public const PLATFORM_YOUTUBE = 'youtube';

    public const METADATA_PENDING = 'pending';

    public const METADATA_FETCHED = 'fetched';

    public const METADATA_FAILED = 'failed';

    public const METADATA_STATUSES = [
        self::METADATA_PENDING,
        self::METADATA_FETCHED,
        self::METADATA_FAILED,
    ];

    protected $fillable = [
        'platform',
        'canonical_url',
        'url_fingerprint',
        'external_content_id',
        'title',
        'description_excerpt',
        'creator_name',
        'thumbnail_url',
        'published_at',
        'metadata_provider',
        'metadata_status',
        'failure_code',
        'metadata_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'metadata_fetched_at' => 'datetime',
        ];
    }

    public function catalogImportProposals(): HasMany
    {
        return $this->hasMany(CatalogImportProposal::class);
    }
}
