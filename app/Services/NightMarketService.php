<?php

namespace App\Services;

use App\Models\Food;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\Stall;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class NightMarketService
{
    /**
     * @param  array{search?: string|null, city?: string|null, operating_day?: string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<NightMarket>
     */
    public function adminMarkets(array $filters): LengthAwarePaginator
    {
        $search = $this->literalLikePattern($filters['search'] ?? null);

        return NightMarket::query()
            ->with(['operatingDays' => fn ($query) => $this->orderOperatingDays($query)])
            ->when($search, fn ($query, string $pattern) => $query->where(function ($query) use ($pattern) {
                $query->where('name', 'like', $pattern)
                    ->orWhere('address', 'like', $pattern)
                    ->orWhere('city', 'like', $pattern);
            }))
            ->when($filters['city'] ?? null, fn ($query, string $city) => $query->where('city', $city))
            ->when($filters['operating_day'] ?? null, fn ($query, string $day) => $query
                ->whereHas('operatingDays', fn ($query) => $query->where('day_of_week', $day)))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();
    }

    /**
     * @return Collection<int, NightMarket>
     */
    public function adminMarketOptions(): Collection
    {
        return NightMarket::query()
            ->select(['id', 'name', 'city', 'status'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, NightMarket>
     */
    public function adminCities(): Collection
    {
        return NightMarket::query()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select('city')
            ->distinct()
            ->orderBy('city')
            ->get();
    }

    public function adminDetails(NightMarket $nightMarket): NightMarket
    {
        return NightMarket::query()
            ->with([
                'operatingDays' => fn ($query) => $this->orderOperatingDays($query),
                'stalls' => fn ($query) => $query->select(['id', 'night_market_id', 'name', 'status'])->orderBy('name'),
            ])
            ->withCount('stalls')
            ->findOrFail($nightMarket->id);
    }

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

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(NightMarket $nightMarket, array $data): NightMarket
    {
        return DB::transaction(function () use ($nightMarket, $data) {
            $nightMarket->update([
                'name' => $data['name'],
                'address' => $data['address'],
                'city' => $data['city'],
                'state' => 'Selangor',
                'description' => $data['description'] ?? null,
            ]);

            $submittedDays = collect($data['operating_days'])->keyBy('day_of_week');

            $nightMarket->operatingDays()
                ->whereNotIn('day_of_week', $submittedDays->keys())
                ->delete();

            $submittedDays->each(function (array $operatingDay) use ($nightMarket): void {
                $nightMarket->operatingDays()->updateOrCreate(
                    ['day_of_week' => $operatingDay['day_of_week']],
                    [
                        'opening_time' => $operatingDay['opening_time'],
                        'closing_time' => $operatingDay['closing_time'],
                    ],
                );
            });

            return $nightMarket->refresh()->load('operatingDays');
        });
    }

    public function setStatus(NightMarket $nightMarket, string $status): NightMarket
    {
        if ($nightMarket->status !== $status) {
            $nightMarket->forceFill(['status' => $status])->save();
        }

        return $nightMarket->refresh();
    }

    private function literalLikePattern(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
