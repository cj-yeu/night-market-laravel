<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CatalogDataQualityService
{
    public const ISSUES = [
        'market_missing_image' => ['label' => 'Active Markets missing an image', 'type' => 'market'],
        'market_missing_source_or_verified' => ['label' => 'Active Markets missing source or verification', 'type' => 'market'],
        'market_missing_schedule' => ['label' => 'Markets missing operating schedule', 'type' => 'market'],
        'stall_unknown_halal' => ['label' => 'Active Stalls with Unknown Halal status', 'type' => 'stall'],
        'stall_missing_metadata' => ['label' => 'Active Stalls missing category, source, or verification', 'type' => 'stall'],
        'food_missing_image' => ['label' => 'Active Foods missing an image', 'type' => 'food'],
        'food_missing_price' => ['label' => 'Active Foods missing price information', 'type' => 'food'],
        'food_must_try_missing_reason' => ['label' => 'Must-Try Foods missing a recommendation reason', 'type' => 'food'],
        'food_missing_metadata' => ['label' => 'Foods missing source, price check, or verification', 'type' => 'food'],
    ];

    /** @return array<string, array{label: string, type: string, count: int}> */
    public function summary(): array
    {
        return collect(self::ISSUES)->map(fn (array $issue, string $key) => [
            ...$issue,
            'count' => $this->queryFor($key)->count(),
        ])->all();
    }

    /** @return array{label: string, type: string} */
    public function issue(string $issue): array
    {
        abort_unless(isset(self::ISSUES[$issue]), 404);

        return self::ISSUES[$issue];
    }

    public function records(string $issue): LengthAwarePaginator
    {
        $definition = $this->issue($issue);
        $query = $this->queryFor($issue);

        return match ($definition['type']) {
            'market' => $query->select(['id', 'name', 'city', 'status', 'image_path', 'source_url', 'verified_at'])
                ->withCount('operatingDays')->orderBy('name')->paginate(15)->withQueryString(),
            'stall' => $query->select(['id', 'night_market_id', 'name', 'status', 'category', 'halal_status', 'source_url', 'verified_at'])
                ->with('nightMarket:id,name')->orderBy('name')->paginate(15)->withQueryString(),
            default => $query->select(['id', 'stall_id', 'name', 'status', 'image_path', 'price_min', 'price_max', 'price_display', 'is_must_try', 'recommendation_reason', 'source_url', 'price_checked_at', 'verified_at'])
                ->with('stall:id,night_market_id,name', 'stall.nightMarket:id,name')->orderBy('name')->paginate(15)->withQueryString(),
        };
    }

    private function queryFor(string $issue)
    {
        return match ($issue) {
            'market_missing_image' => NightMarket::query()->where('status', NightMarket::STATUS_ACTIVE)->whereNull('image_path'),
            'market_missing_source_or_verified' => NightMarket::query()->where('status', NightMarket::STATUS_ACTIVE)->where(fn ($q) => $q->whereNull('source_url')->orWhere('source_url', '')->orWhereNull('verified_at')),
            'market_missing_schedule' => NightMarket::query()->doesntHave('operatingDays'),
            'stall_unknown_halal' => Stall::query()->where('status', Stall::STATUS_ACTIVE)->where('halal_status', Stall::HALAL_UNKNOWN),
            'stall_missing_metadata' => Stall::query()->where('status', Stall::STATUS_ACTIVE)->where(fn ($q) => $q->whereNull('category')->orWhere('category', '')->orWhereNull('source_url')->orWhere('source_url', '')->orWhereNull('verified_at')),
            'food_missing_image' => Food::query()->where('status', Food::STATUS_ACTIVE)->whereNull('image_path'),
            'food_missing_price' => Food::query()->where('status', Food::STATUS_ACTIVE)->whereNull('price_min')->whereNull('price_max')->whereNull('price_display'),
            'food_must_try_missing_reason' => Food::query()->where('status', Food::STATUS_ACTIVE)->where('is_must_try', true)->where(fn ($q) => $q->whereNull('recommendation_reason')->orWhere('recommendation_reason', '')),
            'food_missing_metadata' => Food::query()->where('status', Food::STATUS_ACTIVE)->where(fn ($q) => $q->whereNull('source_url')->orWhere('source_url', '')->orWhereNull('price_checked_at')->orWhereNull('verified_at')),
        };
    }
}
