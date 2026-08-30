<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogSocialMediaSourceLink extends Model
{
    use HasFactory;

    public const TYPE_NIGHT_MARKET = 'night_market';

    public const TYPE_STALL = 'stall';

    public const TYPE_FOOD = 'food';

    public const TYPES = [
        self::TYPE_NIGHT_MARKET,
        self::TYPE_STALL,
        self::TYPE_FOOD,
    ];

    protected $fillable = [
        'social_media_source_id',
        'catalog_import_proposal_id',
        'catalog_type',
        'night_market_id',
        'stall_id',
        'food_id',
    ];

    public function socialMediaSource(): BelongsTo
    {
        return $this->belongsTo(SocialMediaSource::class);
    }

    public function catalogImportProposal(): BelongsTo
    {
        return $this->belongsTo(CatalogImportProposal::class);
    }

    public function nightMarket(): BelongsTo
    {
        return $this->belongsTo(NightMarket::class);
    }

    public function stall(): BelongsTo
    {
        return $this->belongsTo(Stall::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
