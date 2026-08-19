<?php

namespace App\Services;

use App\Models\Food;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\Stall;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class NightMarketService
{
    /**
     * @param  array{search?: string|null, district?: string|null, operating_day?: string|null}  $filters
     * @return Collection<int, NightMarket>
     */
    public function discoverPublicMarkets(array $filters): Collection
    {
        return NightMarket::query()
            ->publiclyVisible()
            ->with(['operatingDays' => fn ($query) => $this->orderOperatingDays($query)])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('address', 'like', '%'.$search.'%')
                        ->orWhere('city', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['district'] ?? null, fn ($query, string $district) => $query->where('city', $district))
            ->when($filters['operating_day'] ?? null, fn ($query, string $operatingDay) => $query
                ->whereHas('operatingDays', fn ($query) => $query->where('day_of_week', $operatingDay)))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, NightMarket>
     */
    public function publicDistricts(): Collection
    {
        return NightMarket::query()
            ->publiclyVisible()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select('city')
            ->distinct()
            ->orderBy('city')
            ->get();
    }

    public function findPubliclyVisible(int $nightMarketId): NightMarket
    {
        return NightMarket::query()
            ->publiclyVisible()
            ->with([
                'operatingDays' => fn ($query) => $this->orderOperatingDays($query),
                'stalls' => fn ($query) => $query
                    ->publiclyVisible()
                    ->orderBy('name'),
                'stalls.foods' => fn ($query) => $query
                    ->publiclyVisible()
                    ->where('is_must_try', true)
                    ->orderBy('name'),
            ])
            ->findOrFail($nightMarketId);
    }

    /**
     * @return array<int, string>
     */
    public function operatingDayOptions(): array
    {
        return MarketOperatingDay::DAYS;
    }

    /**
     * @return SupportCollection<int, Food>
     */
    public function mustTryFoods(NightMarket $nightMarket): SupportCollection
    {
        return $nightMarket->stalls
            ->flatMap(fn (Stall $stall) => $stall->foods)
            ->values();
    }

    private function orderOperatingDays($query): void
    {
        $query->orderByRaw(
            "CASE day_of_week
                WHEN 'Monday' THEN 1
                WHEN 'Tuesday' THEN 2
                WHEN 'Wednesday' THEN 3
                WHEN 'Thursday' THEN 4
                WHEN 'Friday' THEN 5
                WHEN 'Saturday' THEN 6
                WHEN 'Sunday' THEN 7
                ELSE 8 END"
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): NightMarket
    {
        return DB::transaction(function () use ($data) {
            $nightMarket = NightMarket::create([
                'name' => $data['name'],
                'address' => $data['address'],
                'city' => $data['city'],
                'state' => 'Selangor',
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
            ]);

            $nightMarket->operatingDays()->createMany($data['operating_days']);

            return $nightMarket->load('operatingDays');
        });
    }
}
