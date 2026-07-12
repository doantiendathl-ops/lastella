<?php

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Models\Room;
use App\Models\RoomType;
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
 * Phase 4.3A Milestone 5: Booking detail page exposes the fields the new
 * Stay Extension UI control (RoomBoardPanel.vue) depends on.
 */
class StayExtendUiReadinessTest extends TestCase
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

        $this->travelTo('2026-07-12 14:00:00');

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_can_extend_flag_is_true_for_a_checked_in_stay(): void
    {
        $booking = $this->bookingWithCheckedInStay();
        $this->actingAs($this->admin);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.extend', true)
                ->where('booking.stays.0.can_extend', true)
            );
    }

    public function test_can_extend_flag_is_false_for_a_reserved_stay_not_yet_checked_in(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();
        $this->actingAs($this->admin);

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);
        app(StayService::class)->createStayFromAssignment($assignment);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.stays.0.can_extend', false)
            );
    }

    public function test_can_extend_flag_is_false_for_a_checked_out_stay(): void
    {
        $booking = $this->bookingWithCheckedInStay();
        $this->actingAs($this->admin);

        app(\App\Services\BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 800000,
            'payment_method' => \App\Enums\PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($booking->stays->first(), null, true);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.stays.0.can_extend', false)
            );
    }

    public function test_extend_permission_flag_is_true_for_manager_and_reception(): void
    {
        $booking = $this->bookingWithCheckedInStay();

        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');
        $this->actingAs($manager)
            ->get("/admin/bookings/{$booking->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can.extend', true));

        $reception = User::factory()->create();
        $reception->assignRole('RECEPTION');
        $this->actingAs($reception)
            ->get("/admin/bookings/{$booking->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can.extend', true));
    }

    public function test_extend_permission_flag_is_false_for_accountant(): void
    {
        $booking = $this->bookingWithCheckedInStay();

        $accountant = User::factory()->create();
        $accountant->assignRole('ACCOUNTANT');

        $this->actingAs($accountant)
            ->get("/admin/bookings/{$booking->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can.extend', false));
    }

    private function bookingWithCheckedInStay(): \App\Models\Booking
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        $this->actingAs($this->admin);
        app(StayService::class)->checkIn($stay);

        return $booking->fresh();
    }

    private function createBooking(array $overrides = []): \App\Models\Booking
    {
        $payload = array_merge([
            'booking_color' => '#196251',
            'customer_name' => 'Extend UI Readiness Guest',
            'customer_phone' => '0900000007',
            'customer_email' => 'extend-ui-readiness@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-12 14:00:00',
            'checkout_at' => '2026-07-13 12:00:00',
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
        ], $overrides);

        $this->actingAs($this->admin);

        return app(BookingService::class)->createBooking($payload);
    }
}
