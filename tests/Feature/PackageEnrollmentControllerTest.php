<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Models\Booking;
use App\Models\BookingPackageFlag;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\BusinessDateService;
use App\Services\PackageEnrollmentService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ServicePackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PackageEnrollmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private User $reception;
    private Booking $booking;
    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ServicePackageSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('MANAGER');

        $this->reception = User::factory()->create();
        $this->reception->assignRole('RECEPTION');

        $this->businessDate = Carbon::parse('2026-07-04');
        $this->instance(BusinessDateService::class, $this->mockBusinessDate($this->businessDate));

        $this->booking = Booking::factory()->create();

        // See PackageEnrollmentServiceTest::setUp() — the backfill seeder
        // creates no price rows; enroll() now requires an effective rate.
        foreach (PackageEnrollmentService::ALLOWED_PACKAGES as $code) {
            ServicePackage::where('code', $code)->first()->rates()->create([
                'unit_price'     => 100000,
                'effective_from' => '2026-01-01',
            ]);
        }
    }

    private function mockBusinessDate(Carbon $date): BusinessDateService
    {
        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn($date);
        $mock->method('businessDateFor')->willReturn($date);

        return $mock;
    }

    // -------------------------------------------------------------------------
    // show
    // -------------------------------------------------------------------------

    public function test_admin_can_view_packages_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.bookings.packages', $this->booking))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Booking/Packages', false)
                ->has('enrollments')
                ->has('available_packages')
                ->has('can')
                ->where('can.manage_packages', true)
            );
    }

    public function test_reception_can_view_packages_page_read_only(): void
    {
        $this->actingAs($this->reception)
            ->get(route('admin.bookings.packages', $this->booking))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Booking/Packages', false)
                ->where('can.manage_packages', false)
            );
    }

    public function test_unauthenticated_redirected_to_login(): void
    {
        $this->get(route('admin.bookings.packages', $this->booking))
            ->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // enroll
    // -------------------------------------------------------------------------

    public function test_admin_can_enroll_breakfast(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            'value'       => '1',
        ]);
    }

    public function test_admin_can_enroll_extra_person_with_quantity_2(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
                'quantity'    => 2,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
            'value'       => '2',
        ]);
    }

    public function test_admin_can_enroll_extra_bed(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => PackageEnrollmentService::EXTRA_BED_PER_NIGHT,
                'quantity'    => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::EXTRA_BED_PER_NIGHT,
            'value'       => '1',
        ]);
    }

    public function test_reception_cannot_enroll_package(): void
    {
        $this->actingAs($this->reception)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('booking_package_flags', [
            'booking_id' => $this->booking->id,
        ]);
    }

    public function test_enroll_rejects_invalid_package_key(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => 'INVALID_PACKAGE',
                'quantity'    => 1,
            ])
            ->assertSessionHasErrors('package_key');
    }

    public function test_enroll_rejects_quantity_zero(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
                'quantity'    => 0,
            ])
            ->assertSessionHasErrors('quantity');
    }

    // -------------------------------------------------------------------------
    // unenroll
    // -------------------------------------------------------------------------

    public function test_admin_can_unenroll_package_when_not_yet_posted_today(): void
    {
        BookingPackageFlag::factory()->create([
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
            'value'       => '1',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.bookings.packages.unenroll', [
                'booking'    => $this->booking,
                'packageKey' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
        ]);
    }

    public function test_unenroll_returns_error_when_already_posted_today(): void
    {
        BookingPackageFlag::factory()->create([
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
            'value'       => '2',
        ]);

        $folio = Folio::factory()->for($this->booking)->create(['status' => FolioStatus::Open]);

        FolioEntry::factory()->create([
            'folio_id'       => $folio->id,
            'charge_type'    => ChargeType::ExtraPerson,
            'posting_source' => 'NIGHT_AUDIT',
            'entry_date'     => $this->businessDate->toDateString(),
            'voided_at'      => null,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.bookings.packages.unenroll', [
                'booking'    => $this->booking,
                'packageKey' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('package');

        $this->assertDatabaseHas('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
        ]);
    }

    // -------------------------------------------------------------------------
    // available_packages includes current rates resolved from
    // service_package_rates (source of truth — see ServicePackageCatalogTest
    // and the Package Enrollment Dynamic Catalog architecture review).
    // -------------------------------------------------------------------------

    public function test_show_page_includes_current_rate_when_rate_exists(): void
    {
        // setUp() already seeded a 100000 rate effective 2026-01-01 for all 3
        // legacy packages. Add a later, higher rate for EXTRA_PERSON — the
        // most-recently-effective row must win over the older one.
        ServicePackage::where('code', PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT)
            ->first()
            ->rates()
            ->create([
                'unit_price'     => 200000,
                'effective_from' => '2026-02-01',
            ]);

        $this->actingAs($this->admin)
            ->get(route('admin.bookings.packages', $this->booking))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Booking/Packages', false)
                ->where('available_packages.1.key', PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT)
                ->where('available_packages.1.current_rate', 200000)
            );
    }
}
