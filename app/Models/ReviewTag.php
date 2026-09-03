<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ReviewTag extends Model
{
    use HasFactory;

    public const TARGET_MARKET = 'market';

    public const TARGET_FOOD = 'food';

    public const MARKET_NAMES = [
        'Clean',
        'Crowded',
        'Family-Friendly',
        'Friendly Staff',
        'Good Variety',
        'Easy Access',
        'Worth Visiting',
    ];

    public const FOOD_NAMES = [
        'Tasty',
        'Affordable',
        'Fresh',
        'Good Portion',
        'Spicy',
        'Unique Flavor',
        'Worth Trying',
    ];

    public const NAMES = [...self::MARKET_NAMES, ...self::FOOD_NAMES];

    protected $fillable = ['name', 'target_type'];

    public $timestamps = false;

    public function reviews(): BelongsToMany
    {
        return $this->belongsToMany(Review::class);
    }

    /** @return array<int, string> */
    public static function namesForTarget(string $targetType): array
    {
        return match ($targetType) {
            self::TARGET_MARKET => self::MARKET_NAMES,
            self::TARGET_FOOD => self::FOOD_NAMES,
            default => [],
        };
    }
}
