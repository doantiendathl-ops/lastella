<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
            RoomSeeder::class,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_view_dashboard(): void
    {
        $this->get('/dashboard')
            ->assertOk();
    }

    public function test_dashboard_available_rooms_decreases_when_stay_is_checked_in(): void
    {
        $totalRooms = Room::count();
        $stay = $this->createReservedStay();

        app(StayService::class)->checkIn($stay, '2026-06-14 15:00:00');

        $this->assertDashboardMetrics([
            'available_rooms' => $totalRooms - 1,
            'occupied_rooms' => 1,
        ]);
    }

    public function test_dashboard_occupied_rooms_increases_when_stay_is_checked_in(): void
    {
        $stay = $this->createReservedStay();

        Carbon::setTestNow('2026-06-14 10:00:00');

        $this->assertDashboardMetrics([
            'occupied_rooms' => 0,
        ]);

        Carbon::setTestNow('2026-06-14 14:00:00');

        app(StayService::class)->checkIn($stay, '2026-06-14 15:00:00');

        $this->assertDashboardMetrics([
            'occupied_rooms' => 1,
        ]);
    }

    public function test_dashboard_available_rooms_decreases_for_active_assignment(): void
    {
        $totalRooms = Room::count();
        $stay = $this->createReservedStay();

        Carbon::setTestNow('2026-06-14 15:00:00');

        $this->assertSame(StayStatus::Reserved, $stay->refresh()->status);
        $this->assertDashboardMetrics([
            'available_rooms' => $totalRooms - 1,
            'occupied_rooms' => 1,
        ]);
    }

    public function test_dashboard_available_rooms_increases_again_after_checkout(): void
    {
        $totalRooms = Room::count();
        $stay = $this->createReservedStay();
        $booking = $stay->booking;

        app(BookingPaymentService::class)->addPayment($booking, [
            'payment_type' => PaymentType::RoomPayment->value,
            'amount' => 1,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => '2026-06-14 16:00:00',
        ]);

        $checkedIn = app(StayService::class)->checkIn($stay, '2026-06-14 15:00:00');

        $this->assertDashboardMetrics([
            'available_rooms' => $totalRooms - 1,
            'occupied_rooms' => 1,
        ]);

        app(StayService::class)->checkOut($checkedIn, '2026-06-15 11:00:00');

        $this->assertDashboardMetrics([
            'available_rooms' => $totalRooms,
            'occupied_rooms' => 0,
        ]);
    }

    public function test_out_of_order_rooms_are_not_counted_as_available(): void
    {
        $totalRooms = Room::count();
        Room::query()->firstOrFail()->update(['status' => RoomStatus::OutOfOrder]);

        $this->assertDashboardMetrics([
            'available_rooms' => $totalRooms - 1,
            'out_of_order_rooms' => 1,
        ]);
    }

    private function createReservedStay(): Stay
    {
        Carbon::setTestNow('2026-06-14 14:00:00');

        $room = Room::query()->firstOrFail();
        $booking = Booking::factory()->create([
            'booking_color' => '#196251',
            'customer_type' => CustomerType::Individual,
            'booking_type' => BookingType::Overnight,
            'checkin_at' => '2026-06-14 14:00:00',
            'checkout_at' => '2026-06-15 12:00:00',
            'status' => BookingStatus::FullyAssigned,
            'sales_user_id' => $this->admin->id,
        ]);

        $assignment = RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-06-14 14:00:00',
            'end_at' => '2026-06-15 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        return Stay::create([
            'booking_id' => $booking->id,
            'room_assignment_id' => $assignment->id,
            'room_id' => $room->id,
            'planned_checkin_at' => $assignment->start_at,
            'planned_checkout_at' => $assignment->end_at,
            'status' => StayStatus::Reserved,
        ]);
    }

    private function assertDashboardMetrics(array $metrics): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($metrics): Assert {
                foreach ($metrics as $key => $value) {
                    $page->where("metrics.{$key}", $value);
                }

                return $page;
            });
    }
}
