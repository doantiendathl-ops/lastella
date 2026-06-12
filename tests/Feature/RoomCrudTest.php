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
