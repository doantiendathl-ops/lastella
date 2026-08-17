<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StayEvent;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use App\Services\ReconciliationService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * docs/Prompt_2.txt — Checkout With Outstanding Balance.
 *
 * The example throughout the spec (mục IV/XVII): Total 2,000,000, Paid
 * 1,500,000, Outstanding 500,000 → checkout must PASS, the 500,000 must
 * survive as a real receivable tracked in Đối soát, not be zeroed/hidden.
 */
class UnpaidCheckoutTest extends TestCase
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

    public function test_zero_balance_checkout_proceeds_normally(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay(2000000);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 2000000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $booking = $booking->fresh();
        $this->assertSame(BookingStatus::CheckedOut, $booking->status);
        $this->assertSame(FolioStatus::Closed, $booking->folio()->first()->status);
    }

    public function test_outstanding_balance_checkout_passes_and_room_is_released(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay(2000000);
        $room = $stay->room;

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 1500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $booking = $booking->fresh();
        $this->assertSame(BookingStatus::CheckedOut, $booking->status);
        $this->assertSame(StayStatus::CheckedOut, $stay->fresh()->status);

        // Room release: mục XIV — financial debt and physical occupancy are
        // independent; the room must not stay "occupied" just because of debt.
        $room = $room->fresh();
        $this->assertNotSame(RoomStatus::Occupied, $room->status);
    }

    public function test_folio_outstanding_balance_survives_checkout_unchanged(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay(2000000);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 1500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $summary = app(BookingService::class)->paymentSummary($booking->fresh());
        $this->assertEquals(2000000.0, $summary['total_charges']);
        $this->assertEquals(1500000.0, $summary['paid_total']);
        $this->assertEquals(500000.0, $summary['balance_due']);
        $this->assertSame(FolioStatus::Open, $booking->fresh()->folio()->first()->status);
    }

    public function test_reconciliation_lists_the_checked_out_booking_with_correct_fields(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay(2000000);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 1500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $rows = app(ReconciliationService::class)->outstandingBalances();
        $row = collect($rows)->firstWhere('booking_id', $booking->id);

        $this->assertNotNull($row, 'Checked-out booking with debt must appear in Đối soát.');
        $this->assertSame($booking->booking_code, $row['booking_code']);
        $this->assertSame($booking->customer_name, $row['customer_name']);
        $this->assertSame(BookingStatus::CheckedOut->value, $row['status']);
        $this->assertEquals(500000.0, $row['balance_due']);
        $this->assertNotNull($row['checkout_at']);
    }

    // A PartiallyCheckedOut booking (one room out, one still in-house) must not
    // show a "checkout date" — the booking as a whole hasn't checked out yet,
    // even though one of its Stay rows already has an actual_checkout_at.
    public function test_reconciliation_hides_checkout_date_for_partially_checked_out_booking(): void
    {
        $booking = $this->createBooking(1000000);
        $rooms = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(2)->get();

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $rooms[0]->id, 'room_type_id' => $rooms[0]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
            ['room_id' => $rooms[1]->id, 'room_type_id' => $rooms[1]->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);
        $stay1 = app(StayService::class)->createStayFromAssignment($assignments[0]);
        $stay2 = app(StayService::class)->createStayFromAssignment($assignments[1]);
        app(StayService::class)->checkIn($stay1);
        app(StayService::class)->checkIn($stay2);

        // Only stay1 checks out — stay2 remains CheckedIn, so this is non-final.
        app(StayService::class)->checkOut($stay1);
        $this->assertSame(BookingStatus::PartiallyCheckedOut, $booking->fresh()->status);

        $row = collect(app(ReconciliationService::class)->outstandingBalances())->firstWhere('booking_id', $booking->id);

        $this->assertNotNull($row, 'A partially-checked-out booking with an outstanding balance still belongs in the list.');
        $this->assertNull($row['checkout_at'], 'No checkout date until the booking as a whole is CheckedOut.');
    }

    public function test_abnormal_information_flags_the_checked_out_debt(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay(2000000);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 1500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $discrepancies = app(ReconciliationService::class)->discrepancies();
        $row = collect($discrepancies)->firstWhere('booking_id', $booking->id);

        $this->assertNotNull($row);
        $this->assertSame('CHECKED_OUT_OUTSTANDING_BALANCE', $row['type']);
        $this->assertEquals(500000.0, $row['balance_due']);
    }

    public function test_later_full_payment_clears_outstanding_and_anomaly(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay(2000000);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 1500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $this->travelTo('2026-08-20 10:00:00');
        app(BookingPaymentService::class)->addPayment($booking->fresh(), [
            'payment_type' => PaymentType::RoomPayment->value,
            'amount' => 500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $booking = $booking->fresh();
        $summary = app(BookingService::class)->paymentSummary($booking);
        $this->assertEquals(0.0, $summary['balance_due']);

        // mục XI — no reopen of stay/room required; Folio settles on its own.
        $this->assertSame(FolioStatus::Closed, $booking->folio()->first()->status);

        $reconciliationService = app(ReconciliationService::class);
        $this->assertNull(
            collect($reconciliationService->outstandingBalances())->firstWhere('booking_id', $booking->id),
            'Fully-paid booking must drop off the outstanding list.',
        );
        $this->assertNull(
            collect($reconciliationService->discrepancies())->firstWhere('booking_id', $booking->id),
            'Fully-paid booking must drop off the anomaly list.',
        );
    }

    public function test_later_partial_payment_leaves_remaining_balance_visible(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay(2000000);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 1500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        app(BookingPaymentService::class)->addPayment($booking->fresh(), [
            'payment_type' => PaymentType::RoomPayment->value,
            'amount' => 200000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $booking = $booking->fresh();
        $summary = app(BookingService::class)->paymentSummary($booking);
        $this->assertEquals(300000.0, $summary['balance_due']);

        // Not prematurely marked paid/closed — still an open receivable.
        $this->assertSame(FolioStatus::Open, $booking->folio()->first()->status);

        $row = collect(app(ReconciliationService::class)->outstandingBalances())->firstWhere('booking_id', $booking->id);
        $this->assertNotNull($row);
        $this->assertEquals(300000.0, $row['balance_due']);
    }

    public function test_checkout_retry_does_not_duplicate_financial_movement(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay(2000000);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 1500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        try {
            app(StayService::class)->checkOut($stay->fresh(), null, true);
            $this->fail('A retried checkout for an already-checked-out stay must be rejected.');
        } catch (ValidationException) {
            // expected — mục XVI idempotency guard (pre-existing Stay-state check)
        }

        $this->assertEquals(
            500000.0,
            app(BookingService::class)->paymentSummary($booking->fresh())['balance_due'],
            'Balance must be unchanged by the rejected retry.',
        );
        $this->assertSame(
            1,
            StayEvent::where('stay_id', $stay->id)->where('event_type', 'CHECKOUT')->count(),
            'Exactly one Checkout audit event, never duplicated by a retried request.',
        );
    }

    public function test_staff_with_checkout_permission_can_checkout_with_outstanding_balance(): void
    {
        $reception = User::factory()->create(['name' => 'Reception Staff']);
        $reception->assignRole('RECEPTION');

        [$booking, $stay] = $this->bookingWithCheckedInStay(2000000);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 1500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $this->actingAs($reception);
        app(StayService::class)->checkOut($stay, null, true);

        $this->assertSame(BookingStatus::CheckedOut, $booking->fresh()->status);
    }

    /** mục X — audit trail: outstanding balance at the moment of checkout is recorded. */
    public function test_audit_trail_records_outstanding_balance_at_checkout(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay(2000000);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 1500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $event = StayEvent::where('stay_id', $stay->id)->where('event_type', 'CHECKOUT')->firstOrFail();
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertEquals(500000.0, $event->metadata['outstanding_balance_at_checkout']);
    }

    private function bookingWithCheckedInStay(float $roomPrice): array
    {
        $booking = $this->createBooking($roomPrice);
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        return [$booking, $stay];
    }

    private function createBooking(float $roomPrice): Booking
    {
        $payload = [
            'booking_color'    => '#196251',
            'customer_name'    => 'Unpaid Checkout Guest',
            'customer_phone'   => '0900000099',
            'customer_email'   => 'unpaid-checkout@example.test',
            'customer_type'    => CustomerType::Individual->value,
            'booking_type'     => BookingType::Overnight->value,
            'checkin_at'       => '2026-07-01 14:00:00',
            'checkout_at'      => '2026-07-02 12:00:00',
            'adults'           => 2,
            'children_under_6' => 0,
            'children_over_6'  => 0,
            'sales_user_id'    => $this->admin->id,
            'requirements'     => [
                [
                    'room_type_id'     => $this->twinType->id,
                    'quantity'         => 1,
                    'adults'           => 2,
                    'children_under_6' => 0,
                    'children_over_6'  => 0,
                    'room_price'       => $roomPrice,
                    'price_source'     => 'MANUAL',
                ],
            ],
        ];

        return app(BookingService::class)->createBooking($payload);
    }
}
