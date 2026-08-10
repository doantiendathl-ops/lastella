<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\NightAuditRun;
use App\Models\ServiceRate;
use App\Models\Stay;
use App\Models\User;
use App\Services\BusinessDateService;
use App\Services\NightAuditService;
use App\Services\Posting\ExtraBedPostingJob;
use App\Services\Posting\PostingContext;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Room-Scoped Bed Operations Correction — extra-bed quantity now lives on
 * room_assignments.extra_bed_quantity (per room), not on the booking-level
 * BookingPackageFlag. See ExtraBedPostingJob's class docblock for the exact
 * over-posting bug this replaces.
 */
class ExtraBedPostingJobTest extends TestCase
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

    private function makeExtraBedRate(string $unitPrice = '150000.00'): ServiceRate
    {
        return ServiceRate::create([
            'name'           => 'Giường phụ / đêm',
            'charge_type'    => ChargeType::ExtraBed->value,
            'unit_price'     => $unitPrice,
            'effective_from' => '2026-01-01',
            'unit_label'     => 'giường',
            'tax_rate'       => '0.0000',
            'is_active'      => true,
            'display_order'  => 92,
            'created_by'     => null,
        ]);
    }

    /** Room-scoped enrollment: sets the quantity directly on THIS stay's RoomAssignment. */
    private function enrollExtraBedForRoom(Stay $stay, int $quantity = 1): void
    {
        $stay->roomAssignment->update(['extra_bed_quantity' => $quantity]);
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

    public function test_should_process_returns_false_when_quantity_is_zero(): void
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

        $this->assertFalse(app(ExtraBedPostingJob::class)->shouldProcess($context));
    }

    public function test_should_process_returns_false_when_folio_not_open(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $folio->update(['status' => FolioStatus::Closed]);
        $folio->refresh();

        $this->enrollExtraBedForRoom($stay);

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $this->assertFalse(app(ExtraBedPostingJob::class)->shouldProcess($context));
    }

    public function test_should_process_returns_true_when_room_quantity_positive_and_folio_open(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;

        $this->enrollExtraBedForRoom($stay);

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $this->assertTrue(app(ExtraBedPostingJob::class)->shouldProcess($context));
    }

    // -------------------------------------------------------------------------
    // execute
    // -------------------------------------------------------------------------

    public function test_execute_skips_when_no_active_rate(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollExtraBedForRoom($stay);

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $result = app(ExtraBedPostingJob::class)->execute($context);

        $this->assertTrue($result->success);
        $this->assertNull($result->entry);
        $this->assertStringContainsString('EXTRA_BED', $result->message);
        $this->assertDatabaseCount('folio_entries', 0);
    }

    public function test_execute_posts_entry_with_correct_fields_and_posting_key(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollExtraBedForRoom($stay, 1);
        $this->makeExtraBedRate('150000.00');

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $result = app(ExtraBedPostingJob::class)->execute($context);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->entry);

        $expectedKey = "EXTRA_BED_{$stay->id}_" . $this->businessDate->toDateString();

        $this->assertDatabaseHas('folio_entries', [
            'folio_id'       => $folio->id,
            'stay_id'        => $stay->id,
            'posting_source' => 'NIGHT_AUDIT',
            'charge_type'    => ChargeType::ExtraBed->value,
            'posting_key'    => $expectedKey,
            'quantity'       => '1.00',
            'unit_price'     => '150000.00',
            'amount'         => '150000.00',
        ]);
    }

    public function test_execute_amount_multiplied_by_room_quantity(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollExtraBedForRoom($stay, 2);
        $this->makeExtraBedRate('150000.00');

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        app(ExtraBedPostingJob::class)->execute($context);

        $this->assertDatabaseHas('folio_entries', [
            'folio_id'   => $folio->id,
            'quantity'   => '2.00',
            'unit_price' => '150000.00',
            'amount'     => '300000.00',
        ]);
    }

    public function test_execute_is_idempotent_on_retry(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->enrollExtraBedForRoom($stay);
        $this->makeExtraBedRate();

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $job = app(ExtraBedPostingJob::class);
        $job->execute($context);

        $result2 = $job->execute($context);
        $this->assertTrue($result2->alreadyPosted);
        $this->assertDatabaseCount('folio_entries', 1);
    }

    public function test_execute_skips_when_room_quantity_zero_at_execute_time(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        // extra_bed_quantity left at default 0
        $this->makeExtraBedRate();

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $result = app(ExtraBedPostingJob::class)->execute($context);

        $this->assertTrue($result->success);
        $this->assertNull($result->entry);
        $this->assertDatabaseCount('folio_entries', 0);
    }

    public function test_night_audit_service_registers_extra_bed_job(): void
    {
        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->enrollExtraBedForRoom($stay, 1);
        $this->makeExtraBedRate();

        $run = app(NightAuditService::class)->runForDate($this->businessDate);

        $this->assertEquals('COMPLETED', $run->status);

        $this->assertDatabaseHas('night_audit_booking_logs', [
            'job_class' => ExtraBedPostingJob::class,
            'result'    => 'POSTED',
        ]);
    }

    /**
     * Root-cause regression proof (Mục IX/XII/XXXV): a booking with 3 rooms,
     * only ONE of which has extra beds enrolled, must post EXACTLY ONE
     * extra-bed FolioEntry — never one per room. This is exactly the bug the
     * old booking-level BookingPackageFlag lookup caused (every stay of the
     * booking independently matched the same flag).
     */
    public function test_multi_room_booking_posts_extra_bed_only_for_the_enrolled_room(): void
    {
        $stayA = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stayA->booking_id);
        $folio = $booking->folio;

        // Two more stays/rooms on the SAME booking, sharing the same folio.
        $stayB = Stay::factory()->create(['status' => StayStatus::CheckedIn, 'booking_id' => $booking->id]);
        $stayC = Stay::factory()->create(['status' => StayStatus::CheckedIn, 'booking_id' => $booking->id]);

        $this->enrollExtraBedForRoom($stayB, 1); // only room B has an extra bed
        $this->makeExtraBedRate('150000.00');

        foreach ([$stayA, $stayB, $stayC] as $stay) {
            $context = new PostingContext(
                booking:      $booking,
                folio:        $folio,
                businessDate: $this->businessDate,
                stay:         $stay,
            );
            app(ExtraBedPostingJob::class)->execute($context);
        }

        $this->assertDatabaseCount('folio_entries', 1);
        $this->assertDatabaseHas('folio_entries', [
            'folio_id' => $folio->id,
            'stay_id'  => $stayB->id,
            'charge_type' => ChargeType::ExtraBed->value,
            'amount' => '150000.00',
        ]);
    }

    /**
     * Pre-Commit Critical Safety Closure Mục XVII quick audit: the exact
     * A=0/B=1/C=2 scenario — A posts nothing, B posts rate×1, C posts rate×2,
     * scoped correctly per room_assignments.extra_bed_quantity.
     */
    public function test_multi_room_a_zero_b_one_c_two_posts_exact_per_room_amounts(): void
    {
        $stayA = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stayA->booking_id);
        $folio = $booking->folio;

        $stayB = Stay::factory()->create(['status' => StayStatus::CheckedIn, 'booking_id' => $booking->id]);
        $stayC = Stay::factory()->create(['status' => StayStatus::CheckedIn, 'booking_id' => $booking->id]);

        $this->enrollExtraBedForRoom($stayA, 0);
        $this->enrollExtraBedForRoom($stayB, 1);
        $this->enrollExtraBedForRoom($stayC, 2);
        $this->makeExtraBedRate('150000.00');

        $results = [];
        foreach (['A' => $stayA, 'B' => $stayB, 'C' => $stayC] as $label => $stay) {
            $context = new PostingContext(
                booking:      $booking,
                folio:        $folio,
                businessDate: $this->businessDate,
                stay:         $stay,
            );
            $results[$label] = app(ExtraBedPostingJob::class)->execute($context);
        }

        $this->assertNull($results['A']->entry, 'A (qty 0) must post nothing.');
        $this->assertNotNull($results['B']->entry);
        $this->assertNotNull($results['C']->entry);

        $this->assertDatabaseCount('folio_entries', 2);
        $this->assertDatabaseHas('folio_entries', [
            'stay_id' => $stayB->id,
            'charge_type' => ChargeType::ExtraBed->value,
            'quantity' => '1.00',
            'amount' => '150000.00', // rate × 1
        ]);
        $this->assertDatabaseHas('folio_entries', [
            'stay_id' => $stayC->id,
            'charge_type' => ChargeType::ExtraBed->value,
            'quantity' => '2.00',
            'amount' => '300000.00', // rate × 2
        ]);
    }

    /**
     * Pre-Commit Critical Safety Closure Mục XVII: single posting owner —
     * ServicePackagePostingJob must NOT also post an Extra Bed charge for a
     * room enrolled via the per-room EXTRA_BED_PER_NIGHT package. Only
     * ExtraBedPostingJob (via room_assignments.extra_bed_quantity, driven by
     * the SAME enrollment) may ever create a ChargeType::ExtraBed entry.
     */
    public function test_service_package_posting_job_does_not_also_post_extra_bed(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio = $booking->folio;

        $this->enrollExtraBedForRoom($stay, 1);
        $this->makeExtraBedRate('150000.00');

        // Also enroll the SAME booking's legacy EXTRA_BED_PER_NIGHT
        // BookingPackageFlag row (the pre-fix booking-level source) to prove
        // ServicePackagePostingJob's own exclusion list — not merely the
        // absence of a package — is what prevents a double post.
        \App\Models\BookingPackageFlag::create([
            'booking_id' => $booking->id,
            'package_key' => \App\Services\PackageEnrollmentService::EXTRA_BED_PER_NIGHT,
            'created_by' => $this->admin->id,
        ]);

        $context = new PostingContext(booking: $booking, folio: $folio, businessDate: $this->businessDate, stay: $stay);

        app(ExtraBedPostingJob::class)->execute($context);
        app(\App\Services\Posting\ServicePackagePostingJob::class)->execute($context);

        $this->assertSame(
            1,
            \App\Models\FolioEntry::where('folio_id', $folio->id)->where('charge_type', ChargeType::ExtraBed->value)->count(),
            'Exactly one ExtraBed entry — ServicePackagePostingJob must not create a second one.',
        );
    }
}
