<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BookingPackageFlag;
use App\Models\ServicePackage;
use App\Models\ServicePackageRate;
use App\Models\User;
use App\Services\PackageEnrollmentService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ServicePackageSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ServicePackageCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_creates_the_three_existing_packages_with_correct_config(): void
    {
        $this->seed(ServicePackageSeeder::class);

        $this->assertSame(3, ServicePackage::count());

        $breakfast = ServicePackage::where('code', 'BREAKFAST_PER_NIGHT')->first();
        $this->assertNotNull($breakfast);
        $this->assertSame('FOOD_BEVERAGE', $breakfast->charge_type);
        $this->assertSame('ONCE_PER_STAY_PER_NIGHT', $breakfast->calculation_strategy);
        $this->assertSame('NONE', $breakfast->quantity_mode);
        $this->assertTrue($breakfast->is_active);
        $this->assertTrue($breakfast->is_bookable);

        $extraPerson = ServicePackage::where('code', 'EXTRA_PERSON_PER_NIGHT')->first();
        $this->assertNotNull($extraPerson);
        $this->assertSame('EXTRA_PERSON', $extraPerson->charge_type);
        $this->assertSame('MANUAL_QUANTITY_PER_NIGHT', $extraPerson->calculation_strategy);
        $this->assertSame('MANUAL_INPUT', $extraPerson->quantity_mode);

        $extraBed = ServicePackage::where('code', 'EXTRA_BED_PER_NIGHT')->first();
        $this->assertNotNull($extraBed);
        $this->assertSame('EXTRA_BED', $extraBed->charge_type);
        $this->assertSame('MANUAL_QUANTITY_PER_NIGHT', $extraBed->calculation_strategy);
    }

    public function test_backfill_is_idempotent(): void
    {
        $this->seed(ServicePackageSeeder::class);
        $this->seed(ServicePackageSeeder::class);

        $this->assertSame(3, ServicePackage::count());
    }

    public function test_backfill_creates_no_price_rows(): void
    {
        $this->seed(ServicePackageSeeder::class);

        $this->assertSame(0, ServicePackageRate::count());
    }

    public function test_package_rates_relationship_returns_linked_rates(): void
    {
        $package = ServicePackage::create($this->packageAttributes('TEST_PKG'));

        $rate = ServicePackageRate::create([
            'service_package_id' => $package->id,
            'unit_price'         => 100000,
            'effective_from'     => '2026-01-01',
        ]);

        $this->assertTrue($package->rates->contains($rate));
        $this->assertSame(1, $package->rates()->count());
    }

    public function test_active_scope_filters_out_inactive_packages(): void
    {
        ServicePackage::create($this->packageAttributes('ACTIVE_PKG', isActive: true));
        ServicePackage::create($this->packageAttributes('INACTIVE_PKG', isActive: false));

        $codes = ServicePackage::active()->pluck('code')->all();

        $this->assertContains('ACTIVE_PKG', $codes);
        $this->assertNotContains('INACTIVE_PKG', $codes);
    }

    public function test_bookable_scope_filters_out_non_bookable_packages(): void
    {
        ServicePackage::create($this->packageAttributes('BOOKABLE_PKG', isBookable: true));
        ServicePackage::create($this->packageAttributes('NOT_BOOKABLE_PKG', isBookable: false));

        $codes = ServicePackage::bookable()->pluck('code')->all();

        $this->assertContains('BOOKABLE_PKG', $codes);
        $this->assertNotContains('NOT_BOOKABLE_PKG', $codes);
    }

    public function test_service_packages_manage_permission_is_seeded_and_granted_correctly(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = tap(User::factory()->create())->assignRole('ADMIN');
        $manager = tap(User::factory()->create())->assignRole('MANAGER');
        $reception = tap(User::factory()->create())->assignRole('RECEPTION');

        $this->assertTrue($admin->can('service_packages.manage'));
        $this->assertTrue($manager->can('service_packages.manage'));
        $this->assertFalse($reception->can('service_packages.manage'));
    }

    public function test_creating_a_package_writes_an_audit_log_entry(): void
    {
        $package = ServicePackage::create($this->packageAttributes('AUDIT_PKG'));

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => ServicePackage::class,
            'entity_id'   => $package->id,
            'action'      => 'created',
        ]);
    }

    public function test_updating_a_package_name_writes_an_audit_log_entry_with_old_and_new_value(): void
    {
        $package = ServicePackage::create($this->packageAttributes('RENAME_PKG', name: 'Tên cũ'));

        $package->update(['name' => 'Tên mới']);

        $log = AuditLog::where('entity_type', ServicePackage::class)
            ->where('entity_id', $package->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Tên cũ', $log->old_data['name']);
        $this->assertSame('Tên mới', $log->new_data['name']);
    }

    public function test_backfilled_codes_match_the_legacy_package_enrollment_constants_exactly(): void
    {
        $this->seed(ServicePackageSeeder::class);

        // This is the compatibility contract: booking_package_flags.package_key
        // (written by PackageEnrollmentService::enroll()) must resolve to a
        // real service_packages row by exact code match — no alias, no
        // string transformation, no partial match.
        $this->assertTrue(
            ServicePackage::where('code', PackageEnrollmentService::BREAKFAST_PER_NIGHT)->exists()
        );
        $this->assertTrue(
            ServicePackage::where('code', PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT)->exists()
        );
        $this->assertTrue(
            ServicePackage::where('code', PackageEnrollmentService::EXTRA_BED_PER_NIGHT)->exists()
        );

        foreach (PackageEnrollmentService::ALLOWED_PACKAGES as $legacyKey) {
            $this->assertTrue(
                ServicePackage::where('code', $legacyKey)->exists(),
                "No service_packages row backfilled for legacy package_key [{$legacyKey}]"
            );
        }
    }

    public function test_a_legacy_booking_package_flag_resolves_to_the_matching_service_package(): void
    {
        $this->seed(ServicePackageSeeder::class);

        $flag = new BookingPackageFlag([
            'booking_id'  => 1,
            'package_key' => PackageEnrollmentService::EXTRA_BED_PER_NIGHT,
            'value'       => '2',
        ]);

        $matchedPackage = ServicePackage::where('code', $flag->package_key)->first();

        $this->assertNotNull($matchedPackage);
        $this->assertSame('Giường phụ / đêm', $matchedPackage->name);
        $this->assertSame('EXTRA_BED', $matchedPackage->charge_type);
    }

    public function test_package_code_must_be_unique(): void
    {
        ServicePackage::create($this->packageAttributes('DUPLICATE_PKG'));

        $this->expectException(QueryException::class);

        ServicePackage::create($this->packageAttributes('DUPLICATE_PKG'));
    }

    public function test_cannot_save_package_with_an_unimplemented_calculation_strategy(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ServicePackage::create($this->packageAttributes('BAD_STRATEGY_PKG', calculationStrategy: 'ONCE_PER_BOOKING'));
    }

    public function test_cannot_save_package_with_an_unimplemented_quantity_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ServicePackage::create($this->packageAttributes('BAD_QTY_PKG', quantityMode: 'FROM_ADULTS'));
    }

    public function test_cannot_save_package_with_incompatible_quantity_mode_for_strategy(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // OncePerStayPerNight requires quantity_mode = None, not ManualInput.
        ServicePackage::create($this->packageAttributes(
            'INCOMPATIBLE_PKG',
            calculationStrategy: 'ONCE_PER_STAY_PER_NIGHT',
            quantityMode: 'MANUAL_INPUT',
        ));
    }

    public function test_backfilled_packages_pass_the_calculation_strategy_guard(): void
    {
        // Guards against Milestone 1 backfill ever drifting out of sync with
        // the model's own implemented-strategy invariant.
        $this->seed(ServicePackageSeeder::class);

        $this->assertSame(3, ServicePackage::count());
    }

    private function packageAttributes(
        string $code,
        bool $isActive = true,
        bool $isBookable = true,
        ?string $name = null,
        string $calculationStrategy = 'ONCE_PER_STAY_PER_NIGHT',
        string $quantityMode = 'NONE',
    ): array {
        return [
            'code'                  => $code,
            'name'                  => $name ?? $code,
            'charge_type'           => 'OTHER',
            'calculation_strategy'  => $calculationStrategy,
            'quantity_mode'         => $quantityMode,
            'default_quantity'      => 1,
            'unit_label'            => 'đêm',
            'posting_frequency'     => 'PER_NIGHT',
            'is_active'             => $isActive,
            'is_bookable'           => $isBookable,
            'display_order'         => 0,
        ];
    }
}
