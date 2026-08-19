<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Food extends Model
{
    use HasFactory;

    protected $table = 'foods';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const IMAGE_DIRECTORY = 'foods';

    protected $fillable = [
        'stall_id',
        'name',
        'description',
        'category',
        'price_min',
        'price_max',
        'price_display',
        'is_must_try',
        'recommendation_reason',
        'source_url',
        'price_checked_at',
        'verified_at',
        'status',
    ];

    protected $hidden = [
        'catalog_code',
    ];

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereHas('stall', fn (Builder $query) => $query->publiclyVisible());
    }

    public static function isOwnedImagePath(?string $path): bool
    {
        return $path !== null && preg_match(
            '/\Afoods\/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(?:jpe?g|png|webp)\z/i',
            str_replace('\\', '/', $path),
        ) === 1;
    }

    public function imageUrl(): ?string
    {
        return self::isOwnedImagePath($this->image_path)
            ? Storage::disk('public')->url($this->image_path)
            : null;
    }

    protected function casts(): array
    {
        return [
            'is_must_try' => 'boolean',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'price_checked_at' => 'date',
            'verified_at' => 'date',
        ];
    }

    public function formattedPrice(): string
    {
        if (filled($this->price_display)) {
            return trim((string) $this->price_display);
        }

        $minimum = $this->price_min;
        $maximum = $this->price_max;

        if ($minimum !== null && $maximum !== null) {
            if ((float) $minimum === (float) $maximum) {
                return 'RM'.number_format((float) $minimum, 2);
            }

            return 'RM'.number_format((float) $minimum, 2).'–RM'.number_format((float) $maximum, 2);
        }

        if ($minimum !== null) {
            return 'From RM'.number_format((float) $minimum, 2);
        }

        if ($maximum !== null) {
            return 'Up to RM'.number_format((float) $maximum, 2);
        }

        return 'Price not available';
    }

    public function sourceUrl(): ?string
    {
        if (! filter_var($this->source_url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return in_array(strtolower((string) parse_url($this->source_url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $this->source_url
            : null;
    }

    public function stall(): BelongsTo
    {
        return $this->belongsTo(Stall::class);
    }

    public function socialMediaRecords(): HasMany
    {
        return $this->hasMany(SocialMediaRecord::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
