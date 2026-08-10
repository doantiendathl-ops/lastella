<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\StayEventType;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomSwapBatch;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PaymentProjectionService;
use App\Services\RoomAssignmentService;
use App\Services\RoomSwapService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Daily Room Operations Board — ĐỔI PHÒNG (Room Swap) engine core tests.
 * Mirrors StayServiceMoveRoomTest's setup convention (real seeded room map,
 * room 104/201 are both TWIN — the exact rooms the spec's own examples use).
 */
class RoomSwapServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM_PRICE = 800000;

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

        $this->travelTo('2026-08-01 10:00:00');

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_move_source_to_empty_target_room_for_whole_stay(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        [$booking, $assignment] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-04 12:00:00');

        $result = app(RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignment->id, 'target_room_id' => $room201->id]],
            $this->admin,
            warningsAcknowledged: false,
        );

        $this->assertArrayHasKey('batch_id', $result);
        $this->assertSame(AssignmentStatus::Released, $assignment->fresh()->status);

        $newAssignment = RoomAssignment::where('booking_id', $booking->id)
            ->where('status', AssignmentStatus::Assigned)->firstOrFail();
        $this->assertSame($room201->id, $newAssignment->room_id);
        $this->assertSame('2026-08-01 14:00:00', $newAssignment->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-04 12:00:00', $newAssignment->end_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->twinType->id, $newAssignment->room_type_id);
    }

    public function test_binary_swap_same_date_range(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        [$bookingA, $assignmentA] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'guest-a@example.test');
        [$bookingB, $assignmentB] = $this->reservedAssignment($room201, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'guest-b@example.test');

        app(RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignmentA->id, 'target_room_id' => $room201->id]],
            $this->admin,
            warningsAcknowledged: true,
        );

        $newA = RoomAssignment::where('booking_id', $bookingA->id)->where('status', AssignmentStatus::Assigned)->firstOrFail();
        $newB = RoomAssignment::where('booking_id', $bookingB->id)->where('status', AssignmentStatus::Assigned)->firstOrFail();

        $this->assertSame($room201->id, $newA->room_id);
        $this->assertSame($room104->id, $newB->room_id);
        $this->assertSame(AssignmentStatus::Released, $assignmentA->fresh()->status);
        $this->assertSame(AssignmentStatus::Released, $assignmentB->fresh()->status);
    }

    /**
     * Quick Note Lifecycle addendum Mục E: on an A↔B swap, each booking's
     * quick note follows ITS OWN booking/assignment to the new room — never
     * left behind on the physical room, never swapped with the other party's
     * note.
     */
    public function test_binary_swap_each_quick_note_follows_its_own_booking(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        [$bookingA, $assignmentA] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'guest-a@example.test');
        [$bookingB, $assignmentB] = $this->reservedAssignment($room201, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'guest-b@example.test');
        $assignmentA->update(['quick_note' => 'Khách cần phòng yên tĩnh']);
        $assignmentB->update(['quick_note' => 'Khách cần giường phụ']);

        app(RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignmentA->id, 'target_room_id' => $room201->id]],
            $this->admin,
            warningsAcknowledged: true,
        );

        $newA = RoomAssignment::where('booking_id', $bookingA->id)->where('status', AssignmentStatus::Assigned)->firstOrFail();
        $newB = RoomAssignment::where('booking_id', $bookingB->id)->where('status', AssignmentStatus::Assigned)->firstOrFail();

        $this->assertSame($room201->id, $newA->room_id);
        $this->assertSame('Khách cần phòng yên tĩnh', $newA->quick_note);
        $this->assertSame($room104->id, $newB->room_id);
        $this->assertSame('Khách cần giường phụ', $newB->quick_note);
    }

    /**
     * Mục XI/XIII: target room has TWO different bookings across A's 3-day
     * stay — B on day 1 only, C on days 2-3. Each is a binary swap per its
     * own overlapping sub-range.
     */
    public function test_multiday_target_with_two_different_bookings_splits_correctly(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();

        [$bookingA, $assignmentA] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-04 12:00:00', 'guest-a@example.test');
        [$bookingB, $assignmentB] = $this->reservedAssignment($room201, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'guest-b@example.test');
        [$bookingC, $assignmentC] = $this->reservedAssignment($room201, '2026-08-02 12:00:00', '2026-08-04 12:00:00', 'guest-c@example.test');
        $assignmentA->update(['quick_note' => 'Ghi chú A']);
        $assignmentB->update(['quick_note' => 'Ghi chú B']);
        $assignmentC->update(['quick_note' => 'Ghi chú C']);

        app(RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignmentA->id, 'target_room_id' => $room201->id]],
            $this->admin,
            warningsAcknowledged: true,
        );

        $newA = RoomAssignment::where('booking_id', $bookingA->id)->where('status', AssignmentStatus::Assigned)->firstOrFail();
        $this->assertSame($room201->id, $newA->room_id);
        $this->assertSame('2026-08-01 14:00:00', $newA->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-04 12:00:00', $newA->end_at->format('Y-m-d H:i:s'));

        $newB = RoomAssignment::where('booking_id', $bookingB->id)->where('status', AssignmentStatus::Assigned)->firstOrFail();
        $this->assertSame($room104->id, $newB->room_id);
        $this->assertSame('2026-08-01 14:00:00', $newB->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-02 12:00:00', $newB->end_at->format('Y-m-d H:i:s'));

        $newC = RoomAssignment::where('booking_id', $bookingC->id)->where('status', AssignmentStatus::Assigned)->firstOrFail();
        $this->assertSame($room104->id, $newC->room_id);
        $this->assertSame('2026-08-02 12:00:00', $newC->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-04 12:00:00', $newC->end_at->format('Y-m-d H:i:s'));

        // Quick Note Lifecycle addendum Mục F: each booking's own note stays
        // attached to ITS segment — never overwritten by the physical room's
        // prior occupant note.
        $this->assertSame('Ghi chú A', $newA->quick_note);
        $this->assertSame('Ghi chú B', $newB->quick_note);
        $this->assertSame('Ghi chú C', $newC->quick_note);
    }

    /**
     * Only the OVERLAPPING sub-range of a displaced stay moves — the
     * remainder before/after stays in the target room, split into its own
     * new assignment segments.
     */
    public function test_partial_overlap_leaves_remainder_segments_in_target_room(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();

        [$bookingD] = $this->reservedAssignment($room201, '2026-08-01 14:00:00', '2026-08-05 12:00:00', 'guest-d@example.test');
        [$bookingA, $assignmentA] = $this->reservedAssignment($room104, '2026-08-02 14:00:00', '2026-08-03 12:00:00', 'guest-a@example.test');

        app(RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignmentA->id, 'target_room_id' => $room201->id]],
            $this->admin,
            warningsAcknowledged: true,
        );

        $dAssignments = RoomAssignment::where('booking_id', $bookingD->id)
            ->where('status', AssignmentStatus::Assigned)->orderBy('start_at')->get();

        $this->assertCount(3, $dAssignments, 'D must be split into remain-before, swapped, remain-after.');

        $this->assertSame($room201->id, $dAssignments[0]->room_id);
        $this->assertSame('2026-08-01 14:00:00', $dAssignments[0]->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-02 14:00:00', $dAssignments[0]->end_at->format('Y-m-d H:i:s'));

        $this->assertSame($room104->id, $dAssignments[1]->room_id);
        $this->assertSame('2026-08-02 14:00:00', $dAssignments[1]->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-03 12:00:00', $dAssignments[1]->end_at->format('Y-m-d H:i:s'));

        $this->assertSame($room201->id, $dAssignments[2]->room_id);
        $this->assertSame('2026-08-03 12:00:00', $dAssignments[2]->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-05 12:00:00', $dAssignments[2]->end_at->format('Y-m-d H:i:s'));

        $newA = RoomAssignment::where('booking_id', $bookingA->id)->where('status', AssignmentStatus::Assigned)->firstOrFail();
        $this->assertSame($room201->id, $newA->room_id);
    }

    public function test_checked_in_source_is_hard_blocked(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        [, $assignment] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00');
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        app(StayService::class)->checkIn($stay);

        $this->expectException(ValidationException::class);
        app(RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignment->id, 'target_room_id' => $room201->id]],
            $this->admin,
            warningsAcknowledged: true,
        );
    }

    public function test_checked_in_target_is_hard_blocked(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        [, $assignmentA] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'guest-a@example.test');
        [, $assignmentB] = $this->reservedAssignment($room201, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'guest-b@example.test');
        $stayB = Stay::where('room_assignment_id', $assignmentB->id)->firstOrFail();
        app(StayService::class)->checkIn($stayB);

        try {
            app(RoomSwapService::class)->execute(
                [['source_assignment_id' => $assignmentA->id, 'target_room_id' => $room201->id]],
                $this->admin,
                warningsAcknowledged: true,
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException) {
            // No changes should have been written — whole batch rolled back.
            $this->assertSame(AssignmentStatus::Assigned, $assignmentA->fresh()->status);
            $this->assertSame(AssignmentStatus::CheckedIn, $assignmentB->fresh()->status);
        }
    }

    public function test_swap_to_same_room_is_rejected(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        [, $assignment] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00');

        $this->expectException(ValidationException::class);
        app(RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignment->id, 'target_room_id' => $room104->id]],
            $this->admin,
            warningsAcknowledged: true,
        );
    }

    public function test_quick_note_and_extra_bed_quantity_follow_the_booking_on_swap(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        [$booking, $assignment] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00');
        $assignment->update(['quick_note' => 'Khách cần phòng yên tĩnh', 'extra_bed_quantity' => 1]);

        app(RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignment->id, 'target_room_id' => $room201->id]],
            $this->admin,
            warningsAcknowledged: true,
        );

        $newAssignment = RoomAssignment::where('booking_id', $booking->id)->where('status', AssignmentStatus::Assigned)->firstOrFail();
        $this->assertSame('Khách cần phòng yên tĩnh', $newAssignment->quick_note);
        $this->assertSame(1, $newAssignment->extra_bed_quantity);
    }

    public function test_cleaning_status_stays_with_physical_room_not_the_booking(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        $room104->update(['cleaning_status' => 'DIRTY']);
        $room201->update(['cleaning_status' => 'CLEAN']);
        [, $assignment] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00');

        app(RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignment->id, 'target_room_id' => $room201->id]],
            $this->admin,
            warningsAcknowledged: true,
        );

        $this->assertSame('DIRTY', $room104->fresh()->cleaning_status->value);
        $this->assertSame('CLEAN', $room201->fresh()->cleaning_status->value);
    }

    public function test_booking_identity_and_folio_are_unchanged_after_swap(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        [$booking, $assignment] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00');
        $bookingCodeBefore = $booking->booking_code;
        $customerBefore = $booking->customer_name;
        $before = app(PaymentProjectionService::class)->project($booking->fresh());

        app(RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignment->id, 'target_room_id' => $room201->id]],
            $this->admin,
            warningsAcknowledged: true,
        );

        $fresh = $booking->fresh();
        $this->assertSame($bookingCodeBefore, $fresh->booking_code);
        $this->assertSame($customerBefore, $fresh->customer_name);
        $after = app(PaymentProjectionService::class)->project($fresh);
        $this->assertSame($before['expected_total'], $after['expected_total']);
    }

    public function test_swap_creates_audit_batch_linked_to_assignments(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        [, $assignment] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00');

        $result = app(RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignment->id, 'target_room_id' => $room201->id]],
            $this->admin,
            warningsAcknowledged: true,
        );

        $batch = RoomSwapBatch::findOrFail($result['batch_id']);
        $this->assertSame($this->admin->id, $batch->executed_by);
        $this->assertSame(AssignmentStatus::Released, $assignment->fresh()->status);
        $this->assertSame($batch->id, $assignment->fresh()->swap_batch_id);

        $newAssignment = RoomAssignment::where('room_id', $room201->id)->where('status', AssignmentStatus::Assigned)->firstOrFail();
        $this->assertSame($batch->id, $newAssignment->swap_batch_id);

        $newStay = Stay::where('room_assignment_id', $newAssignment->id)->firstOrFail();
        $this->assertTrue($newStay->stayEvents()->where('event_type', StayEventType::RoomMove)->exists());
    }

    public function test_atomic_batch_rolls_back_entirely_when_one_pair_fails(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        $room105 = Room::where('room_number', '105')->firstOrFail();
        $room302 = Room::where('room_number', '302')->firstOrFail();

        [, $assignmentA] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'guest-a@example.test');
        [, $assignmentE] = $this->reservedAssignment($room105, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'guest-e@example.test');
        [, $assignmentF] = $this->reservedAssignment($room302, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'guest-f@example.test');
        $stayF = Stay::where('room_assignment_id', $assignmentF->id)->firstOrFail();
        app(StayService::class)->checkIn($stayF); // makes the SECOND pair's target invalid

        try {
            app(RoomSwapService::class)->execute(
                [
                    ['source_assignment_id' => $assignmentA->id, 'target_room_id' => $room201->id],
                    ['source_assignment_id' => $assignmentE->id, 'target_room_id' => $room302->id],
                ],
                $this->admin,
                warningsAcknowledged: true,
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException) {
            // Pair 1 (A→201) must NOT have been committed either — all-or-nothing.
            $this->assertSame(AssignmentStatus::Assigned, $assignmentA->fresh()->status);
            $this->assertSame($room104->id, $assignmentA->fresh()->room_id);
            $this->assertSame(AssignmentStatus::Assigned, $assignmentE->fresh()->status);
        }
    }

    public function test_no_overlap_remains_after_swap(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        [, $assignmentA] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'guest-a@example.test');
        [, $assignmentB] = $this->reservedAssignment($room201, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'guest-b@example.test');

        app(RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignmentA->id, 'target_room_id' => $room201->id]],
            $this->admin,
            warningsAcknowledged: true,
        );

        $rules = app(\App\Services\RoomAvailabilityRuleService::class);
        // Both rooms should show exactly one active occupant for that night, no double-booking.
        $this->assertTrue($rules->hasConflict($room104->id, '2026-08-01 14:00:00', '2026-08-02 12:00:00'));
        $this->assertTrue($rules->hasConflict($room201->id, '2026-08-01 14:00:00', '2026-08-02 12:00:00'));
        $this->assertSame(1, RoomAssignment::where('room_id', $room104->id)->where('status', AssignmentStatus::Assigned)->count());
        $this->assertSame(1, RoomAssignment::where('room_id', $room201->id)->where('status', AssignmentStatus::Assigned)->count());
    }

    public function test_preview_never_writes_to_the_database(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        [, $assignment] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00');
        $countBefore = RoomAssignment::count();

        $result = app(RoomSwapService::class)->preview([
            ['source_assignment_id' => $assignment->id, 'target_room_id' => $room201->id],
        ]);

        $this->assertFalse($result['has_blockers']);
        $this->assertSame($countBefore, RoomAssignment::count());
        $this->assertSame(AssignmentStatus::Assigned, $assignment->fresh()->status);
    }

    public function test_different_room_type_produces_warning_not_a_blocker(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail(); // TWIN
        $room302 = Room::where('room_number', '302')->firstOrFail(); // DOUBLE
        [, $assignment] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00');

        $result = app(RoomSwapService::class)->preview([
            ['source_assignment_id' => $assignment->id, 'target_room_id' => $room302->id],
        ]);

        $this->assertSame([], $result['pairs'][0]['blockers']);
        $this->assertNotEmpty($result['pairs'][0]['warnings']);
    }

    /**
     * @return array{0: Booking, 1: RoomAssignment}
     */
    private function reservedAssignment(Room $room, string $startAt, string $endAt, string $email = 'guest@example.test'): array
    {
        $booking = $this->createBooking(['customer_email' => $email], $startAt, $endAt);

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => $startAt, 'end_at' => $endAt],
        ]);
        app(StayService::class)->createStayFromAssignment($assignment);

        return [$booking, $assignment];
    }

    private function createBooking(array $overrides, string $checkinAt, string $checkoutAt): Booking
    {
        $payload = array_merge([
            'booking_color' => '#196251',
            'customer_name' => 'Room Swap Guest',
            'customer_phone' => '0900000020',
            'customer_email' => 'room-swap@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => $checkinAt,
            'checkout_at' => $checkoutAt,
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
            'requirements' => [
                [
                    'room_type_id' => $this->twinType->id,
                    'quantity' => 1,
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
