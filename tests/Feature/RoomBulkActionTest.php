<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\RoomService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomBulkActionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->reception = User::factory()->create();
        $this->reception->assignRole('RECEPTION');
    }

    public function test_room_board_marks_out_of_order_rooms_as_ineligible_for_bulk(): void
    {
        $room = $this->makeRoom(['status' => RoomStatus::OutOfOrder->value]);

        $this->actingAs($this->admin)
            ->get('/rooms')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Rooms/Index')
                ->where('floors.0.rooms.0.is_eligible_for_bulk', false)
                ->where('floors.0.rooms.0.is_maintenance', true)
            );
    }

    public function test_admin_can_bulk_mark_rooms_out_of_order(): void
    {
        $room1 = $this->makeRoom(['status' => RoomStatus::VacantClean->value]);
        $room2 = $this->makeRoom(['status' => RoomStatus::VacantDirty->value]);

        $this->actingAs($this->admin)
            ->patch('/rooms/bulk/out-of-order', [
                'room_ids' => [$room1->id, $room2->id],
                'reason' => 'Sửa điều hòa',
            ])
            ->assertOk()
            ->assertJsonCount(2, 'succeeded')
            ->assertJsonCount(0, 'failed');

        $this->assertSame(RoomStatus::OutOfOrder, $room1->fresh()->status);
        $this->assertSame(RoomStatus::OutOfOrder, $room2->fresh()->status);
    }

    public function test_bulk_action_reports_per_room_failure_without_losing_other_successes(): void
    {
        $goodRoom = $this->makeRoom(['status' => RoomStatus::VacantClean->value]);
        // Already cleaning — markOutOfOrder() rejects this per existing single-room rule.
        $cleaningRoom = $this->makeRoom(['status' => RoomStatus::Cleaning->value]);

        $response = $this->actingAs($this->admin)
            ->patch('/rooms/bulk/out-of-order', [
                'room_ids' => [$goodRoom->id, $cleaningRoom->id],
                'reason' => 'Kiểm tra hệ thống',
            ])
            ->assertOk()
            ->assertJsonCount(1, 'succeeded')
            ->assertJsonCount(1, 'failed');

        $this->assertSame(RoomStatus::OutOfOrder, $goodRoom->fresh()->status);
        $this->assertSame(RoomStatus::Cleaning, $cleaningRoom->fresh()->status);
        $this->assertSame($goodRoom->id, $response->json('succeeded.0.room_id'));
    }

    public function test_admin_can_bulk_release_rooms_from_maintenance(): void
    {
        $room = $this->makeRoom(['status' => RoomStatus::OutOfOrder->value]);

        $this->actingAs($this->admin)
            ->patch('/rooms/bulk/release', ['room_ids' => [$room->id]])
            ->assertOk()
            ->assertJsonCount(1, 'succeeded');

        $this->assertSame(RoomStatus::VacantDirty, $room->fresh()->status);
    }

    public function test_reception_cannot_bulk_mark_out_of_order(): void
    {
        $room = $this->makeRoom(['status' => RoomStatus::VacantClean->value]);

        $this->actingAs($this->reception)
            ->patch('/rooms/bulk/out-of-order', [
                'room_ids' => [$room->id],
                'reason' => 'X',
            ])
            ->assertForbidden();

        $this->assertSame(RoomStatus::VacantClean, $room->fresh()->status);
    }

    public function test_bulk_action_does_not_trust_arbitrary_room_ids(): void
    {
        $this->actingAs($this->admin)
            ->patch('/rooms/bulk/out-of-order', [
                'room_ids' => [999999],
                'reason' => 'X',
            ])
            ->assertSessionHasErrors('room_ids.0'); // fails validation: exists:rooms,id
    }

    private function makeRoom(array $overrides = []): Room
    {
        $room = app(RoomService::class)->create([
            'floor_id' => Floor::firstOrFail()->id,
            'room_type_id' => RoomType::firstOrFail()->id,
            'room_number' => 'RB-' . random_int(1000, 9999),
            'status' => RoomStatus::VacantClean->value,
        ]);

        if (count($overrides) > 0) {
            $room->update($overrides);
        }

        return $room->fresh();
    }
}
