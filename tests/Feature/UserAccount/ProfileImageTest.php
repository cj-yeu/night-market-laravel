<?php

namespace Tests\Feature\UserAccount;

use App\Models\NightMarket;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileImageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_cannot_upload_or_delete_an_avatar(): void
    {
        Storage::fake('public');

        $this->patch(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ])->assertRedirect(route('login'));

        $this->delete(route('profile.avatar.destroy'))->assertRedirect(route('login'));
        Storage::disk('public')->assertDirectoryEmpty('avatars');
    }

    public function test_authenticated_user_can_upload_a_randomly_named_jpeg_to_the_avatar_directory(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->upload($user, UploadedFile::fake()->image('original-profile-name.jpg', 300, 300))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'Your profile image has been updated successfully.');

        $path = $user->refresh()->avatar_path;

        $this->assertNotNull($path);
        $this->assertMatchesRegularExpression('/\Aavatars\/[0-9a-f-]+\.jpg\z/', $path);
        $this->assertStringNotContainsString('original-profile-name', $path);
        $this->assertFalse(str_starts_with($path, storage_path()));
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar_path' => $path]);
    }

    public function test_valid_png_upload_succeeds(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->upload($user, UploadedFile::fake()->image('avatar.png', 250, 250))
            ->assertRedirect(route('profile.edit'));

        $this->assertStringEndsWith('.png', $user->refresh()->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_valid_webp_upload_succeeds_when_runtime_supports_webp(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('The current GD runtime does not support WebP generation.');
        }

        Storage::fake('public');
        $user = User::factory()->create();

        $this->upload($user, UploadedFile::fake()->image('avatar.webp', 250, 250))
            ->assertRedirect(route('profile.edit'));

        $this->assertStringEndsWith('.webp', $user->refresh()->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->upload($user, UploadedFile::fake()->create('disguised.jpg', 100, 'text/plain'))
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_svg_upload_is_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $svg = UploadedFile::fake()->createWithContent(
            'avatar.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->upload($user, $svg)->assertSessionHasErrors('avatar');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_gif_upload_is_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->upload($user, UploadedFile::fake()->image('avatar.gif', 200, 200))
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_file_larger_than_two_megabytes_is_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $oversized = UploadedFile::fake()->image('avatar.jpg', 200, 200)->size(2049);

        $this->upload($user, $oversized)->assertSessionHasErrors('avatar');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_image_exceeding_dimension_limit_is_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->upload($user, UploadedFile::fake()->image('avatar.jpg', 4097, 10))
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_failed_validation_keeps_the_previous_avatar_and_file(): void
    {
        Storage::fake('public');
        $oldPath = 'avatars/11111111-1111-4111-8111-111111111111.jpg';
        Storage::disk('public')->put($oldPath, 'old-image');
        $user = User::factory()->create(['avatar_path' => $oldPath]);

        $this->upload($user, UploadedFile::fake()->create('malware.php', 10, 'text/x-php'))
            ->assertSessionHasErrors('avatar');

        $this->assertSame($oldPath, $user->refresh()->avatar_path);
        Storage::disk('public')->assertExists($oldPath);
    }

    public function test_replacing_an_avatar_stores_the_new_file_then_deletes_the_old_owned_file(): void
    {
        Storage::fake('public');
        $oldPath = 'avatars/22222222-2222-4222-8222-222222222222.jpg';
        Storage::disk('public')->put($oldPath, 'old-image');
        $user = User::factory()->create(['avatar_path' => $oldPath]);

        $this->upload($user, UploadedFile::fake()->image('replacement.png', 300, 300))
            ->assertRedirect(route('profile.edit'));

        $newPath = $user->refresh()->avatar_path;

        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_user_can_remove_their_avatar_and_database_path(): void
    {
        Storage::fake('public');
        $path = 'avatars/33333333-3333-4333-8333-333333333333.webp';
        Storage::disk('public')->put($path, 'owned-image');
        $user = User::factory()->create(['avatar_path' => $path]);

        $this->actingAs($user)
            ->delete(route('profile.avatar.destroy'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'Your profile image has been removed.');

        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_removing_when_no_avatar_exists_is_safe_and_idempotent(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($user)
            ->delete(route('profile.avatar.destroy'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'Your profile image has been removed.');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_unsafe_outside_directory_path_is_never_deleted(): void
    {
        Storage::fake('public');
        $unsafePath = 'documents/not-an-owned-avatar.jpg';
        Storage::disk('public')->put($unsafePath, 'unrelated-file');
        $user = User::factory()->create(['avatar_path' => $unsafePath]);

        $this->actingAs($user)->delete(route('profile.avatar.destroy'));

        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('public')->assertExists($unsafePath);
    }

    public function test_avatar_routes_cannot_target_or_delete_another_user(): void
    {
        Storage::fake('public');
        $otherPath = 'avatars/44444444-4444-4444-8444-444444444444.jpg';
        Storage::disk('public')->put($otherPath, 'other-user-image');
        $currentUser = User::factory()->create();
        $otherUser = User::factory()->create(['avatar_path' => $otherPath]);

        $this->actingAs($currentUser)
            ->patch('/profile/avatar/'.$otherUser->id, [
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
            ])
            ->assertNotFound();

        $this->actingAs($currentUser)->delete(route('profile.avatar.destroy'));

        $this->assertSame($otherPath, $otherUser->refresh()->avatar_path);
        Storage::disk('public')->assertExists($otherPath);
    }

    public function test_default_avatar_uses_at_most_two_safe_initials(): void
    {
        $twoInitials = User::factory()->create(['name' => 'Chen Jeng Jun', 'avatar_path' => null]);
        $oneInitial = User::factory()->create(['name' => 'Aisyah', 'avatar_path' => null]);

        $this->assertSame('CJ', $twoInitials->initials());
        $this->assertSame('A', $oneInitial->initials());

        $this->actingAs($twoInitials)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('CJ')
            ->assertSee("Chen Jeng Jun's default profile avatar", false);

        $this->actingAs($oneInitial)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('default profile avatar');
    }

    public function test_profile_page_and_client_navbar_display_the_current_avatar(): void
    {
        Storage::fake('public');
        $path = 'avatars/55555555-5555-4555-8555-555555555555.png';
        Storage::disk('public')->put($path, 'profile-image');
        $user = User::factory()->create(['avatar_path' => $path]);
        $url = Storage::disk('public')->url($path);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee($url)
            ->assertSee('Remove Photo')
            ->assertSee('user-avatar-sm', false)
            ->assertSee('user-avatar-lg', false);
    }

    public function test_public_reviews_display_avatar_or_initials_without_private_user_fields(): void
    {
        Storage::fake('public');
        $market = NightMarket::factory()->create();
        $path = 'avatars/66666666-6666-4666-8666-666666666666.jpg';
        Storage::disk('public')->put($path, 'reviewer-image');
        $reviewerWithImage = User::factory()->create([
            'name' => 'Photo Reviewer',
            'email' => 'private-photo-reviewer@example.test',
            'avatar_path' => $path,
        ]);
        $reviewerWithInitials = User::factory()->create([
            'name' => 'Initial Reviewer',
            'email' => 'private-initial-reviewer@example.test',
            'avatar_path' => null,
        ]);
        Review::factory()->approved()->create([
            'night_market_id' => $market->id,
            'user_id' => $reviewerWithImage->id,
            'comment' => 'Review with profile image.',
        ]);
        Review::factory()->approved()->create([
            'night_market_id' => $market->id,
            'user_id' => $reviewerWithInitials->id,
            'comment' => 'Review with initials.',
        ]);

        $this->get(route('night-markets.show', $market))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($path))
            ->assertSee('IR')
            ->assertDontSee($reviewerWithImage->email)
            ->assertDontSee($reviewerWithInitials->email);
    }

    public function test_approved_review_query_eager_loads_avatar_author_data(): void
    {
        $market = NightMarket::factory()->create();
        Review::factory()->count(3)->approved()->create(['night_market_id' => $market->id]);

        $summary = app(ReviewService::class)->approvedSummaryForMarket($market);

        foreach ($summary['reviews'] as $review) {
            $this->assertTrue($review->relationLoaded('user'));
            $this->assertTrue($review->user->getAttributes() !== []);
            $this->assertArrayHasKey('avatar_path', $review->user->getAttributes());
            $this->assertArrayNotHasKey('email', $review->user->getAttributes());
        }
    }

    private function upload(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->patch(route('profile.avatar.update'), [
            'avatar' => $file,
        ]);
    }
}
