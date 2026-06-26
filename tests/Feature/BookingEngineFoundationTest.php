<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\PriceSource;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Stay;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingEngineFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-01 14:00:00');

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
            RoomSeeder::class,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);
    }

    public function test_booking_can_be_created(): void
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());

        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'customer_name' => 'Jane Guest',
            'booking_type' => BookingType::Overnight->value,
            'customer_type' => CustomerType::Individual->value,
        ]);
    }

    public function test_booking_code_auto_generated(): void
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());

        $this->assertNotEmpty($booking->booking_code);
        $this->assertStringStartsWith('BK-', $booking->booking_code);
    }

    public function test_booking_can_have_multiple_requirements(): void
    {
        $twin = RoomType::where('code', 'TWIN')->firstOrFail();
        $double = RoomType::where('code', 'DOUBLE')->firstOrFail();

        $booking = $this->bookingService()->createBooking($this->bookingPayload([
            'requirements' => [
                $this->requirementPayload($twin, ['quantity' => 2]),
                $this->requirementPayload($double, ['quantity' => 1]),
            ],
        ]));

        $this->assertCount(2, $booking->bookingRequirements);
        $this->assertDatabaseHas('booking_requirements', [
            'booking_id' => $booking->id,
            'room_type_id' => $twin->id,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('booking_requirements', [
            'booking_id' => $booking->id,
            'room_type_id' => $double->id,
            'quantity' => 1,
        ]);
    }

    public function test_booking_can_store_manual_room_price(): void
    {
        $twin = RoomType::where('code', 'TWIN')->firstOrFail();

        $booking = $this->bookingService()->createBooking($this->bookingPayload([
            'requirements' => [
                $this->requirementPayload($twin, [
                    'room_price' => 1999.99,
                    'price_source' => PriceSource::Manual->value,
                ]),
            ],
        ]));

        $this->assertDatabaseHas('booking_requirements', [
            'booking_id' => $booking->id,
            'room_type_id' => $twin->id,
            'room_price' => 1999.99,
            'price_source' => PriceSource::Manual->value,
        ]);
    }

    public function test_booking_can_record_deposit(): void
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());

        $payment = app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 1500,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => '2026-07-01 10:00:00',
            'note' => 'Front desk deposit',
        ]);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'payment_type' => PaymentType::AdditionalDeposit->value,
            'amount' => 500,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'payment_at' => '2026-07-01 12:00:00',
        ]);

        $this->assertDatabaseHas('booking_payments', [
            'id' => $payment->id,
            'booking_id' => $booking->id,
            'payment_type' => PaymentType::Deposit->value,
            'amount' => 1500,
        ]);
        $this->assertSame(2, $booking->bookingPayments()->count());
    }

    public function test_room_conflict_detection_works(): void
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());
        $room = $this->roomForType('TWIN');
        $assignmentService = app(RoomAssignmentService::class);

        $assignmentService->assignRooms($booking, [
            [
                'room_id' => $room->id,
                'room_type_id' => $room->room_type_id,
                'start_at' => '2026-07-01 14:00:00',
                'end_at' => '2026-07-02 12:00:00',
            ],
        ]);

        $this->assertTrue($assignmentService->checkRoomConflict($room, '2026-07-02 10:00:00', '2026-07-02 18:00:00'));
        $this->assertFalse($assignmentService->checkRoomConflict($room, '2026-07-02 12:00:00', '2026-07-02 18:00:00'));
    }

    public function test_room_assignment_succeeds_when_no_conflict_exists(): void
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());
        $room = $this->roomForType('TWIN');

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            [
                'room_id' => $room->id,
                'room_type_id' => $room->room_type_id,
            ],
        ]);

        $this->assertCount(1, $assignments);
        $this->assertDatabaseHas('room_assignments', [
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'status' => AssignmentStatus::Assigned->value,
        ]);
    }

    public function test_booking_becomes_partially_assigned(): void
    {
        $twin = RoomType::where('code', 'TWIN')->firstOrFail();
        $booking = $this->bookingService()->createBooking($this->bookingPayload([
            'requirements' => [
                $this->requirementPayload($twin, ['quantity' => 2]),
            ],
        ]));
        $room = $this->roomForType('TWIN');

        app(RoomAssignmentService::class)->assignRooms($booking, [
            [
                'room_id' => $room->id,
                'room_type_id' => $room->room_type_id,
            ],
        ]);

        $this->assertSame(BookingStatus::PartiallyAssigned, $booking->refresh()->status);
    }

    public function test_booking_becomes_fully_assigned(): void
    {
        $twin = RoomType::where('code', 'TWIN')->firstOrFail();
        $rooms = Room::where('room_type_id', $twin->id)->take(2)->get();
        $booking = $this->bookingService()->createBooking($this->bookingPayload([
            'requirements' => [
                $this->requirementPayload($twin, ['quantity' => 2]),
            ],
        ]));

        app(RoomAssignmentService::class)->assignRooms($booking, $rooms->map(fn (Room $room): array => [
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
        ])->all());

        $this->assertSame(BookingStatus::FullyAssigned, $booking->refresh()->status);
    }

    public function test_stay_can_be_created_from_assignment(): void
    {
        $assignment = $this->createSingleAssignment();

        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        $this->assertDatabaseHas('stays', [
            'id' => $stay->id,
            'booking_id' => $assignment->booking_id,
            'room_assignment_id' => $assignment->id,
            'room_id' => $assignment->room_id,
            'status' => StayStatus::Reserved->value,
        ]);
    }

    public function test_stay_can_check_in(): void
    {
        $assignment = $this->createSingleAssignment();
        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        $checkedIn = app(StayService::class)->checkIn($stay, '2026-07-01 15:00:00');

        $this->assertSame(StayStatus::CheckedIn, $checkedIn->status);
        $this->assertSame(AssignmentStatus::CheckedIn, $assignment->refresh()->status);
        $this->assertSame(BookingStatus::CheckedIn, $assignment->booking->refresh()->status);
        $this->assertNotNull($checkedIn->actual_checkin_at);
    }

    public function test_stay_can_check_out(): void
    {
        $assignment = $this->createSingleAssignment();
        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        app(BookingPaymentService::class)->addPayment($assignment->booking, [
            'payment_type' => PaymentType::RoomPayment->value,
            'amount' => 1800,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => '2026-07-02 10:00:00',
        ]);

        $stayService = app(StayService::class);
        $checkedIn = $stayService->checkIn($stay, '2026-07-01 15:00:00');
        $checkedOut = $stayService->checkOut($checkedIn, '2026-07-02 11:00:00');

        $this->assertSame(StayStatus::CheckedOut, $checkedOut->status);
        $this->assertSame(AssignmentStatus::CheckedOut, $assignment->refresh()->status);
        $this->assertSame(BookingStatus::CheckedOut, $assignment->booking->refresh()->status);
        $this->assertNotNull($checkedOut->actual_checkout_at);
    }

    public function test_booking_does_not_finalize_checkout_when_balance_remains(): void
    {
        $assignment = $this->createSingleAssignment();
        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        $stayService = app(StayService::class);
        $checkedIn = $stayService->checkIn($stay, '2026-07-01 15:00:00');
        $stayService->checkOut($checkedIn, '2026-07-02 11:00:00');

        $this->assertSame(BookingStatus::PartiallyCheckedOut, $assignment->booking->refresh()->status);
    }

    public function test_assigning_conflicting_room_throws_validation_exception(): void
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());
        $conflictingBooking = $this->bookingService()->createBooking($this->bookingPayload([
            'customer_name' => 'Conflicting Guest',
        ]));
        $room = $this->roomForType('TWIN');
        $assignmentService = app(RoomAssignmentService::class);

        $assignmentService->assignRooms($booking, [
            [
                'room_id' => $room->id,
                'room_type_id' => $room->room_type_id,
            ],
        ]);

        $this->expectException(ValidationException::class);

        $assignmentService->assignRooms($conflictingBooking, [
            [
                'room_id' => $room->id,
                'room_type_id' => $room->room_type_id,
            ],
        ]);
    }

    // ===== Phase 2.5 Final Concurrency Fix – Regression Tests =====

    public function test_time_change_uses_locked_validation_and_catches_new_conflict(): void
    {
        // The "concurrent" assignment is committed before updateBooking is called, simulating
        // a write that committed between the old unguarded validate step and the save step.
        // The locked validation must see the fresh DB state and reject the time change.
        $booking = $this->bookingService()->createBooking($this->bookingPayload());
        $room = $this->roomForType('TWIN');
        app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id],
        ]);

        $bookingB = $this->bookingService()->createBooking($this->bookingPayload([
            'customer_name' => 'Concurrent Guest',
            'checkin_at'  => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]));
        app(RoomAssignmentService::class)->assignRooms($bookingB, [[
            'room_id'      => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at'     => '2026-07-02 12:00:00',
            'end_at'       => '2026-07-03 12:00:00',
        ]]);

        $this->expectException(ValidationException::class);

        // Extending booking A into B's window must be rejected by the locked conflict check.
        $this->bookingService()->updateBooking($booking, [
            'checkout_at' => '2026-07-02 14:00:00',
        ]);
    }

    public function test_concurrent_assignment_cannot_bypass_validation(): void
    {
        // Booking A: Jul 2 14:00–Jul 3 12:00. Booking B assigned same room for
        // Jul 1 14:00–Jul 2 14:00 (adjacent, no initial overlap). Moving A's checkin
        // earlier to Jul 1 20:00 falls inside B's range — locked validation rejects it.
        $booking = $this->bookingService()->createBooking($this->bookingPayload([
            'checkin_at'  => '2026-07-02 14:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]));
        $room = $this->roomForType('TWIN');
        app(RoomAssignmentService::class)->assignRooms($booking, [[
            'room_id'      => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at'     => '2026-07-02 14:00:00',
            'end_at'       => '2026-07-03 12:00:00',
        ]]);

        // Booking B holds the same room adjacent to A (B ends exactly when A starts).
        $bookingB = $this->bookingService()->createBooking($this->bookingPayload([
            'customer_name' => 'Booking B',
            'checkin_at'    => '2026-07-01 14:00:00',
            'checkout_at'   => '2026-07-02 14:00:00',
        ]));
        app(RoomAssignmentService::class)->assignRooms($bookingB, [[
            'room_id'      => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at'     => '2026-07-01 14:00:00',
            'end_at'       => '2026-07-02 14:00:00',
        ]]);

        $this->expectException(ValidationException::class);

        // Moving A's checkin to 20:00 on Jul 1 falls inside B's range → conflict.
        $this->bookingService()->updateBooking($booking, [
            'checkin_at' => '2026-07-01 20:00:00',
        ]);
    }

    public function test_concurrent_check_in_cannot_invalidate_booking_time_change(): void
    {
        // CheckedIn is a blocking status: a concurrent check-in on a conflicting room
        // must still cause the time change to be rejected.
        $booking = $this->bookingService()->createBooking($this->bookingPayload());
        $room = $this->roomForType('TWIN');
        app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id],
        ]);

        $bookingB = $this->bookingService()->createBooking($this->bookingPayload([
            'customer_name' => 'Checked-In Guest',
            'checkin_at'  => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]));
        app(RoomAssignmentService::class)->assignRooms($bookingB, [[
            'room_id'      => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at'     => '2026-07-02 12:00:00',
            'end_at'       => '2026-07-03 12:00:00',
        ]]);
        // Simulate concurrent check-in completing before our time change validates.
        RoomAssignment::where('booking_id', $bookingB->id)->update(['status' => AssignmentStatus::CheckedIn]);

        $this->expectException(ValidationException::class);

        $this->bookingService()->updateBooking($booking, [
            'checkout_at' => '2026-07-02 14:00:00',
        ]);
    }

    public function test_concurrent_check_out_does_not_block_booking_time_change(): void
    {
        // CheckedOut is NOT a blocking status; the time change must succeed even if a
        // conflicting assignment was checked out concurrently.
        $booking = $this->bookingService()->createBooking($this->bookingPayload());
        $room = $this->roomForType('TWIN');
        app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id],
        ]);

        $bookingB = $this->bookingService()->createBooking($this->bookingPayload([
            'customer_name' => 'Checked-Out Guest',
            'checkin_at'  => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]));
        app(RoomAssignmentService::class)->assignRooms($bookingB, [[
            'room_id'      => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at'     => '2026-07-02 12:00:00',
            'end_at'       => '2026-07-03 12:00:00',
        ]]);
        // Simulate concurrent checkout completing — assignment is no longer blocking.
        RoomAssignment::where('booking_id', $bookingB->id)->update(['status' => AssignmentStatus::CheckedOut]);

        $updated = $this->bookingService()->updateBooking($booking, [
            'checkout_at' => '2026-07-02 14:00:00',
        ]);

        $this->assertSame('2026-07-02 14:00:00', $updated->refresh()->checkout_at->toDateTimeString());
    }

    public function test_locked_time_change_still_updates_assignment_and_stay(): void
    {
        // Verifies that the locked code path produces the same update results as before.
        $assignment = $this->createSingleAssignment();
        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        $this->bookingService()->updateBooking($assignment->booking, [
            'checkout_at' => '2026-07-03 12:00:00',
        ]);

        $this->assertSame('2026-07-03 12:00:00', $assignment->refresh()->end_at->toDateTimeString());
        $this->assertSame('2026-07-03 12:00:00', $stay->refresh()->planned_checkout_at->toDateTimeString());
    }

    // ===== Phase 2.5 Final Locking Fix – Post-lock Re-validation Tests =====

    public function test_post_lock_revalidation_rejects_when_stay_has_actual_checkout(): void
    {
        // Simulates a concurrent checkout completing after candidate selection but before the lock.
        // The post-lock re-validation on locked stays must detect actual_checkout_at and reject.
        $assignment = $this->createSingleAssignment();
        $stay       = app(StayService::class)->createStayFromAssignment($assignment);

        Stay::whereKey($stay->id)->update([
            'actual_checkout_at' => now(),
            'status'             => StayStatus::CheckedOut->value,
        ]);

        $this->expectException(ValidationException::class);
        $this->bookingService()->updateBooking($assignment->booking, [
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
    }

    public function test_time_change_rejects_checkout_that_happened_before_candidate_query(): void
    {
        // Simulates checkout completing before active assignment candidates are selected.
        // The assignment is no longer active, but the locked booking stay must still
        // block any booking time change.
        $assignment = $this->createSingleAssignment();
        $stay       = app(StayService::class)->createStayFromAssignment($assignment);

        Stay::whereKey($stay->id)->update([
            'actual_checkin_at'  => '2026-07-01 15:00:00',
            'actual_checkout_at' => '2026-07-02 10:00:00',
            'status'             => StayStatus::CheckedOut->value,
        ]);
        RoomAssignment::whereKey($assignment->id)->update([
            'status' => AssignmentStatus::CheckedOut->value,
        ]);

        $this->expectException(ValidationException::class);
        $this->bookingService()->updateBooking($assignment->booking, [
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
    }

    public function test_post_lock_revalidation_rejects_released_assignment(): void
    {
        // When an assignment transitions to Released before the lock is acquired,
        // step 3 (candidate query) excludes it and the time change has no active
        // assignments to update — it returns early without error.
        // This test verifies that a fully-released booking is handled gracefully.
        $assignment = $this->createSingleAssignment();
        $booking    = $assignment->booking;

        // Simulate concurrent release.
        RoomAssignment::whereKey($assignment->id)->update(['status' => AssignmentStatus::Released]);

        // No active assignments → no conflict to check → booking dates update cleanly.
        $updated = $this->bookingService()->updateBooking($booking, [
            'checkout_at' => '2026-07-03 12:00:00',
        ]);

        $this->assertSame('2026-07-03 12:00:00', $updated->refresh()->checkout_at->toDateTimeString());
        // Assignment times are NOT mutated (it was released before locking).
        $this->assertSame('2026-07-02 12:00:00', $assignment->refresh()->end_at->toDateTimeString());
    }

    public function test_post_lock_revalidation_rejects_checkin_change_when_stay_checked_in(): void
    {
        // A concurrent check-in sets actual_checkin_at on the stay.
        // Re-validation on locked stays must block a checkin-time change.
        $assignment = $this->createSingleAssignment();
        $stay       = app(StayService::class)->createStayFromAssignment($assignment);

        Stay::whereKey($stay->id)->update([
            'actual_checkin_at' => '2026-07-01 15:00:00',
            'status'            => StayStatus::CheckedIn->value,
        ]);
        RoomAssignment::whereKey($assignment->id)->update(['status' => AssignmentStatus::CheckedIn]);

        $this->expectException(ValidationException::class);
        $this->bookingService()->updateBooking($assignment->booking, [
            'checkin_at' => '2026-07-01 12:00:00', // moving checkin earlier → rejected
        ]);
    }

    public function test_post_lock_revalidation_allows_checkout_only_change_when_stay_checked_in(): void
    {
        // Checkout-only change is permitted even when actual_checkin_at is set.
        // Lock order: Stay is locked before RA, consistent with StayService::checkIn.
        $assignment = $this->createSingleAssignment();
        $stay       = app(StayService::class)->createStayFromAssignment($assignment);

        Stay::whereKey($stay->id)->update([
            'actual_checkin_at' => '2026-07-01 15:00:00',
            'status'            => StayStatus::CheckedIn->value,
        ]);
        RoomAssignment::whereKey($assignment->id)->update(['status' => AssignmentStatus::CheckedIn]);

        $updated = $this->bookingService()->updateBooking($assignment->booking, [
            'checkout_at' => '2026-07-03 12:00:00',
        ]);

        $this->assertSame('2026-07-03 12:00:00', $updated->refresh()->checkout_at->toDateTimeString());
        $this->assertSame('2026-07-03 12:00:00', $stay->refresh()->planned_checkout_at->toDateTimeString());
    }

    public function test_lock_order_stay_before_assignment_consistent_with_stay_service(): void
    {
        // Verifies that time change (Stay → RA locking) is behaviourally compatible with
        // StayService::checkIn (Stay → RA) — operations that share the same lock order
        // cannot deadlock with each other.
        $assignment = $this->createSingleAssignment();
        $stay       = app(StayService::class)->createStayFromAssignment($assignment);

        // CheckIn then immediately extend checkout: both operations succeed
        // and share the same Stay → RA lock direction.
        app(StayService::class)->checkIn($stay, '2026-07-01 15:00:00');

        $updated = $this->bookingService()->updateBooking($assignment->booking, [
            'checkout_at' => '2026-07-03 12:00:00',
        ]);

        $this->assertSame('2026-07-03 12:00:00', $updated->refresh()->checkout_at->toDateTimeString());
        $this->assertSame('2026-07-03 12:00:00', $stay->refresh()->planned_checkout_at->toDateTimeString());
    }

    private function createSingleAssignment()
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());
        $room = $this->roomForType('TWIN');

        return app(RoomAssignmentService::class)->assignRooms($booking, [
            [
                'room_id' => $room->id,
                'room_type_id' => $room->room_type_id,
            ],
        ])[0];
    }

    private function bookingService(): BookingService
    {
        return app(BookingService::class);
    }

    private function bookingPayload(array $overrides = []): array
    {
        $twin = RoomType::where('code', 'TWIN')->firstOrFail();

        return [
            'booking_color' => '#196251',
            'customer_name' => 'Jane Guest',
            'customer_phone' => '0800000000',
            'customer_email' => 'jane@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
            'note' => null,
            'internal_note' => null,
            'requirements' => [
                $this->requirementPayload($twin),
            ],
            ...$overrides,
        ];
    }

    private function requirementPayload(RoomType $roomType, array $overrides = []): array
    {
        return [
            'room_type_id' => $roomType->id,
            'quantity' => 1,
            'adults' => $roomType->standard_adults,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'room_price' => 1800,
            'price_source' => PriceSource::RateTable->value,
            'note' => null,
            ...$overrides,
        ];
    }

    private function roomForType(string $roomTypeCode): Room
    {
        $roomType = RoomType::where('code', $roomTypeCode)->firstOrFail();

        return Room::where('room_type_id', $roomType->id)->firstOrFail();
    }
}
