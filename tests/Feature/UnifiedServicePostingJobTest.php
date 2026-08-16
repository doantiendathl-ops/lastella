<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FolioStatus;
use App\Enums\ServiceBillingMode;
use App\Enums\ServiceFulfillmentStatus;
use App\Enums\ServiceScope;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\BookingService;
use App\Models\Folio;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Stay;
use App\Models\User;
use App\Services\Posting\PostingContext;
use App\Services\Posting\UnifiedServicePostingJob;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 16/26) — the
 * critical financial-safety coverage for the new PER_NIGHT/ONE_TIME
 * posting path: idempotency, multi-room no-cross-charge (the exact class
 * of bug this whole slice exists to prevent), and retry safety.
 */
class UnifiedServicePostingJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function makeCheckedInStayWithFolio(): Stay
    {
        $stay = Stay::factory()->create(['status' => StayStatus::CheckedIn]);
        $booking = Booking::find($stay->booking_id);
        Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);

        $stay->loadMissing('room');
        if ($stay->room !== null) {
            BookingRequirement::factory()->create([
                'booking_id' => $booking->id,
                'room_type_id' => $stay->room->room_type_id,
                'room_price' => 500000,
            ]);
        }

        return $stay;
    }

    private function makeService(array $overrides = []): Service
    {
        $category = ServiceCategory::create(['code' => 'CAT_'.uniqid(), 'name' => 'Test', 'is_active' => true]);

        return Service::create(array_merge([
            'category_id' => $category->id,
            'code' => 'SVC_'.uniqid(),
            'name' => 'Giường phụ (test)',
            'is_chargeable' => true,
            'scope' => ServiceScope::Room->value,
            'billing_mode' => ServiceBillingMode::PerNight->value,
            'quantity_enabled' => true,
            'default_quantity' => 1,
            'unit_label' => 'giường',
            'fulfillment_required' => false,
            'is_active' => true,
            'is_bookable' => true,
        ], $overrides));
    }

    private function enrollRoomScoped(Stay $stay, Service $service, int $quantity = 1, string $unitPrice = '150000.00'): BookingService
    {
        return BookingService::create([
            'booking_id' => $stay->booking_id,
            'service_id' => $service->id,
            'room_assignment_id' => $stay->room_assignment_id,
            'quantity' => $quantity,
            'billing_mode_selected' => ServiceBillingMode::PerNight->value,
            'suggested_price' => $unitPrice,
            'actual_price' => $unitPrice,
            'fulfillment_status' => ServiceFulfillmentStatus::Created->value,
            'created_by' => $this->user->id,
        ]);
    }

    private function enrollBookingScoped(Booking $booking, Service $service, int $quantity = 1, string $unitPrice = '50000.00'): BookingService
    {
        return BookingService::create([
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'room_assignment_id' => null,
            'quantity' => $quantity,
            'billing_mode_selected' => ServiceBillingMode::PerNight->value,
            'suggested_price' => $unitPrice,
            'actual_price' => $unitPrice,
            'fulfillment_status' => ServiceFulfillmentStatus::Created->value,
            'created_by' => $this->user->id,
        ]);
    }

    private function context(Booking $booking, Folio $folio, Stay $stay, Carbon $date): PostingContext
    {
        return new PostingContext(booking: $booking, folio: $folio, businessDate: $date, stay: $stay);
    }

    // -------------------------------------------------------------------------
    // Basic PER_NIGHT posting
    // -------------------------------------------------------------------------

    public function test_posts_one_room_one_night(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $service = $this->makeService();
        $this->enrollRoomScoped($stay, $service, 1, '150000.00');

        $date = Carbon::parse('2026-08-16');
        $result = app(UnifiedServicePostingJob::class)->execute($this->context($booking, $booking->folio, $stay, $date));

        $this->assertTrue($result->success);
        $this->assertNotNull($result->entry);
        $this->assertDatabaseCount('folio_entries', 1);
        $this->assertDatabaseHas('folio_entries', [
            'folio_id' => $booking->folio->id,
            'stay_id' => $stay->id,
            'amount' => 150000,
        ]);
        $this->assertSame('2026-08-16', $result->entry->entry_date->toDateString());
    }

    public function test_quantity_multiplies_the_amount(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $service = $this->makeService();
        $this->enrollRoomScoped($stay, $service, 2, '150000.00');

        $date = Carbon::parse('2026-08-16');
        app(UnifiedServicePostingJob::class)->execute($this->context($booking, $booking->folio, $stay, $date));

        $this->assertDatabaseHas('folio_entries', ['amount' => '300000.00']);
    }

    public function test_one_room_multiple_nights_posts_a_separate_charge_per_night(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $service = $this->makeService();
        $this->enrollRoomScoped($stay, $service, 1, '150000.00');

        $job = app(UnifiedServicePostingJob::class);
        $job->execute($this->context($booking, $booking->folio, $stay, Carbon::parse('2026-08-16')));
        $job->execute($this->context($booking, $booking->folio, $stay, Carbon::parse('2026-08-17')));
        $job->execute($this->context($booking, $booking->folio, $stay, Carbon::parse('2026-08-18')));

        $this->assertDatabaseCount('folio_entries', 3);
        $this->assertSame(450000.0, (float) \App\Models\FolioEntry::sum('amount'));
    }

    // -------------------------------------------------------------------------
    // Idempotency / retry safety (Section 16/26 — the core financial guarantee)
    // -------------------------------------------------------------------------

    public function test_retry_for_the_same_stay_and_date_never_duplicates(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $service = $this->makeService();
        $this->enrollRoomScoped($stay, $service, 1, '150000.00');

        $date = Carbon::parse('2026-08-16');
        $job = app(UnifiedServicePostingJob::class);

        $first = $job->execute($this->context($booking, $booking->folio, $stay, $date));
        $second = $job->execute($this->context($booking, $booking->folio, $stay, $date));

        $this->assertTrue($first->success && ! $first->alreadyPosted);
        $this->assertTrue($second->alreadyPosted);
        $this->assertDatabaseCount('folio_entries', 1);
    }

    public function test_is_already_posted_reports_true_only_after_posting(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $service = $this->makeService();
        $this->enrollRoomScoped($stay, $service, 1, '150000.00');

        $date = Carbon::parse('2026-08-16');
        $job = app(UnifiedServicePostingJob::class);
        $context = $this->context($booking, $booking->folio, $stay, $date);

        $this->assertFalse($job->isAlreadyPosted($context));
        $job->execute($context);
        $this->assertTrue($job->isAlreadyPosted($context));
    }

    // -------------------------------------------------------------------------
    // Multi-room no-cross-charge — the exact bug class this slice fixes
    // -------------------------------------------------------------------------

    public function test_multi_room_booking_posts_only_for_the_enrolled_room(): void
    {
        $stayA = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stayA->booking_id);
        $folio = $booking->folio;
        $stayB = Stay::factory()->create(['status' => StayStatus::CheckedIn, 'booking_id' => $booking->id]);
        $stayC = Stay::factory()->create(['status' => StayStatus::CheckedIn, 'booking_id' => $booking->id]);

        $service = $this->makeService();
        $this->enrollRoomScoped($stayB, $service, 1, '150000.00'); // only room B

        $date = Carbon::parse('2026-08-16');
        $job = app(UnifiedServicePostingJob::class);
        foreach ([$stayA, $stayB, $stayC] as $stay) {
            $job->execute($this->context($booking, $folio, $stay, $date));
        }

        $this->assertDatabaseCount('folio_entries', 1);
        $this->assertDatabaseHas('folio_entries', ['stay_id' => $stayB->id, 'amount' => '150000.00']);
    }

    public function test_three_rooms_different_quantities_post_exact_per_room_amounts(): void
    {
        $stayA = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stayA->booking_id);
        $folio = $booking->folio;
        $stayB = Stay::factory()->create(['status' => StayStatus::CheckedIn, 'booking_id' => $booking->id]);
        $stayC = Stay::factory()->create(['status' => StayStatus::CheckedIn, 'booking_id' => $booking->id]);

        $service = $this->makeService();
        // A: no enrollment, B: 1, C: 2
        $this->enrollRoomScoped($stayB, $service, 1, '150000.00');
        $this->enrollRoomScoped($stayC, $service, 2, '150000.00');

        $date = Carbon::parse('2026-08-16');
        $job = app(UnifiedServicePostingJob::class);
        foreach (['A' => $stayA, 'B' => $stayB, 'C' => $stayC] as $stay) {
            $job->execute($this->context($booking, $folio, $stay, $date));
        }

        $this->assertDatabaseCount('folio_entries', 2);
        $this->assertDatabaseHas('folio_entries', ['stay_id' => $stayB->id, 'amount' => '150000.00']);
        $this->assertDatabaseHas('folio_entries', ['stay_id' => $stayC->id, 'amount' => '300000.00']);
        $this->assertDatabaseMissing('folio_entries', ['stay_id' => $stayA->id]);
    }

    // -------------------------------------------------------------------------
    // BOOKING scope applies to every stay
    // -------------------------------------------------------------------------

    /**
     * Regression test for a CRITICAL bug caught in security review: Night
     * Audit builds one PostingContext PER ACTIVE STAY and calls execute()
     * once per stay. A BOOKING-scoped PER_NIGHT service is eligible for
     * every stay of the booking by design — but the charge must still be
     * posted exactly ONCE per night for the whole booking, never once PER
     * STAY (that would silently multiply a "resort fee"-style charge by
     * the room count every night — the same overcharge bug class Extra Bed
     * had, just for BOOKING scope instead of ROOM scope).
     */
    public function test_booking_scoped_service_posts_exactly_once_per_night_regardless_of_room_count(): void
    {
        $stayA = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stayA->booking_id);
        $folio = $booking->folio;
        $stayB = Stay::factory()->create(['status' => StayStatus::CheckedIn, 'booking_id' => $booking->id]);
        $stayC = Stay::factory()->create(['status' => StayStatus::CheckedIn, 'booking_id' => $booking->id]);

        $service = $this->makeService(['scope' => ServiceScope::Booking->value]);
        $this->enrollBookingScoped($booking, $service, 1, '50000.00');

        $date = Carbon::parse('2026-08-16');
        $job = app(UnifiedServicePostingJob::class);
        // Night Audit calls execute() once per active stay of the booking — simulate all 3.
        $job->execute($this->context($booking, $folio, $stayA, $date));
        $job->execute($this->context($booking, $folio, $stayB, $date));
        $job->execute($this->context($booking, $folio, $stayC, $date));

        // A BOOKING-scoped PER_NIGHT charge must post exactly once per night, never once per room/stay.
        $this->assertDatabaseCount('folio_entries', 1);
        $this->assertDatabaseHas('folio_entries', ['amount' => 50000]);
    }

    public function test_booking_scoped_service_posts_a_new_charge_on_a_different_night(): void
    {
        $stayA = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stayA->booking_id);
        $folio = $booking->folio;
        $stayB = Stay::factory()->create(['status' => StayStatus::CheckedIn, 'booking_id' => $booking->id]);

        $service = $this->makeService(['scope' => ServiceScope::Booking->value]);
        $this->enrollBookingScoped($booking, $service, 1, '50000.00');

        $job = app(UnifiedServicePostingJob::class);
        foreach ([$stayA, $stayB] as $stay) {
            $job->execute($this->context($booking, $folio, $stay, Carbon::parse('2026-08-16')));
        }
        foreach ([$stayA, $stayB] as $stay) {
            $job->execute($this->context($booking, $folio, $stay, Carbon::parse('2026-08-17')));
        }

        // One charge per night, still never one per room.
        $this->assertDatabaseCount('folio_entries', 2);
    }

    // -------------------------------------------------------------------------
    // Cancelled enrollment stops billing (Section 11 — fulfillment ≠ billing,
    // but Cancelled is the one status that DOES stop billing)
    // -------------------------------------------------------------------------

    public function test_cancelled_enrollment_never_posts(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $service = $this->makeService();
        $bs = $this->enrollRoomScoped($stay, $service, 1, '150000.00');
        $bs->update(['fulfillment_status' => ServiceFulfillmentStatus::Cancelled->value]);

        $date = Carbon::parse('2026-08-16');
        $result = app(UnifiedServicePostingJob::class)->execute($this->context($booking, $booking->folio, $stay, $date));

        $this->assertFalse($result->success && ! $result->alreadyPosted && $result->entry !== null);
        $this->assertDatabaseCount('folio_entries', 0);
    }

    public function test_completed_fulfillment_status_does_not_stop_per_night_billing(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $service = $this->makeService();
        $bs = $this->enrollRoomScoped($stay, $service, 1, '150000.00');
        $bs->update(['fulfillment_status' => ServiceFulfillmentStatus::Completed->value]);

        $date = Carbon::parse('2026-08-16');
        app(UnifiedServicePostingJob::class)->execute($this->context($booking, $booking->folio, $stay, $date));

        $this->assertDatabaseCount('folio_entries', 1);
    }

    // -------------------------------------------------------------------------
    // ONE_TIME billing (Section 15)
    // -------------------------------------------------------------------------

    public function test_one_time_posts_exactly_once_and_never_via_night_audit(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $service = $this->makeService(['scope' => ServiceScope::Booking->value, 'billing_mode' => ServiceBillingMode::OneTime->value, 'quantity_enabled' => false]);

        $bs = BookingService::create([
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'billing_mode_selected' => ServiceBillingMode::OneTime->value,
            'suggested_price' => '80000.00',
            'actual_price' => '80000.00',
            'fulfillment_status' => ServiceFulfillmentStatus::Created->value,
            'created_by' => $this->user->id,
        ]);

        $job = app(UnifiedServicePostingJob::class);
        $entry = $job->postOneTime($bs, $this->user);

        $this->assertNotNull($entry);
        $this->assertDatabaseCount('folio_entries', 1);

        // Night Audit must never pick up a ONE_TIME row.
        $result = $job->execute($this->context($booking, $booking->folio, $stay, Carbon::parse('2026-08-16')));
        $this->assertDatabaseCount('folio_entries', 1);
        $this->assertFalse($result->success && $result->entry !== null && ! $result->alreadyPosted);
    }

    public function test_one_time_posting_called_twice_never_duplicates(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $service = $this->makeService(['scope' => ServiceScope::Booking->value, 'billing_mode' => ServiceBillingMode::OneTime->value]);

        $bs = BookingService::create([
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'billing_mode_selected' => ServiceBillingMode::OneTime->value,
            'suggested_price' => '80000.00',
            'actual_price' => '80000.00',
            'fulfillment_status' => ServiceFulfillmentStatus::Created->value,
            'created_by' => $this->user->id,
        ]);

        $job = app(UnifiedServicePostingJob::class);
        $job->postOneTime($bs, $this->user);
        $second = $job->postOneTime($bs, $this->user);

        $this->assertNull($second);
        $this->assertDatabaseCount('folio_entries', 1);
    }

    public function test_free_service_never_posts_a_charge(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $service = $this->makeService(['scope' => ServiceScope::Booking->value, 'billing_mode' => ServiceBillingMode::OneTime->value, 'is_chargeable' => false]);

        $bs = BookingService::create([
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'billing_mode_selected' => ServiceBillingMode::OneTime->value,
            'suggested_price' => '0.00',
            'actual_price' => '0.00',
            'fulfillment_status' => ServiceFulfillmentStatus::Created->value,
            'created_by' => $this->user->id,
        ]);

        $entry = app(UnifiedServicePostingJob::class)->postOneTime($bs, $this->user);

        $this->assertNull($entry);
        $this->assertDatabaseCount('folio_entries', 0);
    }

    // -------------------------------------------------------------------------
    // rollback() scoping (code review, 2026-08-16) — must never delete another
    // room's legitimate entry when rolling back one stay's PostingContext.
    // -------------------------------------------------------------------------

    public function test_rollback_only_removes_the_entry_for_its_own_stay(): void
    {
        $stayA = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stayA->booking_id);
        $folio = $booking->folio;
        $stayB = Stay::factory()->create(['status' => StayStatus::CheckedIn, 'booking_id' => $booking->id]);

        $service = $this->makeService();
        $this->enrollRoomScoped($stayA, $service, 1, '150000.00');
        $this->enrollRoomScoped($stayB, $service, 1, '150000.00');

        $date = Carbon::parse('2026-08-16');
        $job = app(UnifiedServicePostingJob::class);
        $job->execute($this->context($booking, $folio, $stayA, $date));
        $job->execute($this->context($booking, $folio, $stayB, $date));
        $this->assertDatabaseCount('folio_entries', 2);

        $job->rollback($this->context($booking, $folio, $stayA, $date));

        // Rolling back stay A must never delete stay B's legitimate entry.
        $this->assertDatabaseCount('folio_entries', 1);
        $this->assertDatabaseHas('folio_entries', ['stay_id' => $stayB->id]);
        $this->assertDatabaseMissing('folio_entries', ['stay_id' => $stayA->id]);
    }
}
