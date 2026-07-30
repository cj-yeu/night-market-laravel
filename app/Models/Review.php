<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    protected $fillable = [
        'user_id',
        'night_market_id',
        'rating',
        'comment',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nightMarket(): BelongsTo
    {
        return $this->belongsTo(NightMarket::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ReviewImage::class);
    }
}
