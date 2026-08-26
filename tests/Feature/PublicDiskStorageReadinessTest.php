<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicDiskStorageReadinessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_disk_uses_laravels_storage_path_and_models_generate_relative_public_urls(): void
    {
        $this->assertSame(storage_path('app/public'), config('filesystems.disks.public.root'));
        Storage::fake('public');

        $market = NightMarket::factory()->create(['image_path' => 'night-markets/123e4567-e89b-42d3-a456-426614174000.jpg']);
        $stall = Stall::factory()->for($market)->create(['image_path' => 'stalls/123e4567-e89b-42d3-a456-426614174000.jpg']);
        $food = Food::factory()->for($stall)->create(['image_path' => 'foods/123e4567-e89b-42d3-a456-426614174000.jpg']);
        $user = User::factory()->create(['avatar_path' => 'avatars/123e4567-e89b-42d3-a456-426614174000.jpg']);

        foreach ([$market, $stall, $food, $user] as $model) {
            $path = $model->image_path ?? $model->avatar_path;
            Storage::disk('public')->put($path, 'image');
        }

        $this->assertSame(Storage::disk('public')->url($market->image_path), $market->imageUrl());
        $this->assertSame(Storage::disk('public')->url($stall->image_path), $stall->imageUrl());
        $this->assertSame(Storage::disk('public')->url($food->image_path), $food->imageUrl());
        $this->assertSame(Storage::disk('public')->url($user->avatar_path), $user->avatarUrl());
    }

    public function test_public_pages_keep_local_placeholders_when_an_image_path_is_absent_or_unsafe(): void
    {
        $market = NightMarket::factory()->create(['image_path' => null]);
        $stall = Stall::factory()->for($market)->create(['image_path' => '../unsafe.jpg']);
        $food = Food::factory()->for($stall)->create(['image_path' => null]);

        $this->assertNull($market->imageUrl());
        $this->assertNull($stall->imageUrl());
        $this->assertNull($food->imageUrl());

        $this->get(route('night-markets.show', $market))
            ->assertOk()
            ->assertSee('images/night-market-placeholder.svg', false);
        $this->get(route('foods.show', $food))
            ->assertOk()
            ->assertSee('images/food-placeholder.svg', false);
    }
}
