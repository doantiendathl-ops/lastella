<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BookingPackageFlag;
use App\Models\ServicePackage;
use App\Models\ServicePackageRate;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ServicePackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePackageAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = tap(User::factory()->create())->assignRole('ADMIN');
        $this->manager = tap(User::factory()->create())->assignRole('MANAGER');
        $this->reception = tap(User::factory()->create())->assignRole('RECEPTION');
    }

    // --- Authorization -----------------------------------------------

    public function test_admin_can_view_service_packages_index(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.service-packages.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ServicePackages/Index')
                ->has('packages')
                ->has('chargeTypes')
                ->has('calculationStrategies')
                ->has('quantityModes')
                ->has('postingFrequencies')
            );
    }

    public function test_manager_can_view_service_packages_index(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.service-packages.index'))
            ->assertOk();
    }

    public function test_reception_cannot_view_service_packages_index(): void
    {
        $this->actingAs($this->reception)
            ->get(route('admin.service-packages.index'))
            ->assertForbidden();
    }

    public function test_reception_cannot_create_update_or_toggle_package(): void
    {
        $package = $this->createPackage();

        $this->actingAs($this->reception)
            ->post(route('admin.service-packages.store'), $this->packagePayload('NEW_PKG'))
            ->assertForbidden();

        $this->actingAs($this->reception)
            ->patch(route('admin.service-packages.update', $package), $this->packagePayload($package->code))
            ->assertForbidden();

        $this->actingAs($this->reception)
            ->patch(route('admin.service-packages.toggle', $package), ['field' => 'is_active'])
            ->assertForbidden();
    }

    // --- Package CRUD --------------------------------------------------

    public function test_index_shows_the_three_backfilled_packages(): void
    {
        $this->seed(ServicePackageSeeder::class);

        $response = $this->actingAs($this->admin)->get(route('admin.service-packages.index'))->assertOk();

        $codes = collect($response->viewData('page')['props']['packages'])->pluck('code')->all();
        $this->assertContains('BREAKFAST_PER_NIGHT', $codes);
        $this->assertContains('EXTRA_PERSON_PER_NIGHT', $codes);
        $this->assertContains('EXTRA_BED_PER_NIGHT', $codes);
    }

    public function test_admin_can_create_a_valid_package(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.service-packages.store'), $this->packagePayload('airport_transfer'))
            ->assertRedirect(route('admin.service-packages.index'));

        $this->assertDatabaseHas('service_packages', ['code' => 'AIRPORT_TRANSFER']);
    }

    public function test_code_is_normalized_to_uppercase(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.service-packages.store'), $this->packagePayload('lowercase_code'))
            ->assertRedirect();

        $this->assertDatabaseHas('service_packages', ['code' => 'LOWERCASE_CODE']);
        $this->assertDatabaseMissing('service_packages', ['code' => 'lowercase_code']);
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $this->createPackage('DUPLICATE_PKG');

        $this->actingAs($this->admin)
            ->post(route('admin.service-packages.store'), $this->packagePayload('DUPLICATE_PKG'))
            ->assertSessionHasErrors('code');
    }

    public function test_unimplemented_calculation_strategy_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.service-packages.store'), array_merge(
                $this->packagePayload('BAD_STRATEGY'),
                ['calculation_strategy' => 'ONCE_PER_BOOKING']
            ))
            ->assertSessionHasErrors('calculation_strategy');
    }

    public function test_unimplemented_quantity_mode_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.service-packages.store'), array_merge(
                $this->packagePayload('BAD_QTY'),
                ['quantity_mode' => 'FROM_ADULTS']
            ))
            ->assertSessionHasErrors('quantity_mode');
    }

    public function test_incompatible_strategy_and_quantity_mode_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.service-packages.store'), array_merge(
                $this->packagePayload('BAD_COMBO'),
                ['calculation_strategy' => 'ONCE_PER_STAY_PER_NIGHT', 'quantity_mode' => 'MANUAL_INPUT']
            ))
            ->assertSessionHasErrors('quantity_mode');
    }

    public function test_admin_can_edit_package_name_description_unit_and_order(): void
    {
        $package = $this->createPackage();

        $this->actingAs($this->admin)
            ->patch(route('admin.service-packages.update', $package), array_merge(
                $this->packagePayload($package->code),
                ['name' => 'Tên mới', 'description' => 'Mô tả mới', 'unit_label' => 'lần', 'display_order' => 99]
            ))
            ->assertRedirect();

        $package->refresh();
        $this->assertSame('Tên mới', $package->name);
        $this->assertSame('Mô tả mới', $package->description);
        $this->assertSame('lần', $package->unit_label);
        $this->assertSame(99, $package->display_order);
    }

    public function test_toggle_is_active_flips_only_that_field(): void
    {
        $package = $this->createPackage();

        $this->actingAs($this->admin)
            ->patch(route('admin.service-packages.toggle', $package), ['field' => 'is_active'])
            ->assertRedirect();

        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertTrue($package->is_bookable);
    }

    public function test_toggle_is_bookable_flips_only_that_field(): void
    {
        $package = $this->createPackage();

        $this->actingAs($this->admin)
            ->patch(route('admin.service-packages.toggle', $package), ['field' => 'is_bookable'])
            ->assertRedirect();

        $package->refresh();
        $this->assertTrue($package->is_active);
        $this->assertFalse($package->is_bookable);
    }

    public function test_create_update_and_toggle_write_audit_logs(): void
    {
        $package = $this->createPackage();
        $this->assertDatabaseHas('audit_logs', ['entity_type' => ServicePackage::class, 'entity_id' => $package->id, 'action' => 'created']);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-packages.update', $package), array_merge($this->packagePayload($package->code), ['name' => 'Renamed']))
            ->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['entity_type' => ServicePackage::class, 'entity_id' => $package->id, 'action' => 'updated']);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-packages.toggle', $package), ['field' => 'is_active'])
            ->assertRedirect();
        $this->assertSame(3, AuditLog::where('entity_type', ServicePackage::class)->where('entity_id', $package->id)->count());
    }

    // --- Used-package guards --------------------------------------------

    public function test_unused_package_code_can_be_changed(): void
    {
        $package = $this->createPackage('UNUSED_PKG');

        $this->actingAs($this->admin)
            ->patch(route('admin.service-packages.update', $package), array_merge($this->packagePayload('UNUSED_PKG'), ['code' => 'RENAMED_PKG']))
            ->assertRedirect();

        $this->assertSame('RENAMED_PKG', $package->refresh()->code);
    }

    public function test_package_with_a_rate_cannot_have_its_code_changed(): void
    {
        $package = $this->createPackage('HAS_RATE_PKG');
        ServicePackageRate::create(['service_package_id' => $package->id, 'unit_price' => 100000, 'effective_from' => '2026-01-01']);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-packages.update', $package), array_merge($this->packagePayload('HAS_RATE_PKG'), ['code' => 'NEW_CODE']))
            ->assertSessionHasErrors('code');

        $this->assertSame('HAS_RATE_PKG', $package->refresh()->code);
    }

    public function test_package_with_a_booking_flag_cannot_have_its_code_changed(): void
    {
        $package = $this->createPackage('FLAGGED_PKG');
        BookingPackageFlag::factory()->create(['package_key' => 'FLAGGED_PKG']);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-packages.update', $package), array_merge($this->packagePayload('FLAGGED_PKG'), ['code' => 'NEW_CODE']))
            ->assertSessionHasErrors('code');

        $this->assertSame('FLAGGED_PKG', $package->refresh()->code);
    }

    public function test_used_package_cannot_have_its_strategy_changed(): void
    {
        $package = $this->createPackage('USED_STRATEGY_PKG');
        ServicePackageRate::create(['service_package_id' => $package->id, 'unit_price' => 100000, 'effective_from' => '2026-01-01']);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-packages.update', $package), array_merge(
                $this->packagePayload($package->code),
                ['calculation_strategy' => 'MANUAL_QUANTITY_PER_NIGHT', 'quantity_mode' => 'MANUAL_INPUT']
            ))
            ->assertSessionHasErrors('calculation_strategy');
    }

    public function test_used_package_cannot_have_its_charge_type_changed(): void
    {
        $package = $this->createPackage('USED_CHARGE_PKG');
        ServicePackageRate::create(['service_package_id' => $package->id, 'unit_price' => 100000, 'effective_from' => '2026-01-01']);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-packages.update', $package), array_merge($this->packagePayload($package->code), ['charge_type' => 'EXTRA_BED']))
            ->assertSessionHasErrors('charge_type');
    }

    public function test_there_is_no_hard_delete_route_for_a_used_package(): void
    {
        $package = $this->createPackage('USED_DELETE_PKG');
        ServicePackageRate::create(['service_package_id' => $package->id, 'unit_price' => 100000, 'effective_from' => '2026-01-01']);

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.service-packages.destroy'));
        $this->assertDatabaseHas('service_packages', ['id' => $package->id]);
    }

    public function test_renaming_a_used_package_is_still_allowed(): void
    {
        $package = $this->createPackage('RENAME_USED_PKG');
        ServicePackageRate::create(['service_package_id' => $package->id, 'unit_price' => 100000, 'effective_from' => '2026-01-01']);

        $this->actingAs($this->admin)
            ->patch(route('admin.service-packages.update', $package), array_merge($this->packagePayload($package->code), ['name' => 'Tên đã đổi']))
            ->assertRedirect();

        $this->assertSame('Tên đã đổi', $package->refresh()->name);
    }

    public function test_has_been_used_and_can_change_code_flags_are_correct_in_index(): void
    {
        $unused = $this->createPackage('FLAG_UNUSED');
        $used = $this->createPackage('FLAG_USED');
        ServicePackageRate::create(['service_package_id' => $used->id, 'unit_price' => 100000, 'effective_from' => '2026-01-01']);

        $response = $this->actingAs($this->admin)->get(route('admin.service-packages.index'))->assertOk();
        $rows = collect($response->viewData('page')['props']['packages'])->keyBy('code');

        $this->assertFalse($rows->get('FLAG_UNUSED')['has_been_used']);
        $this->assertTrue($rows->get('FLAG_UNUSED')['can_change_code']);
        $this->assertTrue($rows->get('FLAG_USED')['has_been_used']);
        $this->assertFalse($rows->get('FLAG_USED')['can_change_code']);
    }

    // --- Helpers ---------------------------------------------------------

    private function createPackage(string $code = 'TEST_PKG'): ServicePackage
    {
        return ServicePackage::create($this->packagePayload($code));
    }

    private function packagePayload(string $code): array
    {
        return [
            'code'                  => $code,
            'name'                  => 'Test Package',
            'description'           => null,
            'charge_type'           => 'OTHER',
            'calculation_strategy'  => 'ONCE_PER_STAY_PER_NIGHT',
            'quantity_mode'         => 'NONE',
            'default_quantity'      => 1,
            'unit_label'            => 'đêm',
            'posting_frequency'     => 'PER_NIGHT',
            'is_active'             => true,
            'is_bookable'           => true,
            'display_order'         => 0,
        ];
    }
}
