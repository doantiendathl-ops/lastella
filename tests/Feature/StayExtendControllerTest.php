<?php

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Models\Booking;
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
use Tests\TestCase;

/**
 * Phase 4.3A Milestone 2: HTTP-level authorization + validation for Stay Extension.
 */
class StayExtendControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private RoomType $twinType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
            RoomSeeder::class,
        ]);

        $this->travelTo('2026-07-11 14:00:00');

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_admin_can_extend(): void
    {
        [$booking, $stay] = $this->checkedInStay();
        $this->actingAs($this->admin);

        $response = $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/extend", [
            'new_planned_checkout_at' => '2026-07-14 12:00:00',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame('2026-07-14 12:00:00', $stay->refresh()->planned_checkout_at->toDateTimeString());
    }

    public function test_manager_can_extend(): void
    {
        [$booking, $stay] = $this->checkedInStay();
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');
        $this->actingAs($manager);

        $response = $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/extend", [
            'new_planned_checkout_at' => '2026-07-14 12:00:00',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_reception_can_extend(): void
    {
        [$booking, $stay] = $this->checkedInStay();
        $reception = User::factory()->create();
        $reception->assignRole('RECEPTION');
        $this->actingAs($reception);

        $response = $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/extend", [
            'new_planned_checkout_at' => '2026-07-14 12:00:00',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_unauthorized_role_is_forbidden(): void
    {
        [$booking, $stay] = $this->checkedInStay();
        $accountant = User::factory()->create();
        $accountant->assignRole('ACCOUNTANT');
        $this->actingAs($accountant);

        $response = $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/extend", [
            'new_planned_checkout_at' => '2026-07-14 12:00:00',
        ]);

        $response->assertForbidden();
    }

    public function test_same_or_earlier_date_returns_validation_error(): void
    {
        [$booking, $stay] = $this->checkedInStay();
        $this->actingAs($this->admin);

        $response = $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/extend", [
            'new_planned_checkout_at' => '2026-07-12 08:00:00',
        ]);

        $response->assertSessionHasErrors('new_planned_checkout_at');
    }

    /**
     * @return array{0: Booking, 1: Stay}
     */
    private function checkedInStay(): array
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();

        $this->actingAs($this->admin);
        $booking = app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'Stay Extend HTTP Guest',
            'customer_phone' => '0900000004',
            'customer_email' => 'stay-extend-http@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-11 14:00:00',
            'checkout_at' => '2026-07-12 12:00:00',
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
                    'room_price' => 800000,
                    'price_source' => 'MANUAL',
                ],
            ],
        ]);

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-11 14:00:00', 'end_at' => '2026-07-12 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        return [$booking, $stay];
    }
}
