<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Models\ServiceRate;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceRateCrudTest extends TestCase
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
    }

    public function test_admin_can_view_service_rates_index(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.service-rates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ServiceRates/Index')
                ->has('rates')
                ->has('chargeTypes')
            );
    }

    public function test_reception_cannot_view_service_rates(): void
    {
        $this->actingAs($this->reception)
            ->get(route('admin.service-rates.index'))
            ->assertForbidden();
    }

    public function test_admin_can_create_service_rate(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.service-rates.store'), [
                'name'           => 'Giường phụ',
                'charge_type'    => 'EXTRA_BED',
                'unit_price'     => 300000,
                'effective_from' => '2026-07-01',
                'unit_label'     => 'đêm',
                'display_order'  => 10,
            ])
            ->assertRedirect(route('admin.service-rates.index'));

        $this->assertDatabaseHas('service_rates', [
            'name'           => 'Giường phụ',
            'charge_type'    => 'EXTRA_BED',
            'unit_price'     => '300000.00',
            'effective_from' => '2026-07-01',
        ]);
    }

    public function test_charge_type_room_is_rejected_on_create(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.service-rates.store'), [
                'name'           => 'Tiền phòng',
                'charge_type'    => 'ROOM',
                'unit_price'     => 500000,
                'effective_from' => '2026-07-01',
                'unit_label'     => 'đêm',
            ])
            ->assertSessionHasErrors('charge_type');
    }

    public function test_price_change_inserts_new_row_not_overwrites(): void
    {
        $rate = ServiceRate::create([
            'name'           => 'Bia Heineken',
            'charge_type'    => 'MINIBAR',
            'unit_price'     => 45000,
            'effective_from' => '2026-01-01',
            'unit_label'     => 'chai',
            'is_active'      => true,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-rates.update', $rate), [
                'name'           => 'Bia Heineken',
                'charge_type'    => 'MINIBAR',
                'unit_price'     => 50000,
                'effective_from' => '2026-07-01',
                'unit_label'     => 'chai',
            ])
            ->assertRedirect(route('admin.service-rates.index'));

        // Old row still exists
        $this->assertDatabaseHas('service_rates', ['id' => $rate->id, 'unit_price' => '45000.00']);
        // New row inserted
        $this->assertDatabaseHas('service_rates', ['unit_price' => '50000.00', 'effective_from' => '2026-07-01']);
        $this->assertDatabaseCount('service_rates', 2);
    }

    public function test_non_price_edit_updates_in_place(): void
    {
        $rate = ServiceRate::create([
            'name'           => 'Bia cũ',
            'charge_type'    => 'MINIBAR',
            'unit_price'     => 45000,
            'effective_from' => '2026-01-01',
            'unit_label'     => 'chai',
            'is_active'      => true,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-rates.update', $rate), [
                'name'           => 'Bia Heineken mới',
                'charge_type'    => 'MINIBAR',
                'unit_price'     => 45000,          // same price
                'effective_from' => '2026-01-01',   // same date
                'unit_label'     => 'chai',
            ])
            ->assertRedirect();

        // Still only one row
        $this->assertDatabaseCount('service_rates', 1);
        $this->assertDatabaseHas('service_rates', ['id' => $rate->id, 'name' => 'Bia Heineken mới']);
    }

    public function test_toggle_active_flips_status(): void
    {
        $rate = ServiceRate::create([
            'name'           => 'Test',
            'charge_type'    => 'MINIBAR',
            'unit_price'     => 10000,
            'effective_from' => '2026-01-01',
            'unit_label'     => 'cái',
            'is_active'      => true,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-rates.toggle', $rate))
            ->assertRedirect();

        $this->assertDatabaseHas('service_rates', ['id' => $rate->id, 'is_active' => false]);
    }

    public function test_inactive_rate_toggle_activates(): void
    {
        $rate = ServiceRate::create([
            'name'           => 'Test',
            'charge_type'    => 'MINIBAR',
            'unit_price'     => 10000,
            'effective_from' => '2026-01-01',
            'unit_label'     => 'cái',
            'is_active'      => false,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-rates.toggle', $rate))
            ->assertRedirect();

        $this->assertDatabaseHas('service_rates', ['id' => $rate->id, 'is_active' => true]);
    }

    public function test_reception_cannot_create_service_rate(): void
    {
        $this->actingAs($this->reception)
            ->post(route('admin.service-rates.store'), [
                'name'           => 'X',
                'charge_type'    => 'MINIBAR',
                'unit_price'     => 10000,
                'effective_from' => '2026-01-01',
                'unit_label'     => 'cái',
            ])
            ->assertForbidden();
    }
}
