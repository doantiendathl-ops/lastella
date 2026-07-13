<?php

namespace Tests\Unit\Services;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\NightAuditBookingLog;
use App\Models\NightAuditRun;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use App\Services\FolioService;
use App\Services\PaymentProjectionService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product Sprint 01 — Payment Projection, Milestone 1 (Expected Payment Foundation).
 */
class PaymentProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM_PRICE = 800000;

    private User $admin;
    private RoomType $twinType;
    private PaymentProjectionService $projection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
            RoomSeeder::class,
        ]);

        $this->travelTo('2026-07-12 14:00:00');

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
        $this->projection = app(PaymentProjectionService::class);
    }

    // 1. Single room, single night.
    public function test_single_room_single_night(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);

        $result = $this->projection->project($booking->fresh());

        $this->assertSame((float) self::ROOM_PRICE, $result['expected_total']);
    }

    // 2. Single room, multiple nights.
    public function test_single_room_multiple_nights(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 3);

        $result = $this->projection->project($booking->fresh());

        $this->assertSame((float) (self::ROOM_PRICE * 3), $result['expected_total']);
    }

    // 3. Stay Extension increases Expected Total correctly.
    public function test_stay_extension_increases_expected_total(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay(nights: 1);

        $before = $this->projection->project($booking->fresh());
        $this->assertSame((float) self::ROOM_PRICE, $before['expected_total']);

        app(StayService::class)->extendStay($stay, '2026-07-15 12:00:00', $this->admin); // +2 nights

        $after = $this->projection->project($booking->fresh());
        $this->assertSame((float) (self::ROOM_PRICE * 3), $after['expected_total']);
    }

    // 4. Booking amendment (date change on a not-yet-checked-in Stay) changes Expected Total correctly.
    public function test_booking_amendment_changes_expected_total(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();
        app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);

        $before = $this->projection->project($booking->fresh());
        $this->assertSame((float) self::ROOM_PRICE, $before['expected_total']);

        app(BookingService::class)->updateBooking($booking, [
            'checkin_at' => '2026-07-12 14:00:00',
            'checkout_at' => '2026-07-14 12:00:00', // 2 nights instead of 1
        ]);

        $after = $this->projection->project($booking->fresh());
        $this->assertSame((float) (self::ROOM_PRICE * 2), $after['expected_total']);
    }

    // 5. Multi-room Booking sums correctly.
    public function test_multi_room_booking_sums_correctly(): void
    {
        [$booking] = $this->threeRoomCheckedInBooking();

        $result = $this->projection->project($booking->fresh());

        $this->assertSame((float) (self::ROOM_PRICE * 3), $result['expected_total']);
    }

    // 6. One Stay extended, the other Stays unchanged (Split Stay independence).
    public function test_one_stay_extended_others_unchanged(): void
    {
        [$booking, $stays] = $this->threeRoomCheckedInBooking();
        [$stayA, $stayB, $stayC] = $stays;

        app(StayService::class)->extendStay($stayC, '2026-07-14 12:00:00', $this->admin); // C: +1 night

        $result = $this->projection->project($booking->fresh());
        $breakdown = collect($result['calculation_context']['stays'])->keyBy('stay_id');

        $this->assertSame(1, $breakdown[$stayA->id]['nights']);
        $this->assertSame(1, $breakdown[$stayB->id]['nights']);
        $this->assertSame(2, $breakdown[$stayC->id]['nights']);
        $this->assertSame((float) (self::ROOM_PRICE + self::ROOM_PRICE + self::ROOM_PRICE * 2), $result['expected_total']);
    }

    // 7. Partial Checkout does not drop the valid cost of other Stays.
    public function test_partial_checkout_does_not_drop_other_stay_costs(): void
    {
        [$booking, $stays] = $this->threeRoomCheckedInBooking();
        [$stayA] = $stays;

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => self::ROOM_PRICE * 3,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $this->travelTo('2026-07-13 12:00:00'); // elapse the actual 1 night before checkout
        app(StayService::class)->checkOut($stayA); // partial — B, C remain active

        $result = $this->projection->project($booking->fresh());

        // A's actual (checked-out) night is still counted, plus B and C's projected nights.
        $this->assertSame((float) (self::ROOM_PRICE * 3), $result['expected_total']);
    }

    // 8. Final Checkout does not silently turn the projection into the posted ledger.
    public function test_final_checkout_does_not_overwrite_projection_with_posted_ledger(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay(nights: 1);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => self::ROOM_PRICE,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $this->travelTo('2026-07-13 12:00:00'); // elapse the actual 1 night before checkout
        app(StayService::class)->checkOut($stay, null, true);

        $result = $this->projection->project($booking->fresh());
        $postedTotal = app(FolioService::class)->getFolioTotal($booking->fresh());

        // Both happen to equal the same real cost here — the point is they are
        // independently computed, not that checkout mutates one into the other.
        $this->assertSame((float) self::ROOM_PRICE, $result['expected_total']);
        $this->assertSame((float) self::ROOM_PRICE, $postedTotal);
    }

    // 9. Valid deposit/payment is deducted correctly.
    public function test_valid_deposit_is_deducted_from_expected_balance(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 300000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $result = $this->projection->project($booking->fresh());

        $this->assertSame(300000.0, $result['expected_deposit']);
        $this->assertSame((float) self::ROOM_PRICE - 300000.0, $result['expected_balance']);
    }

    // 10. A refund reduces the deduction (matches the existing paymentSummary() domain rule).
    public function test_refund_is_not_treated_as_a_positive_deduction(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => self::ROOM_PRICE,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(BookingPaymentService::class)->addRefund($booking, [
            'amount' => 100000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $result = $this->projection->project($booking->fresh());

        // Refund subtracts from paid_total, so expected_balance rises back up by that amount.
        $this->assertSame(100000.0, $result['expected_balance']);
    }

    // Payment Reconciliation Review follow-up: recognized_paid_total is exactly
    // paymentSummary()['paid_total'], reused verbatim, never re-derived.
    public function test_recognized_paid_total_equals_payment_summary_paid_total(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 300000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $result = $this->projection->project($booking->fresh());
        $paymentSummary = app(BookingService::class)->paymentSummary($booking->fresh());

        $this->assertSame($paymentSummary['paid_total'], $result['recognized_paid_total']);
    }

    // The core reconciliation invariant this task fixes: expected_balance is
    // exactly expected_total minus recognized_paid_total — nothing else.
    public function test_expected_balance_equals_expected_total_minus_recognized_paid_total(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 200000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(BookingPaymentService::class)->addPayment($booking, [
            'payment_type' => 'ROOM_PAYMENT',
            'amount' => 150000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $result = $this->projection->project($booking->fresh());

        $this->assertSame(
            $result['expected_total'] - $result['recognized_paid_total'],
            $result['expected_balance'],
        );
    }

    // Deposit + AdditionalDeposit both flow into recognized_paid_total.
    public function test_deposit_and_additional_deposit_counted_in_recognized_paid_total(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 200000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(BookingPaymentService::class)->addPayment($booking, [
            'payment_type' => 'ADDITIONAL_DEPOSIT',
            'amount' => 100000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $result = $this->projection->project($booking->fresh());

        $this->assertSame(300000.0, $result['recognized_paid_total']);
    }

    // A RoomPayment/ServicePayment is counted in recognized_paid_total.
    public function test_payment_counted_in_recognized_paid_total(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);

        app(BookingPaymentService::class)->addPayment($booking, [
            'payment_type' => 'ROOM_PAYMENT',
            'amount' => 250000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $result = $this->projection->project($booking->fresh());

        $this->assertSame(250000.0, $result['recognized_paid_total']);
    }

    // An Adjustment is counted in recognized_paid_total.
    public function test_adjustment_counted_in_recognized_paid_total(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);

        app(BookingPaymentService::class)->addPayment($booking, [
            'payment_type' => 'ADJUSTMENT',
            'amount' => 50000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $result = $this->projection->project($booking->fresh());

        $this->assertSame(50000.0, $result['recognized_paid_total']);
    }

    // A refund reduces recognized_paid_total per the existing paymentSummary() rule.
    public function test_refund_reduces_recognized_paid_total(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 300000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(BookingPaymentService::class)->addRefund($booking, [
            'amount' => 100000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $result = $this->projection->project($booking->fresh());

        $this->assertSame(200000.0, $result['recognized_paid_total']);
    }

    // expected_deposit remains only the deposit subset — it must NOT equal
    // recognized_paid_total once a non-deposit payment exists, proving the two
    // fields are genuinely distinct (the exact bug this task fixes).
    public function test_expected_deposit_remains_only_the_deposit_subset(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 200000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(BookingPaymentService::class)->addPayment($booking, [
            'payment_type' => 'ROOM_PAYMENT',
            'amount' => 150000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $result = $this->projection->project($booking->fresh());

        $this->assertSame(200000.0, $result['expected_deposit']);
        $this->assertSame(350000.0, $result['recognized_paid_total']);
        $this->assertNotSame($result['expected_deposit'], $result['recognized_paid_total']);
    }

    // 11. Projection never creates a FolioEntry.
    public function test_projection_creates_no_folio_entry(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);
        $countBefore = \App\Models\FolioEntry::count();

        $this->projection->project($booking->fresh());

        $this->assertSame($countBefore, \App\Models\FolioEntry::count());
    }

    // 12. Projection never creates a Payment.
    public function test_projection_creates_no_payment(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);
        $countBefore = \App\Models\BookingPayment::count();

        $this->projection->project($booking->fresh());

        $this->assertSame($countBefore, \App\Models\BookingPayment::count());
    }

    // 13. Projection never creates a Night Audit posting/run/log.
    public function test_projection_creates_no_night_audit_records(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);
        $runsBefore = NightAuditRun::count();
        $logsBefore = NightAuditBookingLog::count();

        $this->projection->project($booking->fresh());

        $this->assertSame($runsBefore, NightAuditRun::count());
        $this->assertSame($logsBefore, NightAuditBookingLog::count());
    }

    // 14. Projection never creates a child Booking.
    public function test_projection_creates_no_child_booking(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);
        $countBefore = Booking::count();

        $this->projection->project($booking->fresh());

        $this->assertSame($countBefore, Booking::count());
    }

    // 18. Manual rate override is reflected correctly.
    public function test_manual_rate_override_is_reflected(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking([
            'requirements' => [[
                'room_type_id' => $this->twinType->id,
                'quantity' => 1,
                'adults' => 2,
                'children_under_6' => 0,
                'children_over_6' => 0,
                'room_price' => 1234567,
                'price_source' => 'MANUAL',
            ]],
        ]);
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        $result = $this->projection->project($booking->fresh());

        $this->assertSame(1234567.0, $result['expected_total']);
    }

    // 19. Posted non-room extra charges are counted once, never doubled with the room total.
    public function test_posted_extra_charge_counted_once_not_doubled(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 1);
        $folio = $booking->fresh()->folio()->firstOrFail();

        app(FolioService::class)->addCharge($folio, [
            'charge_type' => 'OTHER',
            'description' => 'Extra towel service',
            'quantity' => 1,
            'unit_price' => 50000,
        ]);

        $result = $this->projection->project($booking->fresh());

        $this->assertSame((float) self::ROOM_PRICE + 50000.0, $result['expected_total']);
        $this->assertSame((float) self::ROOM_PRICE, $result['projected_room_total']);
        $this->assertSame(50000.0, $result['posted_non_room_total']);
        // Backward-compat: calculation_context still carries the same values.
        $this->assertSame((float) self::ROOM_PRICE, $result['calculation_context']['room_total']);
        $this->assertSame(50000.0, $result['calculation_context']['posted_non_room_total']);
    }

    // Product Semantics Review follow-up: expected_total is exactly the sum of the
    // two breakdown components — no hidden third term, no formula drift.
    public function test_expected_total_equals_sum_of_breakdown_components(): void
    {
        [$booking] = $this->bookingWithCheckedInStay(nights: 2);
        $folio = $booking->fresh()->folio()->firstOrFail();

        app(FolioService::class)->addCharge($folio, [
            'charge_type' => 'FOOD_BEVERAGE',
            'description' => 'Breakfast (posted)',
            'quantity' => 1,
            'unit_price' => 75000,
        ]);

        $result = $this->projection->project($booking->fresh());

        $this->assertSame(
            $result['projected_room_total'] + $result['posted_non_room_total'],
            $result['expected_total'],
        );
    }

    // Product Semantics Review follow-up: Stay Extension still increases the room
    // breakdown component specifically (not just the combined total).
    public function test_stay_extension_increases_projected_room_total_specifically(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay(nights: 1);

        $before = $this->projection->project($booking->fresh());
        $this->assertSame((float) self::ROOM_PRICE, $before['projected_room_total']);

        app(StayService::class)->extendStay($stay, '2026-07-15 12:00:00', $this->admin); // +2 nights

        $after = $this->projection->project($booking->fresh());
        $this->assertSame((float) (self::ROOM_PRICE * 3), $after['projected_room_total']);
    }

    /**
     * @return array{0: Booking, 1: \App\Models\Stay}
     */
    private function bookingWithCheckedInStay(int $nights): array
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $checkout = now()->copy()->addDays($nights)->setTime(12, 0);

        $booking = $this->createBooking([
            'checkout_at' => $checkout->toDateTimeString(),
        ]);

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => $checkout->toDateTimeString()],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        return [$booking, $stay];
    }

    /**
     * @return array{0: Booking, 1: array<int, \App\Models\Stay>}
     */
    private function threeRoomCheckedInBooking(): array
    {
        $booking = $this->createBooking([
            'requirements' => [[
                'room_type_id' => $this->twinType->id,
                'quantity' => 3,
                'adults' => 2,
                'children_under_6' => 0,
                'children_over_6' => 0,
                'room_price' => self::ROOM_PRICE,
                'price_source' => 'MANUAL',
            ]],
        ]);
        $rooms = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(3)->get();

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $rooms[0]->id, 'room_type_id' => $rooms[0]->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
            ['room_id' => $rooms[1]->id, 'room_type_id' => $rooms[1]->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
            ['room_id' => $rooms[2]->id, 'room_type_id' => $rooms[2]->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);

        $stays = array_map(
            fn ($assignment) => app(StayService::class)->createStayFromAssignment($assignment),
            $assignments,
        );

        foreach ($stays as $stay) {
            app(StayService::class)->checkIn($stay);
        }

        return [$booking, $stays];
    }

    private function createBooking(array $overrides = []): Booking
    {
        $payload = array_merge([
            'booking_color' => '#196251',
            'customer_name' => 'Payment Projection Guest',
            'customer_phone' => '0900000008',
            'customer_email' => 'payment-projection@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-12 14:00:00',
            'checkout_at' => '2026-07-13 12:00:00',
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
        ], $overrides);

        if (! isset($payload['requirements'])) {
            $payload['requirements'] = [
                [
                    'room_type_id' => $this->twinType->id,
                    'quantity' => 1,
                    'adults' => 2,
                    'children_under_6' => 0,
                    'children_over_6' => 0,
                    'room_price' => self::ROOM_PRICE,
                    'price_source' => 'MANUAL',
                ],
            ];
        }

        return app(BookingService::class)->createBooking($payload);
    }
}
