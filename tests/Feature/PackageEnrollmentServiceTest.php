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
use App\Services\BusinessDateService;
use App\Services\PackageEnrollmentService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ServicePackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageEnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private PackageEnrollmentService $service;
    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ServicePackageSeeder::class);

        $this->businessDate = Carbon::parse('2026-07-04');
        $this->instance(BusinessDateService::class, $this->mockBusinessDate($this->businessDate));

        $this->service = app(PackageEnrollmentService::class);

        // The backfill seeder deliberately creates no price rows (Milestone 1
        // decision — no historical price to migrate). enroll() now requires
        // an effective rate, so these tests give the 3 legacy packages a
        // rate effective on/before $this->businessDate.
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
    // enroll with quantity (ADR-80)
    // -------------------------------------------------------------------------

    public function test_enroll_stores_quantity_in_value_field(): void
    {
        $booking = Booking::factory()->create();

        $this->service->enroll($booking, PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT, quantity: 3);

        $this->assertDatabaseHas('booking_package_flags', [
            'booking_id'  => $booking->id,
            'package_key' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
            'value'       => '3',
        ]);
    }

    public function test_enroll_updates_quantity_when_re_enrolled(): void
    {
        $booking = Booking::factory()->create();

        $this->service->enroll($booking, PackageEnrollmentService::EXTRA_BED_PER_NIGHT, quantity: 1);
        $this->service->enroll($booking, PackageEnrollmentService::EXTRA_BED_PER_NIGHT, quantity: 2);

        $this->assertDatabaseCount('booking_package_flags', 1);
        $this->assertDatabaseHas('booking_package_flags', [
            'booking_id'  => $booking->id,
            'package_key' => PackageEnrollmentService::EXTRA_BED_PER_NIGHT,
            'value'       => '2',
        ]);
    }

    // -------------------------------------------------------------------------
    // unenroll guard for new packages
    // -------------------------------------------------------------------------

    public function test_unenroll_blocked_for_extra_person_when_already_posted_today(): void
    {
        $booking = Booking::factory()->create();
        BookingPackageFlag::create([
            'booking_id'  => $booking->id,
            'package_key' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
            'value'       => '1',
            'created_by'  => null,
        ]);

        $folio = Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);

        FolioEntry::factory()->create([
            'folio_id'       => $folio->id,
            'charge_type'    => ChargeType::ExtraPerson,
            'posting_source' => 'NIGHT_AUDIT',
            'entry_date'     => $this->businessDate->toDateString(),
            'voided_at'      => null,
        ]);

        $this->expectException(\App\Exceptions\PackageAlreadyPostedException::class);

        $this->service->unenroll($booking, PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT);
    }

    // -------------------------------------------------------------------------
    // getEnrollmentSummary
    // -------------------------------------------------------------------------

    public function test_get_enrollment_summary_returns_all_three_packages(): void
    {
        $booking = Booking::factory()->create();

        // Only enroll breakfast
        BookingPackageFlag::create([
            'booking_id'  => $booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            'value'       => '1',
            'created_by'  => null,
        ]);

        $summary = $this->service->getEnrollmentSummary($booking);

        $this->assertArrayHasKey(PackageEnrollmentService::BREAKFAST_PER_NIGHT, $summary);
        $this->assertArrayHasKey(PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT, $summary);
        $this->assertArrayHasKey(PackageEnrollmentService::EXTRA_BED_PER_NIGHT, $summary);

        $this->assertTrue($summary[PackageEnrollmentService::BREAKFAST_PER_NIGHT]['enrolled']);
        $this->assertFalse($summary[PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT]['enrolled']);
        $this->assertFalse($summary[PackageEnrollmentService::EXTRA_BED_PER_NIGHT]['enrolled']);

        $this->assertEquals(1, $summary[PackageEnrollmentService::BREAKFAST_PER_NIGHT]['quantity']);
        $this->assertEquals(1, $summary[PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT]['quantity']);
    }
}
