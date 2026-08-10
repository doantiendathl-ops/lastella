<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RoomAssignmentService;
use App\Services\RoomOperationsBoardService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mục XXXIV — Daily Booking Summary: ALL bookings intersecting the selected
 * date, not only bookings with a room assignment.
 */
class RoomOperationsDailySummaryTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM_PRICE = 800000;

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

        $this->travelTo('2026-08-01 10:00:00');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_booking_intersecting_the_date_is_included(): void
    {
        $this->createBookingWithAssignment('2026-08-01 14:00:00', '2026-08-03 12:00:00', 'in-range@example.test');

        $summary = app(RoomOperationsBoardService::class)->dailySummaryForDate('2026-08-02', $this->admin);

        $this->assertCount(1, $summary);
        $this->assertSame('Room Ops Guest', $summary[0]['customer_name']);
    }

    public function test_booking_outside_the_date_is_excluded(): void
    {
        $this->createBookingWithAssignment('2026-08-01 14:00:00', '2026-08-02 12:00:00', 'earlier@example.test');

        $summary = app(RoomOperationsBoardService::class)->dailySummaryForDate('2026-08-10', $this->admin);

        $this->assertCount(0, $summary);
    }

    public function test_cancelled_booking_is_excluded(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBookingWithAssignment('2026-08-01 14:00:00', '2026-08-02 12:00:00', 'cancel-me@example.test');
        app(BookingService::class)->cancelBooking($booking, 'Khách hủy');

        $summary = app(RoomOperationsBoardService::class)->dailySummaryForDate('2026-08-01', $this->admin);

        $this->assertCount(0, $summary);
    }

    public function test_summary_includes_folio_totals_via_payment_projection(): void
    {
        $this->createBookingWithAssignment('2026-08-01 14:00:00', '2026-08-02 12:00:00', 'folio@example.test');

        $summary = app(RoomOperationsBoardService::class)->dailySummaryForDate('2026-08-01', $this->admin);

        $this->assertSame((float) self::ROOM_PRICE, $summary[0]['folio_total']);
        $this->assertSame(0.0, $summary[0]['paid']);
        $this->assertSame((float) self::ROOM_PRICE, $summary[0]['outstanding']);
    }

    public function test_booking_without_room_assignment_is_still_included(): void
    {
        app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'No Room Yet',
            'customer_phone' => '0900000099',
            'customer_email' => 'no-room@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-08-01 14:00:00',
            'checkout_at' => '2026-08-02 12:00:00',
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

        $summary = app(RoomOperationsBoardService::class)->dailySummaryForDate('2026-08-01', $this->admin);

        $this->assertCount(1, $summary);
        $this->assertSame('No Room Yet', $summary[0]['customer_name']);
        $this->assertCount(0, $summary[0]['rooms']);
    }

    private function createBookingWithAssignment(string $checkinAt, string $checkoutAt, string $email)
    {
        $booking = app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'Room Ops Guest',
            'customer_phone' => '0900000040',
            'customer_email' => $email,
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => $checkinAt,
            'checkout_at' => $checkoutAt,
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

        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => $checkinAt, 'end_at' => $checkoutAt],
        ]);
        app(StayService::class)->createStayFromAssignment($assignment);

        return $booking;
    }
}
