<?php

namespace Tests\Unit\Services;

use App\Enums\CleaningPriority;
use App\Enums\CleaningReason;
use App\Enums\HousekeepingAssignmentStatus;
use App\Enums\InspectionResult;
use App\Enums\RoomStatus;
use App\Models\CleaningRecord;
use App\Models\HousekeepingAssignment;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\HousekeepingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HousekeepingServiceTest extends TestCase
{
    use RefreshDatabase;

    private HousekeepingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HousekeepingService();
    }

    private function actorWithMaintenancePermission(): User
    {
        Permission::firstOrCreate(['name' => 'room.maintenance', 'guard_name' => 'web']);

        $actor = User::factory()->create();
        $actor->givePermissionTo('room.maintenance');

        return $actor;
    }

    private function actorWithHousekeepingClaimPermissions(): User
    {
        Permission::firstOrCreate(['name' => 'housekeeping.assign', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'room.status.update', 'guard_name' => 'web']);

        $actor = User::factory()->create();
        $actor->givePermissionTo(['housekeeping.assign', 'room.status.update']);

        return $actor;
    }

    // -------------------------------------------------------------------------
    // assignRoom
    // -------------------------------------------------------------------------

    public function test_assign_room_creates_pending_assignment(): void
    {
        $room  = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $actor = User::factory()->create();

        $assignment = $this->service->assignRoom($room, null, $actor);

        $this->assertEquals(HousekeepingAssignmentStatus::Pending, $assignment->status);
        $this->assertEquals($room->id, $assignment->room_id);
        $this->assertEquals($actor->id, $assignment->assigned_to);
        $this->assertEquals($actor->id, $assignment->assigned_by);
        $this->assertEquals(CleaningPriority::Normal, $assignment->priority);
        $this->assertEquals(CleaningReason::Checkout, $assignment->reason);
    }

    public function test_assign_room_throws_if_not_dirty(): void
    {
        $room  = Room::factory()->create(['status' => RoomStatus::VacantClean]);
        $actor = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->assignRoom($room, null, $actor);
    }

    public function test_assign_room_throws_if_already_assigned(): void
    {
        $room  = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $actor = User::factory()->create();
        HousekeepingAssignment::factory()->pending()->create(['room_id' => $room->id]);

        $this->expectException(ValidationException::class);

        $this->service->assignRoom($room, null, $actor);
    }

    public function test_self_assign_allowed_for_housekeeping(): void
    {
        $room  = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $actor = User::factory()->create();

        $assignment = $this->service->assignRoom($room, $actor, $actor);

        $this->assertEquals($actor->id, $assignment->assigned_to);
    }

    public function test_hk_cannot_assign_to_other_user(): void
    {
        $room     = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $actor    = User::factory()->create();
        $otherHk  = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->service->assignRoom($room, $otherHk, $actor);
    }

    public function test_manager_can_assign_to_other_user(): void
    {
        $room    = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $manager = $this->actorWithMaintenancePermission();
        $hk      = User::factory()->create();

        $assignment = $this->service->assignRoom($room, $hk, $manager);

        $this->assertEquals($hk->id, $assignment->assigned_to);
        $this->assertEquals($manager->id, $assignment->assigned_by);
    }

    // -------------------------------------------------------------------------
    // startCleaning
    // -------------------------------------------------------------------------

    public function test_start_cleaning_transitions_room_to_cleaning(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $actor = User::factory()->create();
        $assignment = HousekeepingAssignment::factory()->pending()->create([
            'room_id'     => $room->id,
            'assigned_to' => $actor->id,
        ]);

        $result = $this->service->startCleaning($assignment, $actor);

        $this->assertEquals(HousekeepingAssignmentStatus::InProgress, $result->status);
        $this->assertNotNull($result->started_at);
        $this->assertEquals(RoomStatus::Cleaning, $room->refresh()->status);
        $this->assertDatabaseHas('cleaning_records', [
            'assignment_id'      => $assignment->id,
            'room_id'            => $room->id,
            'cleaned_by'         => $actor->id,
            'room_status_before' => RoomStatus::VacantDirty->value,
        ]);
    }

    public function test_start_cleaning_throws_if_not_pending(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Cleaning]);
        $actor = User::factory()->create();
        $assignment = HousekeepingAssignment::factory()->inProgress()->create([
            'room_id'     => $room->id,
            'assigned_to' => $actor->id,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->startCleaning($assignment, $actor);
    }

    public function test_start_cleaning_throws_if_not_assigned_user_without_permission(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $assignee = User::factory()->create();
        $otherHk  = User::factory()->create();
        $assignment = HousekeepingAssignment::factory()->pending()->create([
            'room_id'     => $room->id,
            'assigned_to' => $assignee->id,
        ]);

        $this->expectException(AuthorizationException::class);

        $this->service->startCleaning($assignment, $otherHk);
    }

    // -------------------------------------------------------------------------
    // startCleaning — ADR-90 auto-claim (Milestone 5.1)
    // -------------------------------------------------------------------------

    public function test_start_cleaning_auto_claims_unassigned_assignment(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $actor = $this->actorWithHousekeepingClaimPermissions();
        $assignment = HousekeepingAssignment::factory()->pending()->create([
            'room_id'     => $room->id,
            'assigned_to' => null,
        ]);

        $result = $this->service->startCleaning($assignment, $actor);

        $this->assertEquals(HousekeepingAssignmentStatus::InProgress, $result->status);
        $this->assertEquals($actor->id, $result->assigned_to);
        $this->assertEquals(RoomStatus::Cleaning, $room->refresh()->status);
        $this->assertDatabaseHas('housekeeping_assignments', [
            'id'          => $assignment->id,
            'assigned_to' => $actor->id,
            'status'      => HousekeepingAssignmentStatus::InProgress->value,
        ]);
    }

    public function test_start_cleaning_does_not_auto_claim_without_housekeeping_assign_permission(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $actor = User::factory()->create(); // no permissions at all
        $assignment = HousekeepingAssignment::factory()->pending()->create([
            'room_id'     => $room->id,
            'assigned_to' => null,
        ]);

        $this->expectException(AuthorizationException::class);

        $this->service->startCleaning($assignment, $actor);
    }

    public function test_start_cleaning_does_not_auto_claim_without_room_status_update_permission(): void
    {
        Permission::firstOrCreate(['name' => 'housekeeping.assign', 'guard_name' => 'web']);
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $actor = User::factory()->create();
        $actor->givePermissionTo('housekeeping.assign'); // missing room.status.update
        $assignment = HousekeepingAssignment::factory()->pending()->create([
            'room_id'     => $room->id,
            'assigned_to' => null,
        ]);

        $this->expectException(AuthorizationException::class);

        $this->service->startCleaning($assignment, $actor);
    }

    public function test_start_cleaning_does_not_reassign_an_already_assigned_assignment(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $owner = User::factory()->create();
        $otherActor = $this->actorWithHousekeepingClaimPermissions();
        $assignment = HousekeepingAssignment::factory()->pending()->create([
            'room_id'     => $room->id,
            'assigned_to' => $owner->id,
        ]);

        // Another user with claim-eligible permissions still cannot start someone else's
        // assignment — auto-claim only applies when assigned_to is null.
        $this->expectException(AuthorizationException::class);

        $this->service->startCleaning($assignment, $otherActor);
    }

    public function test_start_cleaning_manager_with_room_maintenance_still_works_on_unassigned_assignment(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $manager = $this->actorWithMaintenancePermission();
        $assignment = HousekeepingAssignment::factory()->pending()->create([
            'room_id'     => $room->id,
            'assigned_to' => null,
        ]);

        $result = $this->service->startCleaning($assignment, $manager);

        $this->assertEquals(HousekeepingAssignmentStatus::InProgress, $result->status);
        $this->assertEquals(RoomStatus::Cleaning, $room->refresh()->status);
    }

    // -------------------------------------------------------------------------
    // completeCleaning
    // -------------------------------------------------------------------------

    public function test_complete_cleaning_transitions_room_to_inspected(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Cleaning]);
        $actor = User::factory()->create();
        $assignment = HousekeepingAssignment::factory()->inProgress()->create([
            'room_id'     => $room->id,
            'assigned_to' => $actor->id,
        ]);
        CleaningRecord::factory()->create([
            'room_id'       => $room->id,
            'assignment_id' => $assignment->id,
            'cleaned_by'    => $actor->id,
            'started_at'    => now()->subMinutes(20),
            'completed_at'  => null,
        ]);

        $record = $this->service->completeCleaning($assignment, $actor, 'Đã dọn xong');

        $this->assertNotNull($record->completed_at);
        $this->assertEquals('Đã dọn xong', $record->cleaning_notes);
        $this->assertEquals(HousekeepingAssignmentStatus::Done, $assignment->refresh()->status);
        $this->assertEquals(RoomStatus::Inspected, $room->refresh()->status);
        $this->assertNotNull($room->last_cleaned_at);
    }

    public function test_complete_cleaning_computes_duration(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Cleaning]);
        $actor = User::factory()->create();
        $assignment = HousekeepingAssignment::factory()->inProgress()->create([
            'room_id'     => $room->id,
            'assigned_to' => $actor->id,
        ]);
        CleaningRecord::factory()->create([
            'room_id'       => $room->id,
            'assignment_id' => $assignment->id,
            'cleaned_by'    => $actor->id,
            'started_at'    => now()->subMinutes(22),
            'completed_at'  => null,
        ]);

        $record = $this->service->completeCleaning($assignment, $actor);

        $this->assertEquals(22, $record->duration_minutes);
    }

    public function test_complete_cleaning_throws_if_no_open_record(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Cleaning]);
        $actor = User::factory()->create();
        $assignment = HousekeepingAssignment::factory()->inProgress()->create([
            'room_id'     => $room->id,
            'assigned_to' => $actor->id,
        ]);

        $this->expectException(LogicException::class);

        $this->service->completeCleaning($assignment, $actor);
    }

    // -------------------------------------------------------------------------
    // passInspection / failInspection / skipInspection
    // -------------------------------------------------------------------------

    public function test_pass_inspection_transitions_to_vacant_clean(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Inspected]);
        $inspector = User::factory()->create();
        CleaningRecord::factory()->awaitingInspection()->create(['room_id' => $room->id]);

        $record = $this->service->passInspection($room, $inspector, 'Đạt tiêu chuẩn');

        $this->assertEquals(InspectionResult::Pass, $record->inspection_result);
        $this->assertEquals($inspector->id, $record->inspected_by);
        $this->assertEquals(RoomStatus::VacantClean, $room->refresh()->status);
    }

    public function test_fail_inspection_transitions_to_vacant_dirty(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Inspected]);
        $inspector = User::factory()->create();
        CleaningRecord::factory()->awaitingInspection()->create(['room_id' => $room->id]);

        $record = $this->service->failInspection($room, $inspector, 'Chưa sạch');

        $this->assertEquals(InspectionResult::Fail, $record->inspection_result);
        $this->assertEquals(RoomStatus::VacantDirty, $room->refresh()->status);
    }

    public function test_fail_inspection_auto_escalates_priority(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Inspected]);
        $inspector = User::factory()->create();
        CleaningRecord::factory()->awaitingInspection()->create(['room_id' => $room->id]);

        $this->service->failInspection($room, $inspector, 'Chưa sạch');

        $this->assertDatabaseHas('housekeeping_assignments', [
            'room_id'  => $room->id,
            'status'   => HousekeepingAssignmentStatus::Pending->value,
            'priority' => CleaningPriority::High->value,
        ]);
    }

    public function test_skip_inspection_requires_notes(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Inspected]);
        $inspector = User::factory()->create();
        CleaningRecord::factory()->awaitingInspection()->create(['room_id' => $room->id]);

        $this->expectException(ValidationException::class);

        $this->service->skipInspection($room, $inspector, '   ');
    }

    public function test_skip_inspection_transitions_to_vacant_clean(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Inspected]);
        $inspector = User::factory()->create();
        CleaningRecord::factory()->awaitingInspection()->create(['room_id' => $room->id]);

        $record = $this->service->skipInspection($room, $inspector, 'Quản lý bỏ qua vì khách VIP quay lại gấp');

        $this->assertEquals(InspectionResult::Skip, $record->inspection_result);
        $this->assertEquals('Quản lý bỏ qua vì khách VIP quay lại gấp', $record->inspection_notes);
        $this->assertEquals(RoomStatus::VacantClean, $room->refresh()->status);
    }

    // -------------------------------------------------------------------------
    // markOutOfOrder / releaseFromOutOfOrder
    // -------------------------------------------------------------------------

    public function test_mark_out_of_order_cancels_active_assignment(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $actor = User::factory()->create();
        $assignment = HousekeepingAssignment::factory()->pending()->create(['room_id' => $room->id]);

        $this->service->markOutOfOrder($room, $actor, 'Hỏng điều hòa');

        $this->assertEquals(RoomStatus::OutOfOrder, $room->refresh()->status);
        $this->assertEquals(HousekeepingAssignmentStatus::Cancelled, $assignment->refresh()->status);
        $this->assertEquals($actor->id, $assignment->cancelled_by);
    }

    public function test_mark_out_of_order_throws_if_room_is_cleaning(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Cleaning]);
        $actor = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->markOutOfOrder($room, $actor, 'Hỏng điều hòa');
    }

    public function test_release_from_out_of_order_sets_target_status(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::OutOfOrder]);
        $actor = User::factory()->create();

        $result = $this->service->releaseFromOutOfOrder($room, $actor);

        $this->assertEquals(RoomStatus::VacantDirty, $result->status);
    }

    // -------------------------------------------------------------------------
    // autoMarkOccupied / autoMarkDirtyOnCheckout (non-throwing hooks — ADR-84)
    // -------------------------------------------------------------------------

    public function test_auto_mark_occupied_updates_room_status(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Reserved]);
        $stay = Stay::factory()->create(['room_id' => $room->id]);

        $this->service->autoMarkOccupied($stay);

        $this->assertEquals(RoomStatus::Occupied, $room->refresh()->status);
    }

    public function test_auto_mark_occupied_never_throws(): void
    {
        $stay = Stay::factory()->make(['room_id' => 999999]);

        $this->service->autoMarkOccupied($stay);

        $this->assertTrue(true);
    }

    public function test_auto_mark_dirty_creates_checkout_assignment(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Occupied]);
        $stay = Stay::factory()->create(['room_id' => $room->id]);

        $this->service->autoMarkDirtyOnCheckout($stay);

        $this->assertEquals(RoomStatus::VacantDirty, $room->refresh()->status);
        $this->assertDatabaseHas('housekeeping_assignments', [
            'room_id'  => $room->id,
            'status'   => HousekeepingAssignmentStatus::Pending->value,
            'reason'   => CleaningReason::Checkout->value,
            'priority' => CleaningPriority::Normal->value,
        ]);
    }

    public function test_auto_mark_dirty_on_checkout_never_throws(): void
    {
        Log::shouldReceive('error')->once();

        $stay = Stay::factory()->make(['room_id' => 999999]);

        $this->service->autoMarkDirtyOnCheckout($stay);

        $this->assertDatabaseMissing('housekeeping_assignments', ['room_id' => 999999]);
    }
}
