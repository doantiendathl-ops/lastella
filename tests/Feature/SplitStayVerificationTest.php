<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ChargeType;
use App\Enums\CustomerType;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethod;
use App\Enums\StayEventType;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\FolioEntry;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use App\Services\NightAuditService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Carbon\Carbon;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4.3A Milestone 4: Split Stay Verification.
 *
 * Test-only milestone — proves (does not build) that one Booking already supports
 * multiple independent per-room Stay timelines, with one room extending while others
 * check out on schedule, with no child Booking and no cross-Stay interference.
 */
class SplitStayVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private RoomType $twinType;
    private const ROOM_PRICE = 800000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
            RoomSeeder::class,
        ]);

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_full_split_stay_scenario_three_rooms_one_extended(): void
    {
        // ── Setup: one Booking, 3 rooms, all 1 night ────────────────────────────
        $this->travelTo('2026-07-12 14:00:00');

        $rooms = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(3)->get();
        $booking = $this->createBooking();

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $rooms[0]->id, 'room_type_id' => $rooms[0]->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
            ['room_id' => $rooms[1]->id, 'room_type_id' => $rooms[1]->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
            ['room_id' => $rooms[2]->id, 'room_type_id' => $rooms[2]->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);
        [$assignmentA, $assignmentB, $assignmentC] = $assignments;

        // (1)(3) Exactly one Booking, exactly three independent RoomAssignments.
        $this->assertSame(1, Booking::count());
        $this->assertSame(3, RoomAssignment::where('booking_id', $booking->id)->count());

        $stayA = app(StayService::class)->createStayFromAssignment($assignmentA);
        $stayB = app(StayService::class)->createStayFromAssignment($assignmentB);
        $stayC = app(StayService::class)->createStayFromAssignment($assignmentC);

        // (4) Exactly three independent Stays.
        $this->assertSame(3, Stay::where('booking_id', $booking->id)->count());

        // ── Check in all 3 rooms ─────────────────────────────────────────────────
        app(StayService::class)->checkIn($stayA);
        app(StayService::class)->checkIn($stayB);
        app(StayService::class)->checkIn($stayC);

        $this->assertSame(BookingStatus::CheckedIn, $booking->fresh()->status);

        // Full deposit covering the eventual grand total (A:1 night + B:1 night + C:2 nights).
        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => self::ROOM_PRICE + self::ROOM_PRICE + (self::ROOM_PRICE * 2),
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        // ── Extend Room C by 1 additional night ─────────────────────────────────
        $stayASnapshotBeforeExtend = $stayA->refresh()->getAttributes();
        $stayBSnapshotBeforeExtend = $stayB->refresh()->getAttributes();
        $assignmentASnapshotBeforeExtend = $assignmentA->refresh()->getAttributes();
        $assignmentBSnapshotBeforeExtend = $assignmentB->refresh()->getAttributes();

        app(StayService::class)->extendStay($stayC, '2026-07-14 12:00:00', $this->admin);

        // (5) Extending Room C changes only its own Stay/RoomAssignment.
        $this->assertSame('2026-07-14 12:00:00', $stayC->refresh()->planned_checkout_at->toDateTimeString());
        $this->assertSame('2026-07-14 12:00:00', $assignmentC->refresh()->end_at->toDateTimeString());

        // (6)(7) Room A and Room B are completely unaffected by Room C's extension.
        $this->assertSame($stayASnapshotBeforeExtend, $stayA->refresh()->getAttributes());
        $this->assertSame($stayBSnapshotBeforeExtend, $stayB->refresh()->getAttributes());
        $this->assertSame($assignmentASnapshotBeforeExtend, $assignmentA->refresh()->getAttributes());
        $this->assertSame($assignmentBSnapshotBeforeExtend, $assignmentB->refresh()->getAttributes());

        // ── Idempotency check: re-running Night Audit for the check-in night (already
        // posted directly by checkIn()) must not create any new entry for any stay. ──
        $folioEntryCountAfterCheckIn = FolioEntry::where('charge_type', ChargeType::Room->value)->count();
        $this->assertSame(3, $folioEntryCountAfterCheckIn);

        app(NightAuditService::class)->runForDate(Carbon::parse('2026-07-12'));

        $this->assertSame($folioEntryCountAfterCheckIn, FolioEntry::where('charge_type', ChargeType::Room->value)->count());

        // ── Check out Room A on schedule (partial — B and C still active) ───────
        $this->travelTo('2026-07-13 12:00:00');

        $stayBSnapshotBeforeACheckout = $stayB->refresh()->getAttributes();
        $stayCSnapshotBeforeACheckout = $stayC->refresh()->getAttributes();

        app(StayService::class)->checkOut($stayA);

        // (8) Checking out Room A does not change Room B or Room C.
        $this->assertSame($stayBSnapshotBeforeACheckout, $stayB->refresh()->getAttributes());
        $this->assertSame($stayCSnapshotBeforeACheckout, $stayC->refresh()->getAttributes());

        $this->assertSame(BookingStatus::PartiallyCheckedOut, $booking->fresh()->status);
        $this->assertSame(FolioStatus::Open, $booking->folio()->firstOrFail()->status);

        // ── Check out Room B on schedule (partial — C still active) ─────────────
        $stayCSnapshotBeforeBCheckout = $stayC->refresh()->getAttributes();

        app(StayService::class)->checkOut($stayB);

        // (9) Checking out Room B does not change Room C.
        $this->assertSame($stayCSnapshotBeforeBCheckout, $stayC->refresh()->getAttributes());

        // (10) Room C remains CheckedIn after A and B have both checked out.
        $this->assertSame(StayStatus::CheckedIn, $stayC->fresh()->status);
        $this->assertSame(BookingStatus::PartiallyCheckedOut, $booking->fresh()->status);
        $this->assertNotSame(BookingStatus::CheckedOut, $booking->fresh()->status);
        $this->assertSame(FolioStatus::Open, $booking->folio()->firstOrFail()->status);

        // ── Night Audit for the extended (second) night — only Room C is CheckedIn ──
        $this->travelTo('2026-07-13 22:00:00');

        app(NightAuditService::class)->runForDate(Carbon::parse('2026-07-13'));

        $folioEntryCountAfterSecondNightAudit = FolioEntry::where('charge_type', ChargeType::Room->value)->count();
        $this->assertSame(4, $folioEntryCountAfterSecondNightAudit);
        $this->assertDatabaseHas('folio_entries', [
            'stay_id' => $stayC->id,
            'charge_type' => ChargeType::Room->value,
        ]);

        // Idempotent re-run: running the same business date again posts nothing new.
        app(NightAuditService::class)->runForDate(Carbon::parse('2026-07-13'));
        $this->assertSame($folioEntryCountAfterSecondNightAudit, FolioEntry::where('charge_type', ChargeType::Room->value)->count());

        // ── Final checkout: Room C, the last active Stay ─────────────────────────
        $this->travelTo('2026-07-14 12:00:00');

        app(StayService::class)->checkOut($stayC, null, true);

        // (11) Final checkout of Room C finalizes the Booking.
        $this->assertSame(BookingStatus::CheckedOut, $booking->fresh()->status);
        $this->assertSame(FolioStatus::Closed, $booking->folio()->firstOrFail()->status);

        // ── No child Booking, no duplicates ──────────────────────────────────────
        // (1)(2) Exactly one Booking exists throughout; no child Booking was ever created.
        $this->assertSame(1, Booking::count());
        $this->assertSame(3, Stay::where('booking_id', $booking->id)->count());
        $this->assertSame(3, RoomAssignment::where('booking_id', $booking->id)->count());

        // ── Billing verification: no double-post, no under-post ─────────────────
        $roomChargesByStay = FolioEntry::where('folio_id', $booking->folio()->firstOrFail()->id)
            ->where('charge_type', ChargeType::Room->value)
            ->get()
            ->groupBy('stay_id');

        $this->assertCount(1, $roomChargesByStay[$stayA->id], 'Room A must be billed for exactly its 1 actual night.');
        $this->assertCount(1, $roomChargesByStay[$stayB->id], 'Room B must be billed for exactly its 1 actual night.');
        $this->assertCount(2, $roomChargesByStay[$stayC->id], 'Room C must be billed for exactly its 2 actual nights (1 original + 1 extended).');

        $totalA = $roomChargesByStay[$stayA->id]->sum('amount');
        $totalB = $roomChargesByStay[$stayB->id]->sum('amount');
        $totalC = $roomChargesByStay[$stayC->id]->sum('amount');

        $this->assertEquals(self::ROOM_PRICE, (float) $totalA);
        $this->assertEquals(self::ROOM_PRICE, (float) $totalB);
        $this->assertEquals(self::ROOM_PRICE * 2, (float) $totalC);
        $this->assertEquals(self::ROOM_PRICE + self::ROOM_PRICE + (self::ROOM_PRICE * 2), (float) ($totalA + $totalB + $totalC));

        // Posting keys are unique per stay+business-date — no double posting anywhere.
        $postingKeys = FolioEntry::where('folio_id', $booking->folio()->firstOrFail()->id)
            ->where('charge_type', ChargeType::Room->value)
            ->pluck('posting_key');
        $this->assertSame($postingKeys->count(), $postingKeys->unique()->count());

        // ── No new Payment row was created merely by extension/partial/final checkout ──
        $this->assertSame(1, $booking->bookingPayments()->count());

        // ── StayEvent timeline verification (occurred_at ASC, id ASC) ────────────
        $timelineA = $stayA->stayEvents()->orderBy('occurred_at')->orderBy('id')->pluck('event_type')->all();
        $timelineB = $stayB->stayEvents()->orderBy('occurred_at')->orderBy('id')->pluck('event_type')->all();
        $timelineC = $stayC->stayEvents()->orderBy('occurred_at')->orderBy('id')->pluck('event_type')->all();

        $this->assertSame([StayEventType::CheckIn, StayEventType::PartialCheckout], $timelineA);
        $this->assertSame([StayEventType::CheckIn, StayEventType::PartialCheckout], $timelineB);
        $this->assertSame([StayEventType::CheckIn, StayEventType::ExtendStay, StayEventType::Checkout], $timelineC);

        // Cross-check the metadata observed by each event against the actual decision point.
        $partialA = $stayA->stayEvents()->where('event_type', StayEventType::PartialCheckout)->firstOrFail();
        $partialB = $stayB->stayEvents()->where('event_type', StayEventType::PartialCheckout)->firstOrFail();
        $finalC   = $stayC->stayEvents()->where('event_type', StayEventType::Checkout)->firstOrFail();

        $this->assertSame(2, $partialA->metadata['remaining_active_stays']); // B, C still active
        $this->assertSame(1, $partialB->metadata['remaining_active_stays']); // C still active
        $this->assertSame(0, $finalC->metadata['remaining_active_stays']);   // none left
    }

    private function createBooking(array $overrides = []): Booking
    {
        $payload = array_merge([
            'booking_color' => '#196251',
            'customer_name' => 'Split Stay Guest',
            'customer_phone' => '0900000006',
            'customer_email' => 'split-stay@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-12 14:00:00',
            'checkout_at' => '2026-07-13 12:00:00',
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
            'requirements' => [
                [
                    'room_type_id' => $this->twinType->id,
                    'quantity' => 3,
                    'adults' => 2,
                    'children_under_6' => 0,
                    'children_over_6' => 0,
                    'room_price' => self::ROOM_PRICE,
                    'price_source' => 'MANUAL',
                ],
            ],
        ], $overrides);

        return app(BookingService::class)->createBooking($payload);
    }
}
