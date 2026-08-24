<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
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

    public const FOOD_TAGS = [
        'tasty' => 'Tasty',
        'good_value' => 'Good value',
        'large_portion' => 'Large portion',
        'long_queue' => 'Long queue',
    ];

    public const MARKET_TAGS = [
        'many_choices' => 'Many choices',
        'clean' => 'Clean',
        'easy_parking' => 'Easy parking',
        'family_friendly' => 'Family-friendly',
    ];

    protected $fillable = [
        'user_id',
        'night_market_id',
        'food_id',
        'rating',
        'comment',
        'tags',
        'status',
    ];

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function isMarketReview(): bool
    {
        return $this->night_market_id !== null && $this->food_id === null;
    }

    public function isFoodReview(): bool
    {
        return $this->food_id !== null && $this->night_market_id === null;
    }

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'tags' => 'array',
        ];
    }

    /** @return array<string, string> */
    public static function foodTagOptions(): array
    {
        return self::FOOD_TAGS;
    }

    /** @return array<string, string> */
    public static function marketTagOptions(): array
    {
        return self::MARKET_TAGS;
    }

    /** @return list<string> */
    public static function tagsForFood(array $tags): array
    {
        return array_values(array_intersect($tags, array_keys(self::FOOD_TAGS)));
    }

    /** @return list<string> */
    public static function tagsForMarket(array $tags): array
    {
        return array_values(array_intersect($tags, array_keys(self::MARKET_TAGS)));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nightMarket(): BelongsTo
    {
        return $this->belongsTo(NightMarket::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ReviewImage::class);
    }
}
