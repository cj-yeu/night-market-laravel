<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\SocialMediaRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class SocialMediaDataService
{
    /**
     * @param  array{search?: string|null, night_market_id?: int|null, platform?: string|null}  $filters
     */
    public function records(array $filters): LengthAwarePaginator
    {
        return SocialMediaRecord::query()
            ->with(['nightMarket:id,name', 'food:id,name'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('content_summary', 'like', '%'.$search.'%')
                        ->orWhere('original_post_url', 'like', '%'.$search.'%')
                        ->orWhereHas('nightMarket', fn ($query) => $query
                            ->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('food', fn ($query) => $query
                            ->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($filters['night_market_id'] ?? null, fn ($query, int $marketId) => $query
                ->where('night_market_id', $marketId))
            ->when($filters['platform'] ?? null, fn ($query, string $platform) => $query
                ->where('platform', $platform))
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();
    }

    /**
     * @return array{nightMarkets: Collection<int, NightMarket>, foods: Collection<int, Food>, platforms: array<int, string>}
     */
    public function formOptions(): array
    {
        return [
            'nightMarkets' => $this->activeSelangorMarkets(),
            'foods' => $this->eligibleFoods(),
            'platforms' => SocialMediaRecord::PLATFORMS,
        ];
    }

    /**
     * @return Collection<int, NightMarket>
     */
    public function activeSelangorMarkets(): Collection
    {
        return NightMarket::query()
            ->where('status', NightMarket::STATUS_ACTIVE)
            ->where('state', 'Selangor')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SocialMediaRecord
    {
        $this->validateEligibility($data);

        return SocialMediaRecord::create($this->recordData($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SocialMediaRecord $socialMediaRecord, array $data): SocialMediaRecord
    {
        $this->validateEligibility($data);
        $socialMediaRecord->update($this->recordData($data));

        return $socialMediaRecord->refresh();
    }

    public function delete(SocialMediaRecord $socialMediaRecord): void
    {
        $socialMediaRecord->delete();
    }

    /**
     * @return Collection<int, Food>
     */
    private function eligibleFoods(): Collection
    {
        return Food::query()
            ->where('status', Food::STATUS_ACTIVE)
            ->whereHas('stall', fn ($query) => $query->where('status', 'active'))
            ->whereHas('stall.nightMarket', fn ($query) => $query
                ->where('status', NightMarket::STATUS_ACTIVE)
                ->where('state', 'Selangor'))
            ->with('stall:id,night_market_id,name')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateEligibility(array $data): void
    {
        $marketIsEligible = NightMarket::query()
            ->whereKey($data['night_market_id'])
            ->where('status', NightMarket::STATUS_ACTIVE)
            ->where('state', 'Selangor')
            ->exists();

        if (! $marketIsEligible) {
            throw ValidationException::withMessages([
                'night_market_id' => 'The selected night market must be active and located in Selangor.',
            ]);
        }

        if (! empty($data['food_id'])) {
            $foodIsEligible = Food::query()
                ->whereKey($data['food_id'])
                ->where('status', Food::STATUS_ACTIVE)
                ->whereHas('stall', fn ($query) => $query
                    ->where('status', 'active')
                    ->where('night_market_id', $data['night_market_id']))
                ->exists();

            if (! $foodIsEligible) {
                throw ValidationException::withMessages([
                    'food_id' => 'The selected food must be active and belong to the selected night market.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function recordData(array $data): array
    {
        return [
            'night_market_id' => $data['night_market_id'],
            'food_id' => $data['food_id'] ?? null,
            'platform' => $data['platform'],
            'original_post_url' => $data['original_post_url'],
            'content_summary' => $data['content_summary'],
            'posted_date' => $data['posted_date'],
            'likes' => $data['likes'],
            'comments' => $data['comments'],
            'shares' => $data['shares'],
        ];
    }
}
