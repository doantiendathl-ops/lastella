<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\ReleaseBatch;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Room Demand/Room Board Unification M4 — release_batches schema,
 * room_assignments.release_batch_id FK, and model relationships.
 */
class ReleaseBatchSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_batches_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('release_batches'));
        $this->assertTrue(Schema::hasColumns('release_batches', [
            'id', 'booking_id', 'released_by', 'reason', 'note', 'reduce_demand', 'released_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_room_assignments_has_release_batch_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('room_assignments', 'release_batch_id'));
    }

    public function test_release_batch_id_is_nullable(): void
    {
        $booking = Booking::factory()->create();
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();

        $assignment = RoomAssignment::factory()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
        ]);

        $this->assertNull($assignment->release_batch_id, 'existing/legacy assignments must stay NULL, never backfilled');
    }

    public function test_relationship_resolves_the_linked_batch(): void
    {
        $booking = Booking::factory()->create();
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        $batch = ReleaseBatch::create([
            'booking_id' => $booking->id,
            'released_by' => User::factory()->create()->id,
            'reason' => 'test',
            'note' => null,
            'reduce_demand' => false,
            'released_at' => now(),
        ]);
        $assignment = RoomAssignment::factory()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
            'release_batch_id' => $batch->id,
        ]);

        $this->assertSame($batch->id, $assignment->releaseBatch->id);
        $this->assertTrue($batch->roomAssignments->contains($assignment));
        $this->assertSame($booking->id, $batch->booking->id);
    }

    public function test_unmapped_assignment_still_reads_fine_with_null_relationship(): void
    {
        $booking = Booking::factory()->create();
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        $assignment = RoomAssignment::factory()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
        ]);

        $this->assertNull($assignment->releaseBatch);
    }

    public function test_foreign_key_blocks_deleting_a_referenced_release_batch(): void
    {
        $booking = Booking::factory()->create();
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        $batch = ReleaseBatch::create([
            'booking_id' => $booking->id,
            'released_by' => User::factory()->create()->id,
            'reason' => 'test',
            'note' => null,
            'reduce_demand' => false,
            'released_at' => now(),
        ]);
        RoomAssignment::factory()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
            'release_batch_id' => $batch->id,
        ]);

        $this->expectException(QueryException::class);
        $batch->delete();
    }

    public function test_existing_room_assignments_and_booking_requirement_id_untouched_by_m4_migrations(): void
    {
        $booking = Booking::factory()->create();
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        $requirement = BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);
        $assignment = RoomAssignment::factory()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => $requirement->id,
        ]);

        $this->assertSame($requirement->id, $assignment->fresh()->booking_requirement_id, 'M1 mapping must survive M4 migrations unchanged');
    }

    public function test_migration_rollback_runs_cleanly_and_restores_state(): void
    {
        $countBefore = RoomAssignment::count();

        Artisan::call('migrate:rollback', ['--step' => 2]);

        $this->assertFalse(Schema::hasTable('release_batches'));
        $this->assertFalse(Schema::hasColumn('room_assignments', 'release_batch_id'));
        $this->assertSame($countBefore, RoomAssignment::count(), 'rolling back M4 must not touch existing room_assignments rows');

        Artisan::call('migrate');

        $this->assertTrue(Schema::hasTable('release_batches'));
        $this->assertTrue(Schema::hasColumn('room_assignments', 'release_batch_id'));
    }

    // ── Final Gap Closure §IV/§V ────────────────────────────────────────

    public function test_database_rejects_release_batch_without_reason(): void
    {
        $booking = Booking::factory()->create();

        $this->expectException(QueryException::class);

        \Illuminate\Support\Facades\DB::table('release_batches')->insert([
            'booking_id' => $booking->id,
            'released_by' => null,
            'reason' => null,
            'note' => null,
            'reduce_demand' => false,
            'released_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_booking_deletion_is_blocked_when_a_release_batch_references_it(): void
    {
        $booking = Booking::factory()->create();
        ReleaseBatch::create([
            'booking_id' => $booking->id,
            'released_by' => User::factory()->create()->id,
            'reason' => 'test',
            'note' => null,
            'reduce_demand' => false,
            'released_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $booking->delete();
    }
}
