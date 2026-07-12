<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\StayEventType;
use App\Enums\StayStatus;
use App\Exceptions\BookingTerminalException;
use App\Exceptions\OutstandingBalanceException;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
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
 * Phase 3.1B1: Checkout Integration Tests.
 * Covers ADR-38 through ADR-49.
 */
class CheckoutIntegrationTest extends TestCase
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

        $this->travelTo('2026-07-01 14:00:00');

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    // ── ADR-38, ADR-39, ADR-41, ADR-42, ADR-43 ──────────────────────────────

    public function test_checkout_transitions_booking_to_checked_out_when_balance_is_zero(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        // Pay exact amount (800 000 VND)
        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $this->assertSame(BookingStatus::CheckedOut, $booking->fresh()->status);

        // Phase 4.3A M3: a final checkout (no other active Stay left) records a Checkout event.
        $this->assertDatabaseHas('stay_events', [
            'stay_id' => $stay->id,
            'event_type' => StayEventType::Checkout->value,
        ]);
    }

    public function test_folio_auto_closes_at_last_stay_checkout(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $this->assertSame(FolioStatus::Closed, $booking->folio()->first()->status);
    }

    // ── ADR-40 ───────────────────────────────────────────────────────────────

    public function test_checkout_blocked_by_outstanding_balance(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        // No payment — balance = 800 000 outstanding

        $this->expectException(OutstandingBalanceException::class);

        app(StayService::class)->checkOut($stay, null, true);
    }

    public function test_checkout_blocked_with_partial_payment(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $this->expectException(OutstandingBalanceException::class);

        app(StayService::class)->checkOut($stay, null, true);
    }

    public function test_checkout_blocked_leaves_booking_and_folio_unchanged(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        try {
            app(StayService::class)->checkOut($stay, null, true);
        } catch (OutstandingBalanceException) {
            // expected
        }

        $this->assertNotSame(BookingStatus::CheckedOut, $booking->fresh()->status);
        $this->assertSame(FolioStatus::Open, $booking->folio()->first()->status);
    }

    // ── ADR-44: BookingTerminalException ─────────────────────────────────────

    public function test_add_deposit_blocked_on_terminal_booking(): void
    {
        $booking = $this->createBooking();
        $booking->update(['status' => BookingStatus::CheckedOut]);

        $this->expectException(BookingTerminalException::class);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_add_payment_blocked_on_terminal_booking(): void
    {
        $booking = $this->createBooking();
        $booking->update(['status' => BookingStatus::CheckedOut]);

        $this->expectException(BookingTerminalException::class);

        app(BookingPaymentService::class)->addPayment($booking, [
            'payment_type' => PaymentType::RoomPayment->value,
            'amount' => 500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_add_refund_blocked_on_terminal_booking(): void
    {
        $booking = $this->createBooking();
        $booking->update(['status' => BookingStatus::Cancelled]);

        $this->expectException(BookingTerminalException::class);

        app(BookingPaymentService::class)->addRefund($booking, [
            'amount' => 100000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_delete_payment_blocked_on_terminal_booking(): void
    {
        $booking = $this->createBooking();

        // Add a payment while non-terminal
        $payment = app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 300000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        // Now mark booking as terminal
        $booking->update(['status' => BookingStatus::CheckedOut]);

        $this->expectException(BookingTerminalException::class);

        app(BookingPaymentService::class)->deletePayment($payment);
    }

    public function test_folio_charge_blocked_on_terminal_booking(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        // Pay and check out to put booking in CheckedOut terminal state
        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(StayService::class)->checkOut($stay, null, true);

        $folio = $booking->folio()->first();
        // Manually reopen folio to isolate addCharge guard (not testing autoClose here)
        $folio->update(['status' => FolioStatus::Open]);

        $this->expectException(BookingTerminalException::class);

        app(FolioService::class)->addCharge($folio, [
            'charge_type' => 'OTHER',
            'description' => 'Extra charge',
            'quantity'    => 1,
            'unit_price'  => 50000,
        ]);
    }

    public function test_folio_reopen_blocked_on_terminal_booking(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(StayService::class)->checkOut($stay, null, true);

        $folio = $booking->folio()->first();

        $this->expectException(BookingTerminalException::class);

        app(FolioService::class)->reopenFolio($folio);
    }

    // ── ADR-49: Active stay definition (Reserved OR CheckedIn) ───────────────

    public function test_reserved_stay_prevents_premature_finalisation(): void
    {
        // Booking with two TWIN rooms assigned
        $booking = $this->createBooking();
        $rooms   = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(2)->get();

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $rooms[0]->id, 'room_type_id' => $rooms[0]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
            ['room_id' => $rooms[1]->id, 'room_type_id' => $rooms[1]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay1 = app(StayService::class)->createStayFromAssignment($assignments[0]);
        $stay2 = app(StayService::class)->createStayFromAssignment($assignments[1]);  // stays Reserved

        // Check in only stay1
        app(StayService::class)->checkIn($stay1);

        // Pay full amount BEFORE checkout (so balance check won't interfere)
        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        // Check out stay1 — stay2 is still Reserved, so booking should NOT finalise
        app(StayService::class)->checkOut($stay1);

        // ADR-49: Reserved stay2 blocks final transition
        $this->assertNotSame(BookingStatus::CheckedOut, $booking->fresh()->status);
        $this->assertSame(BookingStatus::PartiallyCheckedOut, $booking->fresh()->status);
        $this->assertSame(FolioStatus::Open, $booking->folio()->first()->status);
    }

    // ── ADR-38: Partial checkout intermediate status ──────────────────────────

    public function test_partial_checkout_of_multi_stay_booking_sets_partially_checked_out(): void
    {
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

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        // Check out only stay1
        app(StayService::class)->checkOut($stay1);

        $this->assertSame(BookingStatus::PartiallyCheckedOut, $booking->fresh()->status);

        // Phase 4.3A M3: stay1 (checked out while stay2 remains active) records PartialCheckout,
        // never Checkout — and stay2 is untouched.
        $this->assertDatabaseHas('stay_events', [
            'stay_id' => $stay1->id,
            'event_type' => StayEventType::PartialCheckout->value,
        ]);
        $this->assertDatabaseMissing('stay_events', [
            'stay_id' => $stay1->id,
            'event_type' => StayEventType::Checkout->value,
        ]);
        $this->assertSame(StayStatus::CheckedIn, $stay2->fresh()->status);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function bookingWithCheckedInStay(): array
    {
        $booking = $this->createBooking();

        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        return [$booking, $stay];
    }

    private function createBooking(array $overrides = []): Booking
    {
        $payload = array_merge([
            'booking_color'    => '#196251',
            'customer_name'    => 'Integration Guest',
            'customer_phone'   => '0900000000',
            'customer_email'   => 'guest@example.test',
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
                    'room_type_id'      => $this->twinType->id,
                    'quantity'          => 1,
                    'adults'            => 2,
                    'children_under_6'  => 0,
                    'children_over_6'   => 0,
                    'room_price'        => 800000,
                    'price_source'      => 'MANUAL',
                ],
            ];
        }

        return app(BookingService::class)->createBooking($payload);
    }
}
