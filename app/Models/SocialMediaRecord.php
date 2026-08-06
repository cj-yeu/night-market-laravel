<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialMediaRecord extends Model
{
    use HasFactory;

    public const PLATFORMS = [
        'Facebook',
        'Instagram',
        'TikTok',
        'X / Twitter',
    ];

    protected $fillable = [
        'night_market_id',
        'food_id',
        'platform',
        'original_post_url',
        'content_summary',
        'posted_date',
        'likes',
        'comments',
        'shares',
    ];

    protected function casts(): array
    {
        return [
            'posted_date' => 'date',
            'likes' => 'integer',
            'comments' => 'integer',
            'shares' => 'integer',
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
}
