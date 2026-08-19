<?php

namespace Tests\Feature;

use App\Models\NightMarket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NightMarketImageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_admin_can_upload_valid_jpeg_and_png_images_with_generated_paths(): void
    {
        $admin = $this->admin();

        foreach (['jpg', 'png'] as $extension) {
            $market = NightMarket::factory()->create();
            $originalName = 'private-original-name.'.$extension;

            $this->actingAs($admin)
                ->patch(route('admin.night-markets.image.update', $market), [
                    'image' => UploadedFile::fake()->image($originalName, 800, 450),
                ])
                ->assertRedirect(route('admin.night-markets.show', $market))
                ->assertSessionHasNoErrors();

            $path = $market->fresh()->image_path;
            $this->assertTrue(NightMarket::isOwnedImagePath($path));
            $this->assertStringNotContainsString('private-original-name', $path);
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_admin_can_upload_webp_when_runtime_support_exists(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is not available in this runtime.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'market-webp-');
        $image = imagecreatetruecolor(200, 120);
        imagewebp($image, $temporaryPath);
        imagedestroy($image);

        try {
            $market = NightMarket::factory()->create();
            $upload = new UploadedFile($temporaryPath, 'original.webp', 'image/webp', null, true);

            $this->actingAs($this->admin())
                ->patch(route('admin.night-markets.image.update', $market), ['image' => $upload])
                ->assertRedirect(route('admin.night-markets.show', $market))
                ->assertSessionHasNoErrors();

            $this->assertStringEndsWith('.webp', $market->fresh()->image_path);
            Storage::disk('public')->assertExists($market->fresh()->image_path);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function test_invalid_disguised_svg_and_gif_uploads_are_rejected(): void
    {
        $market = NightMarket::factory()->create();
        $admin = $this->admin();
        $invalidFiles = [
            UploadedFile::fake()->createWithContent('disguised.jpg', '<?php echo "not an image";'),
            UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->image('animation.gif', 100, 100),
        ];

        foreach ($invalidFiles as $file) {
            $this->actingAs($admin)
                ->from(route('admin.night-markets.show', $market))
                ->patch(route('admin.night-markets.image.update', $market), ['image' => $file])
                ->assertRedirect(route('admin.night-markets.show', $market))
                ->assertSessionHasErrors('image');
        }

        $this->assertNull($market->fresh()->image_path);
        $this->assertSame([], Storage::disk('public')->allFiles(NightMarket::IMAGE_DIRECTORY));
    }

    public function test_oversized_and_excessive_dimension_images_are_rejected(): void
    {
        $market = NightMarket::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('admin.night-markets.image.update', $market), [
                'image' => UploadedFile::fake()->image('large.jpg')->size(2049),
            ])->assertSessionHasErrors('image');

        $this->actingAs($admin)
            ->patch(route('admin.night-markets.image.update', $market), [
                'image' => UploadedFile::fake()->image('wide.png', 4097, 20),
            ])->assertSessionHasErrors('image');

        $this->assertNull($market->fresh()->image_path);
    }

    public function test_failed_replacement_preserves_existing_image_and_file(): void
    {
        $market = $this->marketWithOwnedImage();
        $oldPath = $market->image_path;

        $this->actingAs($this->admin())
            ->patch(route('admin.night-markets.image.update', $market), [
                'image' => UploadedFile::fake()->createWithContent('fake.jpg', 'not image content'),
            ])->assertSessionHasErrors('image');

        $this->assertSame($oldPath, $market->fresh()->image_path);
        Storage::disk('public')->assertExists($oldPath);
    }

    public function test_replacement_deletes_only_the_previous_owned_image(): void
    {
        $market = $this->marketWithOwnedImage();
        $oldPath = $market->image_path;
        Storage::disk('public')->put('night-markets/unrelated.txt', 'keep');

        $this->actingAs($this->admin())
            ->patch(route('admin.night-markets.image.update', $market), [
                'image' => UploadedFile::fake()->image('replacement.jpg', 600, 400),
            ])->assertSessionHasNoErrors();

        $newPath = $market->fresh()->image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertExists('night-markets/unrelated.txt');
    }

    public function test_deletion_is_owned_scoped_and_idempotent(): void
    {
        $market = $this->marketWithOwnedImage();
        $path = $market->image_path;

        $this->actingAs($this->admin())
            ->delete(route('admin.night-markets.image.destroy', $market))
            ->assertRedirect(route('admin.night-markets.show', $market));
        $this->assertNull($market->fresh()->image_path);
        Storage::disk('public')->assertMissing($path);

        $this->actingAs($this->admin())
            ->delete(route('admin.night-markets.image.destroy', $market))
            ->assertRedirect(route('admin.night-markets.show', $market));
    }

    public function test_non_owned_path_is_cleared_but_never_deleted(): void
    {
        $market = NightMarket::factory()->create();
        $unsafePath = 'other-module/important.jpg';
        Storage::disk('public')->put($unsafePath, 'keep');
        $market->forceFill(['image_path' => $unsafePath])->save();

        $this->actingAs($this->admin())
            ->delete(route('admin.night-markets.image.destroy', $market))
            ->assertRedirect(route('admin.night-markets.show', $market));

        $this->assertNull($market->fresh()->image_path);
        Storage::disk('public')->assertExists($unsafePath);
    }

    public function test_guest_and_client_cannot_manage_images(): void
    {
        $market = NightMarket::factory()->create();
        $updateUrl = route('admin.night-markets.image.update', $market);
        $deleteUrl = route('admin.night-markets.image.destroy', $market);

        $this->patch($updateUrl)->assertRedirect(route('login'));
        $this->delete($deleteUrl)->assertRedirect(route('login'));

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->actingAs($client)->patch($updateUrl)->assertForbidden();
        $this->actingAs($client)->delete($deleteUrl)->assertForbidden();
    }

    public function test_deactivation_and_reactivation_preserve_the_image(): void
    {
        $market = $this->marketWithOwnedImage();
        $path = $market->image_path;
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.night-markets.deactivate', $market));
        $this->assertSame($path, $market->fresh()->image_path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)->patch(route('admin.night-markets.activate', $market));
        $this->assertSame($path, $market->fresh()->image_path);
        Storage::disk('public')->assertExists($path);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function marketWithOwnedImage(): NightMarket
    {
        $market = NightMarket::factory()->create();
        $path = 'night-markets/123e4567-e89b-42d3-a456-426614174000.jpg';
        Storage::disk('public')->put($path, 'owned image');
        $market->forceFill(['image_path' => $path])->save();

        return $market->fresh();
    }
}
