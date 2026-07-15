<?php

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Product Sprint 02 — Room Move: HTTP-level authorization/UI-readiness.
 */
class RoomMoveControllerTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM_PRICE = 800000;

    private User $admin;
    private RoomType $twinType;
    private RoomType $doubleType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
            RoomSeeder::class,
        ]);

        $this->travelTo('2026-07-14 14:00:00');

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
        $this->doubleType = RoomType::where('code', 'DOUBLE')->firstOrFail();
    }

    public function test_admin_can_move_room_to_a_different_room_type(): void
    {
        [$booking, $stay] = $this->checkedInStay();
        $targetRoom = Room::where('room_type_id', $this->doubleType->id)->where('status', '!=', 'OUT_OF_ORDER')->firstOrFail();
        $this->actingAs($this->admin);

        $response = $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/move-room", [
            'new_room_id' => $targetRoom->id,
            'reason' => 'Khách muốn nâng hạng phòng',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame($targetRoom->id, $stay->fresh()->room_id);
    }

    public function test_admin_can_move_room(): void
    {
        [$booking, $stay] = $this->checkedInStay();
        $targetRoom = $this->availableTargetRoom();
        $this->actingAs($this->admin);

        $response = $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/move-room", [
            'new_room_id' => $targetRoom->id,
            'reason' => 'Điều hòa hỏng',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame($targetRoom->id, $stay->fresh()->room_id);
    }

    public function test_manager_and_reception_can_move_room(): void
    {
        foreach (['MANAGER', 'RECEPTION'] as $i => $role) {
            // Each iteration uses a distinct pair of rooms — the prior iteration's
            // rooms remain occupied by its own (unrelated) booking for the same dates.
            [$booking, $stay] = $this->checkedInStay($i * 2);
            $targetRoom = $this->availableTargetRoom($i * 2 + 1);

            $user = User::factory()->create();
            $user->assignRole($role);
            $this->actingAs($user);

            $response = $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/move-room", [
                'new_room_id' => $targetRoom->id,
            ]);

            $response->assertRedirect();
            $response->assertSessionHasNoErrors();
        }
    }

    public function test_unauthorized_role_is_forbidden(): void
    {
        [$booking, $stay] = $this->checkedInStay();
        $targetRoom = $this->availableTargetRoom();

        $accountant = User::factory()->create();
        $accountant->assignRole('ACCOUNTANT');
        $this->actingAs($accountant);

        $response = $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/move-room", [
            'new_room_id' => $targetRoom->id,
        ]);

        $response->assertForbidden();
    }

    public function test_same_room_returns_validation_error(): void
    {
        [$booking, $stay, $currentRoom] = $this->checkedInStay();
        $this->actingAs($this->admin);

        $response = $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/move-room", [
            'new_room_id' => $currentRoom->id,
        ]);

        $response->assertSessionHasErrors('room_id');
    }

    public function test_booking_detail_payload_includes_can_move_room_flags(): void
    {
        [$booking] = $this->checkedInStay();

        $this->actingAs($this->admin)
            ->get("/admin/bookings/{$booking->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.moveRoom', true)
                ->where('booking.stays.0.can_move_room', true)
                ->has('booking.stays.0.room_type_id')
            );
    }

    /**
     * @return array{0: \App\Models\Booking, 1: Stay, 2: Room}
     */
    private function checkedInStay(int $roomOffset = 0): array
    {
        $room = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', 'OUT_OF_ORDER')->orderBy('id')->get()[$roomOffset];

        $this->actingAs($this->admin);
        $booking = app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'Room Move HTTP Guest',
            'customer_phone' => '0900000011',
            'customer_email' => 'room-move-http@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-14 14:00:00',
            'checkout_at' => '2026-07-15 12:00:00',
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
            'requirements' => [
                [
                    'room_type_id' => $this->twinType->id,
                    'quantity' => 1,
                    'adults' => 2,
                    'children_under_6' => 0,
                    'children_over_6' => 0,
                    'room_price' => self::ROOM_PRICE,
                    'price_source' => 'MANUAL',
                ],
            ],
        ]);

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-14 14:00:00', 'end_at' => '2026-07-15 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        return [$booking, $stay, $room];
    }

    private function availableTargetRoom(int $roomOffset = 1): Room
    {
        return Room::where('room_type_id', $this->twinType->id)
            ->where('status', '!=', 'OUT_OF_ORDER')
            ->orderBy('id')
            ->get()[$roomOffset];
    }
}
