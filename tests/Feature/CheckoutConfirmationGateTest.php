<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ChargeType;
use App\Enums\CustomerType;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethod;
use App\Enums\StayStatus;
use App\Exceptions\BookingTerminalException;
use App\Exceptions\FinalCheckoutConfirmationRequiredException;
use App\Exceptions\FolioClosedException;
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
 * Phase 3.1.2 — Final Checkout Charge Review Gate.
 *
 * ADR-55: Final checkout requires explicit confirmation that all charges have been entered.
 * ADR-56: Post-checkout charge lock — derived from booking.status === CHECKED_OUT (terminal guard).
 *
 * Charge lock semantics:
 *   - addCharge()  → BookingTerminalException  (ADR-44 terminal guard, pre-existing)
 *   - voidEntry()  → FolioClosedException      (folio auto-closed on checkout, pre-existing)
 *   - No new column or state introduced; charge lock is derived from booking status.
 */
class CheckoutConfirmationGateTest extends TestCase
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

    // ── Test 1: Partial checkout proceeds without confirmed ───────────────────

    public function test_non_final_checkout_proceeds_without_confirmed(): void
    {
        [$booking, $stay1, $stay2] = $this->bookingWithTwoCheckedInStays();

        // Checkout stay1 without confirmed — it is NOT the final checkout
        app(StayService::class)->checkOut($stay1);

        $this->assertSame(StayStatus::CheckedOut, $stay1->fresh()->status);
        $this->assertSame(BookingStatus::PartiallyCheckedOut, $booking->fresh()->status);
    }

    // ── Test 2: Charges remain editable after partial checkout ────────────────

    public function test_non_final_checkout_charges_remain_editable(): void
    {
        [$booking, $stay1, $stay2] = $this->bookingWithTwoCheckedInStays();

        // Partial checkout — booking not yet CHECKED_OUT, folio still OPEN
        app(StayService::class)->checkOut($stay1);

        $folio = $booking->folio()->first();
        $this->assertSame(FolioStatus::Open, $folio->status);

        // Charges can still be added
        $entry = app(FolioService::class)->addCharge($folio, [
            'charge_type' => ChargeType::Other->value,
            'description' => 'Minibar',
            'quantity'    => 2,
            'unit_price'  => 50000,
        ]);

        $this->assertNotNull($entry->id);
        $this->assertEquals('100000.00', $entry->amount);
    }

    // ── Test 3: Backend rejects final checkout without confirmed ──────────────

    public function test_final_checkout_without_confirmed_throws_exception(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        $this->expectException(FinalCheckoutConfirmationRequiredException::class);

        app(StayService::class)->checkOut($stay);
    }

    // ── Test 4: Backend accepts final checkout with confirmed ─────────────────

    public function test_final_checkout_with_confirmed_completes_successfully(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $this->assertSame(BookingStatus::CheckedOut, $booking->fresh()->status);
        $this->assertSame(FolioStatus::Closed, $booking->folio()->first()->status);
    }

    // ── Test 5: HTTP rejects final checkout without confirmed (flash) ─────────

    public function test_http_final_checkout_without_confirmed_returns_confirmation_flash(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        // POST without confirmed — backend gate fires
        $response = $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out");

        $response->assertRedirect(route('admin.bookings.show', [
            'booking' => $booking->id,
            'tab'     => 'room_map',
        ]));

        $response->assertSessionHas('final_checkout_confirmation_required', $stay->id);

        // Stay must NOT have been checked out
        $this->assertSame(StayStatus::CheckedIn, $stay->fresh()->status);
        $this->assertNotSame(BookingStatus::CheckedOut, $booking->fresh()->status);
    }

    // ── Test 6: HTTP accepts final checkout with confirmed ────────────────────

    public function test_http_final_checkout_with_confirmed_succeeds(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        $response = $this->post(
            "/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out",
            ['confirmed' => true],
        );

        $response->assertRedirect(route('admin.bookings.show', [
            'booking' => $booking->id,
            'tab'     => 'room_map',
        ]));

        $response->assertSessionHas('success');
        $this->assertSame(BookingStatus::CheckedOut, $booking->fresh()->status);
    }

    // ── Test 7: CHECKED_OUT booking — add charge blocked (ADR-44 terminal guard)

    public function test_checked_out_booking_add_charge_blocked(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $folio = $booking->folio()->first();
        $this->assertSame(BookingStatus::CheckedOut, $booking->fresh()->status);

        // ADR-44 terminal guard fires before folio-status check; manually reopen folio
        // to prove it is the booking-terminal guard, not the folio-closed guard.
        $folio->update(['status' => FolioStatus::Open]);

        $this->expectException(BookingTerminalException::class);

        app(FolioService::class)->addCharge($folio, [
            'charge_type' => ChargeType::Other->value,
            'description' => 'Late charge attempt',
            'quantity'    => 1,
            'unit_price'  => 100000,
        ]);
    }

    // ── Test 8: CHECKED_OUT booking — void charge blocked (folio auto-closed) ─

    public function test_checked_out_booking_void_charge_blocked(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        // Add a voidable charge before checkout
        $folio  = $booking->folio()->first();
        $charge = app(FolioService::class)->addCharge($folio, [
            'charge_type' => ChargeType::Other->value,
            'description' => 'Minibar',
            'quantity'    => 1,
            'unit_price'  => 50000,
        ]);

        // Pay full amount (room charge + minibar)
        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 850000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        // Folio is now CLOSED — voidEntry must reject with FolioClosedException
        $this->expectException(FolioClosedException::class);

        app(FolioService::class)->voidEntry($charge->fresh(), 'Post-checkout void attempt', $this->admin);
    }

    // ── Test 9: Payment rejection unchanged by charge lock ────────────────────

    public function test_payment_rejection_unchanged_by_charge_lock(): void
    {
        // The Phase 3.1.2 charge lock must not alter payment rejection behavior.
        // Payments on CHECKED_OUT bookings were already blocked by the ADR-44 terminal
        // guard in BookingPaymentService — the new charge gate must not change that.
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $this->assertSame(BookingStatus::CheckedOut, $booking->fresh()->status);

        // Adding further payment after checkout throws BookingTerminalException —
        // same pre-3.1.2 behavior; charge lock does not introduce a new payment guard.
        $this->expectException(BookingTerminalException::class);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 1000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);
    }

    // ── Test 10: Confirmation not required when a Reserved stay is still active ─

    public function test_confirmation_not_required_when_reserved_stay_still_active(): void
    {
        // ADR-49: Reserved stays count as active. If a booking has a checked-in stay
        // AND a reserved stay, checking out the checked-in stay is NON-FINAL
        // and must proceed without confirmation.
        $booking = $this->createBooking();
        $rooms   = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(2)->get();

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $rooms[0]->id, 'room_type_id' => $rooms[0]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
            ['room_id' => $rooms[1]->id, 'room_type_id' => $rooms[1]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay1 = app(StayService::class)->createStayFromAssignment($assignments[0]);
        $stay2 = app(StayService::class)->createStayFromAssignment($assignments[1]);

        // Only check in stay1 — stay2 remains Reserved
        app(StayService::class)->checkIn($stay1);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        // Checkout stay1 WITHOUT confirmed — stay2 (Reserved) keeps this non-final
        app(StayService::class)->checkOut($stay1);

        $this->assertSame(StayStatus::CheckedOut, $stay1->fresh()->status);
        $this->assertSame(BookingStatus::PartiallyCheckedOut, $booking->fresh()->status);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function bookingWithCheckedInStay(): array
    {
        $booking = $this->createBooking();
        $room    = Room::where('room_type_id', $this->twinType->id)->firstOrFail();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        return [$booking, $stay];
    }

    private function bookingWithTwoCheckedInStays(): array
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

        return [$booking, $stay1, $stay2];
    }

    private function createBooking(array $overrides = []): Booking
    {
        $payload = array_merge([
            'booking_color'    => '#196251',
            'customer_name'    => 'Gate Test Guest',
            'customer_phone'   => '0900000002',
            'customer_email'   => 'gate@example.test',
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
