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
use App\Models\NightAuditRun;
use App\Models\ServiceRate;
use App\Models\Stay;
use App\Models\User;
use App\Services\BusinessDateService;
use App\Services\NightAuditPipeline;
use App\Services\NightAuditService;
use App\Services\PackageEnrollmentService;
use App\Services\Posting\BreakfastPostingJob;
use App\Services\Posting\PostingContext;
use App\Services\Posting\RoomChargePostingJob;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BreakfastPostingJobFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->businessDate = Carbon::parse('2026-07-03');
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

    private function makeBreakfastRate(string $unitPrice = '150000.00'): ServiceRate
    {
        return ServiceRate::create([
            'name'           => 'Bữa sáng',
            'charge_type'    => ChargeType::FoodBeverage->value,
            'unit_price'     => $unitPrice,
            'effective_from' => '2026-01-01',
            'unit_label'     => 'người',
            'tax_rate'       => '0.0000',
            'is_active'      => true,
            'display_order'  => 10,
            'created_by'     => null,
        ]);
    }

    private function enrollBreakfast(Booking $booking): void
    {
        BookingPackageFlag::create([
            'booking_id'  => $booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            'value'       => '1',
            'created_by'  => null,
        ]);
    }

    private function makeAuditRun(): NightAuditRun
    {
        return NightAuditRun::create([
            'business_date' => $this->businessDate->toDateString(),
            'status'        => 'PENDING',
            'run_by'        => $this->admin->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // PostingJob — shouldProcess
    // -------------------------------------------------------------------------

    public function test_should_process_returns_false_for_non_enrolled_booking(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $job = app(BreakfastPostingJob::class);
        $this->assertFalse($job->shouldProcess($context));
    }

    public function test_should_process_returns_true_for_enrolled_booking(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;

        $this->enrollBreakfast($booking);

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $job = app(BreakfastPostingJob::class);
        $this->assertTrue($job->shouldProcess($context));
    }

    // -------------------------------------------------------------------------
    // PostingJob — execute
    // -------------------------------------------------------------------------

    public function test_breakfast_job_skips_when_no_food_beverage_rate_exists(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollBreakfast($booking);

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $job    = app(BreakfastPostingJob::class);
        $result = $job->execute($context);

        $this->assertTrue($result->success);
        $this->assertNull($result->entry);
        $this->assertStringContainsString('FOOD_BEVERAGE', $result->message);

        $this->assertDatabaseCount('folio_entries', 0);
    }

    public function test_breakfast_job_posts_entry_for_enrolled_booking(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollBreakfast($booking);
        $this->makeBreakfastRate('150000.00');

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $job    = app(BreakfastPostingJob::class);
        $result = $job->execute($context);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->entry);

        $this->assertDatabaseHas('folio_entries', [
            'folio_id'       => $folio->id,
            'stay_id'        => $stay->id,
            'posting_source' => 'NIGHT_AUDIT',
            'unit_price'     => '150000.00',
        ]);
    }

    public function test_breakfast_job_uses_posting_key_format(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollBreakfast($booking);
        $this->makeBreakfastRate();

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $job = app(BreakfastPostingJob::class);
        $job->execute($context);

        $expectedKey = "BREAKFAST_{$stay->id}_" . $this->businessDate->toDateString();
        $this->assertDatabaseHas('folio_entries', [
            'posting_key' => $expectedKey,
        ]);
    }

    public function test_breakfast_job_uses_night_audit_posting_source(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollBreakfast($booking);
        $this->makeBreakfastRate();

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        app(BreakfastPostingJob::class)->execute($context);

        $this->assertDatabaseHas('folio_entries', [
            'posting_source' => 'NIGHT_AUDIT',
            'folio_id'       => $folio->id,
        ]);
    }

    public function test_breakfast_job_uses_food_beverage_charge_type(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollBreakfast($booking);
        $this->makeBreakfastRate();

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        app(BreakfastPostingJob::class)->execute($context);

        $this->assertDatabaseHas('folio_entries', [
            'charge_type' => ChargeType::FoodBeverage->value,
            'folio_id'    => $folio->id,
        ]);
    }

    public function test_breakfast_job_uses_service_rate_amount(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollBreakfast($booking);
        $this->makeBreakfastRate('99000.00');

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        app(BreakfastPostingJob::class)->execute($context);

        $this->assertDatabaseHas('folio_entries', [
            'unit_price' => '99000.00',
            'amount'     => '99000.00',
            'folio_id'   => $folio->id,
        ]);
    }

    public function test_breakfast_job_is_idempotent(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollBreakfast($booking);
        $this->makeBreakfastRate();

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $job = app(BreakfastPostingJob::class);
        $job->execute($context);

        // Second call for same business date → alreadyPosted
        $result2 = $job->execute($context);
        $this->assertTrue($result2->alreadyPosted);
        $this->assertDatabaseCount('folio_entries', 1);
    }

    // -------------------------------------------------------------------------
    // Pipeline integration
    // -------------------------------------------------------------------------

    public function test_pipeline_processes_room_charge_before_breakfast(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollBreakfast($booking);
        $this->makeBreakfastRate();

        $auditRun = $this->makeAuditRun();
        $pipeline = app(NightAuditPipeline::class);
        $pipeline->register(app(RoomChargePostingJob::class));
        $pipeline->register(app(BreakfastPostingJob::class));
        $pipeline->run($auditRun, $this->businessDate);

        $auditRun->refresh();
        $this->assertEquals('COMPLETED', $auditRun->status);

        // Both room charge and breakfast were posted
        $this->assertDatabaseHas('night_audit_booking_logs', [
            'stay_id'   => $stay->id,
            'job_class' => RoomChargePostingJob::class,
            'result'    => 'POSTED',
        ]);
        $this->assertDatabaseHas('night_audit_booking_logs', [
            'stay_id'   => $stay->id,
            'job_class' => BreakfastPostingJob::class,
            'result'    => 'POSTED',
        ]);

        // Room charge log appears before breakfast log
        $logs    = \App\Models\NightAuditBookingLog::orderBy('id')->get();
        $roomIdx = $logs->search(fn ($l) => $l->job_class === RoomChargePostingJob::class);
        $brkIdx  = $logs->search(fn ($l) => $l->job_class === BreakfastPostingJob::class);
        $this->assertLessThan($brkIdx, $roomIdx);
    }

    public function test_pipeline_skips_breakfast_for_non_enrolled_bookings(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();
        // NOT enrolled for breakfast
        $this->makeBreakfastRate();

        $auditRun = $this->makeAuditRun();
        $pipeline = app(NightAuditPipeline::class);
        $pipeline->register(app(RoomChargePostingJob::class));
        $pipeline->register(app(BreakfastPostingJob::class));
        $pipeline->run($auditRun, $this->businessDate);

        $this->assertDatabaseHas('night_audit_booking_logs', [
            'job_class' => BreakfastPostingJob::class,
            'result'    => 'SKIPPED',
        ]);

        // No breakfast folio entry
        $this->assertDatabaseMissing('folio_entries', [
            'charge_type' => ChargeType::FoodBeverage->value,
        ]);
    }

    public function test_rerun_does_not_duplicate_breakfast_entries(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollBreakfast($booking);
        $this->makeBreakfastRate();

        // First run
        $auditRun1 = $this->makeAuditRun();
        $pipeline  = app(NightAuditPipeline::class);
        $pipeline->register(app(RoomChargePostingJob::class));
        $pipeline->register(app(BreakfastPostingJob::class));
        $pipeline->run($auditRun1, $this->businessDate);

        // Second run same date
        $auditRun2 = NightAuditRun::create([
            'business_date' => $this->businessDate->copy()->addDay()->toDateString(),
            'status'        => 'PENDING',
        ]);
        $pipeline2 = app(NightAuditPipeline::class);
        $pipeline2->register(app(RoomChargePostingJob::class));
        $pipeline2->register(app(BreakfastPostingJob::class));
        $pipeline2->run($auditRun2, $this->businessDate); // same business date

        // Still only one breakfast entry
        $this->assertDatabaseCount('folio_entries', 2); // 1 room + 1 breakfast
        $this->assertDatabaseHas('night_audit_booking_logs', [
            'job_class' => BreakfastPostingJob::class,
            'result'    => 'ALREADY_POSTED',
        ]);
    }

    public function test_night_audit_service_registers_breakfast_job(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollBreakfast($booking);
        $this->makeBreakfastRate();

        $service = app(NightAuditService::class);
        $run     = $service->runForDate($this->businessDate);

        $this->assertEquals('COMPLETED', $run->status);

        $this->assertDatabaseHas('night_audit_booking_logs', [
            'job_class' => BreakfastPostingJob::class,
            'result'    => 'POSTED',
        ]);
    }

    public function test_night_audit_service_posts_both_room_and_breakfast_in_order(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollBreakfast($booking);
        $this->makeBreakfastRate();

        app(NightAuditService::class)->runForDate($this->businessDate);

        $folio = $booking->folio;
        $this->assertEquals(2, $folio->folioEntries()->count());

        $entries = $folio->folioEntries()->orderBy('id')->get();
        $this->assertEquals(ChargeType::Room->value, $entries[0]->charge_type->value);
        $this->assertEquals(ChargeType::FoodBeverage->value, $entries[1]->charge_type->value);
    }
}
