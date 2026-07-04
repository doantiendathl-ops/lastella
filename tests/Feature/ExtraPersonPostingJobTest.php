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
use App\Services\NightAuditService;
use App\Services\PackageEnrollmentService;
use App\Services\Posting\ExtraPersonPostingJob;
use App\Services\Posting\PostingContext;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtraPersonPostingJobTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin        = User::factory()->create();
        $this->businessDate = Carbon::parse('2026-07-04');

        $this->instance(BusinessDateService::class, $this->mockBusinessDate($this->businessDate));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function makeExtraPersonRate(string $unitPrice = '200000.00'): ServiceRate
    {
        return ServiceRate::create([
            'name'           => 'Người thêm / đêm',
            'charge_type'    => ChargeType::ExtraPerson->value,
            'unit_price'     => $unitPrice,
            'effective_from' => '2026-01-01',
            'unit_label'     => 'người',
            'tax_rate'       => '0.0000',
            'is_active'      => true,
            'display_order'  => 91,
            'created_by'     => null,
        ]);
    }

    private function enrollExtraPerson(Booking $booking, int $quantity = 1): void
    {
        BookingPackageFlag::create([
            'booking_id'  => $booking->id,
            'package_key' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
            'value'       => (string) $quantity,
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
    // shouldProcess
    // -------------------------------------------------------------------------

    public function test_should_process_returns_false_when_not_enrolled(): void
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

        $this->assertFalse(app(ExtraPersonPostingJob::class)->shouldProcess($context));
    }

    public function test_should_process_returns_false_when_folio_not_open(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $folio->update(['status' => FolioStatus::Closed]);
        $folio->refresh();

        $this->enrollExtraPerson($booking);

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $this->assertFalse(app(ExtraPersonPostingJob::class)->shouldProcess($context));
    }

    public function test_should_process_returns_true_when_enrolled_and_folio_open(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;

        $this->enrollExtraPerson($booking);

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $this->assertTrue(app(ExtraPersonPostingJob::class)->shouldProcess($context));
    }

    // -------------------------------------------------------------------------
    // execute
    // -------------------------------------------------------------------------

    public function test_execute_skips_when_no_active_rate(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollExtraPerson($booking);

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $result = app(ExtraPersonPostingJob::class)->execute($context);

        $this->assertTrue($result->success);
        $this->assertNull($result->entry);
        $this->assertStringContainsString('EXTRA_PERSON', $result->message);
        $this->assertDatabaseCount('folio_entries', 0);
    }

    public function test_execute_posts_entry_with_correct_fields_and_posting_key(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollExtraPerson($booking, 1);
        $this->makeExtraPersonRate('200000.00');

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $result = app(ExtraPersonPostingJob::class)->execute($context);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->entry);

        $expectedKey = "EXTRA_PERSON_{$stay->id}_" . $this->businessDate->toDateString();

        $this->assertDatabaseHas('folio_entries', [
            'folio_id'       => $folio->id,
            'stay_id'        => $stay->id,
            'posting_source' => 'NIGHT_AUDIT',
            'charge_type'    => ChargeType::ExtraPerson->value,
            'posting_key'    => $expectedKey,
            'quantity'       => '1.00',
            'unit_price'     => '200000.00',
            'amount'         => '200000.00',
        ]);
    }

    public function test_execute_amount_multiplied_by_quantity_from_flag(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollExtraPerson($booking, 2);
        $this->makeExtraPersonRate('200000.00');

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        app(ExtraPersonPostingJob::class)->execute($context);

        $this->assertDatabaseHas('folio_entries', [
            'folio_id'   => $folio->id,
            'quantity'   => '2.00',
            'unit_price' => '200000.00',
            'amount'     => '400000.00',
        ]);
    }

    public function test_execute_is_idempotent_on_retry(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollExtraPerson($booking);
        $this->makeExtraPersonRate();

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $job = app(ExtraPersonPostingJob::class);
        $job->execute($context);

        $result2 = $job->execute($context);
        $this->assertTrue($result2->alreadyPosted);
        $this->assertDatabaseCount('folio_entries', 1);
    }

    public function test_execute_skips_when_not_enrolled_at_execute_time(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        // No flag created
        $this->makeExtraPersonRate();

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $result = app(ExtraPersonPostingJob::class)->execute($context);

        $this->assertTrue($result->success);
        $this->assertNull($result->entry);
        $this->assertDatabaseCount('folio_entries', 0);
    }

    public function test_night_audit_service_registers_extra_person_job(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollExtraPerson($booking, 1);
        $this->makeExtraPersonRate();

        $run = app(NightAuditService::class)->runForDate($this->businessDate);

        $this->assertEquals('COMPLETED', $run->status);

        $this->assertDatabaseHas('night_audit_booking_logs', [
            'job_class' => ExtraPersonPostingJob::class,
            'result'    => 'POSTED',
        ]);
    }
}
