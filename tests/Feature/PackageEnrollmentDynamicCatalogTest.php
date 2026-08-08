<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPackageFlag;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\BusinessDateService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Package Enrollment Dynamic Catalog Integration — targeted coverage for the
 * gap closed by this task: service_packages/service_package_rates is now the
 * single source of truth for what Booking Package Enrollment can show and
 * accept, replacing the old hardcoded $packageMap/ALLOWED_PACKAGES catalog.
 *
 * See docs/reviews/package-enrollment-dynamic-catalog-architecture-review.md
 * for the full before/after and docs/reports/package-enrollment-hardcoded-catalog-gap.md
 * for the original defect report this closes.
 */
class PackageEnrollmentDynamicCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Booking $booking;
    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = tap(User::factory()->create())->assignRole('ADMIN');
        $this->booking = Booking::factory()->create();

        $this->businessDate = Carbon::parse('2026-08-08');
        $this->instance(BusinessDateService::class, $this->mockBusinessDate($this->businessDate));
    }

    private function mockBusinessDate(Carbon $date): BusinessDateService
    {
        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn($date);
        $mock->method('businessDateFor')->willReturn($date);

        return $mock;
    }

    private function makePackage(array $overrides = []): ServicePackage
    {
        return ServicePackage::create(array_merge([
            'code'                 => 'QA_PILOT_PACKAGE',
            'name'                 => 'QA Pilot Package',
            'description'          => 'Gói thí điểm QA',
            'charge_type'          => 'OTHER',
            'calculation_strategy' => 'ONCE_PER_STAY_PER_NIGHT',
            'quantity_mode'        => 'NONE',
            'default_quantity'     => 1,
            'unit_label'           => 'đêm',
            'posting_frequency'    => 'PER_NIGHT',
            'is_active'            => true,
            'is_bookable'          => true,
            'display_order'        => 5,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // CASE 1 + 2 — active + bookable + priced package appears in the booking
    // catalog; this is literally "QA PILOT PACKAGE created in admin, visible
    // in Booking Enrollment" from the same DB row.
    // -------------------------------------------------------------------------

    public function test_active_bookable_package_with_rate_appears_in_booking_catalog(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $this->actingAs($this->admin)
            ->get(route('admin.bookings.packages', $this->booking))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Booking/Packages', false)
                ->where('available_packages', function ($packages) {
                    $keys = collect($packages)->pluck('key');

                    return $keys->contains('QA_PILOT_PACKAGE');
                })
            );
    }

    public function test_package_price_shown_on_booking_page_matches_admin_catalog_rate(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $this->actingAs($this->admin)
            ->get(route('admin.bookings.packages', $this->booking))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Booking/Packages', false)
                ->where('available_packages', function ($packages) {
                    $pkg = collect($packages)->firstWhere('key', 'QA_PILOT_PACKAGE');

                    return $pkg !== null && (float) $pkg['current_rate'] === 50000.0;
                })
            );
    }

    // -------------------------------------------------------------------------
    // CASE 3 — inactive package cannot be enrolled, even by a direct POST
    // bypassing the disabled frontend button.
    // -------------------------------------------------------------------------

    public function test_inactive_package_cannot_be_enrolled(): void
    {
        $package = $this->makePackage(['is_active' => false]);
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => 'QA_PILOT_PACKAGE',
            ])
            ->assertSessionHasErrors('package_key');

        $this->assertDatabaseMissing('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => 'QA_PILOT_PACKAGE',
        ]);
    }

    // -------------------------------------------------------------------------
    // CASE 4 — active but not bookable ("no new enrollment") cannot be
    // enrolled either.
    // -------------------------------------------------------------------------

    public function test_not_bookable_package_cannot_be_enrolled(): void
    {
        $package = $this->makePackage(['is_bookable' => false]);
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => 'QA_PILOT_PACKAGE',
            ])
            ->assertSessionHasErrors('package_key');

        $this->assertDatabaseMissing('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => 'QA_PILOT_PACKAGE',
        ]);
    }

    // -------------------------------------------------------------------------
    // CASE 5 — active + bookable but no effective rate: shown with a null
    // price (frontend disables the button); backend also rejects a direct
    // POST that bypasses that disabled state.
    // -------------------------------------------------------------------------

    public function test_package_without_effective_rate_shows_null_price_and_cannot_be_enrolled(): void
    {
        $this->makePackage(); // no rate row created at all

        $this->actingAs($this->admin)
            ->get(route('admin.bookings.packages', $this->booking))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Booking/Packages', false)
                ->where('available_packages', function ($packages) {
                    $pkg = collect($packages)->firstWhere('key', 'QA_PILOT_PACKAGE');

                    return $pkg !== null && $pkg['current_rate'] === null;
                })
            );

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => 'QA_PILOT_PACKAGE',
            ])
            ->assertSessionHasErrors('package_key');

        $this->assertDatabaseMissing('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => 'QA_PILOT_PACKAGE',
        ]);
    }

    // -------------------------------------------------------------------------
    // CASE 6 — effective rate resolution: most-recently-effective-and-created
    // active rate as of the current business date wins, matching
    // ServicePackage::currentRate() semantics already covered for the admin
    // catalog (ServicePackageCatalogTest / ServicePackageRateVersioningTest).
    // -------------------------------------------------------------------------

    public function test_effective_rate_resolution_picks_latest_effective_from_on_or_before_business_date(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 40000, 'effective_from' => '2026-01-01']);
        $package->rates()->create(['unit_price' => 60000, 'effective_from' => '2026-08-01']); // <= businessDate
        $package->rates()->create(['unit_price' => 99000, 'effective_from' => '2026-09-01']); // future, not yet effective

        $this->actingAs($this->admin)
            ->get(route('admin.bookings.packages', $this->booking))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Booking/Packages', false)
                ->where('available_packages', function ($packages) {
                    $pkg = collect($packages)->firstWhere('key', 'QA_PILOT_PACKAGE');

                    return $pkg !== null && (float) $pkg['current_rate'] === 60000.0;
                })
            );
    }

    // -------------------------------------------------------------------------
    // CASE 7 — server resolves price; the client cannot influence it. There
    // is no price field accepted by the enroll endpoint at all, and
    // booking_package_flags never stores a price — confirm both.
    // -------------------------------------------------------------------------

    public function test_enroll_ignores_any_client_supplied_price_fields(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => 'QA_PILOT_PACKAGE',
                'current_rate' => 1, // attempted price tampering
                'unit_price'    => 1,
            ])
            ->assertRedirect();

        $flag = BookingPackageFlag::where('booking_id', $this->booking->id)
            ->where('package_key', 'QA_PILOT_PACKAGE')
            ->first();

        $this->assertNotNull($flag);
        // booking_package_flags has no price column at all — the schema
        // itself makes client-supplied price impossible to persist.
        $this->assertFalse($flag->getAttributes() !== [] && array_key_exists('unit_price', $flag->getAttributes()));
    }

    // -------------------------------------------------------------------------
    // CASE 8 + 9 — historical enrollment survives the package becoming
    // inactive: it must still render (in both available_packages and
    // enrollments) even though it is no longer offered for new enrollment.
    // -------------------------------------------------------------------------

    public function test_historical_enrollment_still_renders_after_package_is_deactivated(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        BookingPackageFlag::create([
            'booking_id'  => $this->booking->id,
            'package_key' => 'QA_PILOT_PACKAGE',
            'value'       => '1',
        ]);

        $package->update(['is_active' => false, 'is_bookable' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.bookings.packages', $this->booking))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Booking/Packages', false)
                ->where('available_packages', function ($packages) {
                    $keys = collect($packages)->pluck('key');

                    return $keys->contains('QA_PILOT_PACKAGE');
                })
                ->where('enrollments.QA_PILOT_PACKAGE.enrolled', true)
            );
    }

    public function test_cannot_re_enroll_a_deactivated_package_but_can_still_unenroll_it(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        BookingPackageFlag::create([
            'booking_id'  => $this->booking->id,
            'package_key' => 'QA_PILOT_PACKAGE',
            'value'       => '1',
        ]);

        $package->update(['is_active' => false]);

        // Cannot re-enroll (already enrolled is idempotent via updateOrCreate,
        // but a fresh booking attempting new enrollment must be blocked).
        $otherBooking = Booking::factory()->create();
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $otherBooking), [
                'package_key' => 'QA_PILOT_PACKAGE',
            ])
            ->assertSessionHasErrors('package_key');

        // But the original booking can still unenroll its historical flag.
        $this->actingAs($this->admin)
            ->delete(route('admin.bookings.packages.unenroll', [
                'booking'    => $this->booking,
                'packageKey' => 'QA_PILOT_PACKAGE',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => 'QA_PILOT_PACKAGE',
        ]);
    }

    // -------------------------------------------------------------------------
    // CASE 12 — duplicate/repeated enrollment on a dynamic (non-legacy)
    // package is idempotent, same guarantee as the legacy 3 packages
    // (BookingPackageEnrollmentTest::test_duplicate_enroll_is_idempotent).
    // -------------------------------------------------------------------------

    public function test_duplicate_enroll_is_idempotent_for_a_dynamic_package(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), ['package_key' => 'QA_PILOT_PACKAGE'])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), ['package_key' => 'QA_PILOT_PACKAGE'])
            ->assertRedirect();

        $this->assertDatabaseCount('booking_package_flags', 1);
    }

    // -------------------------------------------------------------------------
    // Hardcode-removal proof: an unknown package_key that was never a valid
    // hardcoded constant, and never a real service_packages row, is rejected
    // — the ALLOWED_PACKAGES constant is no longer consulted for validation.
    // -------------------------------------------------------------------------

    public function test_enroll_rejects_a_package_key_with_no_matching_service_package_row(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => 'NOT_A_REAL_PACKAGE',
            ])
            ->assertSessionHasErrors('package_key');

        $this->assertDatabaseMissing('booking_package_flags', [
            'booking_id' => $this->booking->id,
        ]);
    }
}
