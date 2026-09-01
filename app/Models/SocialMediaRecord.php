<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialMediaRecord extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    public const PLATFORMS = [
        'Facebook',
        'Instagram',
        'TikTok',
        'YouTube',
        'X / Twitter',
    ];

    public const EXTRACTION_MANUAL = 'manual';

    public const EXTRACTION_SUCCEEDED = 'succeeded';

    public const EXTRACTION_FAILED = 'failed';

    public const EXTRACTION_STATUSES = [
        self::EXTRACTION_MANUAL,
        self::EXTRACTION_SUCCEEDED,
        self::EXTRACTION_FAILED,
    ];

    protected $fillable = [
        'night_market_id',
        'food_id',
        'platform',
        'original_post_url',
        'source_url_hash',
        'extracted_title',
        'content_summary',
        'external_image_url',
        'extraction_status',
        'posted_date',
        'likes',
        'comments',
        'shares',
        'engagement_count',
        'status',
        'extracted_hashtags',
        'extracted_location_mentions',
        'extracted_market_mentions',
        'extracted_food_mentions',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'rejected_by',
        'rejected_at',
    ];

    /**
     * Internal de-duplication key. It is derived from the stored URL and never
     * shown publicly, so it stays out of array and JSON serialisation.
     *
     * @var list<string>
     */
    protected $hidden = [
        'source_url_hash',
    ];

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_APPROVED)
            ->whereNotNull('night_market_id')
            ->whereHas('nightMarket', fn (Builder $query) => $query->publiclyVisible())
            ->where(function (Builder $query) {
                $query->whereNull('food_id')
                    ->orWhereHas('food', fn (Builder $query) => $query
                        ->publiclyVisible()
                        ->whereHas('stall', fn (Builder $query) => $query
                            ->whereColumn('stalls.night_market_id', 'social_media_records.night_market_id')));
            });
    }

    protected function casts(): array
    {
        return [
            'posted_date' => 'date',
            'likes' => 'integer',
            'comments' => 'integer',
            'shares' => 'integer',
            'engagement_count' => 'integer',
            'extracted_hashtags' => 'array',
            'extracted_location_mentions' => 'array',
            'extracted_market_mentions' => 'array',
            'extracted_food_mentions' => 'array',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function nightMarket(): BelongsTo
    {
        return $this->belongsTo(NightMarket::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
