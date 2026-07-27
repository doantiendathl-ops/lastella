<?php

namespace Tests\Feature;

use App\Enums\HousekeepingAssignmentStatus;
use App\Enums\RoomStatus;
use App\Models\HousekeepingAssignment;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HousekeepingBulkActionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $housekeeping;
    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = tap(User::factory()->create())->assignRole('ADMIN');
        $this->housekeeping = tap(User::factory()->create())->assignRole('HOUSEKEEPING');
        $this->reception = tap(User::factory()->create())->assignRole('RECEPTION');
    }

    public function test_bulk_assign_puts_dirty_rooms_into_pending(): void
    {
        $room1 = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $room2 = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.housekeeping.bulk.assign'), ['room_ids' => [$room1->id, $room2->id]])
            ->assertOk()
            ->assertJsonCount(2, 'succeeded');

        $this->assertDatabaseHas('housekeeping_assignments', ['room_id' => $room1->id, 'status' => HousekeepingAssignmentStatus::Pending->value]);
        $this->assertDatabaseHas('housekeeping_assignments', ['room_id' => $room2->id, 'status' => HousekeepingAssignmentStatus::Pending->value]);
    }

    public function test_bulk_start_fails_independently_for_room_without_pending_assignment(): void
    {
        $room1 = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $assignment = HousekeepingAssignment::factory()->pending()->create(['room_id' => $room1->id]);

        $room2 = Room::factory()->create(['status' => RoomStatus::VacantClean]); // no assignment at all

        $response = $this->actingAs($this->admin)
            ->patchJson(route('admin.housekeeping.bulk.start'), ['room_ids' => [$room1->id, $room2->id]])
            ->assertOk()
            ->assertJsonCount(1, 'succeeded')
            ->assertJsonCount(1, 'failed');

        $this->assertSame($room1->id, $response->json('succeeded.0.room_id'));
        $this->assertSame(RoomStatus::Cleaning, $room1->fresh()->status);
        $this->assertSame(RoomStatus::VacantClean, $room2->fresh()->status);
    }

    public function test_bulk_start_rejects_stale_state_gracefully_this_is_the_concurrency_guard(): void
    {
        // Simulates: page loaded with assignment Pending, but another user already started it
        // (or it moved on) before this bulk submit reaches the server — must not silently overwrite.
        $room = Room::factory()->create(['status' => RoomStatus::Cleaning]);
        HousekeepingAssignment::factory()->inProgress()->create(['room_id' => $room->id]);

        $response = $this->actingAs($this->admin)
            ->patchJson(route('admin.housekeeping.bulk.start'), ['room_ids' => [$room->id]])
            ->assertOk();

        $this->assertSame(1, count($response->json('failed')));
        $this->assertStringContainsString('thay đổi', $response->json('failed.0.reason'));
    }

    public function test_bulk_complete_moves_in_progress_rooms_to_inspected(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Cleaning]);
        $assignment = HousekeepingAssignment::factory()->inProgress()->create(['room_id' => $room->id]);
        \App\Models\CleaningRecord::factory()->create([
            'room_id' => $room->id,
            'assignment_id' => $assignment->id,
            'started_at' => now()->subMinutes(15),
            'completed_at' => null,
        ]);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.housekeeping.bulk.complete'), ['room_ids' => [$room->id]])
            ->assertOk()
            ->assertJsonCount(1, 'succeeded');

        $this->assertSame(RoomStatus::Inspected, $room->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // bulkMarkClean / bulkMarkDirty (Room Operations Simplification)
    // -------------------------------------------------------------------------

    public function test_bulk_mark_clean_succeeds_for_eligible_rooms_and_fails_independently_for_maintenance(): void
    {
        $dirtyRoom = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $maintenanceRoom = Room::factory()->create(['status' => RoomStatus::OutOfOrder]);

        $response = $this->actingAs($this->reception)
            ->patchJson(route('admin.housekeeping.bulk.mark-clean'), ['room_ids' => [$dirtyRoom->id, $maintenanceRoom->id]])
            ->assertOk()
            ->assertJsonCount(1, 'succeeded')
            ->assertJsonCount(1, 'failed');

        $this->assertSame($dirtyRoom->id, $response->json('succeeded.0.room_id'));
        $this->assertSame(RoomStatus::VacantClean, $dirtyRoom->fresh()->status);
        $this->assertSame(RoomStatus::OutOfOrder, $maintenanceRoom->fresh()->status);
    }

    public function test_bulk_mark_dirty_succeeds_for_reception(): void
    {
        $room1 = Room::factory()->create(['status' => RoomStatus::VacantClean]);
        $room2 = Room::factory()->create(['status' => RoomStatus::VacantClean]);

        $this->actingAs($this->reception)
            ->patchJson(route('admin.housekeeping.bulk.mark-dirty'), ['room_ids' => [$room1->id, $room2->id]])
            ->assertOk()
            ->assertJsonCount(2, 'succeeded');

        $this->assertSame(RoomStatus::VacantDirty, $room1->fresh()->status);
        $this->assertSame(RoomStatus::VacantDirty, $room2->fresh()->status);
    }

    public function test_accountant_cannot_bulk_mark_clean(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $accountant = tap(User::factory()->create())->assignRole('ACCOUNTANT');

        $this->actingAs($accountant)
            ->patchJson(route('admin.housekeeping.bulk.mark-clean'), ['room_ids' => [$room->id]])
            ->assertForbidden();
    }

    public function test_reception_cannot_bulk_assign(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->reception)
            ->patchJson(route('admin.housekeeping.bulk.assign'), ['room_ids' => [$room->id]])
            ->assertForbidden();
    }

    public function test_detail_endpoint_returns_room_and_stay_information(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty, 'notes' => 'Ghi chú test']);

        $this->actingAs($this->admin)
            ->getJson(route('admin.housekeeping.detail', $room->id))
            ->assertOk()
            ->assertJsonPath('room.id', $room->id)
            ->assertJsonPath('room.notes', 'Ghi chú test');
    }
}
