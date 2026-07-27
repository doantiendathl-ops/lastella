<?php

namespace Tests\Feature;

use App\Enums\ResourceType;
use App\Enums\RoomStatus;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_room_and_matching_resource(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');

        $floor = Floor::where('code', '8')->firstOrFail();
        $roomType = RoomType::where('code', 'DOUBLE')->firstOrFail();

        $this->actingAs($admin)
            ->post('/rooms', [
                'floor_id' => $floor->id,
                'room_type_id' => $roomType->id,
                'room_number' => '909',
                'status' => RoomStatus::VacantClean->value,
            ])
            ->assertRedirect('/rooms');

        $this->assertDatabaseHas('resources', [
            'code' => 'RM-909',
            'type' => ResourceType::Room->value,
        ]);

        $this->assertDatabaseHas('rooms', [
            'room_number' => '909',
            'floor_id' => $floor->id,
            'room_type_id' => $roomType->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'entity_type' => Room::class,
        ]);
    }

    /**
     * Final Consistency Review: the Rooms admin CRUD form bypasses HousekeepingService
     * entirely (it writes `status` directly) — without RoomService's
     * syncCleaningStatusForVacantCluster(), this would desync cleaning_status from a
     * freshly-chosen status. VACANT_DIRTY is one of the four "vacant cluster" values
     * with an unambiguous implied cleanliness, so it must sync.
     */
    public function test_creating_a_room_as_vacant_dirty_syncs_cleaning_status(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
        ]);

        $admin = tap(User::factory()->create())->assignRole('ADMIN');
        $floor = Floor::where('code', '8')->firstOrFail();
        $roomType = RoomType::where('code', 'DOUBLE')->firstOrFail();

        $this->actingAs($admin)->post('/rooms', [
            'floor_id' => $floor->id,
            'room_type_id' => $roomType->id,
            'room_number' => '911',
            'status' => RoomStatus::VacantDirty->value,
        ])->assertRedirect('/rooms');

        $room = Room::where('room_number', '911')->firstOrFail();
        $this->assertEquals(RoomStatus::VacantDirty, $room->status);
        $this->assertEquals('DIRTY', $room->cleaning_status->value);
    }

    public function test_editing_room_status_to_vacant_clean_syncs_cleaning_status(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
        ]);

        $admin = tap(User::factory()->create())->assignRole('ADMIN');
        $floor = Floor::where('code', '8')->firstOrFail();
        $roomType = RoomType::where('code', 'DOUBLE')->firstOrFail();
        $room = Room::factory()->create([
            'floor_id' => $floor->id,
            'room_type_id' => $roomType->id,
            'status' => RoomStatus::VacantDirty,
            'cleaning_status' => 'DIRTY',
        ]);

        $this->actingAs($admin)->put("/rooms/{$room->id}", [
            'floor_id' => $floor->id,
            'room_type_id' => $roomType->id,
            'room_number' => $room->room_number,
            'status' => RoomStatus::VacantClean->value,
        ])->assertRedirect('/rooms');

        $room->refresh();
        $this->assertEquals(RoomStatus::VacantClean, $room->status);
        $this->assertEquals('CLEAN', $room->cleaning_status->value);
    }

    /**
     * Occupied/Reserved/OutOfOrder/OutOfService are NOT unambiguous — editing status
     * to one of those via this admin form must leave cleaning_status untouched
     * (preserve whatever it already was), same rule as every other write path.
     */
    public function test_editing_room_status_to_occupied_does_not_touch_cleaning_status(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
        ]);

        $admin = tap(User::factory()->create())->assignRole('ADMIN');
        $floor = Floor::where('code', '8')->firstOrFail();
        $roomType = RoomType::where('code', 'DOUBLE')->firstOrFail();
        $room = Room::factory()->create([
            'floor_id' => $floor->id,
            'room_type_id' => $roomType->id,
            'status' => RoomStatus::VacantClean,
            'cleaning_status' => 'DIRTY', // deliberately inconsistent-looking to prove it's untouched, not re-derived
        ]);

        $this->actingAs($admin)->put("/rooms/{$room->id}", [
            'floor_id' => $floor->id,
            'room_type_id' => $roomType->id,
            'room_number' => $room->room_number,
            'status' => RoomStatus::Occupied->value,
        ])->assertRedirect('/rooms');

        $room->refresh();
        $this->assertEquals(RoomStatus::Occupied, $room->status);
        $this->assertEquals('DIRTY', $room->cleaning_status->value);
    }

    public function test_user_without_room_permission_cannot_create_room(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
        ]);

        $sales = User::factory()->create();
        $sales->assignRole('SALES');

        $floor = Floor::firstOrFail();
        $roomType = RoomType::firstOrFail();

        $this->actingAs($sales)
            ->post('/rooms', [
                'floor_id' => $floor->id,
                'room_type_id' => $roomType->id,
                'room_number' => '910',
                'status' => RoomStatus::VacantClean->value,
            ])
            ->assertForbidden();
    }
}
