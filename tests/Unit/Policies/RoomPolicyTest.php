<?php

namespace Tests\Unit\Policies;

use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Policies\RoomPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_with_rooms_manage_can_manage_rooms(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $policy = new RoomPolicy();

        $this->assertTrue($policy->viewAny($manager));
        $this->assertTrue($policy->create($manager));
    }

    public function test_sales_role_cannot_manage_rooms(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $sales = User::factory()->create();
        $sales->assignRole('SALES');

        $policy = new RoomPolicy();

        $this->assertFalse($policy->viewAny($sales));
        $this->assertFalse($policy->create($sales));
    }

    /**
     * Phase 4.2 Milestone 3 (ADR-89): HOUSEKEEPING lost rooms.manage — can view
     * the board via housekeeping.view but cannot CRUD rooms.
     */
    public function test_housekeeping_can_view_but_cannot_manage_rooms(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $housekeeping = User::factory()->create();
        $housekeeping->assignRole('HOUSEKEEPING');
        $room = Room::factory()->create();

        $policy = new RoomPolicy();

        $this->assertTrue($policy->viewAny($housekeeping));
        $this->assertTrue($policy->view($housekeeping, $room));
        $this->assertFalse($policy->create($housekeeping));
        $this->assertFalse($policy->update($housekeeping, $room));
        $this->assertFalse($policy->delete($housekeeping, $room));
    }

    /**
     * Phase 4.2 Milestone 3: RECEPTION gained housekeeping.view (read-only board access).
     */
    public function test_reception_can_view_but_cannot_manage_rooms(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $reception = User::factory()->create();
        $reception->assignRole('RECEPTION');
        $room = Room::factory()->create();

        $policy = new RoomPolicy();

        $this->assertTrue($policy->viewAny($reception));
        $this->assertTrue($policy->view($reception, $room));
        $this->assertFalse($policy->create($reception));
    }

    /**
     * M3-02 (ChatGPT M3 review, hardened in M4): HTTP-level confirmation that HOUSEKEEPING
     * is blocked from Room CRUD routes, not just the policy unit assertions above.
     */
    public function test_housekeeping_gets_403_on_room_crud_routes(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $housekeeping = User::factory()->create();
        $housekeeping->assignRole('HOUSEKEEPING');
        $floor = Floor::factory()->create();
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->create(['floor_id' => $floor->id, 'room_type_id' => $roomType->id]);

        // Valid payloads so the FormRequest (authorize() hardcoded true, real gate is in
        // the controller) passes validation and actually reaches the $this->authorize() call.
        $validPayload = [
            'floor_id' => $floor->id,
            'room_type_id' => $roomType->id,
            'room_number' => '999',
            'status' => 'VACANT_CLEAN',
        ];

        $this->actingAs($housekeeping)->post('/rooms', $validPayload)->assertForbidden();
        $this->actingAs($housekeeping)->put("/rooms/{$room->id}", array_merge($validPayload, ['room_number' => $room->room_number]))->assertForbidden();
        $this->actingAs($housekeeping)->delete("/rooms/{$room->id}")->assertForbidden();
    }

    /**
     * M3-02: HOUSEKEEPING can still read the room index (housekeeping.view OR-gate),
     * confirming the read/write split is enforced end-to-end, not just at create/update/delete.
     */
    public function test_housekeeping_gets_200_on_room_index(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $housekeeping = User::factory()->create();
        $housekeeping->assignRole('HOUSEKEEPING');

        $this->actingAs($housekeeping)->get('/rooms')->assertOk();
    }
}
