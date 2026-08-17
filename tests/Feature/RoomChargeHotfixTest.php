<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ChargeType;
use App\Enums\CustomerType;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethod;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
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

    // docs/Prompt_2.txt mục IV — supersedes the old OBE-blocks-final-checkout
    // behavior: the last stay's checkout now succeeds with the balance
    // preserved on the (still OPEN) Folio, instead of throwing.
    public function test_checkout_all_last_stay_succeeds_with_balance_outstanding(): void
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

        // No payment — balance outstanding

        // Non-last stay checkout is allowed (server does not check balance on partial checkout)
        app(StayService::class)->checkOut($stay1);

        $this->assertSame(StayStatus::CheckedOut, $stay1->fresh()->status);
        $this->assertSame(BookingStatus::PartiallyCheckedOut, $booking->fresh()->status);

        // Last stay checkout now succeeds — the 2-room aggregate charge
        // (1,600,000) stays outstanding, tracked on the Folio, not blocked.
        app(StayService::class)->checkOut($stay2, null, true);

        $booking = $booking->fresh();
        $this->assertSame(StayStatus::CheckedOut, $stay2->fresh()->status);
        $this->assertSame(BookingStatus::CheckedOut, $booking->status);
        $this->assertSame(FolioStatus::Open, $booking->folio->fresh()->status);
        $this->assertEquals(1600000.0, app(BookingService::class)->paymentSummary($booking)['balance_due']);
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

    // docs/Prompt_2.txt mục IV — supersedes the old HTTP-level OBE-block proof:
    // the last stay's checkout now succeeds (with confirmed=true) despite the
    // outstanding balance, instead of redirecting to the payments tab with an error.
    public function test_checkout_all_http_succeeds_when_balance_outstanding(): void
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

        // Last stay checkout with confirmed=true now succeeds despite the balance.
        $response = $this->post(route('admin.bookings.stays.check-out', [$booking, $stay2]), ['confirmed' => true]);
        $response->assertRedirect(route('admin.bookings.show', ['booking' => $booking->id, 'tab' => 'room_map']));
        $response->assertSessionHas('success');

        $this->assertSame(StayStatus::CheckedOut, $stay2->fresh()->status);
        $this->assertSame(BookingStatus::CheckedOut, $booking->fresh()->status);
        $this->assertEquals(1600000.0, app(BookingService::class)->paymentSummary($booking->fresh())['balance_due']);
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
