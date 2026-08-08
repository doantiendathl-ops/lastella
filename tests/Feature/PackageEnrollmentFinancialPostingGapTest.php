<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingPackageFlag;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\ServicePackage;
use App\Models\ServiceRate;
use App\Models\Stay;
use App\Models\User;
use App\Services\BusinessDateService;
use App\Services\NightAuditService;
use App\Services\PackageEnrollmentService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REMAINING, DELIBERATELY DEFERRED gaps only — see
 * docs/reports/package-enrollment-dynamic-financial-posting-gap.md for the
 * full history.
 *
 * As of the "Dynamic Service Package Generic Financial Posting" task, the
 * original finding this file proved (dynamic packages enroll but never post
 * a charge) is RESOLVED for non-legacy packages by
 * App\Services\Posting\ServicePackagePostingJob — see
 * tests/Feature/ServicePackagePostingJobTest.php for that positive coverage.
 * The two tests that used to assert "0 FolioEntry for a dynamic package"
 * were removed from this file because they are no longer true and would be
 * a false regression signal; do not re-add them without re-confirming the
 * gap first.
 *
 * This file now locks in exactly what is still NOT resolved (Active Pilot
 * decision — deliberately deferred, not an oversight):
 *  - legacy price-source convergence (service_package_rates vs service_rates
 *    for the 3 legacy packages specifically stay on service_rates);
 *  - historical correctness regression guards that predate this task and
 *    must keep passing regardless of which posting path is involved.
 */
class PackageEnrollmentFinancialPostingGapTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin        = User::factory()->create();
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

    private function makeCheckedInStayWithFolio(): Stay
    {
        $stay    = Stay::factory()->create(['status' => StayStatus::CheckedIn]);
        $booking = Booking::find($stay->booking_id);
        Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);

        $stay->loadMissing('room');
        if ($stay->room !== null) {
            BookingRequirement::factory()->create([
                'booking_id'   => $booking->id,
                'room_type_id' => $stay->room->room_type_id,
                'room_price'   => 500000,
            ]);
        }

        return $stay;
    }

    // -------------------------------------------------------------------------
    // Second source-of-truth proof: service_package_rates has zero reach
    // into what an actual FolioEntry amount is for a legacy package — only
    // service_rates does, even though the Admin catalog (and, since the
    // prior task, the Booking Enrollment display) reads service_package_rates.
    // -------------------------------------------------------------------------

    public function test_legacy_package_posting_amount_comes_from_service_rates_not_service_package_rates(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);

        BookingPackageFlag::create([
            'booking_id'  => $booking->id,
            'package_key' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
            'value'       => '1',
        ]);

        // Give the ServicePackage catalog row (used for display since the
        // prior task) a completely different price than service_rates.
        $catalogPackage = ServicePackage::where('code', PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT)->first();
        $catalogPackage?->rates()->create(['unit_price' => 999999, 'effective_from' => '2026-01-01']);

        // The real posting rate, from the legacy table the posting job
        // actually reads.
        ServiceRate::create([
            'name'           => 'Người thêm / đêm',
            'charge_type'    => ChargeType::ExtraPerson->value,
            'unit_price'     => '200000.00',
            'effective_from' => '2026-01-01',
            'unit_label'     => 'người',
            'tax_rate'       => '0.0000',
            'is_active'      => true,
            'display_order'  => 91,
            'created_by'     => null,
        ]);

        app(NightAuditService::class)->runForDate($this->businessDate);

        // The posted amount matches service_rates (200000), NOT the
        // service_package_rates catalog price (999999) — proving the two
        // tables are genuinely independent sources for the same package.
        $this->assertDatabaseHas('folio_entries', [
            'folio_id'   => $booking->folio->id,
            'unit_price' => '200000.00',
        ]);
        $this->assertDatabaseMissing('folio_entries', [
            'folio_id'   => $booking->folio->id,
            'unit_price' => '999999.00',
        ]);
    }

    // -------------------------------------------------------------------------
    // Historical correctness (Prompt Section XIII) — legacy path only,
    // since it is the only path that posts anything at all.
    // -------------------------------------------------------------------------

    public function test_rate_change_after_posting_does_not_alter_already_posted_folio_entry(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);

        BookingPackageFlag::create([
            'booking_id'  => $booking->id,
            'package_key' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
            'value'       => '1',
        ]);

        ServiceRate::create([
            'name' => 'Người thêm / đêm', 'charge_type' => ChargeType::ExtraPerson->value,
            'unit_price' => '200000.00', 'effective_from' => '2026-01-01', 'unit_label' => 'người',
            'tax_rate' => '0.0000', 'is_active' => true, 'display_order' => 91, 'created_by' => null,
        ]);

        app(NightAuditService::class)->runForDate($this->businessDate);

        $posted = FolioEntry::where('folio_id', $booking->folio->id)
            ->where('charge_type', ChargeType::ExtraPerson->value)
            ->firstOrFail();

        // Rate changes AFTER posting.
        ServiceRate::create([
            'name' => 'Người thêm / đêm', 'charge_type' => ChargeType::ExtraPerson->value,
            'unit_price' => '500000.00', 'effective_from' => '2026-08-01', 'unit_label' => 'người',
            'tax_rate' => '0.0000', 'is_active' => true, 'display_order' => 91, 'created_by' => null,
        ]);

        $posted->refresh();
        $this->assertSame('200000.00', $posted->unit_price);
        $this->assertSame('200000.00', $posted->amount);
    }

    public function test_re_running_night_audit_for_the_same_completed_business_date_does_not_duplicate(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);

        BookingPackageFlag::create([
            'booking_id'  => $booking->id,
            'package_key' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
            'value'       => '1',
        ]);
        ServiceRate::create([
            'name' => 'Người thêm / đêm', 'charge_type' => ChargeType::ExtraPerson->value,
            'unit_price' => '200000.00', 'effective_from' => '2026-01-01', 'unit_label' => 'người',
            'tax_rate' => '0.0000', 'is_active' => true, 'display_order' => 91, 'created_by' => null,
        ]);

        $firstRun = app(NightAuditService::class)->runForDate($this->businessDate);
        $secondRun = app(NightAuditService::class)->runForDate($this->businessDate);

        // runForDate() short-circuits to the existing completed run — same
        // NightAuditRun row, no re-execution, no duplicate FolioEntry.
        $this->assertSame($firstRun->id, $secondRun->id);
        $this->assertSame(
            1,
            FolioEntry::where('folio_id', $booking->folio->id)
                ->where('charge_type', ChargeType::ExtraPerson->value)
                ->count(),
        );
    }
}
