<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RoomAssignmentService;
use App\Services\RoomAvailabilityCheckerService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User request (2026-08-18 chat): "Hiện tại các phòng đã out thì có thể xem
 * lại trên sơ đồ kiểm tra phòng được không?" — extends docs/yeucaumoi.txt
 * mục 11's historical occupancy principle (already covered for Sơ đồ thao
 * tác by RoomOperationsHistoricalOccupancyTest) to Sơ đồ kiểm tra phòng.
 * A fully-past query range must show a room's CheckedOut booking as
 * historical info ("Đã trả phòng"), but this must NEVER block a genuinely
 * new/future booking query for that same room — the guest already left.
 */
class RoomAvailabilityCheckerHistoricalOccupancyTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM_PRICE = 800000;

    private User $admin;
    private RoomType $twinType;
    private Room $room;

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
        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
        $this->room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function checkedOutStayFixture(): void
    {
        $this->travelTo('2026-08-01 14:00:00');

        $booking = app(BookingService::class)->createBooking([
            'booking_color' => '#FF6600',
            'customer_name' => 'Historical Guest',
            'customer_phone' => '0900000032',
            'customer_email' => 'historical-avail@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-08-01 14:00:00',
            'checkout_at' => '2026-08-02 12:00:00',
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
            'requirements' => [[
                'room_type_id' => $this->twinType->id,
                'quantity' => 1,
                'adults' => 2,
                'children_under_6' => 0,
                'children_over_6' => 0,
                'room_price' => self::ROOM_PRICE,
                'price_source' => 'MANUAL',
            ]],
        ]);

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $this->room->id, 'room_type_id' => $this->room->room_type_id, 'start_at' => '2026-08-01 14:00:00', 'end_at' => '2026-08-02 12:00:00'],
        ]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay, '2026-08-01 14:00:00');

        $this->travelTo('2026-08-02 12:30:00');
        app(StayService::class)->checkOut($stay->fresh(), '2026-08-02 12:30:00', true);

        $this->assertSame(AssignmentStatus::CheckedOut, $assignment->fresh()->status);
    }

    public function test_fully_past_range_shows_the_checked_out_booking(): void
    {
        $this->checkedOutStayFixture();

        // Move "now" well past the stay, so the queried range is genuinely historical.
        $this->travelTo('2026-08-05 09:00:00');

        $room = $this->checkerRoom('2026-08-01 14:00:00', '2026-08-02 12:00:00');

        $this->assertSame('checked_out', $room['availability']);
        $this->assertSame('Đã trả phòng', $room['availability_label']);
        $this->assertSame('#FF6600', $room['primary_color'], 'mục 7 — booking_color still rides along for the historical occupant.');
        $this->assertCount(1, $room['bookings']);
        $this->assertSame('Historical Guest', $room['bookings'][0]['customer_name']);
        $this->assertSame('CHECKED_OUT', $room['bookings'][0]['assignment_status']);
    }

    public function test_range_touching_today_does_not_leak_the_checked_out_booking(): void
    {
        $this->checkedOutStayFixture();
        // "now" stays at 2026-08-02 12:30 — the guest just checked out.

        // Range spans from the stay's own dates through "today" — touches the
        // live/current day, so this must stay on the live-only filter.
        $room = $this->checkerRoom('2026-08-01 14:00:00', '2026-08-03 12:00:00');

        $this->assertSame('available', $room['availability'], 'A range touching today/future must never show a departed guest — that would misrepresent the room as unbookable for no reason.');
        $this->assertCount(0, $room['bookings']);
    }

    public function test_checked_out_booking_never_blocks_a_new_future_booking_query(): void
    {
        $this->checkedOutStayFixture();
        $this->travelTo('2026-08-05 09:00:00');

        // Querying the SAME room for a brand-new future date range must show
        // it as plain available — a CheckedOut guest never blocks a new booking.
        $room = $this->checkerRoom('2026-08-10 14:00:00', '2026-08-11 12:00:00');

        $this->assertSame('available', $room['availability']);
    }

    private function checkerRoom(string $startAt, string $endAt): array
    {
        $result = app(RoomAvailabilityCheckerService::class)->check($startAt, $endAt);

        foreach ($result['floors'] as $floor) {
            foreach ($floor['rooms'] as $room) {
                if ($room['room_id'] === $this->room->id) {
                    return $room;
                }
            }
        }

        $this->fail("Room {$this->room->id} not found in availability checker result.");
    }
}
