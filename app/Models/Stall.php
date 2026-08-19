<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stall extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const HALAL_CERTIFIED = 'halal_certified';

    public const HALAL_MUSLIM_OWNED_OR_CLAIMED = 'muslim_owned_or_claimed';

    public const HALAL_NON_HALAL = 'non_halal';

    public const HALAL_UNKNOWN = 'unknown';

    public const HALAL_STATUSES = [
        self::HALAL_CERTIFIED,
        self::HALAL_MUSLIM_OWNED_OR_CLAIMED,
        self::HALAL_NON_HALAL,
        self::HALAL_UNKNOWN,
    ];

    protected $fillable = [
        'night_market_id',
        'name',
        'description',
        'category',
        'halal_status',
        'halal_evidence_url',
        'halal_notes',
        'source_url',
        'verified_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function halalStatusOptions(): array
    {
        return [
            self::HALAL_CERTIFIED => 'Halal-certified',
            self::HALAL_MUSLIM_OWNED_OR_CLAIMED => 'Muslim-owned/claimed',
            self::HALAL_NON_HALAL => 'Non-halal',
            self::HALAL_UNKNOWN => 'Unknown',
        ];
    }

    public function halalStatusLabel(): string
    {
        return self::halalStatusOptions()[$this->halal_status] ?? self::halalStatusOptions()[self::HALAL_UNKNOWN];
    }

    public function halalPublicLabel(): string
    {
        return match ($this->halal_status) {
            self::HALAL_CERTIFIED => 'Halal-certified',
            self::HALAL_MUSLIM_OWNED_OR_CLAIMED => 'Muslim-owned/claimed (not certification)',
            self::HALAL_NON_HALAL => 'Non-halal',
            default => 'Halal status not verified',
        };
    }

    public function halalBadgeClass(): string
    {
        return match ($this->halal_status) {
            self::HALAL_CERTIFIED => 'text-bg-success',
            self::HALAL_MUSLIM_OWNED_OR_CLAIMED => 'text-bg-info',
            self::HALAL_NON_HALAL => 'text-bg-danger',
            default => 'text-bg-secondary',
        };
    }

    public function hasCurrentHalalEvidence(): bool
    {
        return $this->halal_status !== self::HALAL_UNKNOWN
            && $this->halalEvidenceUrl() !== null;
    }

    public function halalEvidenceUrl(): ?string
    {
        return $this->safeExternalUrl($this->halal_evidence_url);
    }

    public function sourceUrl(): ?string
    {
        return $this->safeExternalUrl($this->source_url);
    }

    private function safeExternalUrl(?string $url): ?string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $url
            : null;
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereHas('nightMarket', fn (Builder $query) => $query->publiclyVisible());
    }

    public function nightMarket(): BelongsTo
    {
        return $this->belongsTo(NightMarket::class);
    }

    public function foods(): HasMany
    {
        return $this->hasMany(Food::class);
    }
}
