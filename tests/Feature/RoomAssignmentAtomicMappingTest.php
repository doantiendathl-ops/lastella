<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\User;
use App\Services\RoomAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Room Demand/Room Board Unification M2 — atomic requirement mapping for the
 * existing demand-first assignment flow
 * (RoomAssignmentService::assignRoomsWithRequirementLink()).
 */
class RoomAssignmentAtomicMappingTest extends TestCase
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
        return [Booking::factory()->create(), RoomType::factory()->create()];
    }

    // ── A. Mapping cơ bản ────────────────────────────────────────────────

    public function test_single_requirement_line_auto_resolves_without_target_payload(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);

        $created = $this->assignments->assignRoomsWithRequirementLink($booking, [
            ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
        ]);

        $this->assertCount(1, $created);
        $this->assertSame($requirement->id, $created[0]->booking_requirement_id);
    }

    public function test_multiple_rooms_same_room_type_all_map_to_the_same_requirement(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $rooms = Room::factory()->for($roomType)->count(3)->create();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'quantity' => 3,
        ]);

        $created = $this->assignments->assignRoomsWithRequirementLink($booking, $rooms->map(fn ($room) => [
            'room_id' => $room->id, 'room_type_id' => $roomType->id,
            'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at,
        ])->all());

        $this->assertCount(3, $created);
        foreach ($created as $assignment) {
            $this->assertSame($requirement->id, $assignment->booking_requirement_id);
        }
    }

    public function test_multiple_room_types_each_map_to_their_own_requirement(): void
    {
        [$booking, $roomTypeA] = $this->makeBookingAndRoomType();
        $roomTypeB = RoomType::factory()->create();
        $roomA = Room::factory()->for($roomTypeA)->create();
        $roomB = Room::factory()->for($roomTypeB)->create();
        $reqA = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomTypeA->id]);
        $reqB = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomTypeB->id]);

        $created = $this->assignments->assignRoomsWithRequirementLink($booking, [
            ['room_id' => $roomA->id, 'room_type_id' => $roomTypeA->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
            ['room_id' => $roomB->id, 'room_type_id' => $roomTypeB->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
        ]);

        $byRoom = collect($created)->keyBy('room_id');
        $this->assertSame($reqA->id, $byRoom[$roomA->id]->booking_requirement_id);
        $this->assertSame($reqB->id, $byRoom[$roomB->id]->booking_requirement_id);
    }

    public function test_assignment_created_via_demand_first_endpoint_is_never_null(): void
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

    // ── B. Multiple requirement lines ───────────────────────────────────

    public function test_multiple_lines_without_target_blocks_the_whole_request(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 500000]);
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 700000]);

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, [
                ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(0, RoomAssignment::where('booking_id', $booking->id)->count());
        }
    }

    public function test_multiple_lines_with_valid_target_maps_to_the_chosen_line(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 500000]);
        $chosen = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 700000]);

        $created = $this->assignments->assignRoomsWithRequirementLink($booking, [
            ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
        ], [$roomType->id => $chosen->id]);

        $this->assertSame($chosen->id, $created[0]->booking_requirement_id);
    }

    public function test_target_belonging_to_a_different_booking_is_blocked_and_rolls_back(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);
        $otherBooking = Booking::factory()->create();
        $foreignRequirement = BookingRequirement::factory()->create(['booking_id' => $otherBooking->id, 'room_type_id' => $roomType->id]);

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, [
                ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
            ], [$roomType->id => $foreignRequirement->id]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(0, RoomAssignment::where('booking_id', $booking->id)->count());
        }
    }

    public function test_target_belonging_to_a_different_room_type_is_blocked_and_rolls_back(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $otherRoomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);
        $wrongTypeRequirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $otherRoomType->id]);

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, [
                ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
            ], [$roomType->id => $wrongTypeRequirement->id]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(0, RoomAssignment::where('booking_id', $booking->id)->count());
        }
    }

    public function test_nonexistent_target_fails_validation(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);

        $this->expectException(ValidationException::class);

        $this->assignments->assignRoomsWithRequirementLink($booking, [
            ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
        ], [$roomType->id => 999999]);
    }

    public function test_target_line_with_zero_quantity_is_ineligible_and_blocked(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 1]);
        // Directly force quantity=0 to bypass StoreBookingRequirementRequest's
        // min:1 rule — simulates the "hidden" line state a future reduce-demand
        // flow (M4) would leave behind (Product Owner Decision #18).
        $hiddenLine = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);
        $hiddenLine->forceFill(['quantity' => 0])->save();

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, [
                ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
            ], [$roomType->id => $hiddenLine->id]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(0, RoomAssignment::where('booking_id', $booking->id)->count());
        }
    }

    // ── C. No requirement ────────────────────────────────────────────────

    public function test_room_type_without_any_requirement_is_blocked_with_clear_message(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();
        $countBefore = BookingRequirement::count();

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, [
                ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'chưa có dòng nhu cầu phù hợp',
                collect($e->errors())->flatten()->first(),
            );
            $this->assertSame(0, RoomAssignment::where('booking_id', $booking->id)->count());
            $this->assertSame($countBefore, BookingRequirement::count());
        }
    }

    // ── D. Atomicity ─────────────────────────────────────────────────────

    public function test_one_unavailable_room_in_batch_rolls_back_the_entire_batch(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $roomA = Room::factory()->for($roomType)->create();
        $roomB = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5]);

        $conflictingBooking = Booking::factory()->create([
            'checkin_at' => $booking->checkin_at,
            'checkout_at' => $booking->checkout_at,
        ]);
        RoomAssignment::factory()->for($conflictingBooking)->for($roomB)->create([
            'room_type_id' => $roomType->id,
            'status' => AssignmentStatus::Assigned,
            'start_at' => $booking->checkin_at,
            'end_at' => $booking->checkout_at,
        ]);

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, [
                ['room_id' => $roomA->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
                ['room_id' => $roomB->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(0, RoomAssignment::where('booking_id', $booking->id)->count());
        }
    }

    public function test_one_wrong_target_requirement_rolls_back_the_entire_batch(): void
    {
        [$booking, $roomTypeA] = $this->makeBookingAndRoomType();
        $roomTypeB = RoomType::factory()->create();
        $roomA = Room::factory()->for($roomTypeA)->create();
        $roomB = Room::factory()->for($roomTypeB)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomTypeA->id]);
        // roomTypeB has no requirement at all -> resolution fails for that group.

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, [
                ['room_id' => $roomA->id, 'room_type_id' => $roomTypeA->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
                ['room_id' => $roomB->id, 'room_type_id' => $roomTypeB->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            // room_type A resolved fine on its own, but the batch must still roll back completely.
            $this->assertSame(0, RoomAssignment::where('booking_id', $booking->id)->count());
        }
    }

    public function test_concurrent_selection_of_the_same_room_only_one_request_succeeds(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 1]);

        $secondBooking = Booking::factory()->create([
            'checkin_at' => $booking->checkin_at,
            'checkout_at' => $booking->checkout_at,
        ]);
        BookingRequirement::factory()->create(['booking_id' => $secondBooking->id, 'room_type_id' => $roomType->id, 'quantity' => 1]);

        // First "staff member" commits fully before the second one runs — same
        // sequential-simulation idiom already used across this project's tests
        // (BookingEngineFoundationTest::test_concurrent_*).
        $this->assignments->assignRoomsWithRequirementLink($booking, [
            ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
        ]);

        $this->expectException(ValidationException::class);

        $this->assignments->assignRoomsWithRequirementLink($secondBooking, [
            ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $secondBooking->checkin_at, 'end_at' => $secondBooking->checkout_at],
        ]);
    }

    public function test_no_orphaned_or_null_assignment_exists_after_a_rejected_batch(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();
        // No requirement at all — guaranteed rejection.

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, [
                ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
            ]);
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertSame(0, RoomAssignment::count());
        $this->assertSame(0, RoomAssignment::whereNull('booking_requirement_id')->count());
    }

    // ── E. Regression — legacy assignRooms() unaffected ──────────────────

    public function test_legacy_assign_rooms_still_works_without_any_requirement_and_without_mapping(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();

        $created = $this->assignments->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
        ]);

        $this->assertCount(1, $created);
        $this->assertNull($created[0]->booking_requirement_id);
        $this->assertDatabaseHas('room_assignments', ['id' => $created[0]->id, 'booking_requirement_id' => null]);
    }

    // ── F. Frontend/Inertia ────────────────────────────────────────────────

    public function test_show_page_props_include_requirement_lines_for_target_selection(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 500000]);
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 700000]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('booking.requirements', 2)
                ->where('booking.requirements.0.room_type_id', $roomType->id)
            );
    }

    public function test_backend_rejects_ambiguous_batch_with_clear_validation_error_key(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 500000]);
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 700000]);

        $response = $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => $booking->checkin_at->format('Y-m-d H:i:s'),
            'end_at' => $booking->checkout_at->format('Y-m-d H:i:s'),
        ]);

        $response->assertSessionHasErrors("target_requirement_id.{$roomType->id}");
        $this->assertSame(0, RoomAssignment::where('booking_id', $booking->id)->count());
    }

    public function test_payload_with_valid_target_map_assigns_successfully_via_http(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 500000]);
        $chosen = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 700000]);

        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => $booking->checkin_at->format('Y-m-d H:i:s'),
            'end_at' => $booking->checkout_at->format('Y-m-d H:i:s'),
            'target_requirement_id' => [$roomType->id => $chosen->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('room_assignments', [
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'booking_requirement_id' => $chosen->id,
        ]);
    }

    // ── Architecture Gap Closure (M5, Blocker A) — demand-first capacity ──

    private function makeActiveAssignment(Booking $booking, RoomType $roomType, BookingRequirement $requirement): RoomAssignment
    {
        $room = Room::factory()->for($roomType)->create();

        return RoomAssignment::factory()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => $requirement->id,
            'status' => AssignmentStatus::Assigned,
            'start_at' => $booking->checkin_at,
            'end_at' => $booking->checkout_at,
        ]);
    }

    public function test_capacity_quantity_5_active_3_request_2_is_accepted(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5]);
        $this->makeActiveAssignment($booking, $roomType, $requirement);
        $this->makeActiveAssignment($booking, $roomType, $requirement);
        $this->makeActiveAssignment($booking, $roomType, $requirement);
        $newRooms = Room::factory()->for($roomType)->count(2)->create();

        $created = $this->assignments->assignRoomsWithRequirementLink($booking, $newRooms->map(fn ($room) => [
            'room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at,
        ])->all());

        $this->assertCount(2, $created);
        $this->assertSame(5, $requirement->fresh()->quantity, 'quantity must never be mutated by demand-first');
    }

    public function test_capacity_quantity_5_active_4_request_2_rejects_whole_batch(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5]);
        for ($i = 0; $i < 4; $i++) {
            $this->makeActiveAssignment($booking, $roomType, $requirement);
        }
        $newRooms = Room::factory()->for($roomType)->count(2)->create();

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, $newRooms->map(fn ($room) => [
                'room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at,
            ])->all());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(4, RoomAssignment::where('booking_requirement_id', $requirement->id)->where('status', AssignmentStatus::Assigned)->count(), 'active count must stay exactly 4 — no partial write');
        }
    }

    public function test_capacity_quantity_5_active_5_new_request_is_rejected(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5]);
        for ($i = 0; $i < 5; $i++) {
            $this->makeActiveAssignment($booking, $roomType, $requirement);
        }
        $newRoom = Room::factory()->for($roomType)->create();

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, [
                ['room_id' => $newRoom->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(5, RoomAssignment::where('booking_requirement_id', $requirement->id)->where('status', AssignmentStatus::Assigned)->count());
        }
    }

    public function test_batch_of_3_rooms_but_remaining_only_2_rolls_back_entirely_no_partial_2_of_3(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5]);
        for ($i = 0; $i < 3; $i++) {
            $this->makeActiveAssignment($booking, $roomType, $requirement);
        }
        // remaining = 5 - 3 = 2, but the batch requests 3 rooms.
        $newRooms = Room::factory()->for($roomType)->count(3)->create();

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, $newRooms->map(fn ($room) => [
                'room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at,
            ])->all());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(3, RoomAssignment::where('booking_requirement_id', $requirement->id)->where('status', AssignmentStatus::Assigned)->count(), 'never silently assign 2 of 3 — the whole request must fail');
            foreach ($newRooms as $room) {
                $this->assertDatabaseMissing('room_assignments', ['room_id' => $room->id]);
            }
        }
    }

    public function test_capacity_check_covers_multiple_requirements_independently_in_one_batch(): void
    {
        $booking = Booking::factory()->create();
        $roomTypeA = RoomType::factory()->create();
        $roomTypeB = RoomType::factory()->create();
        $requirementA = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomTypeA->id, 'quantity' => 1]);
        $requirementB = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomTypeB->id, 'quantity' => 2]);
        $roomA = Room::factory()->for($roomTypeA)->create();
        $roomsB = Room::factory()->for($roomTypeB)->count(2)->create();

        $created = $this->assignments->assignRoomsWithRequirementLink($booking, array_merge(
            [['room_id' => $roomA->id, 'room_type_id' => $roomTypeA->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at]],
            $roomsB->map(fn ($room) => ['room_id' => $room->id, 'room_type_id' => $roomTypeB->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at])->all(),
        ));

        $this->assertCount(3, $created);
        $this->assertSame($requirementA->id, collect($created)->firstWhere('room_id', $roomA->id)->booking_requirement_id);
        foreach ($roomsB as $room) {
            $this->assertSame($requirementB->id, collect($created)->firstWhere('room_id', $room->id)->booking_requirement_id);
        }
    }

    public function test_capacity_check_rejects_one_of_two_requirements_rolls_back_whole_batch(): void
    {
        $booking = Booking::factory()->create();
        $roomTypeA = RoomType::factory()->create();
        $roomTypeB = RoomType::factory()->create();
        $requirementA = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomTypeA->id, 'quantity' => 1]);
        $requirementB = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomTypeB->id, 'quantity' => 1]);
        $this->makeActiveAssignment($booking, $roomTypeA, $requirementA); // A already full
        $roomA = Room::factory()->for($roomTypeA)->create();
        $roomB = Room::factory()->for($roomTypeB)->create();

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, [
                ['room_id' => $roomA->id, 'room_type_id' => $roomTypeA->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
                ['room_id' => $roomB->id, 'room_type_id' => $roomTypeB->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            // Requirement A is over capacity -> the WHOLE batch rolls back,
            // including room B's otherwise-valid assignment against requirement B.
            $this->assertDatabaseMissing('room_assignments', ['room_id' => $roomB->id]);
            $this->assertSame(0, RoomAssignment::where('booking_requirement_id', $requirementB->id)->count());
        }
    }

    public function test_demand_first_rejects_cancelled_booking(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $booking->update(['status' => \App\Enums\BookingStatus::Cancelled]);
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, [
                ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(0, RoomAssignment::count());
        }
    }

    public function test_demand_first_rejects_no_show_booking(): void
    {
        [$booking, $roomType] = $this->makeBookingAndRoomType();
        $booking->update(['status' => \App\Enums\BookingStatus::NoShow]);
        $room = Room::factory()->for($roomType)->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);

        try {
            $this->assignments->assignRoomsWithRequirementLink($booking, [
                ['room_id' => $room->id, 'room_type_id' => $roomType->id, 'start_at' => $booking->checkin_at, 'end_at' => $booking->checkout_at],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(0, RoomAssignment::count());
        }
    }
}
