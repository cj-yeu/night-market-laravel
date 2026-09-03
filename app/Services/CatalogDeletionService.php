<?php

namespace App\Services;

use App\Models\CatalogSocialMediaSourceLink;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CatalogDeletionService
{
    /** @return array{id: int, name: string} */
    public function deleteFood(Food $food): array
    {
        return $this->delete($food, function (Food $locked): void {
            $this->requireInactive($locked, 'Food');
            $this->requireNoRows('reviews', 'food_id', $locked->id, 'Food reviews must be removed before this food can be deleted.');
            $this->requireNoRows('visit_plan_items', 'food_id', $locked->id, 'Visit plan items must be removed before this food can be deleted.');
            CatalogSocialMediaSourceLink::query()->where('food_id', $locked->id)->delete();
        });
    }

    /** @return array{id: int, name: string} */
    public function deleteStall(Stall $stall): array
    {
        return $this->delete($stall, function (Stall $locked): void {
            $this->requireInactive($locked, 'Stall');
            $this->requireNoRows('foods', 'stall_id', $locked->id, 'Remove all Foods from this Stall before deleting it.');
            $this->requireNoRows('visit_plan_items', 'stall_id', $locked->id, 'Visit plan items must be removed before this stall can be deleted.');
            CatalogSocialMediaSourceLink::query()->where('stall_id', $locked->id)->delete();
        });
    }

    /** @return array{id: int, name: string} */
    public function deleteNightMarket(NightMarket $nightMarket): array
    {
        return $this->delete($nightMarket, function (NightMarket $locked): void {
            $this->requireInactive($locked, 'Night Market');
            $this->requireNoRows('stalls', 'night_market_id', $locked->id, 'Remove all Stalls from this Night Market before deleting it.');
            $this->requireNoRows('reviews', 'night_market_id', $locked->id, 'Market reviews must be removed before this Night Market can be deleted.');
            $this->requireNoRows('visit_plans', 'night_market_id', $locked->id, 'Visit plans must be removed before this Night Market can be deleted.');
            $locked->operatingDays()->delete();
            CatalogSocialMediaSourceLink::query()->where('night_market_id', $locked->id)->delete();
        });
    }

    /**
     * @template TModel of Food|Stall|NightMarket
     *
     * @param  TModel  $record
     * @param  callable(TModel): void  $guard
     * @return array{id: int, name: string}
     */
    private function delete(Food|Stall|NightMarket $record, callable $guard): array
    {
        $result = DB::transaction(function () use ($record, $guard): array {
            $locked = $record::query()->lockForUpdate()->findOrFail($record->id);
            $guard($locked);

            $result = [
                'id' => (int) $locked->id,
                'name' => (string) $locked->name,
                'image_path' => $locked->image_path,
                'image_owned' => $locked::isOwnedImagePath($locked->image_path),
            ];

            $locked->delete();

            return $result;
        });

        if ($result['image_owned']) {
            Storage::disk('public')->delete($result['image_path']);
        }

        return ['id' => $result['id'], 'name' => $result['name']];
    }

    private function requireInactive(Food|Stall|NightMarket $record, string $label): void
    {
        if ($record->status !== $record::STATUS_INACTIVE) {
            throw ValidationException::withMessages([
                'catalog' => $label.' must be deactivated before it can be permanently deleted.',
            ]);
        }
    }

    private function requireNoRows(string $table, string $column, int $id, string $message): void
    {
        if (DB::table($table)->where($column, $id)->exists()) {
            throw ValidationException::withMessages(['catalog' => $message]);
        }
    }
}
