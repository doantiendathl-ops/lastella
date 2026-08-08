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
use App\Models\NightAuditRun;
use App\Models\ServicePackage;
use App\Models\ServiceRate;
use App\Models\Stay;
use App\Models\User;
use App\Services\BusinessDateService;
use App\Services\FolioService;
use App\Services\NightAuditService;
use App\Services\PackageEnrollmentService;
use App\Services\Posting\BreakfastPostingJob;
use App\Services\Posting\ExtraBedPostingJob;
use App\Services\Posting\ExtraPersonPostingJob;
use App\Services\Posting\PostingContext;
use App\Services\Posting\ServicePackagePostingJob;
use App\Services\RevenueReportService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Generic ServicePackagePostingJob — Active Pilot financial posting for
 * dynamic (non-legacy) ServicePackage enrollments.
 *
 * Closes the gap proven by the earlier PackageEnrollmentFinancialPostingGapTest
 * (that file now covers only what remains deliberately deferred — see
 * docs/reports/package-enrollment-dynamic-financial-posting-gap.md).
 */
class ServicePackagePostingJobTest extends TestCase
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

    private function enrollFlag(Booking $booking, string $packageKey, string $quantity = '1'): void
    {
        BookingPackageFlag::create([
            'booking_id'  => $booking->id,
            'package_key' => $packageKey,
            'value'       => $quantity,
        ]);
    }

    private function context(Stay $stay): PostingContext
    {
        $booking = Booking::find($stay->booking_id);

        return new PostingContext(
            booking:      $booking,
            folio:        $booking->folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );
    }

    // -------------------------------------------------------------------------
    // 1. Dynamic OncePerStayPerNight posts correct FolioEntry.
    // -------------------------------------------------------------------------

    public function test_dynamic_once_per_stay_per_night_package_posts_correct_folio_entry(): void
    {
        $package = $this->makePackage(); // OncePerStayPerNight/NONE, default
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, 'QA_PILOT_PACKAGE');

        $result = app(ServicePackagePostingJob::class)->execute($this->context($stay));

        $this->assertTrue($result->success);
        $this->assertNotNull($result->entry);
        $this->assertSame('50000.00', $result->entry->unit_price);
        $this->assertSame('1.00', $result->entry->quantity);
        $this->assertSame('50000.00', $result->entry->amount);
    }

    // -------------------------------------------------------------------------
    // 2. Dynamic ManualQuantityPerNight posts quantity × rate correctly.
    // -------------------------------------------------------------------------

    public function test_dynamic_manual_quantity_per_night_package_posts_quantity_times_rate(): void
    {
        $package = $this->makePackage([
            'code'                 => 'DYNAMIC_MANUAL_QTY',
            'name'                 => 'Dynamic Manual Qty Package',
            'calculation_strategy' => 'MANUAL_QUANTITY_PER_NIGHT',
            'quantity_mode'        => 'MANUAL_INPUT',
        ]);
        $package->rates()->create(['unit_price' => 30000, 'effective_from' => '2026-08-01']);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, 'DYNAMIC_MANUAL_QTY', '4');

        $result = app(ServicePackagePostingJob::class)->execute($this->context($stay));

        $this->assertNotNull($result->entry);
        $this->assertSame('30000.00', $result->entry->unit_price);
        $this->assertSame('4.00', $result->entry->quantity);
        $this->assertSame('120000.00', $result->entry->amount);
    }

    // -------------------------------------------------------------------------
    // 3. QA PILOT PACKAGE posts successfully — exact acceptance from Prompt
    //    Section X: enroll = YES, post = YES, charge_type OTHER, traceable,
    //    correct date, correct amount, no duplicate, rate source correct.
    // -------------------------------------------------------------------------

    public function test_qa_pilot_package_full_acceptance(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);

        // CAN ENROLL = YES
        app(PackageEnrollmentService::class)->enroll($booking, 'QA_PILOT_PACKAGE', $this->admin, 1);
        $this->assertDatabaseHas('booking_package_flags', [
            'booking_id' => $booking->id, 'package_key' => 'QA_PILOT_PACKAGE',
        ]);

        // CAN POST FOLIO = YES, via the real Night Audit run (not calling
        // the job directly) — exercises the full registered pipeline.
        $run = app(NightAuditService::class)->runForDate($this->businessDate);
        $this->assertEquals('COMPLETED', $run->status);

        $entry = FolioEntry::where('folio_id', $booking->folio->id)
            ->where('posting_key', "SVC_PKG_QA_PILOT_PACKAGE_{$stay->id}_2026-08-08")
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(ChargeType::Other, $entry->charge_type);
        $this->assertSame('50000.00', $entry->unit_price);
        $this->assertSame('50000.00', $entry->amount);
        $this->assertSame('2026-08-08', $entry->entry_date->toDateString());
        $this->assertStringContainsString('QA Pilot Package', $entry->description);
        $this->assertStringContainsString('QA_PILOT_PACKAGE', $entry->description);

        // No duplicate on a second run for the same completed date.
        app(NightAuditService::class)->runForDate($this->businessDate);
        $this->assertSame(
            1,
            FolioEntry::where('folio_id', $booking->folio->id)
                ->where('posting_key', "SVC_PKG_QA_PILOT_PACKAGE_{$stay->id}_2026-08-08")
                ->count(),
        );
    }

    // -------------------------------------------------------------------------
    // 4. Price comes from service_package_rates, not service_rates.
    // -------------------------------------------------------------------------

    public function test_dynamic_package_price_comes_from_service_package_rates_not_service_rates(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        // A service_rates row with a totally different price and an
        // unrelated charge_type — proves the job never touches this table.
        ServiceRate::create([
            'name' => 'Unrelated', 'charge_type' => ChargeType::Other->value,
            'unit_price' => '999999.00', 'effective_from' => '2026-01-01', 'unit_label' => 'đêm',
            'tax_rate' => '0.0000', 'is_active' => true, 'display_order' => 1, 'created_by' => null,
        ]);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, 'QA_PILOT_PACKAGE');

        $result = app(ServicePackagePostingJob::class)->execute($this->context($stay));

        $this->assertSame('50000.00', $result->entry->unit_price);
    }

    // -------------------------------------------------------------------------
    // 5. Future effective rate selected correctly.
    // -------------------------------------------------------------------------

    public function test_future_effective_rate_selected_correctly_by_business_date(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 40000, 'effective_from' => '2026-01-01']); // A
        $package->rates()->create(['unit_price' => 60000, 'effective_from' => '2026-08-08']); // B, effective on our business date

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, 'QA_PILOT_PACKAGE');

        $result = app(ServicePackagePostingJob::class)->execute($this->context($stay));

        $this->assertSame('60000.00', $result->entry->unit_price);
    }

    public function test_rate_before_effective_date_is_not_yet_applied(): void
    {
        $earlierDate = Carbon::parse('2026-08-01');
        $this->instance(BusinessDateService::class, $this->mockBusinessDate($earlierDate));

        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 40000, 'effective_from' => '2026-01-01']); // A
        $package->rates()->create(['unit_price' => 60000, 'effective_from' => '2026-08-08']); // B, not yet effective

        $stay    = Stay::factory()->create(['status' => StayStatus::CheckedIn]);
        $booking = Booking::find($stay->booking_id);
        Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);
        $this->enrollFlag($booking, 'QA_PILOT_PACKAGE');

        $context = new PostingContext(booking: $booking, folio: $booking->folio, businessDate: $earlierDate, stay: $stay);
        $result  = app(ServicePackagePostingJob::class)->execute($context);

        $this->assertSame('40000.00', $result->entry->unit_price);
    }

    // -------------------------------------------------------------------------
    // 6. Posted historical entry unchanged after rate change.
    // -------------------------------------------------------------------------

    public function test_posted_entry_unchanged_after_rate_changes(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, 'QA_PILOT_PACKAGE');

        $result = app(ServicePackagePostingJob::class)->execute($this->context($stay));
        $entry  = $result->entry;

        $package->rates()->create(['unit_price' => 999000, 'effective_from' => '2026-08-09']);

        $entry->refresh();
        $this->assertSame('50000.00', $entry->unit_price);
        $this->assertSame('50000.00', $entry->amount);
    }

    // -------------------------------------------------------------------------
    // 7. Night Audit rerun no duplicate.
    // -------------------------------------------------------------------------

    public function test_execute_is_idempotent_on_retry(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, 'QA_PILOT_PACKAGE');

        $job = app(ServicePackagePostingJob::class);
        $job->execute($this->context($stay));
        $result2 = $job->execute($this->context($stay));

        $this->assertTrue($result2->alreadyPosted);
        $this->assertSame(1, FolioEntry::where('folio_id', $booking->folio->id)->count());
    }

    // -------------------------------------------------------------------------
    // 8-11. Legacy exclusion — generic job never posts for the 3 legacy
    // keys; dedicated jobs still post exactly once; total = 1, not 2.
    // -------------------------------------------------------------------------

    public function test_generic_job_skips_legacy_breakfast_dedicated_job_still_posts_once(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, PackageEnrollmentService::BREAKFAST_PER_NIGHT);

        ServiceRate::create([
            'name' => 'Ăn sáng', 'charge_type' => ChargeType::FoodBeverage->value,
            'unit_price' => '80000.00', 'effective_from' => '2026-01-01', 'unit_label' => 'đêm',
            'tax_rate' => '0.0000', 'is_active' => true, 'display_order' => 1, 'created_by' => null,
        ]);

        $context = $this->context($stay);

        $genericResult  = app(ServicePackagePostingJob::class)->execute($context);
        $dedicatedResult = app(BreakfastPostingJob::class)->execute($context);

        $this->assertNull($genericResult->entry);
        $this->assertNotNull($dedicatedResult->entry);
        $this->assertSame(
            1,
            FolioEntry::where('folio_id', $booking->folio->id)
                ->where('charge_type', ChargeType::FoodBeverage->value)
                ->count(),
        );
    }

    public function test_generic_job_skips_legacy_extra_person_dedicated_job_still_posts_once(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT, '2');

        ServiceRate::create([
            'name' => 'Người thêm', 'charge_type' => ChargeType::ExtraPerson->value,
            'unit_price' => '200000.00', 'effective_from' => '2026-01-01', 'unit_label' => 'người',
            'tax_rate' => '0.0000', 'is_active' => true, 'display_order' => 1, 'created_by' => null,
        ]);

        $context = $this->context($stay);

        $genericResult   = app(ServicePackagePostingJob::class)->execute($context);
        $dedicatedResult = app(ExtraPersonPostingJob::class)->execute($context);

        $this->assertNull($genericResult->entry);
        $this->assertNotNull($dedicatedResult->entry);
        $this->assertSame(
            1,
            FolioEntry::where('folio_id', $booking->folio->id)
                ->where('charge_type', ChargeType::ExtraPerson->value)
                ->count(),
        );
    }

    public function test_generic_job_skips_legacy_extra_bed_dedicated_job_still_posts_once(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, PackageEnrollmentService::EXTRA_BED_PER_NIGHT, '1');

        ServiceRate::create([
            'name' => 'Giường phụ', 'charge_type' => ChargeType::ExtraBed->value,
            'unit_price' => '150000.00', 'effective_from' => '2026-01-01', 'unit_label' => 'giường',
            'tax_rate' => '0.0000', 'is_active' => true, 'display_order' => 1, 'created_by' => null,
        ]);

        $context = $this->context($stay);

        $genericResult   = app(ServicePackagePostingJob::class)->execute($context);
        $dedicatedResult = app(ExtraBedPostingJob::class)->execute($context);

        $this->assertNull($genericResult->entry);
        $this->assertNotNull($dedicatedResult->entry);
        $this->assertSame(
            1,
            FolioEntry::where('folio_id', $booking->folio->id)
                ->where('charge_type', ChargeType::ExtraBed->value)
                ->count(),
        );
    }

    public function test_full_night_audit_run_posts_legacy_and_dynamic_packages_without_double_posting(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);

        $this->enrollFlag($booking, PackageEnrollmentService::BREAKFAST_PER_NIGHT);
        $this->enrollFlag($booking, 'QA_PILOT_PACKAGE');

        ServiceRate::create([
            'name' => 'Ăn sáng', 'charge_type' => ChargeType::FoodBeverage->value,
            'unit_price' => '80000.00', 'effective_from' => '2026-01-01', 'unit_label' => 'đêm',
            'tax_rate' => '0.0000', 'is_active' => true, 'display_order' => 1, 'created_by' => null,
        ]);

        app(NightAuditService::class)->runForDate($this->businessDate);

        // 3 charges total for this stay: Room Charge (from the checked-in
        // BookingRequirement helper) + 1 Breakfast (dedicated path) + 1
        // QA_PILOT_PACKAGE (generic path) — the per-charge-type assertions
        // below are what actually prove "no double-post": exactly 1 of
        // each, never 2.
        $this->assertSame(3, FolioEntry::where('folio_id', $booking->folio->id)->count());
        $this->assertSame(
            1,
            FolioEntry::where('folio_id', $booking->folio->id)->where('charge_type', ChargeType::FoodBeverage->value)->count(),
        );
        $this->assertSame(
            1,
            FolioEntry::where('folio_id', $booking->folio->id)->where('charge_type', ChargeType::Other->value)->count(),
        );
    }

    // -------------------------------------------------------------------------
    // 12. No-rate dynamic package does not create an invalid charge.
    // -------------------------------------------------------------------------

    public function test_no_effective_rate_does_not_create_a_charge(): void
    {
        $this->makePackage(); // no rate row at all

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, 'QA_PILOT_PACKAGE');

        $result = app(ServicePackagePostingJob::class)->execute($this->context($stay));

        $this->assertNull($result->entry);
        $this->assertTrue($result->success);
        $this->assertDatabaseCount('folio_entries', 0);
    }

    // -------------------------------------------------------------------------
    // 13 + 14. is_bookable=false / is_active=false do not stop posting for
    // an EXISTING enrollment — only new enrollment is gated (already
    // enforced in PackageEnrollmentService::enroll(), tested elsewhere).
    // -------------------------------------------------------------------------

    public function test_no_new_enrollment_package_still_posts_for_an_existing_enrollment(): void
    {
        $package = $this->makePackage(['is_bookable' => false]);
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, 'QA_PILOT_PACKAGE'); // pre-existing flag, bypassing enroll()

        $result = app(ServicePackagePostingJob::class)->execute($this->context($stay));

        $this->assertNotNull($result->entry);
    }

    public function test_inactive_package_still_posts_for_an_existing_enrollment(): void
    {
        $package = $this->makePackage(['is_active' => false]);
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, 'QA_PILOT_PACKAGE');

        $result = app(ServicePackagePostingJob::class)->execute($this->context($stay));

        $this->assertNotNull($result->entry);
    }

    // -------------------------------------------------------------------------
    // 15. Malformed quantity safely handled.
    // -------------------------------------------------------------------------

    public function test_malformed_quantity_is_clamped_to_minimum_one_never_negative_or_zero(): void
    {
        $package = $this->makePackage([
            'code' => 'MALFORMED_QTY_PKG', 'name' => 'Malformed Qty Package',
            'calculation_strategy' => 'MANUAL_QUANTITY_PER_NIGHT', 'quantity_mode' => 'MANUAL_INPUT',
        ]);
        $package->rates()->create(['unit_price' => 10000, 'effective_from' => '2026-08-01']);

        foreach (['0', '-5', 'not-a-number', ''] as $malformedValue) {
            $stay    = $this->makeCheckedInStayWithFolio();
            $booking = Booking::find($stay->booking_id);
            $this->enrollFlag($booking, 'MALFORMED_QTY_PKG', $malformedValue);

            $result = app(ServicePackagePostingJob::class)->execute($this->context($stay));

            $this->assertNotNull($result->entry, "Failed for value [{$malformedValue}]");
            $this->assertSame('1.00', $result->entry->quantity, "Failed for value [{$malformedValue}]");
            $this->assertSame('10000.00', $result->entry->amount, "Failed for value [{$malformedValue}]");
        }
    }

    // -------------------------------------------------------------------------
    // 16. Tourist Tax unchanged — posts independently, no interference.
    // -------------------------------------------------------------------------

    public function test_city_tax_posts_independently_alongside_dynamic_package(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, 'QA_PILOT_PACKAGE');

        $settingsMock = $this->createMock(\App\Services\HotelSettingsService::class);
        $settingsMock->method('getBool')->willReturnCallback(
            fn (string $key) => $key === 'city_tax_enabled'
        );
        $settingsMock->method('getInt')->willReturnCallback(
            fn (string $key, int $default = 0) => $key === 'city_tax_quantity' ? 1 : $default
        );
        $this->instance(\App\Services\HotelSettingsService::class, $settingsMock);

        ServiceRate::create([
            'name' => 'Thuế du lịch', 'charge_type' => ChargeType::CityTax->value,
            'unit_price' => '20000.00', 'effective_from' => '2026-01-01', 'unit_label' => 'đêm',
            'tax_rate' => '0.0000', 'is_active' => true, 'display_order' => 1, 'created_by' => null,
        ]);

        app(NightAuditService::class)->runForDate($this->businessDate);

        $this->assertSame(
            1,
            FolioEntry::where('folio_id', $booking->folio->id)->where('charge_type', ChargeType::CityTax->value)->count(),
        );
        $this->assertSame(
            1,
            FolioEntry::where('folio_id', $booking->folio->id)->where('charge_type', ChargeType::Other->value)->count(),
        );
    }

    // -------------------------------------------------------------------------
    // 17. Folio totals correct.
    // -------------------------------------------------------------------------

    public function test_folio_total_includes_the_dynamic_package_charge(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, 'QA_PILOT_PACKAGE');

        app(ServicePackagePostingJob::class)->execute($this->context($stay));

        $total = app(FolioService::class)->getFolioTotal($booking);

        $this->assertSame(50000.0, $total);
    }

    // -------------------------------------------------------------------------
    // 18. Revenue does not regress — dynamic charge aggregates under OTHER.
    // -------------------------------------------------------------------------

    public function test_revenue_report_includes_dynamic_charge_under_other_without_crashing(): void
    {
        $package = $this->makePackage();
        $package->rates()->create(['unit_price' => 50000, 'effective_from' => '2026-08-01']);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollFlag($booking, 'QA_PILOT_PACKAGE');

        app(ServicePackagePostingJob::class)->execute($this->context($stay));

        $summary = app(RevenueReportService::class)->dailySummary($this->businessDate);

        $this->assertArrayHasKey('by_charge_type', $summary);
        $otherRow = collect($summary['by_charge_type'])->firstWhere('charge_type', ChargeType::Other->value);
        $this->assertNotNull($otherRow);
        $this->assertSame(50000.0, $otherRow['amount']);
    }
}
