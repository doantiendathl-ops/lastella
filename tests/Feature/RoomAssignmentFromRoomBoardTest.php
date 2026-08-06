<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\ChargeType;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\RoomAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Room Demand/Room Board Unification M3 — Room-Board-first reverse
 * synchronization (RoomAssignmentService::assignRoomsFromRoomBoard()).
 *
 * Following the same sequential-simulation convention as
 * RoomAssignmentAtomicMappingTest (M2) for lock-guarded "concurrency"
 * scenarios: a competing write is fully committed before the operation under
 * test runs.
 */
class RoomAssignmentFromRoomBoardTest extends TestCase
{
    use RefreshDatabase;

    private RoomAssignmentService $assignments;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->assignments = app(RoomAssignmentService::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);
    }

    private function makeBookingAndRoomType(): array
    {
        return [
            Booking::factory()->create(['status' => BookingStatus::PendingAssignment]),
            RoomType::factory()->create(),
        ];
    }

    private function group(array $overrides = []): array
    {
        return array_merge([
            'room_type_id' => null,
            'room_ids' => [],
            'target_requirement_id' => null,
            'room_price' => null,
            'price_source' => null,
            'note' => null,
            'adults' => null,
            'children_under_6' => null,
            'children_over_6' => null,
        ], $overrides);
    }

    // ── A. Reconciliation formula (Section VIII) ──────────────────────────

    public function test_selection_within_remaining_never_inflates_demand(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5,
        ]);
        RoomAssignment::factory()->for($booking)->count(2)
            ->state(fn () => ['room_id' => Room::factory()->for($roomType)->create()->id, 'room_type_id' => $roomType->id, 'booking_requirement_id' => $requirement->id, 'status' => AssignmentStatus::Assigned])
            ->create();
        $newRooms = Room::factory()->for($roomType)->count(2)->create();

        $result = $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group(['room_type_id' => $roomType->id, 'room_ids' => $newRooms->pluck('id')->all()]),
        ], $booking->checkin_at, $booking->checkout_at);

        $this->assertSame(5, $requirement->fresh()->quantity, 'required=5, assigned=2, selected=2 -> remaining=3, excess=0 -> demand stays 5');
        $this->assertCount(2, $result['assignments']);
        foreach ($result['assignments'] as $assignment) {
            $this->assertSame($requirement->id, $assignment->booking_requirement_id);
        }
    }

    public function test_selection_exceeding_remaining_adds_only_the_true_excess(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5,
            'room_price' => 500000, 'price_source' => 'RATE_TABLE',
        ]);
        RoomAssignment::factory()->for($booking)->count(4)
            ->state(fn () => ['room_id' => Room::factory()->for($roomType)->create()->id, 'room_type_id' => $roomType->id, 'booking_requirement_id' => $requirement->id, 'status' => AssignmentStatus::Assigned])
            ->create();
        $newRooms = Room::factory()->for($roomType)->count(3)->create();

        $result = $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group([
                'room_type_id' => $roomType->id, 'room_ids' => $newRooms->pluck('id')->all(),
                'room_price' => 500000, 'price_source' => 'RATE_TABLE',
            ]),
        ], $booking->checkin_at, $booking->checkout_at);

        // required=5, assigned_active=4, selected=3 -> remaining=1, excess_to_add=2 -> quantity becomes 7, not 8.
        $this->assertSame(7, $requirement->fresh()->quantity);
        $this->assertCount(3, $result['assignments']);
        foreach ($result['assignments'] as $assignment) {
            $this->assertSame($requirement->id, $assignment->booking_requirement_id);
        }
    }

    public function test_reconciliation_never_produces_negative_remaining_or_excess(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5]);
        $room = Room::factory()->for($roomType)->create();

        $result = $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group(['room_type_id' => $roomType->id, 'room_ids' => [$room->id]]),
        ], $booking->checkin_at, $booking->checkout_at);

        $this->assertCount(1, $result['assignments']);
    }

    // ── B. Existing requirement handling ───────────────────────────────────

    public function test_single_eligible_line_auto_maps_without_target(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 3]);
        $rooms = Room::factory()->for($roomType)->count(2)->create();

        $result = $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group(['room_type_id' => $roomType->id, 'room_ids' => $rooms->pluck('id')->all()]),
        ], $booking->checkin_at, $booking->checkout_at);

        foreach ($result['assignments'] as $assignment) {
            $this->assertSame($requirement->id, $assignment->booking_requirement_id);
        }
        $this->assertSame(3, $requirement->fresh()->quantity);
    }

    public function test_multiple_lines_without_target_is_rejected_and_rolls_back(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 500000]);
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 700000]);
        $room = Room::factory()->for($roomType)->create();

        try {
            $this->assignments->assignRoomsFromRoomBoard($booking, [
                $this->group(['room_type_id' => $roomType->id, 'room_ids' => [$room->id]]),
            ], $booking->checkin_at, $booking->checkout_at);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(0, RoomAssignment::where('booking_id', $booking->id)->count());
            $this->assertSame(0, Stay::count());
        }
    }

    public function test_multiple_lines_with_explicit_target_maps_correctly(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 500000]);
        $chosen = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 700000]);
        $room = Room::factory()->for($roomType)->create();

        $result = $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group(['room_type_id' => $roomType->id, 'room_ids' => [$room->id], 'target_requirement_id' => $chosen->id]),
        ], $booking->checkin_at, $booking->checkout_at);

        $this->assertSame($chosen->id, $result['assignments'][0]->booking_requirement_id);
    }

    public function test_excess_with_matching_price_and_source_increases_existing_line(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 1,
            'room_price' => 500000, 'price_source' => 'RATE_TABLE',
        ]);
        $rooms = Room::factory()->for($roomType)->count(2)->create();

        $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group([
                'room_type_id' => $roomType->id, 'room_ids' => $rooms->pluck('id')->all(),
                'room_price' => 500000, 'price_source' => 'RATE_TABLE',
            ]),
        ], $booking->checkin_at, $booking->checkout_at);

        $this->assertSame(2, $requirement->fresh()->quantity);
        $this->assertSame(1, BookingRequirement::where('booking_id', $booking->id)->count(), 'no new line created, old line increased in place');
    }

    public function test_excess_with_mismatched_price_creates_a_new_line_instead_of_mutating_old(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 1,
            'room_price' => 500000, 'price_source' => 'RATE_TABLE',
        ]);
        $rooms = Room::factory()->for($roomType)->count(2)->create();

        $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group([
                'room_type_id' => $roomType->id, 'room_ids' => $rooms->pluck('id')->all(),
                'room_price' => 900000, 'price_source' => 'MANUAL',
                'adults' => 1, 'children_under_6' => 0, 'children_over_6' => 0,
            ]),
        ], $booking->checkin_at, $booking->checkout_at);

        $this->assertSame(1, $requirement->fresh()->quantity, 'old line untouched');
        $newLine = BookingRequirement::where('booking_id', $booking->id)->where('id', '!=', $requirement->id)->first();
        $this->assertNotNull($newLine);
        $this->assertSame(1, $newLine->quantity);
        $this->assertEquals(900000, $newLine->room_price);
    }

    // ── C. New requirement creation ────────────────────────────────────────

    public function test_no_requirement_at_all_creates_a_new_line_for_the_full_selection(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $rooms = Room::factory()->for($roomType)->count(2)->create();

        $result = $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group([
                'room_type_id' => $roomType->id, 'room_ids' => $rooms->pluck('id')->all(),
                'room_price' => 600000, 'price_source' => 'MANUAL', 'note' => 'walk-in',
                'adults' => 2, 'children_under_6' => 0, 'children_over_6' => 0,
            ]),
        ], $booking->checkin_at, $booking->checkout_at);

        $newLine = BookingRequirement::where('booking_id', $booking->id)->sole();
        $this->assertSame(2, $newLine->quantity);
        $this->assertCount(2, $result['assignments']);
        foreach ($result['assignments'] as $assignment) {
            $this->assertSame($newLine->id, $assignment->booking_requirement_id);
        }
    }

    public function test_new_line_creation_without_pricing_payload_fails_clearly(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();

        try {
            $this->assignments->assignRoomsFromRoomBoard($booking, [
                $this->group(['room_type_id' => $roomType->id, 'room_ids' => [$room->id]]),
            ], $booking->checkin_at, $booking->checkout_at);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(0, BookingRequirement::count());
            $this->assertSame(0, RoomAssignment::count());
        }
    }

    // ── D. Folio-locked handling ───────────────────────────────────────────

    public function test_folio_locked_line_still_absorbs_within_capacity_rooms_without_being_modified(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 2,
            'room_price' => 500000, 'price_source' => 'RATE_TABLE',
        ]);
        $folio = Folio::factory()->for($booking)->create();
        FolioEntry::factory()->for($folio)->create(['charge_type' => ChargeType::Room, 'voided_at' => null]);
        $room = Room::factory()->for($roomType)->create();

        $result = $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group(['room_type_id' => $roomType->id, 'room_ids' => [$room->id]]),
        ], $booking->checkin_at, $booking->checkout_at);

        $this->assertSame($requirement->id, $result['assignments'][0]->booking_requirement_id);
        $this->assertSame(500000.0, (float) $requirement->fresh()->room_price, 'folio-locked line must never be mutated');
        $this->assertSame(2, $requirement->fresh()->quantity);
    }

    public function test_folio_locked_line_excess_creates_a_new_line_never_increases_the_locked_one(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 1,
            'room_price' => 500000, 'price_source' => 'RATE_TABLE',
        ]);
        $folio = Folio::factory()->for($booking)->create();
        FolioEntry::factory()->for($folio)->create(['charge_type' => ChargeType::Room, 'voided_at' => null]);
        $rooms = Room::factory()->for($roomType)->count(2)->create();

        $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group([
                'room_type_id' => $roomType->id, 'room_ids' => $rooms->pluck('id')->all(),
                // Even an EXACT price/source match must not increase a locked line.
                'room_price' => 500000, 'price_source' => 'RATE_TABLE',
                'adults' => 1, 'children_under_6' => 0, 'children_over_6' => 0,
            ]),
        ], $booking->checkin_at, $booking->checkout_at);

        $this->assertSame(1, $requirement->fresh()->quantity, 'locked line quantity never changes');
        $this->assertSame(2, BookingRequirement::where('booking_id', $booking->id)->count());
    }

    // ── E. Atomicity ────────────────────────────────────────────────────────

    public function test_one_unavailable_room_rolls_back_the_entire_batch_no_stay_no_requirement(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $goodRoom = Room::factory()->for($roomType)->create();
        $badRoom = Room::factory()->for($roomType)->create(['status' => RoomStatus::OutOfOrder]);

        try {
            $this->assignments->assignRoomsFromRoomBoard($booking, [
                $this->group([
                    'room_type_id' => $roomType->id, 'room_ids' => [$goodRoom->id, $badRoom->id],
                    'room_price' => 500000, 'price_source' => 'MANUAL',
                    'adults' => 2, 'children_under_6' => 0, 'children_over_6' => 0,
                ]),
            ], $booking->checkin_at, $booking->checkout_at);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(0, RoomAssignment::count());
            $this->assertSame(0, BookingRequirement::count());
            $this->assertSame(0, Stay::count());
        }
    }

    public function test_stay_rows_are_created_atomically_for_every_new_assignment(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 2]);
        $rooms = Room::factory()->for($roomType)->count(2)->create();

        $result = $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group(['room_type_id' => $roomType->id, 'room_ids' => $rooms->pluck('id')->all()]),
        ], $booking->checkin_at, $booking->checkout_at);

        $this->assertCount(2, $result['stays']);
        foreach ($result['assignments'] as $assignment) {
            $this->assertDatabaseHas('stays', ['room_assignment_id' => $assignment->id]);
        }
    }

    public function test_room_type_mismatch_against_actual_room_row_rolls_back_the_batch(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $otherRoomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 1]);

        try {
            // Frontend lies about room_type_id — server must re-derive from the
            // locked Room row and reject, never trust the payload.
            $this->assignments->assignRoomsFromRoomBoard($booking, [
                $this->group(['room_type_id' => $otherRoomType->id, 'room_ids' => [$room->id]]),
            ], $booking->checkin_at, $booking->checkout_at);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(0, RoomAssignment::count());
        }
    }

    public function test_concurrent_selection_of_the_same_room_only_one_request_succeeds(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 1]);
        $room = Room::factory()->for($roomType)->create();

        $secondBooking = Booking::factory()->create(['checkin_at' => $booking->checkin_at, 'checkout_at' => $booking->checkout_at, 'status' => BookingStatus::PendingAssignment]);
        BookingRequirement::factory()->create(['booking_id' => $secondBooking->id, 'room_type_id' => $roomType->id, 'quantity' => 1]);

        $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group(['room_type_id' => $roomType->id, 'room_ids' => [$room->id]]),
        ], $booking->checkin_at, $booking->checkout_at);

        $this->expectException(ValidationException::class);

        $this->assignments->assignRoomsFromRoomBoard($secondBooking, [
            $this->group(['room_type_id' => $roomType->id, 'room_ids' => [$room->id]]),
        ], $secondBooking->checkin_at, $secondBooking->checkout_at);
    }

    // ── F. State matrix / room availability ────────────────────────────────

    public function test_cancelled_booking_is_blocked(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Cancelled]);
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);

        $this->expectException(ValidationException::class);

        $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group(['room_type_id' => $roomType->id, 'room_ids' => [$room->id]]),
        ], $booking->checkin_at, $booking->checkout_at);
    }

    public function test_checked_out_booking_is_blocked(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::CheckedOut]);
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);

        $this->expectException(ValidationException::class);

        $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group(['room_type_id' => $roomType->id, 'room_ids' => [$room->id]]),
        ], $booking->checkin_at, $booking->checkout_at);
    }

    public function test_partially_checked_out_booking_is_blocked(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::PartiallyCheckedOut]);
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);

        $this->expectException(ValidationException::class);

        $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group(['room_type_id' => $roomType->id, 'room_ids' => [$room->id]]),
        ], $booking->checkin_at, $booking->checkout_at);
    }

    public function test_pending_assignment_booking_is_allowed(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);
        $room = Room::factory()->for($roomType)->create();

        $result = $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group(['room_type_id' => $roomType->id, 'room_ids' => [$room->id]]),
        ], $booking->checkin_at, $booking->checkout_at);

        $this->assertCount(1, $result['assignments']);
    }

    public function test_out_of_order_room_blocks_the_whole_batch(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 1]);
        $room = Room::factory()->for($roomType)->create(['status' => RoomStatus::OutOfOrder]);

        $this->expectException(ValidationException::class);

        $this->assignments->assignRoomsFromRoomBoard($booking, [
            $this->group(['room_type_id' => $roomType->id, 'room_ids' => [$room->id]]),
        ], $booking->checkin_at, $booking->checkout_at);
    }

    // ── G. Regression — demand-first and legacy flows unaffected ──────────

    public function test_demand_first_endpoint_still_works_unaffected_by_the_new_flow(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);

        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => $booking->checkin_at->format('Y-m-d H:i:s'),
            'end_at' => $booking->checkout_at->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $assignment = RoomAssignment::where('booking_id', $booking->id)->first();
        $this->assertNotNull($assignment);
        $this->assertNotNull($assignment->booking_requirement_id);
    }

    public function test_legacy_assign_rooms_still_creates_null_linked_assignments(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();

        $created = $this->assignments->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
        ]);

        $this->assertNull($created[0]->booking_requirement_id);
    }

    // ── H. HTTP / Inertia / authorization ──────────────────────────────────

    public function test_route_requires_both_room_assign_and_booking_update_permissions(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();

        $userWithOnlyBookingUpdate = User::factory()->create();
        $userWithOnlyBookingUpdate->givePermissionTo('booking.update');
        $this->actingAs($userWithOnlyBookingUpdate);

        $this->post("/admin/bookings/{$booking->id}/room-board/assignments", [
            'room_ids' => [$room->id],
            'start_at' => $booking->checkin_at->format('Y-m-d H:i:s'),
            'end_at' => $booking->checkout_at->format('Y-m-d H:i:s'),
            'groups' => [[
                'room_type_id' => $roomType->id, 'room_ids' => [$room->id],
                'room_price' => 500000, 'price_source' => 'MANUAL',
                'adults' => 2, 'children_under_6' => 0, 'children_over_6' => 0,
            ]],
        ])->assertForbidden();

        $userWithOnlyRoomAssign = User::factory()->create();
        $userWithOnlyRoomAssign->givePermissionTo('room.assign');
        $this->actingAs($userWithOnlyRoomAssign);

        $this->post("/admin/bookings/{$booking->id}/room-board/assignments", [
            'room_ids' => [$room->id],
            'start_at' => $booking->checkin_at->format('Y-m-d H:i:s'),
            'end_at' => $booking->checkout_at->format('Y-m-d H:i:s'),
            'groups' => [[
                'room_type_id' => $roomType->id, 'room_ids' => [$room->id],
                'room_price' => 500000, 'price_source' => 'MANUAL',
                'adults' => 2, 'children_under_6' => 0, 'children_over_6' => 0,
            ]],
        ])->assertForbidden();

        $this->assertSame(0, RoomAssignment::count());
    }

    public function test_full_happy_path_via_http_creates_requirement_assignment_and_stay(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();

        $this->post("/admin/bookings/{$booking->id}/room-board/assignments", [
            'room_ids' => [$room->id],
            'start_at' => $booking->checkin_at->format('Y-m-d H:i:s'),
            'end_at' => $booking->checkout_at->format('Y-m-d H:i:s'),
            'groups' => [[
                'room_type_id' => $roomType->id,
                'room_ids' => [$room->id],
                'room_price' => 500000,
                'price_source' => 'MANUAL',
                'note' => 'HTTP test',
                'adults' => 1,
                'children_under_6' => 0,
                'children_over_6' => 0,
            ]],
        ])->assertRedirect("/admin/bookings/{$booking->id}?tab=room_map");

        $requirement = BookingRequirement::where('booking_id', $booking->id)->sole();
        $this->assertSame(1, $requirement->quantity);
        $assignment = RoomAssignment::where('booking_id', $booking->id)->sole();
        $this->assertSame($requirement->id, $assignment->booking_requirement_id);
        $this->assertDatabaseHas('stays', ['room_assignment_id' => $assignment->id]);
    }

    public function test_mismatched_group_room_ids_against_top_level_is_rejected_structurally(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();
        $otherRoom = Room::factory()->for($roomType)->create();

        $response = $this->post("/admin/bookings/{$booking->id}/room-board/assignments", [
            'room_ids' => [$room->id],
            'start_at' => $booking->checkin_at->format('Y-m-d H:i:s'),
            'end_at' => $booking->checkout_at->format('Y-m-d H:i:s'),
            'groups' => [[
                'room_type_id' => $roomType->id,
                'room_ids' => [$otherRoom->id], // does not match top-level room_ids
                'room_price' => 500000, 'price_source' => 'MANUAL',
                'adults' => 1, 'children_under_6' => 0, 'children_over_6' => 0,
            ]],
        ]);

        $response->assertSessionHasErrors('groups');
        $this->assertSame(0, RoomAssignment::count());
    }

    public function test_show_page_props_include_active_assignment_count_remaining_and_folio_lock(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 3]);
        $room = Room::factory()->for($roomType)->create();
        RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id, 'booking_requirement_id' => $requirement->id, 'status' => AssignmentStatus::Assigned,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.requirements.0.active_assignment_count', 1)
                ->where('booking.requirements.0.remaining', 2)
                ->where('booking.requirements.0.is_folio_locked', false)
            );
    }
}
