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
use App\Services\RoomOperationsBoardService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/yeucaumoi.txt mục 11 — Historical Room Map: a checked-out Booking
 * must still render on the Sơ đồ thao tác board when viewing a past date
 * that falls inside its occupancy interval, but must NOT reappear on the
 * live/current-day board once the guest has actually left (mục 7's booking
 * color as background is verified here too — it rides on the same payload).
 */
class RoomOperationsHistoricalOccupancyTest extends TestCase
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
    }

    private function checkedOutStayFixture(): void
    {
        $this->travelTo('2026-08-01 14:00:00');

        $booking = app(BookingService::class)->createBooking([
            'booking_color' => '#FF6600',
            'customer_name' => 'Historical Guest',
            'customer_phone' => '0900000031',
            'customer_email' => 'historical@example.test',
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

    public function test_past_date_inside_stay_interval_still_shows_the_checked_out_booking(): void
    {
        $this->checkedOutStayFixture();

        // Move "now" well past the stay, so 2026-08-01 is genuinely historical.
        $this->travelTo('2026-08-05 09:00:00');

        $room = $this->boardRoom('2026-08-01');

        $this->assertNotNull($room['occupant'], 'A checked-out booking must still render on a past date inside its interval.');
        $this->assertSame('Historical Guest', $room['occupant']['customer_name']);
        $this->assertSame('#FF6600', $room['occupant']['booking_color'], 'mục 7 — booking_color must ride along on the occupant payload.');
    }

    public function test_date_after_stay_interval_shows_room_as_vacant(): void
    {
        $this->checkedOutStayFixture();
        $this->travelTo('2026-08-05 09:00:00');

        $room = $this->boardRoom('2026-08-03');

        $this->assertNull($room['occupant'], 'A date after the stay ended must show the room as vacant, not occupied.');
    }

    public function test_live_view_on_checkout_day_shows_vacant_not_the_departed_guest(): void
    {
        $this->checkedOutStayFixture();
        // Still "now" = 2026-08-02 12:30 (right after checkout) — no travel forward.

        $room = $this->boardRoom('2026-08-02');

        $this->assertNull($room['occupant'], 'The live/current-day board must reflect real-time state — room is free the moment the guest checks out, even though the date interval still nominally overlaps.');
    }

    private function boardRoom(string $date): array
    {
        $board = app(RoomOperationsBoardService::class)->boardForDate($date, $this->admin);

        foreach ($board['floors'] as $floor) {
            foreach ($floor['rooms'] as $room) {
                if ($room['id'] === $this->room->id) {
                    return $room;
                }
            }
        }

        $this->fail("Room {$this->room->id} not found on board for {$date}.");
    }
}
