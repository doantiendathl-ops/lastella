<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HotelSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HotelSettingsCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin     = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->manager   = User::factory()->create();
        $this->manager->assignRole('MANAGER');

        $this->reception = User::factory()->create();
        $this->reception->assignRole('RECEPTION');

        Cache::flush();
    }

    public function test_admin_can_view_hotel_settings_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.hotel-settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/HotelSettings/Index')
                ->has('settings')
                ->has('settings.business_date_offset_hours')
            );
    }

    public function test_manager_cannot_view_hotel_settings_page(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.hotel-settings.index'))
            ->assertForbidden();
    }

    public function test_admin_can_update_settings(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.hotel-settings.update'), [
                'settings' => [
                    ['key' => 'late_checkout_grace_minutes', 'value' => '45'],
                ],
            ])
            ->assertRedirect(route('admin.hotel-settings.index'));

        $this->assertDatabaseHas('hotel_settings', [
            'key'   => 'late_checkout_grace_minutes',
            'value' => '45',
        ]);
    }

    public function test_manager_cannot_update_settings(): void
    {
        $this->actingAs($this->manager)
            ->patch(route('admin.hotel-settings.update'), [
                'settings' => [['key' => 'late_checkout_grace_minutes', 'value' => '45']],
            ])
            ->assertForbidden();
    }

    public function test_unknown_key_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.hotel-settings.update'), [
                'settings' => [['key' => 'invalid_unknown_key', 'value' => 'x']],
            ])
            ->assertSessionHasErrors('settings.0.key');
    }

    public function test_update_records_updated_by_and_invalidates_cache(): void
    {
        $service = new HotelSettingsService();

        // Warm cache
        $service->get('currency_code');

        $this->actingAs($this->admin)
            ->patch(route('admin.hotel-settings.update'), [
                'settings' => [['key' => 'currency_code', 'value' => 'USD']],
            ]);

        $this->assertDatabaseHas('hotel_settings', [
            'key'        => 'currency_code',
            'value'      => 'USD',
            'updated_by' => $this->admin->id,
        ]);

        // Cache should have been busted — fresh read returns new value
        $this->assertEquals('USD', $service->get('currency_code'));
    }
}
