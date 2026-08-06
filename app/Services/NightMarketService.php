<?php

namespace App\Services;

use App\Models\NightMarket;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class NightMarketService
{
    /**
     * @param  array{search?: string|null, district?: string|null}  $filters
     * @return Collection<int, NightMarket>
     */
    public function discoverActiveMarkets(array $filters): Collection
    {
        return NightMarket::query()
            ->where('status', NightMarket::STATUS_ACTIVE)
            ->where('state', 'Selangor')
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('address', 'like', '%'.$search.'%')
                        ->orWhere('city', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['district'] ?? null, fn ($query, string $district) => $query->where('city', $district))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, NightMarket>
     */
    public function activeDistricts(): Collection
    {
        return NightMarket::query()
            ->where('status', NightMarket::STATUS_ACTIVE)
            ->where('state', 'Selangor')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select('city')
            ->distinct()
            ->orderBy('city')
            ->get();
    }

    public function findActiveForClient(int $nightMarketId): NightMarket
    {
        return NightMarket::query()
            ->where('status', NightMarket::STATUS_ACTIVE)
            ->where('state', 'Selangor')
            ->with(['operatingDays' => function ($query) {
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
            }])
            ->findOrFail($nightMarketId);
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
