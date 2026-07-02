<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ChargeType;
use App\Enums\CustomerType;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethod;
use App\Enums\StayStatus;
use App\Exceptions\OutstandingBalanceException;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use App\Services\FolioService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3.1.1 Hotfix: Room charge quantity and checkout-all regression tests.
 *
 * Issue 1 — Room charge quantity missing nights multiplier:
 *   autoPostRoomCharge() was computing amount = room_price × rooms (no × nights).
 *   Fix: amount = room_price × rooms × nights (calendar-day difference).
 *
 * Issue 2 — "Trả tất cả phòng" partial checkout when balance > 0:
 *   checkOutAll in Vue bypassed the individual last-stay disabled guard, allowing
 *   N-1 stays to check out while the last stay failed with OBE.
 *   Fix: checkOutAll button disabled when balance_due > 0 (matches individual guard).
 */
class RoomChargeHotfixTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private RoomType $twinType;

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

    // ── Issue 1: Room charge quantity ─────────────────────────────────────────

    public function test_two_night_booking_posts_room_charge_quantity_of_two(): void
    {
        // Check-in: 2026-06-30, Check-out: 2026-07-02 → 2 nights
        $this->travelTo('2026-06-30 14:00:00');

        $booking = $this->createBooking([
            'checkin_at'  => '2026-06-30 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
            'requirements' => [
                [
                    'room_type_id'     => $this->twinType->id,
                    'quantity'         => 1,
                    'adults'           => 2,
                    'children_under_6' => 0,
                    'children_over_6'  => 0,
                    'room_price'       => 2100000,
                    'price_source'     => 'MANUAL',
                ],
            ],
        ]);

        $room       = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-06-30 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        $entry = $booking->folio->folioEntries()
            ->where('charge_type', ChargeType::Room->value)
            ->whereNull('voided_at')
            ->firstOrFail();

        $this->assertSame('2.00', $entry->quantity, 'quantity must equal number of nights');
        $this->assertEquals('2100000.00', $entry->unit_price, 'unit_price must be per-night total');
    }

    public function test_two_night_booking_room_charge_total_equals_nightly_price_times_nights(): void
    {
        $this->travelTo('2026-06-30 14:00:00');

        $booking = $this->createBooking([
            'checkin_at'  => '2026-06-30 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
            'requirements' => [
                [
                    'room_type_id'     => $this->twinType->id,
                    'quantity'         => 1,
                    'adults'           => 2,
                    'children_under_6' => 0,
                    'children_over_6'  => 0,
                    'room_price'       => 2100000, // per-night
                    'price_source'     => 'MANUAL',
                ],
            ],
        ]);

        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-06-30 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        $entry = $booking->folio->folioEntries()
            ->where('charge_type', ChargeType::Room->value)
            ->whereNull('voided_at')
            ->firstOrFail();

        // 2 nights × 2,100,000/night = 4,200,000
        $this->assertEquals('4200000.00', $entry->amount);
    }

    public function test_one_night_booking_still_posts_correct_single_night_charge(): void
    {
        // Ensures the nights-multiplier fix does not break 1-night bookings
        $this->travelTo('2026-07-01 14:00:00');

        $booking = $this->createBooking([
            'checkin_at'  => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
            'requirements' => [
                [
                    'room_type_id'     => $this->twinType->id,
                    'quantity'         => 1,
                    'adults'           => 2,
                    'children_under_6' => 0,
                    'children_over_6'  => 0,
                    'room_price'       => 800000,
                    'price_source'     => 'MANUAL',
                ],
            ],
        ]);

        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        $entry = $booking->folio->folioEntries()
            ->where('charge_type', ChargeType::Room->value)
            ->whereNull('voided_at')
            ->firstOrFail();

        $this->assertSame('1.00', $entry->quantity);
        $this->assertEquals('800000.00', $entry->unit_price);
        $this->assertEquals('800000.00', $entry->amount);
    }

    public function test_three_night_booking_posts_correct_quantity_and_amount(): void
    {
        $this->travelTo('2026-07-01 14:00:00');

        $booking = $this->createBooking([
            'checkin_at'  => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-04 12:00:00', // 3 nights
            'requirements' => [
                [
                    'room_type_id'     => $this->twinType->id,
                    'quantity'         => 1,
                    'adults'           => 2,
                    'children_under_6' => 0,
                    'children_over_6'  => 0,
                    'room_price'       => 1000000,
                    'price_source'     => 'MANUAL',
                ],
            ],
        ]);

        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-04 12:00:00'],
        ]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        $entry = $booking->folio->folioEntries()
            ->where('charge_type', ChargeType::Room->value)
            ->whereNull('voided_at')
            ->firstOrFail();

        $this->assertSame('3.00', $entry->quantity);
        $this->assertEquals('3000000.00', $entry->amount);
    }

    public function test_multi_room_booking_computes_total_room_charge_correctly(): void
    {
        // 1 TWIN room at 800,000/night for 2 nights = 2 × 800,000 = 1,600,000
        $this->travelTo('2026-07-01 14:00:00');

        $booking = $this->createBooking([
            'checkin_at'  => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-03 12:00:00', // 2 nights
            'requirements' => [
                [
                    'room_type_id'     => $this->twinType->id,
                    'quantity'         => 2, // 2 TWIN rooms
                    'adults'           => 4,
                    'children_under_6' => 0,
                    'children_over_6'  => 0,
                    'room_price'       => 800000,
                    'price_source'     => 'MANUAL',
                ],
            ],
        ]);

        $rooms = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(2)->get();
        app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $rooms[0]->id, 'room_type_id' => $rooms[0]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-03 12:00:00'],
            ['room_id' => $rooms[1]->id, 'room_type_id' => $rooms[1]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-03 12:00:00'],
        ]);

        $stay1 = app(StayService::class)->createStayFromAssignment($booking->roomAssignments[0]);
        app(StayService::class)->checkIn($stay1);

        $entry = $booking->folio->folioEntries()
            ->where('charge_type', ChargeType::Room->value)
            ->whereNull('voided_at')
            ->firstOrFail();

        // 2 nights × (800,000 × 2 rooms) = 2 × 1,600,000 = 3,200,000
        $this->assertSame('2.00', $entry->quantity, 'quantity = nights');
        $this->assertEquals('1600000.00', $entry->unit_price, 'unit_price = per-night total for all rooms');
        $this->assertEquals('3200000.00', $entry->amount, 'amount = nights × per-night-total');
    }

    public function test_guarded_folio_total_estimate_includes_nights_multiplier(): void
    {
        // Before the room charge is posted, calculateGuardedFolioTotal() falls back
        // to an estimate. That estimate must include the nights multiplier so that the
        // displayed balance_due matches what will be posted at check-in.
        $this->travelTo('2026-07-01 14:00:00');

        $booking = $this->createBooking([
            'checkin_at'  => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-03 12:00:00', // 2 nights
            'requirements' => [
                [
                    'room_type_id'     => $this->twinType->id,
                    'quantity'         => 1,
                    'adults'           => 2,
                    'children_under_6' => 0,
                    'children_over_6'  => 0,
                    'room_price'       => 1500000,
                    'price_source'     => 'MANUAL',
                ],
            ],
        ]);

        // Do NOT check in — estimate path is triggered when room charge is not posted
        $folioService = app(FolioService::class);
        $estimate = $folioService->calculateGuardedFolioTotal($booking);

        // 2 nights × 1,500,000 = 3,000,000
        $this->assertEquals(3000000.0, $estimate);
    }

    public function test_two_night_checkout_succeeds_after_paying_correct_amount(): void
    {
        $this->travelTo('2026-06-30 14:00:00');

        $booking = $this->createBooking([
            'checkin_at'  => '2026-06-30 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00', // 2 nights
            'requirements' => [
                [
                    'room_type_id'     => $this->twinType->id,
                    'quantity'         => 1,
                    'adults'           => 2,
                    'children_under_6' => 0,
                    'children_over_6'  => 0,
                    'room_price'       => 2100000,
                    'price_source'     => 'MANUAL',
                ],
            ],
        ]);

        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-06-30 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        // Pay the FULL two-night amount
        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 4200000, // 2 × 2,100,000
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        $this->travelTo('2026-07-02 11:00:00');
        app(StayService::class)->checkOut($stay, null, true);

        $this->assertSame(BookingStatus::CheckedOut, $booking->fresh()->status);
    }

    public function test_two_night_checkout_blocked_when_only_one_night_paid(): void
    {
        $this->travelTo('2026-06-30 14:00:00');

        $booking = $this->createBooking([
            'checkin_at'  => '2026-06-30 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00', // 2 nights
            'requirements' => [
                [
                    'room_type_id'     => $this->twinType->id,
                    'quantity'         => 1,
                    'adults'           => 2,
                    'children_under_6' => 0,
                    'children_over_6'  => 0,
                    'room_price'       => 2100000,
                    'price_source'     => 'MANUAL',
                ],
            ],
        ]);

        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-06-30 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        // Underpay — only one night, not two
        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 2100000, // only 1 night
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        $this->expectException(OutstandingBalanceException::class);

        $this->travelTo('2026-07-02 11:00:00');
        app(StayService::class)->checkOut($stay, null, true);
    }

    // ── Issue 2: Checkout all / multi-stay ───────────────────────────────────

    public function test_checkout_all_succeeds_for_multi_stay_booking_when_balance_is_zero(): void
    {
        $this->travelTo('2026-07-01 14:00:00');

        $booking = $this->createBooking();
        $rooms   = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(2)->get();

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $rooms[0]->id, 'room_type_id' => $rooms[0]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
            ['room_id' => $rooms[1]->id, 'room_type_id' => $rooms[1]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay1 = app(StayService::class)->createStayFromAssignment($assignments[0]);
        $stay2 = app(StayService::class)->createStayFromAssignment($assignments[1]);

        app(StayService::class)->checkIn($stay1);
        // Second check-in: autoPostRoomCharge is idempotent — no duplicate entry
        app(StayService::class)->checkIn($stay2);

        // The aggregate room charge covers both rooms: 2 rooms × 800,000 = 1,600,000 for 1 night
        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 1600000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        // Simulate checkOutAll: sequential individual checkouts
        app(StayService::class)->checkOut($stay1);
        app(StayService::class)->checkOut($stay2, null, true); // last stay → finaliseBookingCheckout

        $this->assertSame(BookingStatus::CheckedOut, $booking->fresh()->status);
        $this->assertSame(FolioStatus::Closed, $booking->folio->fresh()->status);
    }

    public function test_checkout_all_last_stay_blocked_by_obe_when_balance_outstanding(): void
    {
        // This is the server-side enforcement that backs the UI disabled state.
        $this->travelTo('2026-07-01 14:00:00');

        $booking = $this->createBooking();
        $rooms   = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(2)->get();

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $rooms[0]->id, 'room_type_id' => $rooms[0]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
            ['room_id' => $rooms[1]->id, 'room_type_id' => $rooms[1]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay1 = app(StayService::class)->createStayFromAssignment($assignments[0]);
        $stay2 = app(StayService::class)->createStayFromAssignment($assignments[1]);

        app(StayService::class)->checkIn($stay1);
        app(StayService::class)->checkIn($stay2);

        // No payment — balance outstanding

        // Non-last stay checkout is allowed (server does not check balance on partial checkout)
        app(StayService::class)->checkOut($stay1);

        $this->assertSame(StayStatus::CheckedOut, $stay1->fresh()->status);
        $this->assertSame(BookingStatus::PartiallyCheckedOut, $booking->fresh()->status);

        // Last stay checkout throws OBE — this is what the UI disabled state prevents
        $this->expectException(OutstandingBalanceException::class);
        app(StayService::class)->checkOut($stay2, null, true);
    }

    public function test_partial_checkout_booking_can_checkout_remaining_rooms_after_payment(): void
    {
        $this->travelTo('2026-07-01 14:00:00');

        $booking = $this->createBooking();
        $rooms   = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(2)->get();

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $rooms[0]->id, 'room_type_id' => $rooms[0]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
            ['room_id' => $rooms[1]->id, 'room_type_id' => $rooms[1]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay1 = app(StayService::class)->createStayFromAssignment($assignments[0]);
        $stay2 = app(StayService::class)->createStayFromAssignment($assignments[1]);

        app(StayService::class)->checkIn($stay1);
        app(StayService::class)->checkIn($stay2);

        // Partial checkout: stay1 exits while balance is outstanding
        app(StayService::class)->checkOut($stay1);
        $this->assertSame(BookingStatus::PartiallyCheckedOut, $booking->fresh()->status);

        // Now pay — 2 rooms × 800,000 = 1,600,000
        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 1600000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        // stay2 can now complete checkout
        app(StayService::class)->checkOut($stay2, null, true);

        $this->assertSame(BookingStatus::CheckedOut, $booking->fresh()->status);
    }

    public function test_released_room_assignment_cannot_be_checked_out(): void
    {
        $this->travelTo('2026-07-01 14:00:00');

        $booking = $this->createBooking();
        $room    = Room::where('room_type_id', $this->twinType->id)->firstOrFail();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        // Do NOT check in — stay is still Reserved, assignment is still Assigned

        // Release the assignment
        app(RoomAssignmentService::class)->releaseAssignment($assignment, 'Test release');

        // Attempt to check out the reserved (never checked in) stay should fail
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(StayService::class)->checkOut($stay);
    }

    // ── HTTP layer tests ──────────────────────────────────────────────────────

    public function test_checkout_all_http_blocked_when_balance_outstanding(): void
    {
        $this->travelTo('2026-07-01 14:00:00');

        $booking = $this->createBooking();
        $rooms   = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(2)->get();

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $rooms[0]->id, 'room_type_id' => $rooms[0]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
            ['room_id' => $rooms[1]->id, 'room_type_id' => $rooms[1]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay1 = app(StayService::class)->createStayFromAssignment($assignments[0]);
        $stay2 = app(StayService::class)->createStayFromAssignment($assignments[1]);
        app(StayService::class)->checkIn($stay1);
        app(StayService::class)->checkIn($stay2);

        // Non-last stay checkout succeeds even without payment
        $response = $this->post(route('admin.bookings.stays.check-out', [$booking, $stay1]));
        $response->assertRedirect();

        $this->assertSame(StayStatus::CheckedOut, $stay1->fresh()->status);

        // Last stay checkout with confirmed=true: reaches OBE → redirects to payments tab with error
        $response = $this->post(route('admin.bookings.stays.check-out', [$booking, $stay2]), ['confirmed' => true]);
        $response->assertRedirect(route('admin.bookings.show', ['booking' => $booking->id, 'tab' => 'payments']));
        $response->assertSessionHas('error');

        // Last stay must NOT be checked out
        $this->assertSame(StayStatus::CheckedIn, $stay2->fresh()->status);
        $this->assertNotSame(BookingStatus::CheckedOut, $booking->fresh()->status);
    }

    public function test_checkout_all_http_succeeds_after_full_payment(): void
    {
        $this->travelTo('2026-07-01 14:00:00');

        $booking = $this->createBooking();
        $rooms   = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(2)->get();

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $rooms[0]->id, 'room_type_id' => $rooms[0]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
            ['room_id' => $rooms[1]->id, 'room_type_id' => $rooms[1]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay1 = app(StayService::class)->createStayFromAssignment($assignments[0]);
        $stay2 = app(StayService::class)->createStayFromAssignment($assignments[1]);
        app(StayService::class)->checkIn($stay1);
        app(StayService::class)->checkIn($stay2);

        // Pay in full: 2 rooms × 800,000 = 1,600,000
        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 1600000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        $response1 = $this->post(route('admin.bookings.stays.check-out', [$booking, $stay1]));
        $response1->assertRedirect(route('admin.bookings.show', ['booking' => $booking->id, 'tab' => 'room_map']));

        $response2 = $this->post(route('admin.bookings.stays.check-out', [$booking, $stay2]), ['confirmed' => true]);
        $response2->assertRedirect(route('admin.bookings.show', ['booking' => $booking->id, 'tab' => 'room_map']));

        $this->assertSame(BookingStatus::CheckedOut, $booking->fresh()->status);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createBooking(array $overrides = []): Booking
    {
        $payload = array_merge([
            'booking_color'    => '#196251',
            'customer_name'    => 'Hotfix Guest',
            'customer_phone'   => '0900000001',
            'customer_email'   => 'hotfix@example.test',
            'customer_type'    => CustomerType::Individual->value,
            'booking_type'     => BookingType::Overnight->value,
            'checkin_at'       => '2026-07-01 14:00:00',
            'checkout_at'      => '2026-07-02 12:00:00',
            'adults'           => 2,
            'children_under_6' => 0,
            'children_over_6'  => 0,
            'sales_user_id'    => $this->admin->id,
        ], $overrides);

        if (! isset($payload['requirements'])) {
            $payload['requirements'] = [
                [
                    'room_type_id'     => $this->twinType->id,
                    'quantity'         => 1,
                    'adults'           => 2,
                    'children_under_6' => 0,
                    'children_over_6'  => 0,
                    'room_price'       => 800000,
                    'price_source'     => 'MANUAL',
                ],
            ];
        }

        return app(BookingService::class)->createBooking($payload);
    }
}
