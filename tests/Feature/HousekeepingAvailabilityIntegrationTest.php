<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 4.2 Milestone 4: verifies the Room Availability board (existing Phase 3 Inertia
 * endpoint — not touched structurally) correctly reflects the new CLEANING availability
 * state without disturbing BOOKED/OCCUPIED/CHECKED_IN handling.
 */
class HousekeepingAvailabilityIntegrationTest extends TestCase
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

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_cleaning_room_shows_cleaning_availability(): void
    {
        $room = $this->roomForType('TWIN');
        $room->update(['status' => RoomStatus::Cleaning]);

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-02 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'cleaning'
                        )
                ));
    }

    public function test_out_of_order_room_shows_out_of_order_availability(): void
    {
        $room = $this->roomForType('TWIN');
        $room->update(['status' => RoomStatus::OutOfOrder]);

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-02 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'out_of_order'
                        )
                ));
    }

    public function test_vacant_clean_room_shows_available(): void
    {
        $room = $this->roomForType('TWIN');
        $room->update(['status' => RoomStatus::VacantClean]);

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-02 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'available'
                        )
                ));
    }

    public function test_cleaning_room_is_excluded_from_room_type_remaining_count(): void
    {
        $room = $this->roomForType('TWIN');
        $room->update(['status' => RoomStatus::Cleaning]);
        $totalTwin = Room::where('room_type_id', $this->twinType->id)->count();

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-02 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.room_type_summary', fn ($summary): bool =>
                    collect($summary)->contains(fn (array $s): bool =>
                        $s['room_type_code'] === 'TWIN' && $s['remaining'] === $totalTwin - 1
                    )
                ));
    }

    public function test_checked_in_room_still_shows_occupied_not_cleaning(): void
    {
        // Relative-to-now dates — immune to the hardcoded-date drift that affects
        // the pre-existing RoomAvailabilityCheckerTest/BookingManagementUiTest suites.
        $startAt = now()->addDay()->setTime(14, 0);
        $endAt = now()->addDays(2)->setTime(12, 0);

        // Room 101 (the first TWIN room by id, per RoomSeeder's authoritative map) is
        // seeded as OutOfOrder by design — reset to VacantClean so this test observes
        // the availability derived purely from the RoomAssignment, not the seed baseline.
        $room = $this->roomForType('TWIN');
        $room->update(['status' => RoomStatus::VacantClean]);

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id'   => $booking->id,
            'room_id'      => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at'     => $startAt,
            'end_at'       => $endAt,
            'status'       => AssignmentStatus::CheckedIn,
            'assigned_by'  => $this->admin->id,
        ]);

        $this->get('/admin/room-availability?start_at=' . $startAt->format('Y-m-d H:i') . '&end_at=' . $endAt->format('Y-m-d H:i'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'occupied'
                        )
                ));
    }

    private function roomForType(string $code): Room
    {
        $roomType = RoomType::where('code', $code)->firstOrFail();

        return Room::where('room_type_id', $roomType->id)->firstOrFail();
    }
}
