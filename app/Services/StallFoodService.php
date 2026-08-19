<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class StallFoodService
{
    /**
     * @param  array{search?: string|null, night_market_id?: int|null, category?: string|null, halal_status?: string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<Stall>
     */
    public function adminStalls(array $filters): LengthAwarePaginator
    {
        $search = $this->literalLikePattern($filters['search'] ?? null);

        return Stall::query()
            ->with('nightMarket:id,name,city,status')
            ->withCount('foods')
            ->when($search, fn ($query, string $pattern) => $query->where(function ($query) use ($pattern) {
                $query->where('name', 'like', $pattern)
                    ->orWhere('description', 'like', $pattern);
            }))
            ->when($filters['night_market_id'] ?? null, fn ($query, int $marketId) => $query
                ->where('night_market_id', $marketId))
            ->when($filters['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->when($filters['halal_status'] ?? null, fn ($query, string $halalStatus) => $query
                ->where('halal_status', $halalStatus))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();
    }

    /**
     * @param  array{search?: string|null, night_market_id?: int|null, stall_id?: int|null, category?: string|null, is_must_try?: string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<Food>
     */
    public function adminFoods(array $filters): LengthAwarePaginator
    {
        $search = $this->literalLikePattern($filters['search'] ?? null);

        return Food::query()
            ->with(['stall:id,night_market_id,name,status', 'stall.nightMarket:id,name,city,status'])
            ->when($search, fn ($query, string $pattern) => $query->where(function ($query) use ($pattern) {
                $query->where('name', 'like', $pattern)
                    ->orWhere('description', 'like', $pattern);
            }))
            ->when($filters['night_market_id'] ?? null, fn ($query, int $marketId) => $query
                ->whereHas('stall', fn ($query) => $query->where('night_market_id', $marketId)))
            ->when($filters['stall_id'] ?? null, fn ($query, int $stallId) => $query->where('stall_id', $stallId))
            ->when($filters['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->when(array_key_exists('is_must_try', $filters) && $filters['is_must_try'] !== null,
                fn ($query) => $query->where('is_must_try', $filters['is_must_try'] === '1'))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();
    }

    public function adminStallDetails(Stall $stall): Stall
    {
        return Stall::query()
            ->with([
                'nightMarket:id,name,city,status',
                'foods' => fn ($query) => $query
                    ->select(['id', 'stall_id', 'name', 'category', 'is_must_try', 'status'])
                    ->orderBy('name'),
            ])
            ->withCount('foods')
            ->findOrFail($stall->id);
    }

    public function adminFoodDetails(Food $food): Food
    {
        return Food::query()
            ->with(['stall:id,night_market_id,name,status', 'stall.nightMarket:id,name,city,status'])
            ->findOrFail($food->id);
    }

    /**
     * @return Collection<int, Stall>
     */
    public function adminStallOptions(): Collection
    {
        return Stall::query()
            ->select(['id', 'night_market_id', 'name', 'status'])
            ->with('nightMarket:id,name')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Food>
     */
    public function adminCategories(): Collection
    {
        return Food::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->get();
    }

    /**
     * @return Collection<int, Stall>
     */
    public function adminStallCategories(): Collection
    {
        return Stall::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->get();
    }

    public function findPubliclyVisibleMarket(int $nightMarketId): NightMarket
    {
        return NightMarket::query()
            ->publiclyVisible()
            ->findOrFail($nightMarketId);
    }

    /**
     * @param  array{search?: string|null, category?: string|null}  $filters
     * @return Collection<int, Stall>
     */
    public function discoverStallsForMarket(NightMarket $nightMarket, array $filters): Collection
    {
        $search = $filters['search'] ?? null;
        $category = $filters['category'] ?? null;

        return Stall::query()
            ->where('night_market_id', $nightMarket->id)
            ->publiclyVisible()
            ->with(['foods' => fn ($query) => $query
                ->publiclyVisible()
                ->orderByDesc('is_must_try')
                ->orderBy('name')])
            ->when($search && $category, fn ($query) => $this
                ->applyCombinedSearchAndCategory($query, $search, $category))
            ->when($search && ! $category, fn ($query) => $this->applyKeywordSearch($query, $search))
            ->when(! $search && $category, fn ($query) => $this->applyCategoryFilter($query, $category))
            ->orderBy('name')
            ->get();
    }

    private function applyKeywordSearch($query, string $search): void
    {
        $query->where(function ($query) use ($search) {
            $query->where('name', 'like', '%'.$search.'%')
                ->orWhere('description', 'like', '%'.$search.'%')
                ->orWhereHas('foods', fn ($query) => $query
                    ->where('status', Food::STATUS_ACTIVE)
                    ->where(function ($query) use ($search) {
                        $query->where('name', 'like', '%'.$search.'%')
                            ->orWhere('description', 'like', '%'.$search.'%');
                    }));
        });
    }

    private function applyCategoryFilter($query, string $category): void
    {
        $query->whereHas('foods', fn ($query) => $query
            ->where('status', Food::STATUS_ACTIVE)
            ->where('category', $category));
    }

    private function applyCombinedSearchAndCategory($query, string $search, string $category): void
    {
        $query->where(function ($query) use ($search, $category) {
            $query->where(function ($query) use ($search, $category) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
                $this->applyCategoryFilter($query, $category);
            })->orWhereHas('foods', fn ($query) => $query
                ->where('status', Food::STATUS_ACTIVE)
                ->where('category', $category)
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                }));
        });
    }

    /**
     * @return Collection<int, Food>
     */
    public function activeCategoriesForMarket(NightMarket $nightMarket): Collection
    {
        return Food::query()
            ->where('status', Food::STATUS_ACTIVE)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->whereHas('stall', fn ($query) => $query
                ->where('night_market_id', $nightMarket->id)
                ->where('status', Stall::STATUS_ACTIVE))
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->get();
    }

    public function findPubliclyVisibleFood(int $foodId): Food
    {
        return Food::query()
            ->publiclyVisible()
            ->with('stall.nightMarket')
            ->findOrFail($foodId);
    }

    /**
     * @return Collection<int, NightMarket>
     */
    public function activeNightMarkets(): Collection
    {
        return NightMarket::where('status', NightMarket::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Stall>
     */
    public function activeStalls(): Collection
    {
        return Stall::with('nightMarket')
            ->where('status', Stall::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createStall(array $data): Stall
    {
        return Stall::create($this->stallAttributes($data, includeStatus: true));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFood(array $data): Food
    {
        return Food::create($this->foodAttributes($data, includeStatus: true));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateStall(Stall $stall, array $data): Stall
    {
        $stall->update($this->stallAttributes($data));

        return $stall->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateFood(Food $food, array $data): Food
    {
        $food->update($this->foodAttributes($data));

        return $food->refresh();
    }

    public function setStallStatus(Stall $stall, string $status): Stall
    {
        if ($stall->status !== $status) {
            $stall->forceFill(['status' => $status])->save();
        }

        return $stall->refresh();
    }

    public function setFoodStatus(Food $food, string $status): Food
    {
        if ($food->status !== $status) {
            $food->forceFill(['status' => $status])->save();
        }

        return $food->refresh();
    }

    private function literalLikePattern(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stallAttributes(array $data, bool $includeStatus = false): array
    {
        $attributes = collect($data)->only([
            'night_market_id',
            'name',
            'description',
            'category',
            'halal_status',
            'halal_evidence_url',
            'halal_notes',
            'source_url',
            'verified_at',
        ])->all();

        if ($includeStatus) {
            $attributes['status'] = $data['status'];
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function foodAttributes(array $data, bool $includeStatus = false): array
    {
        $attributes = collect($data)->only([
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
        ])->all();

        if ($includeStatus) {
            $attributes['status'] = $data['status'];
        }

        return $attributes;
    }
}
