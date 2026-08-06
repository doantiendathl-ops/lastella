<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\ChargeType;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\ReleaseBatch;
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
 * Room Demand/Room Board Unification M4 — atomic bulk room release
 * (RoomAssignmentService::bulkReleaseAssignments()).
 *
 * Following the same sequential-simulation convention as M2/M3's tests for
 * lock-guarded "concurrency" scenarios: a competing write is fully committed
 * before the operation under test runs.
 */
class BulkRoomReleaseTest extends TestCase
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

    private function makeBooking(array $overrides = []): Booking
    {
        return Booking::factory()->create(array_merge(['status' => BookingStatus::PartiallyAssigned], $overrides));
    }

    private function makeAssignment(Booking $booking, RoomType $roomType, ?int $requirementId = null, array $overrides = []): RoomAssignment
    {
        $room = Room::factory()->for($roomType)->create();

        $assignment = RoomAssignment::factory()->create(array_merge([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => $requirementId,
            'status' => AssignmentStatus::Assigned,
            'start_at' => $booking->checkin_at,
            'end_at' => $booking->checkout_at,
        ], $overrides));

        Stay::factory()->create([
            'booking_id' => $booking->id,
            'room_assignment_id' => $assignment->id,
            'room_id' => $room->id,
            'planned_checkin_at' => $assignment->start_at,
            'planned_checkout_at' => $assignment->end_at,
        ]);

        return $assignment;
    }

    // ── A. Batch cơ bản ─────────────────────────────────────────────────

    public function test_release_two_assignments_same_requirement_without_reducing_demand(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5]);
        $a1 = $this->makeAssignment($booking, $roomType, $requirement->id);
        $a2 = $this->makeAssignment($booking, $roomType, $requirement->id);

        $result = $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $a2->id], 'Khách đổi ý', null, false);

        $this->assertCount(2, $result['assignments']);
        $this->assertSame(AssignmentStatus::Released, $a1->fresh()->status);
        $this->assertSame(AssignmentStatus::Released, $a2->fresh()->status);
        $this->assertSame('Khách đổi ý', $a1->fresh()->release_reason);
        $this->assertSame('Khách đổi ý', $a2->fresh()->release_reason);
        $this->assertSame($result['batch']->id, $a1->fresh()->release_batch_id);
        $this->assertSame($result['batch']->id, $a2->fresh()->release_batch_id);
        $this->assertSame(1, ReleaseBatch::where('booking_id', $booking->id)->count());
        $this->assertSame(5, $requirement->fresh()->quantity, 'demand unchanged when reduce_demand=false');
    }

    public function test_release_multiple_assignments_different_room_types_in_one_batch(): void
    {
        $booking = $this->makeBooking();
        $typeA = RoomType::factory()->create();
        $typeB = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $typeA);
        $a2 = $this->makeAssignment($booking, $typeB);

        $result = $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $a2->id], 'lý do', null, false);

        $this->assertCount(2, $result['assignments']);
        $this->assertSame(AssignmentStatus::Released, $a1->fresh()->status);
        $this->assertSame(AssignmentStatus::Released, $a2->fresh()->status);
    }

    public function test_shared_note_saved_on_batch(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $result = $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', 'ghi chú riêng cho lượt gỡ này', false);

        $this->assertSame('ghi chú riêng cho lượt gỡ này', $result['batch']->note);
    }

    public function test_actor_and_time_saved_correctly(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $result = $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, false);

        $this->assertSame($this->admin->id, $result['batch']->released_by);
        $this->assertNotNull($result['batch']->released_at);
    }

    // ── B. Giảm nhu cầu ──────────────────────────────────────────────────

    public function test_reduce_demand_two_assignments_same_requirement_decreases_quantity_by_two(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5]);
        $a1 = $this->makeAssignment($booking, $roomType, $requirement->id);
        $a2 = $this->makeAssignment($booking, $roomType, $requirement->id);

        $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $a2->id], 'lý do', null, true);

        $this->assertSame(3, $requirement->fresh()->quantity);
    }

    public function test_reduce_demand_multiple_requirements_each_decreases_correctly(): void
    {
        $booking = $this->makeBooking();
        $typeA = RoomType::factory()->create();
        $typeB = RoomType::factory()->create();
        $reqA = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $typeA->id, 'quantity' => 3]);
        $reqB = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $typeB->id, 'quantity' => 4]);
        $a1 = $this->makeAssignment($booking, $typeA, $reqA->id);
        $a2 = $this->makeAssignment($booking, $typeB, $reqB->id);
        $a3 = $this->makeAssignment($booking, $typeB, $reqB->id);

        $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $a2->id, $a3->id], 'lý do', null, true);

        $this->assertSame(2, $reqA->fresh()->quantity);
        $this->assertSame(2, $reqB->fresh()->quantity);
    }

    public function test_reduce_demand_to_zero_keeps_row_not_deleted(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 2]);
        $a1 = $this->makeAssignment($booking, $roomType, $requirement->id);
        $a2 = $this->makeAssignment($booking, $roomType, $requirement->id);

        $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $a2->id], 'lý do', null, true);

        $this->assertSame(0, $requirement->fresh()->quantity);
        $this->assertDatabaseHas('booking_requirements', ['id' => $requirement->id]);
    }

    public function test_reduce_demand_cannot_go_negative(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        // quantity=1 but 2 assignments reference it (over-assigned edge case) -> would go to -1.
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 1]);
        $a1 = $this->makeAssignment($booking, $roomType, $requirement->id);
        $a2 = $this->makeAssignment($booking, $roomType, $requirement->id);

        try {
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $a2->id], 'lý do', null, true);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status);
            $this->assertSame(AssignmentStatus::Assigned, $a2->fresh()->status);
            $this->assertSame(1, $requirement->fresh()->quantity);
            $this->assertSame(0, ReleaseBatch::count());
        }
    }

    public function test_reduce_demand_cannot_go_below_remaining_active(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5]);
        // 3 assignments reference this line; release only 1 -> proposed = 5-1=4,
        // but 2 remain active -> 4 >= 2 is fine. Now try releasing 4 of 5 total
        // quantity while only 1 is being released and 4 stay active -> still fine.
        // Force the invalid case: quantity artificially lowered below what's held.
        $a1 = $this->makeAssignment($booking, $roomType, $requirement->id);
        $a2 = $this->makeAssignment($booking, $roomType, $requirement->id);
        $a3 = $this->makeAssignment($booking, $roomType, $requirement->id);
        $requirement->forceFill(['quantity' => 2])->save(); // now 3 active but quantity=2 (inconsistent legacy state)

        try {
            // Release only a1: remaining_active_after_release = 3-1=2, proposed = 2-1=1 -> 1 < 2 invalid.
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, true);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status);
            $this->assertSame(2, $requirement->fresh()->quantity);
        }
    }

    public function test_null_mapping_without_reduce_demand_releases_successfully(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType, null);

        $result = $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, false);

        $this->assertCount(1, $result['assignments']);
        $this->assertSame(AssignmentStatus::Released, $a1->fresh()->status);
    }

    public function test_null_mapping_with_reduce_demand_rejects_whole_batch(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5]);
        $a1 = $this->makeAssignment($booking, $roomType, $requirement->id);
        $a2 = $this->makeAssignment($booking, $roomType, null); // legacy, ambiguous

        try {
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $a2->id], 'lý do', null, true);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status);
            $this->assertSame(AssignmentStatus::Assigned, $a2->fresh()->status);
            $this->assertSame(5, $requirement->fresh()->quantity);
            $this->assertSame(0, ReleaseBatch::count());
        }
    }

    public function test_folio_locked_requirement_with_reduce_demand_rejects_whole_batch(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5]);
        $a1 = $this->makeAssignment($booking, $roomType, $requirement->id);
        $folio = Folio::factory()->for($booking)->create();
        FolioEntry::factory()->for($folio)->create(['charge_type' => ChargeType::Room, 'voided_at' => null]);

        try {
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, true);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status);
            $this->assertSame(5, $requirement->fresh()->quantity);
            $this->assertSame(0, ReleaseBatch::count());
            // Folio never touched.
            $this->assertSame(1, FolioEntry::where('folio_id', $folio->id)->count());
        }
    }

    // ── C. Atomicity ─────────────────────────────────────────────────────

    public function test_assignment_not_belonging_to_booking_rolls_back_whole_batch(): void
    {
        $booking = $this->makeBooking();
        $otherBooking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);
        $foreign = $this->makeAssignment($otherBooking, $roomType);

        try {
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $foreign->id], 'lý do', null, false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status);
            $this->assertSame(AssignmentStatus::Assigned, $foreign->fresh()->status);
            $this->assertSame(0, ReleaseBatch::count());
        }
    }

    public function test_already_released_assignment_rolls_back_whole_batch(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);
        $a2 = $this->makeAssignment($booking, $roomType, null, ['status' => AssignmentStatus::Released]);

        try {
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $a2->id], 'lý do', null, false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status);
            $this->assertSame(0, ReleaseBatch::count());
        }
    }

    public function test_nonexistent_assignment_fails_validation(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        try {
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id, 999999], 'lý do', null, false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status);
            $this->assertSame(0, ReleaseBatch::count());
        }
    }

    public function test_checked_in_assignment_rolls_back_whole_batch(): void
    {
        $booking = $this->makeBooking(['status' => BookingStatus::PartiallyCheckedIn]);
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);
        $a2 = $this->makeAssignment($booking, $roomType, null, ['status' => AssignmentStatus::CheckedIn]);
        Stay::where('room_assignment_id', $a2->id)->update(['actual_checkin_at' => now(), 'status' => StayStatus::CheckedIn]);

        try {
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $a2->id], 'lý do', null, false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status);
            $this->assertSame(0, ReleaseBatch::count());
        }
    }

    public function test_multi_room_type_batch_one_group_error_no_partial_success(): void
    {
        $booking = $this->makeBooking();
        $typeA = RoomType::factory()->create();
        $typeB = RoomType::factory()->create();
        $reqA = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $typeA->id, 'quantity' => 5]);
        $a1 = $this->makeAssignment($booking, $typeA, $reqA->id);
        $a2 = $this->makeAssignment($booking, $typeB, null); // null mapping, room_type B group

        try {
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $a2->id], 'lý do', null, true);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status, 'room_type A group must not partially succeed');
            $this->assertSame(5, $reqA->fresh()->quantity);
            $this->assertSame(0, ReleaseBatch::count());
        }
    }

    public function test_double_submit_second_request_does_not_release_again(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, false);
        $this->assertSame(1, ReleaseBatch::count());

        try {
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do lần 2', null, false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(1, ReleaseBatch::count(), 'second submit must not create a second batch');
        }
    }

    public function test_concurrent_release_of_same_assignment_only_one_succeeds(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        // First "browser tab" commits fully before the second one runs — same
        // sequential-simulation idiom already used across this project's tests.
        $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'tab 1', null, false);

        $this->expectException(ValidationException::class);

        $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'tab 2', null, false);
    }

    // ── D. State matrix ──────────────────────────────────────────────────

    public function test_partially_assigned_booking_allows_release(): void
    {
        $booking = $this->makeBooking(['status' => BookingStatus::PartiallyAssigned]);
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $result = $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, false);

        $this->assertCount(1, $result['assignments']);
    }

    public function test_partially_checked_out_booking_blocks_bulk_release(): void
    {
        $booking = $this->makeBooking(['status' => BookingStatus::PartiallyCheckedOut]);
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $this->expectException(ValidationException::class);
        $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, false);
    }

    public function test_checked_out_booking_blocks_bulk_release(): void
    {
        $booking = $this->makeBooking(['status' => BookingStatus::CheckedOut]);
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $this->expectException(ValidationException::class);
        $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, false);
    }

    public function test_cancelled_booking_blocks_bulk_release(): void
    {
        $booking = $this->makeBooking(['status' => BookingStatus::Cancelled]);
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $this->expectException(ValidationException::class);
        $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, false);
    }

    public function test_no_show_booking_blocks_bulk_release(): void
    {
        $booking = $this->makeBooking(['status' => BookingStatus::NoShow]);
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $this->expectException(ValidationException::class);
        $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, false);
    }

    public function test_checked_in_assignment_always_blocked_even_in_valid_booking_state(): void
    {
        $booking = $this->makeBooking(['status' => BookingStatus::CheckedIn]);
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType, null, ['status' => AssignmentStatus::CheckedIn]);
        Stay::where('room_assignment_id', $a1->id)->update(['actual_checkin_at' => now(), 'status' => StayStatus::CheckedIn]);

        $this->expectException(ValidationException::class);
        $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, false);
    }

    // ── E. Authorization ─────────────────────────────────────────────────

    public function test_room_unassign_permission_without_reduce_demand_allowed_via_http(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $user = User::factory()->create();
        $user->givePermissionTo('room.unassign');
        $this->actingAs($user);

        $this->post("/admin/bookings/{$booking->id}/assignments/bulk-release", [
            'assignment_ids' => [$a1->id],
            'reason' => 'lý do',
            'reduce_demand' => false,
        ])->assertRedirect();

        $this->assertSame(AssignmentStatus::Released, $a1->fresh()->status);
    }

    public function test_missing_room_unassign_permission_returns_403(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $user = User::factory()->create();
        $user->givePermissionTo('booking.update');
        $this->actingAs($user);

        $this->post("/admin/bookings/{$booking->id}/assignments/bulk-release", [
            'assignment_ids' => [$a1->id],
            'reason' => 'lý do',
            'reduce_demand' => false,
        ])->assertForbidden();

        $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status);
    }

    public function test_room_unassign_without_booking_update_and_reduce_demand_true_returns_403(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5]);
        $a1 = $this->makeAssignment($booking, $roomType, $requirement->id);

        $user = User::factory()->create();
        $user->givePermissionTo('room.unassign');
        $this->actingAs($user);

        $this->post("/admin/bookings/{$booking->id}/assignments/bulk-release", [
            'assignment_ids' => [$a1->id],
            'reason' => 'lý do',
            'reduce_demand' => true,
        ])->assertForbidden();

        $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status);
        $this->assertSame(5, $requirement->fresh()->quantity);
    }

    public function test_both_permissions_allowed_via_http(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 5]);
        $a1 = $this->makeAssignment($booking, $roomType, $requirement->id);

        $user = User::factory()->create();
        $user->givePermissionTo(['room.unassign', 'booking.update']);
        $this->actingAs($user);

        $this->post("/admin/bookings/{$booking->id}/assignments/bulk-release", [
            'assignment_ids' => [$a1->id],
            'reason' => 'lý do',
            'reduce_demand' => true,
        ])->assertRedirect();

        $this->assertSame(AssignmentStatus::Released, $a1->fresh()->status);
        $this->assertSame(4, $requirement->fresh()->quantity);
    }

    // ── F. Backward compatibility ────────────────────────────────────────

    public function test_existing_single_release_still_works(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $this->post("/admin/bookings/{$booking->id}/assignments/{$a1->id}/release", [
            'release_reason' => 'Khách đổi phòng',
        ])->assertRedirect();

        $this->assertSame(AssignmentStatus::Released, $a1->fresh()->status);
        $this->assertSame('Khách đổi phòng', $a1->fresh()->release_reason);
        $this->assertNull($a1->fresh()->release_batch_id, 'single release must never create a batch');
    }

    public function test_existing_single_release_reason_still_recorded_via_service(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $released = $this->assignments->releaseAssignment($a1, 'lý do trực tiếp qua service');

        $this->assertSame('lý do trực tiếp qua service', $released->release_reason);
        $this->assertNull($released->release_batch_id);
    }

    public function test_demand_first_m2_still_works(): void
    {
        $booking = $this->makeBooking(['status' => BookingStatus::PendingAssignment]);
        $roomType = RoomType::factory()->create();
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

    public function test_room_board_first_m3_still_works(): void
    {
        $booking = $this->makeBooking(['status' => BookingStatus::PendingAssignment]);
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();

        $this->post("/admin/bookings/{$booking->id}/room-board/assignments", [
            'room_ids' => [$room->id],
            'start_at' => $booking->checkin_at->format('Y-m-d H:i:s'),
            'end_at' => $booking->checkout_at->format('Y-m-d H:i:s'),
            'groups' => [[
                'room_type_id' => $roomType->id,
                'room_ids' => [$room->id],
                'room_price' => 500000, 'price_source' => 'MANUAL',
                'adults' => 1, 'children_under_6' => 0, 'children_over_6' => 0,
            ]],
        ])->assertRedirect();

        $this->assertSame(1, BookingRequirement::where('booking_id', $booking->id)->count());
        $this->assertSame(1, RoomAssignment::where('booking_id', $booking->id)->count());
    }

    // ── G. Audit ─────────────────────────────────────────────────────────

    public function test_release_batch_created_with_correct_fields(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 3]);
        $a1 = $this->makeAssignment($booking, $roomType, $requirement->id);

        $result = $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do đầy đủ', 'ghi chú đầy đủ', true);

        $batch = $result['batch']->fresh();
        $this->assertSame($booking->id, $batch->booking_id);
        $this->assertSame($this->admin->id, $batch->released_by);
        $this->assertSame('lý do đầy đủ', $batch->reason);
        $this->assertSame('ghi chú đầy đủ', $batch->note);
        $this->assertTrue($batch->reduce_demand);
        $this->assertNotNull($batch->released_at);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => ReleaseBatch::class, 'entity_id' => $batch->id, 'action' => 'created']);
    }

    public function test_rollback_creates_no_release_batch(): void
    {
        $booking = $this->makeBooking(['status' => BookingStatus::Cancelled]);
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        try {
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, false);
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertSame(0, ReleaseBatch::count());
        $this->assertSame(0, \App\Models\AuditLog::where('entity_type', ReleaseBatch::class)->count());
    }

    // ── H. Frontend / Inertia ────────────────────────────────────────────

    public function test_show_page_props_include_can_release_and_requirement_mapping(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'quantity' => 3]);
        $a1 = $this->makeAssignment($booking, $roomType, $requirement->id);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.assignments.0.can_release', true)
                ->where('booking.assignments.0.booking_requirement_id', $requirement->id)
                ->where('can.reduceDemand', true)
            );
    }

    public function test_bulk_release_endpoint_redirects_with_success_flash_mentioning_count(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);
        $a2 = $this->makeAssignment($booking, $roomType);

        $response = $this->post("/admin/bookings/{$booking->id}/assignments/bulk-release", [
            'assignment_ids' => [$a1->id, $a2->id],
            'reason' => 'lý do',
            'reduce_demand' => false,
        ]);

        $response->assertRedirect("/admin/bookings/{$booking->id}?tab=room_map");
        $response->assertSessionHas('success', 'Đã gỡ 2 phòng.');
    }

    public function test_bulk_release_rejects_duplicate_assignment_ids_in_payload(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $response = $this->post("/admin/bookings/{$booking->id}/assignments/bulk-release", [
            'assignment_ids' => [$a1->id, $a1->id],
            'reason' => 'lý do',
            'reduce_demand' => false,
        ]);

        $response->assertSessionHasErrors('assignment_ids.0');
    }

    // ── Final Gap Closure §IV — reason invariant ───────────────────────────

    public function test_bulk_release_request_without_reason_is_rejected(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $response = $this->post("/admin/bookings/{$booking->id}/assignments/bulk-release", [
            'assignment_ids' => [$a1->id],
            'reduce_demand' => false,
        ]);

        $response->assertSessionHasErrors('reason');
        $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status);
        $this->assertSame(0, ReleaseBatch::count());
    }

    public function test_service_called_directly_with_whitespace_only_reason_is_rejected(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        try {
            // Bypasses BulkReleaseAssignmentRequest entirely — the service
            // itself must independently reject a blank reason, not just rely
            // on the FormRequest (Final Gap Closure Mục IV).
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id], '   ', null, false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
            $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status, 'no assignment released');
            $this->assertSame(0, ReleaseBatch::count(), 'no batch created');
        }
    }

    public function test_database_never_accepts_a_release_batch_without_reason_via_the_service(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);
        $a2 = $this->makeAssignment($booking, $roomType);

        try {
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $a2->id], '', null, false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(0, ReleaseBatch::count());
            $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status);
            $this->assertSame(AssignmentStatus::Assigned, $a2->fresh()->status);
        }
    }

    public function test_reason_is_trimmed_and_identical_on_batch_and_every_assignment(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);
        $a2 = $this->makeAssignment($booking, $roomType);

        $result = $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $a2->id], '  Khách đổi ý  ', null, false);

        $this->assertSame('Khách đổi ý', $result['batch']->reason);
        $this->assertSame('Khách đổi ý', $a1->fresh()->release_reason);
        $this->assertSame('Khách đổi ý', $a2->fresh()->release_reason);
    }

    // ── Final Gap Closure §VI — actor consistency ──────────────────────────

    public function test_batch_and_every_assignment_record_the_same_actor(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);
        $a2 = $this->makeAssignment($booking, $roomType);

        $result = $this->assignments->bulkReleaseAssignments($booking, [$a1->id, $a2->id], 'lý do', null, false);

        $this->assertSame($this->admin->id, $result['batch']->released_by);
        $this->assertSame($this->admin->id, $a1->fresh()->released_by);
        $this->assertSame($this->admin->id, $a2->fresh()->released_by);
        $this->assertSame($result['batch']->released_by, $a1->fresh()->released_by);
        $this->assertSame($result['batch']->released_by, $a2->fresh()->released_by);
    }

    public function test_audit_log_records_the_correct_actor_for_the_batch(): void
    {
        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        $result = $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, false);

        $log = \App\Models\AuditLog::where('entity_type', ReleaseBatch::class)
            ->where('entity_id', $result['batch']->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_no_batch_created_when_no_authenticated_actor(): void
    {
        \Illuminate\Support\Facades\Auth::logout();

        $booking = $this->makeBooking();
        $roomType = RoomType::factory()->create();
        $a1 = $this->makeAssignment($booking, $roomType);

        try {
            $this->assignments->bulkReleaseAssignments($booking, [$a1->id], 'lý do', null, false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(0, ReleaseBatch::count());
            $this->assertSame(AssignmentStatus::Assigned, $a1->fresh()->status);
        }
    }
}
