<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ServicePackage;
use App\Models\ServicePackageRate;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePackageRateAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $reception;
    private ServicePackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = tap(User::factory()->create())->assignRole('ADMIN');
        $this->reception = tap(User::factory()->create())->assignRole('RECEPTION');

        $this->package = ServicePackage::create([
            'code' => 'RATE_TEST_PKG',
            'name' => 'Rate Test Package',
            'charge_type' => 'OTHER',
            'calculation_strategy' => 'ONCE_PER_STAY_PER_NIGHT',
            'quantity_mode' => 'NONE',
            'default_quantity' => 1,
            'unit_label' => 'đêm',
            'posting_frequency' => 'PER_NIGHT',
        ]);
    }

    public function test_reception_cannot_manage_rates(): void
    {
        $this->actingAs($this->reception)
            ->post(route('admin.service-packages.rates.store', $this->package), ['unit_price' => 100000, 'effective_from' => '2026-01-01'])
            ->assertForbidden();
    }

    public function test_admin_can_view_rate_history_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.service-packages.history', $this->package))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/ServicePackages/History')->has('rates'));
    }

    public function test_admin_can_create_the_first_rate(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.service-packages.rates.store', $this->package), [
                'unit_price' => 150000,
                'effective_from' => '2026-01-01',
            ])
            ->assertRedirect(route('admin.service-packages.history', $this->package));

        $this->assertDatabaseHas('service_package_rates', [
            'service_package_id' => $this->package->id,
            'unit_price' => '150000.00',
        ]);
    }

    public function test_admin_can_create_a_future_rate(): void
    {
        ServicePackageRate::create(['service_package_id' => $this->package->id, 'unit_price' => 150000, 'effective_from' => '2026-01-01']);

        $this->actingAs($this->admin)
            ->post(route('admin.service-packages.rates.store', $this->package), [
                'unit_price' => 180000,
                'effective_from' => '2099-01-01',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('service_package_rates', 2);
    }

    public function test_creating_a_new_rate_does_not_modify_the_old_row(): void
    {
        $original = ServicePackageRate::create(['service_package_id' => $this->package->id, 'unit_price' => 150000, 'effective_from' => '2026-01-01']);

        $this->actingAs($this->admin)
            ->post(route('admin.service-packages.rates.store', $this->package), [
                'unit_price' => 180000,
                'effective_from' => '2026-02-01',
            ])
            ->assertRedirect();

        $this->assertEquals(150000, $original->refresh()->unit_price);
    }

    public function test_current_rate_resolves_correctly_for_business_date(): void
    {
        ServicePackageRate::create(['service_package_id' => $this->package->id, 'unit_price' => 150000, 'effective_from' => '2026-01-01']);
        ServicePackageRate::create(['service_package_id' => $this->package->id, 'unit_price' => 180000, 'effective_from' => '2026-06-01']);

        $this->assertEquals(180000, $this->package->currentRate('2026-06-15')->unit_price);
        $this->assertEquals(150000, $this->package->currentRate('2026-03-01')->unit_price);
    }

    public function test_future_rate_is_not_yet_current(): void
    {
        ServicePackageRate::create(['service_package_id' => $this->package->id, 'unit_price' => 150000, 'effective_from' => '2026-01-01']);
        ServicePackageRate::create(['service_package_id' => $this->package->id, 'unit_price' => 999999, 'effective_from' => '2099-01-01']);

        $this->assertEquals(150000, $this->package->currentRate('2026-06-01')->unit_price);
    }

    public function test_inactive_rate_is_not_resolved_as_current(): void
    {
        ServicePackageRate::create(['service_package_id' => $this->package->id, 'unit_price' => 150000, 'effective_from' => '2026-01-01', 'is_active' => false]);

        $this->assertNull($this->package->currentRate('2026-06-01'));
    }

    public function test_tie_breaker_for_same_effective_from_picks_newest_row(): void
    {
        ServicePackageRate::create(['service_package_id' => $this->package->id, 'unit_price' => 150000, 'effective_from' => '2026-01-01']);
        $newer = ServicePackageRate::create(['service_package_id' => $this->package->id, 'unit_price' => 160000, 'effective_from' => '2026-01-01']);

        $resolved = $this->package->currentRate('2026-01-01');
        $this->assertSame($newer->id, $resolved->id);
    }

    public function test_toggle_rate_creates_audit_log(): void
    {
        $rate = ServicePackageRate::create(['service_package_id' => $this->package->id, 'unit_price' => 150000, 'effective_from' => '2026-01-01']);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-packages.rates.toggle', ['servicePackage' => $this->package->id, 'rate' => $rate->id]))
            ->assertRedirect();

        $this->assertFalse($rate->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => ServicePackageRate::class,
            'entity_id' => $rate->id,
            'action' => 'updated',
        ]);
    }

    public function test_rate_belongs_to_the_correct_package_only(): void
    {
        $otherPackage = ServicePackage::create([
            'code' => 'OTHER_PKG', 'name' => 'Other', 'charge_type' => 'OTHER',
            'calculation_strategy' => 'ONCE_PER_STAY_PER_NIGHT', 'quantity_mode' => 'NONE',
            'default_quantity' => 1, 'unit_label' => 'đêm', 'posting_frequency' => 'PER_NIGHT',
        ]);
        $rate = ServicePackageRate::create(['service_package_id' => $this->package->id, 'unit_price' => 150000, 'effective_from' => '2026-01-01']);

        // Toggling a rate through the wrong package's URL must 404, not silently succeed.
        $this->actingAs($this->admin)
            ->patch(route('admin.service-packages.rates.toggle', ['servicePackage' => $otherPackage->id, 'rate' => $rate->id]))
            ->assertNotFound();
    }

    public function test_resolving_current_rate_never_uses_charge_type(): void
    {
        // Two different packages sharing the same charge_type must not leak
        // each other's prices — proves the resolver is keyed by package id.
        $sibling = ServicePackage::create([
            'code' => 'SIBLING_PKG', 'name' => 'Sibling', 'charge_type' => 'OTHER',
            'calculation_strategy' => 'ONCE_PER_STAY_PER_NIGHT', 'quantity_mode' => 'NONE',
            'default_quantity' => 1, 'unit_label' => 'đêm', 'posting_frequency' => 'PER_NIGHT',
        ]);
        ServicePackageRate::create(['service_package_id' => $sibling->id, 'unit_price' => 999999, 'effective_from' => '2026-01-01']);
        ServicePackageRate::create(['service_package_id' => $this->package->id, 'unit_price' => 150000, 'effective_from' => '2026-01-01']);

        $this->assertEquals(150000, $this->package->currentRate('2026-06-01')->unit_price);
        $this->assertEquals(999999, $sibling->currentRate('2026-06-01')->unit_price);
    }

    public function test_a_price_of_zero_is_never_created_implicitly(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.service-packages.history', $this->package))
            ->assertOk();

        $this->assertDatabaseCount('service_package_rates', 0);
    }
}
