<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class NightMarket extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const IMAGE_DIRECTORY = 'night-markets';

    protected $fillable = [
        'name',
        'address',
        'city',
        'state',
        'description',
        'status',
    ];

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('state', 'Selangor');
    }

    public static function isOwnedImagePath(?string $path): bool
    {
        if ($path === null) {
            return false;
        }

        return preg_match(
            '/\Anight-markets\/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(?:jpe?g|png|webp)\z/i',
            str_replace('\\', '/', $path),
        ) === 1;
    }

    public function imageUrl(): ?string
    {
        return self::isOwnedImagePath($this->image_path)
            ? Storage::disk('public')->url($this->image_path)
            : null;
    }

    public function googleMapsUrl(): ?string
    {
        $address = trim((string) $this->address);

        if ($address === '') {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address);
    }

    public function operatingDays(): HasMany
    {
        return $this->hasMany(MarketOperatingDay::class);
    }

    public function stalls(): HasMany
    {
        return $this->hasMany(Stall::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function visitPlans(): HasMany
    {
        return $this->hasMany(VisitPlan::class);
    }

    public function socialMediaRecords(): HasMany
    {
        return $this->hasMany(SocialMediaRecord::class);
    }
}
