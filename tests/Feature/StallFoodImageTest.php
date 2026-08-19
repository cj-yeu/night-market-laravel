<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use App\Services\StallFoodImageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StallFoodImageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_admin_uploads_jpeg_and_png_images_to_entity_scoped_uuid_paths(): void
    {
        $admin = $this->admin();

        foreach (['jpg', 'png'] as $extension) {
            $stall = Stall::factory()->create();
            $food = Food::factory()->create();

            $this->actingAs($admin)->patch(route('admin.stalls.image.update', $stall), [
                'image' => UploadedFile::fake()->image('private-stall-name.'.$extension, 800, 450),
            ])->assertRedirect(route('admin.stalls.show', $stall))->assertSessionHasNoErrors();

            $this->actingAs($admin)->patch(route('admin.foods.image.update', $food), [
                'image' => UploadedFile::fake()->image('private-food-name.'.$extension, 800, 600),
            ])->assertRedirect(route('admin.foods.show', $food))->assertSessionHasNoErrors();

            $stallPath = $stall->fresh()->image_path;
            $foodPath = $food->fresh()->image_path;
            $this->assertTrue(Stall::isOwnedImagePath($stallPath));
            $this->assertTrue(Food::isOwnedImagePath($foodPath));
            $this->assertStringStartsWith('stalls/', $stallPath);
            $this->assertStringStartsWith('foods/', $foodPath);
            $this->assertStringNotContainsString('private-', $stallPath);
            $this->assertStringNotContainsString('private-', $foodPath);
            Storage::disk('public')->assertExists($stallPath);
            Storage::disk('public')->assertExists($foodPath);
        }
    }

    public function test_admin_uploads_webp_for_both_entities_when_supported(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is not available in this runtime.');
        }

        foreach ([Stall::class, Food::class] as $modelClass) {
            $temporaryPath = tempnam(sys_get_temp_dir(), 'catalog-webp-');
            $image = imagecreatetruecolor(200, 120);
            imagewebp($image, $temporaryPath);
            imagedestroy($image);

            try {
                $record = $modelClass::factory()->create();
                $route = $record instanceof Stall ? 'admin.stalls.image.update' : 'admin.foods.image.update';

                $this->actingAs($this->admin())->patch(route($route, $record), [
                    'image' => new UploadedFile($temporaryPath, 'private.webp', 'image/webp', null, true),
                ])->assertSessionHasNoErrors();

                $path = $record->fresh()->image_path;
                $this->assertStringEndsWith('.webp', $path);
                Storage::disk('public')->assertExists($path);
            } finally {
                if (is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }
        }
    }

    public function test_invalid_unsupported_oversized_and_malformed_uploads_preserve_existing_images(): void
    {
        $stall = $this->stallWithOwnedImage();
        $food = $this->foodWithOwnedImage();
        $stallPath = $stall->image_path;
        $foodPath = $food->image_path;
        $admin = $this->admin();

        $invalidUploads = [
            UploadedFile::fake()->createWithContent('renamed.jpg', '<?php echo "unsafe";'),
            UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->image('animation.gif', 100, 100),
            UploadedFile::fake()->createWithContent('corrupt.png', 'not valid image content'),
            UploadedFile::fake()->image('oversized.jpg')->size(2049),
            UploadedFile::fake()->image('too-wide.png', 4097, 20),
        ];

        foreach ($invalidUploads as $index => $upload) {
            $record = $index % 2 === 0 ? $stall : $food;
            $route = $record instanceof Stall ? 'admin.stalls.image.update' : 'admin.foods.image.update';

            $this->actingAs($admin)->patch(route($route, $record), ['image' => $upload])
                ->assertSessionHasErrors('image');
        }

        $this->actingAs($admin)->patch(route('admin.stalls.image.update', $stall), [
            'image' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')],
        ])->assertSessionHasErrors('image');

        $this->actingAs($admin)->patch(route('admin.foods.image.update', $food), [
            'image' => ['malformed'],
        ])->assertSessionHasErrors('image');

        $this->assertSame($stallPath, $stall->fresh()->image_path);
        $this->assertSame($foodPath, $food->fresh()->image_path);
        Storage::disk('public')->assertExists($stallPath);
        Storage::disk('public')->assertExists($foodPath);
    }

    public function test_replacement_deletes_only_the_previous_owned_image_for_each_entity(): void
    {
        $stall = $this->stallWithOwnedImage();
        $food = $this->foodWithOwnedImage();
        $oldStallPath = $stall->image_path;
        $oldFoodPath = $food->image_path;
        Storage::disk('public')->put('stalls/unrelated.txt', 'keep');
        Storage::disk('public')->put('night-markets/123e4567-e89b-42d3-a456-426614174000.jpg', 'keep');
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.stalls.image.update', $stall), [
            'image' => UploadedFile::fake()->image('new.jpg', 800, 450),
        ])->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($oldStallPath);
        Storage::disk('public')->assertExists($oldFoodPath);
        Storage::disk('public')->assertExists('stalls/unrelated.txt');

        $newStallPath = $stall->fresh()->image_path;
        $this->actingAs($admin)->patch(route('admin.foods.image.update', $food), [
            'image' => UploadedFile::fake()->image('new.png', 800, 600),
        ])->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($oldFoodPath);
        Storage::disk('public')->assertExists($newStallPath);
        Storage::disk('public')->assertExists('night-markets/123e4567-e89b-42d3-a456-426614174000.jpg');
    }

    public function test_failed_persistence_cleans_new_files_and_restores_previous_state(): void
    {
        foreach ([
            [Stall::class, 'stalls/123e4567-e89b-42d3-a456-426614174000.jpg', 'updateStallImage'],
            [Food::class, 'foods/123e4567-e89b-42d3-a456-426614174001.jpg', 'updateFoodImage'],
        ] as [$modelClass, $oldPath, $method]) {
            Storage::disk('public')->put($oldPath, 'old image');
            $record = Mockery::mock($modelClass)->makePartial();
            $record->setRawAttributes(['id' => 123, 'image_path' => $oldPath], true);
            $record->shouldReceive('saveOrFail')->once()->andThrow(new RuntimeException('database failure'));

            try {
                app(StallFoodImageService::class)->{$method}(
                    $record,
                    UploadedFile::fake()->image('new.jpg', 400, 300),
                );
                $this->fail('Expected image persistence to fail safely.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('image', $exception->errors());
            }

            $this->assertSame($oldPath, $record->image_path);
            Storage::disk('public')->assertExists($oldPath);
            $this->assertSame([$oldPath], Storage::disk('public')->allFiles(dirname($oldPath)));
        }
    }

    public function test_deletion_is_entity_owned_scoped_and_idempotent(): void
    {
        $stall = $this->stallWithOwnedImage();
        $food = $this->foodWithOwnedImage();
        $stallPath = $stall->image_path;
        $foodPath = $food->image_path;
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('admin.stalls.image.destroy', $stall))
            ->assertRedirect(route('admin.stalls.show', $stall));
        $this->assertNull($stall->fresh()->image_path);
        Storage::disk('public')->assertMissing($stallPath);
        Storage::disk('public')->assertExists($foodPath);
        $this->actingAs($admin)->delete(route('admin.stalls.image.destroy', $stall))->assertSessionHasNoErrors();

        $this->actingAs($admin)->delete(route('admin.foods.image.destroy', $food))
            ->assertRedirect(route('admin.foods.show', $food));
        $this->assertNull($food->fresh()->image_path);
        Storage::disk('public')->assertMissing($foodPath);
        $this->actingAs($admin)->delete(route('admin.foods.image.destroy', $food))->assertSessionHasNoErrors();
    }

    public function test_unsafe_and_non_owned_paths_are_cleared_without_deleting_referenced_files(): void
    {
        foreach ([
            [Stall::factory()->create(), '../avatars/private.jpg', 'avatars/private.jpg', 'admin.stalls.image.destroy'],
            [Food::factory()->create(), 'stalls/123e4567-e89b-42d3-a456-426614174000.jpg', 'stalls/123e4567-e89b-42d3-a456-426614174000.jpg', 'admin.foods.image.destroy'],
        ] as [$record, $unsafePath, $protectedPath, $route]) {
            Storage::disk('public')->put($protectedPath, 'keep');
            $record->forceFill(['image_path' => $unsafePath])->save();

            $this->actingAs($this->admin())->delete(route($route, $record))->assertSessionHasNoErrors();

            $this->assertNull($record->fresh()->image_path);
            Storage::disk('public')->assertExists($protectedPath);
        }
    }

    public function test_guest_and_client_cannot_manage_stall_or_food_images(): void
    {
        $stall = Stall::factory()->create();
        $food = Food::factory()->create();
        $routes = [
            ['patch', route('admin.stalls.image.update', $stall)],
            ['delete', route('admin.stalls.image.destroy', $stall)],
            ['patch', route('admin.foods.image.update', $food)],
            ['delete', route('admin.foods.image.destroy', $food)],
        ];

        foreach ($routes as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }

        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));
        foreach ($routes as [$method, $url]) {
            $this->{$method}($url)->assertForbidden();
        }
    }

    public function test_deactivation_and_reactivation_preserve_stall_and_food_images(): void
    {
        $stall = $this->stallWithOwnedImage();
        $food = $this->foodWithOwnedImage();
        $admin = $this->admin();

        foreach ([
            [$stall, 'admin.stalls.deactivate', 'admin.stalls.activate'],
            [$food, 'admin.foods.deactivate', 'admin.foods.activate'],
        ] as [$record, $deactivateRoute, $activateRoute]) {
            $path = $record->image_path;
            $this->actingAs($admin)->patch(route($deactivateRoute, $record));
            $this->assertSame($path, $record->fresh()->image_path);
            Storage::disk('public')->assertExists($path);
            $this->actingAs($admin)->patch(route($activateRoute, $record));
            $this->assertSame($path, $record->fresh()->image_path);
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_admin_and_public_pages_render_images_or_local_placeholders_with_escaped_alt_text(): void
    {
        $market = NightMarket::factory()->create(['name' => 'Image Market']);
        $stall = Stall::factory()->for($market)->create(['name' => '<script>Stall</script>']);
        $food = Food::factory()->for($stall)->mustTry()->create(['name' => '<b>Food</b>']);
        $stallPath = 'stalls/123e4567-e89b-42d3-a456-426614174000.jpg';
        $foodPath = 'foods/123e4567-e89b-42d3-a456-426614174001.png';
        $stall->forceFill(['image_path' => $stallPath])->save();
        $food->forceFill(['image_path' => $foodPath])->save();

        $this->actingAs($this->admin())->get(route('admin.stalls.index'))
            ->assertOk()->assertSee('/storage/'.$stallPath, false)->assertSee('&lt;script&gt;Stall&lt;/script&gt; stall', false);
        $this->get(route('admin.stalls.show', $stall))->assertOk()->assertSee('Upload or replace image');
        $this->get(route('admin.stalls.edit', $stall))->assertOk()->assertSee('Remove Image');
        $this->get(route('admin.foods.index'))->assertOk()->assertSee('/storage/'.$foodPath, false);
        $this->get(route('admin.foods.show', $food))->assertOk()->assertSee('Upload or replace image');
        $this->get(route('admin.foods.edit', $food))->assertOk()->assertSee('Remove Image');

        auth()->logout();
        $this->get(route('night-markets.show', $market))->assertOk()
            ->assertSee('/storage/'.$stallPath, false)->assertSee('/storage/'.$foodPath, false)
            ->assertSee('&lt;b&gt;Food&lt;/b&gt; food', false);
        $this->get(route('night-markets.stalls.index', $market))->assertOk()
            ->assertSee('/storage/'.$stallPath, false)->assertSee('/storage/'.$foodPath, false);
        $this->get(route('foods.show', $food))->assertOk()->assertSee('/storage/'.$foodPath, false);

        $placeholderStall = Stall::factory()->for($market)->create();
        $placeholderFood = Food::factory()->for($placeholderStall)->create();
        $this->get(route('night-markets.stalls.index', $market))->assertOk()
            ->assertSee('images/stall-placeholder.svg', false)->assertSee('images/food-placeholder.svg', false);
    }

    public function test_inactive_records_with_images_remain_inaccessible_publicly(): void
    {
        $market = NightMarket::factory()->create();
        $inactiveStall = $this->stallWithOwnedImage(['night_market_id' => $market->id, 'status' => Stall::STATUS_INACTIVE]);
        $inactiveFood = $this->foodWithOwnedImage(['stall_id' => Stall::factory()->for($market)->create()->id, 'status' => Food::STATUS_INACTIVE]);

        $this->get(route('night-markets.stalls.index', $market))
            ->assertOk()->assertDontSee($inactiveStall->name)->assertDontSee($inactiveFood->name);
        $this->get(route('foods.show', $inactiveFood))->assertNotFound();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function stallWithOwnedImage(array $attributes = []): Stall
    {
        $stall = Stall::factory()->create($attributes);
        $path = 'stalls/123e4567-e89b-42d3-a456-'.str_pad((string) $stall->id, 12, '0', STR_PAD_LEFT).'.jpg';
        Storage::disk('public')->put($path, 'stall image');
        $stall->forceFill(['image_path' => $path])->save();

        return $stall->fresh();
    }

    private function foodWithOwnedImage(array $attributes = []): Food
    {
        $food = Food::factory()->create($attributes);
        $path = 'foods/123e4567-e89b-42d3-a456-'.str_pad((string) $food->id, 12, '0', STR_PAD_LEFT).'.jpg';
        Storage::disk('public')->put($path, 'food image');
        $food->forceFill(['image_path' => $path])->save();

        return $food->fresh();
    }
}
