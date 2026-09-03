<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CatalogImportProposal extends Model
{
    use HasFactory;

    public const TARGET_EXISTING_MARKET = 'existing_market';

    public const TARGET_EXISTING_STALL = 'existing_stall';

    public const TARGET_NEW_MARKET = 'new_market';

    public const TARGET_TYPES = [
        self::TARGET_EXISTING_MARKET,
        self::TARGET_EXISTING_STALL,
        self::TARGET_NEW_MARKET,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_IMPORTING,
        self::STATUS_IMPORTED,
        self::STATUS_FAILED,
    ];

    public const EXTRACTION_PENDING = 'pending';

    public const EXTRACTION_PROCESSING = 'processing';

    public const EXTRACTION_COMPLETED = 'completed';

    public const EXTRACTION_FAILED = 'failed';

    public const EXTRACTION_STATUSES = [
        self::EXTRACTION_PENDING,
        self::EXTRACTION_PROCESSING,
        self::EXTRACTION_COMPLETED,
        self::EXTRACTION_FAILED,
    ];

    protected $fillable = [
        'social_media_source_id',
        'target_type',
        'matched_night_market_id',
        'matched_stall_id',
        'status',
        'revision',
        'created_by',
        'reviewed_by',
        'review_note',
        'submitted_at',
        'reviewed_at',
        'imported_at',
        'failure_code',
        'extraction_status',
        'extraction_failure_code',
        'extraction_model',
        'extraction_input_hash',
        'extracted_at',
        'review_metadata_snapshot',
        'review_input_hash',
        'extraction_attempt_token',
        'extraction_attempt_started_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'imported_at' => 'datetime',
            'extracted_at' => 'datetime',
            'review_metadata_snapshot' => 'array',
            'extraction_attempt_started_at' => 'datetime',
        ];
    }

    public function socialMediaSource(): BelongsTo
    {
        return $this->belongsTo(SocialMediaSource::class);
    }

    public function matchedNightMarket(): BelongsTo
    {
        return $this->belongsTo(NightMarket::class, 'matched_night_market_id');
    }

    public function matchedStall(): BelongsTo
    {
        return $this->belongsTo(Stall::class, 'matched_stall_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function proposalMarket(): HasOne
    {
        return $this->hasOne(CatalogImportProposalMarket::class);
    }

    public function catalogSourceLinks(): HasMany
    {
        return $this->hasMany(CatalogSocialMediaSourceLink::class);
    }
}
