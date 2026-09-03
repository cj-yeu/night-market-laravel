<?php

namespace App\Models;

use App\Support\CatalogMarketIdentity;
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
        'source_url',
        'verified_at',
        'status',
    ];

    protected $hidden = [
        'catalog_code',
        'catalog_identity_hash',
    ];

    protected static function booted(): void
    {
        static::creating(static function (NightMarket $market): void {
            $market->catalog_identity_hash = $market->computedCatalogIdentityHash();
        });

        static::updating(static function (NightMarket $market): void {
            if ($market->isDirty(CatalogMarketIdentity::FIELDS)) {
                $market->catalog_identity_hash = $market->computedCatalogIdentityHash();
            }
        });
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('state', 'Selangor');
    }

    public function scopeEligibleForPlanning(Builder $query): Builder
    {
        return $query
            ->publiclyVisible()
            ->whereHas('operatingDays')
            ->whereHas('stalls', fn (Builder $stallQuery) => $stallQuery
                ->where('status', Stall::STATUS_ACTIVE)
                ->whereHas('foods', fn (Builder $foodQuery) => $foodQuery
                    ->where('status', Food::STATUS_ACTIVE)));
    }

    public function scopeWithPublicReviewSummary(Builder $query): Builder
    {
        return $query
            ->withCount([
                'reviews as public_reviews_count' => fn (Builder $reviewQuery) => $reviewQuery
                    ->publiclyVisible()
                    ->whereNull('food_id'),
            ])
            ->withAvg([
                'reviews as public_reviews_avg_rating' => fn (Builder $reviewQuery) => $reviewQuery
                    ->publiclyVisible()
                    ->whereNull('food_id'),
            ], 'rating');
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

    protected function casts(): array
    {
        return ['verified_at' => 'date'];
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

    public function catalogImportProposals(): HasMany
    {
        return $this->hasMany(CatalogImportProposal::class, 'matched_night_market_id');
    }

    public function catalogSourceLinks(): HasMany
    {
        return $this->hasMany(CatalogSocialMediaSourceLink::class);
    }

    private function computedCatalogIdentityHash(): string
    {
        return CatalogMarketIdentity::hash(
            $this->name,
            $this->address,
            $this->city,
            $this->state,
        );
    }
}
