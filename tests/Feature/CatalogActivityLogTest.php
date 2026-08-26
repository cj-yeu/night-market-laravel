<?php

namespace Tests\Feature;

use App\Models\CatalogAuditLog;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogActivityLogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_activity_log_is_admin_only(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->get(route('admin.catalog-activity.index'))->assertRedirect(route('login'));
        $this->actingAs($client)->get(route('admin.catalog-activity.index'))->assertForbidden();
    }

    public function test_admin_catalog_create_is_logged_and_validation_failure_is_not(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.night-markets.store'), [
            'name' => 'Audited Market', 'address' => '1 Audit Road', 'city' => 'Shah Alam', 'status' => 'active',
            'source_url' => 'https://example.test/market', 'verified_at' => '2026-08-20',
            'operating_days' => [['day_of_week' => 'Friday', 'opening_time' => '17:00', 'closing_time' => '23:00']],
        ])->assertRedirect(route('admin.night-markets.create'));

        $market = NightMarket::query()->where('name', 'Audited Market')->firstOrFail();
        $this->assertDatabaseHas('catalog_audit_logs', ['user_id' => $admin->id, 'entity_type' => 'night_market', 'entity_id' => $market->id, 'action' => 'created']);

        $this->actingAs($admin)->post(route('admin.night-markets.store'), [])->assertSessionHasErrors(['name', 'address', 'city']);
        $this->assertSame(1, CatalogAuditLog::query()->count());
    }

    public function test_no_op_status_change_is_not_logged_and_filters_are_preserved(): void
    {
        $admin = $this->admin();
        $market = NightMarket::factory()->create(['status' => NightMarket::STATUS_INACTIVE]);
        foreach (range(1, 21) as $id) {
            CatalogAuditLog::query()->create(['user_id' => $admin->id, 'entity_type' => 'food', 'entity_id' => $id, 'action' => 'updated', 'summary' => 'Updated food “Laksa”']);
        }

        $this->actingAs($admin)->patch(route('admin.night-markets.deactivate', $market))->assertRedirect(route('admin.night-markets.index'));
        $this->assertSame(21, CatalogAuditLog::query()->count());

        $this->actingAs($admin)->patch(route('admin.night-markets.activate', $market))->assertRedirect(route('admin.night-markets.index'));
        $this->assertDatabaseHas('catalog_audit_logs', ['entity_id' => $market->id, 'action' => 'activated']);

        $this->actingAs($admin)->get(route('admin.catalog-activity.index', ['entity_type' => 'food', 'action' => 'updated', 'search' => 'Laksa']))
            ->assertOk()->assertSee('Updated food')->assertSee('page=2', false);
    }

    public function test_image_changes_log_only_safe_image_details(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $market = NightMarket::factory()->create();

        $this->actingAs($admin)->patch(route('admin.night-markets.image.update', $market), [
            'image' => UploadedFile::fake()->image('private-file-name.jpg', 800, 450),
        ])->assertSessionHasNoErrors();

        $log = CatalogAuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('image_updated', $log->action);
        $this->assertSame('Image updated', $log->changed_fields['image']['after']);
        $this->assertStringNotContainsString('private-file-name', json_encode($log->changed_fields));
        $this->assertStringNotContainsString('night-markets/', json_encode($log->changed_fields));
    }

    public function test_no_op_market_update_does_not_create_an_audit_entry(): void
    {
        $market = NightMarket::factory()->create(['source_url' => null, 'verified_at' => null]);
        MarketOperatingDay::factory()->create(['night_market_id' => $market->id, 'day_of_week' => 'Friday', 'opening_time' => '17:00', 'closing_time' => '23:00']);

        $this->actingAs($this->admin())->patch(route('admin.night-markets.update', $market), [
            'name' => $market->name, 'address' => $market->address, 'city' => $market->city,
            'description' => $market->description, 'source_url' => null, 'verified_at' => null,
            'operating_days' => [['day_of_week' => 'Friday', 'opening_time' => '17:00', 'closing_time' => '23:00']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, CatalogAuditLog::query()->count());
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }
}
