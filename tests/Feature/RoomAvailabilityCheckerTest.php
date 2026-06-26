<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
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

class RoomAvailabilityCheckerTest extends TestCase
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

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');
    }

    // --- Authorization & Structure ---

    public function test_authorized_user_can_view_room_availability_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/room-availability')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/RoomAvailability/Index'));
    }

    public function test_user_without_permission_is_rejected_with_403(): void
    {
        $housekeeping = User::factory()->create();
        $housekeeping->assignRole('HOUSEKEEPING');

        $this->actingAs($housekeeping)
            ->get('/admin/room-availability')
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/room-availability')->assertRedirect('/login');
    }

    public function test_response_includes_required_structure(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/room-availability')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('availability.range.start')
                ->has('availability.range.end')
                ->has('availability.room_type_summary')
                ->has('availability.floors')
                ->has('filters.start_at')
                ->has('filters.end_at')
                ->has('can.viewBooking')
            );
    }

    // --- Required Business Logic Tests ---

    public function test_assigned_room_in_range_shows_as_reserved_and_blocks(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-02 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'reserved'
                        )
                )
            );
    }

    public function test_assigned_room_outside_range_shows_as_available(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-05 14:00:00',
            'end_at' => '2026-08-06 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-02 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'available'
                        )
                )
            );
    }

    public function test_checked_in_room_whose_dates_do_not_overlap_search_is_available(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-06-10 14:00:00',
            'end_at' => '2026-06-11 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-02 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'available'
                        )
                )
            );
    }

    public function test_checked_in_room_whose_dates_overlap_search_shows_as_occupied(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-03 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get('/admin/room-availability?start_at=2026-08-02 14:00&end_at=2026-08-04 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'occupied'
                        )
                )
            );
    }

    public function test_checked_out_room_does_not_block_availability(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-02 12:00:00',
            'status' => AssignmentStatus::CheckedOut,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-02 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'available'
                        )
                )
            );
    }

    public function test_released_assignment_does_not_block_availability(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-02 12:00:00',
            'status' => AssignmentStatus::Released,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-02 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'available'
                        )
                )
            );
    }

    public function test_room_type_summary_counts_only_overlapping_blocking_statuses(): void
    {
        $this->actingAs($this->admin);
        $roomType = RoomType::where('code', 'TWIN')->firstOrFail();
        $rooms = Room::where('room_type_id', $roomType->id)->orderBy('room_number')->limit(3)->get();

        $this->assertGreaterThanOrEqual(3, $rooms->count(), 'Need at least 3 TWIN rooms for this test.');

        $booking1 = Booking::factory()->create();
        $booking2 = Booking::factory()->create();
        $booking3 = Booking::factory()->create();

        // Room 1: Assigned (reserved) in range → blocks
        RoomAssignment::create([
            'booking_id' => $booking1->id,
            'room_id' => $rooms[0]->id,
            'room_type_id' => $roomType->id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        // Room 2: CheckedIn overlapping range → blocks
        RoomAssignment::create([
            'booking_id' => $booking2->id,
            'room_id' => $rooms[1]->id,
            'room_type_id' => $roomType->id,
            'start_at' => '2026-07-31 14:00:00',
            'end_at' => '2026-08-05 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        // Room 3: CheckedIn but dates do NOT overlap range → available
        RoomAssignment::create([
            'booking_id' => $booking3->id,
            'room_id' => $rooms[2]->id,
            'room_type_id' => $roomType->id,
            'start_at' => '2026-06-10 14:00:00',
            'end_at' => '2026-06-11 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        $totalTwin = Room::where('room_type_id', $roomType->id)->count();

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-02 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.room_type_summary', fn ($summary): bool =>
                    collect($summary)->contains(fn (array $rt): bool =>
                        $rt['room_type_code'] === 'TWIN'
                        && $rt['occupied'] === 2
                        && $rt['remaining'] === $totalTwin - 2
                    )
                )
            );
    }

    public function test_room_board_shows_non_overlapping_checked_in_as_available_with_info(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');

        $otherBooking = Booking::factory()->create(['customer_name' => 'Other Guest']);
        RoomAssignment::create([
            'booking_id' => $otherBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-06-10 14:00:00',
            'end_at' => '2026-06-11 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        // Room Board: non-overlapping CHECKED_IN → available with info_booking
        $newBooking = Booking::factory()->create([
            'checkin_at' => '2026-08-01 14:00:00',
            'checkout_at' => '2026-08-02 12:00:00',
        ]);

        $this->get("/admin/bookings/{$newBooking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['id'] === $room->id
                            && $r['availability_status'] === 'available'
                            && $r['info_booking'] !== null
                            && $r['info_booking']['booking_code'] === $otherBooking->booking_code
                        )
                )
            );
    }

    public function test_room_board_shows_overlapping_checked_in_as_conflict(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');

        $otherBooking = Booking::factory()->create(['customer_name' => 'Overlapping Guest']);
        RoomAssignment::create([
            'booking_id' => $otherBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-03 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        $newBooking = Booking::factory()->create([
            'checkin_at' => '2026-08-02 14:00:00',
            'checkout_at' => '2026-08-04 12:00:00',
        ]);

        $this->get("/admin/bookings/{$newBooking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['id'] === $room->id
                            && $r['availability_status'] === 'conflict'
                        )
                )
            );
    }

    // --- Boundary Touching Ranges ---

    public function test_checkout_at_boundary_touching_next_checkin_does_not_overlap(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');

        $booking1 = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking1->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get('/admin/room-availability?start_at=2026-08-02 12:00&end_at=2026-08-03 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'available'
                        )
                )
            );
    }

    public function test_overlapping_assigned_rooms_are_blocked(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-03 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get('/admin/room-availability?start_at=2026-08-02 14:00&end_at=2026-08-04 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'reserved'
                        )
                )
            );
    }

    // --- Multi-booking no-overlap ---

    public function test_same_room_multiple_non_overlapping_assignments_shows_multi_booking(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');

        $booking1 = Booking::factory()->create();
        $booking2 = Booking::factory()->create();

        RoomAssignment::create([
            'booking_id' => $booking1->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);
        RoomAssignment::create([
            'booking_id' => $booking2->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-02 14:00:00',
            'end_at' => '2026-08-03 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-03 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'multi_booking'
                        )
                )
            );
    }

    // --- Out of order ---

    public function test_out_of_order_room_blocks_availability(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');
        $room->update(['status' => \App\Enums\RoomStatus::OutOfOrder]);

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-02 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'out_of_order'
                        )
                )
            );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // REGRESSION: Date range overlap filtering for CHECKED_IN assignments
    // ═══════════════════════════════════════════════════════════════════════

    public function test_checked_in_booking_ending_before_search_period_is_not_visible(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('DOUBLE');

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-06-24 14:00:00',
            'end_at' => '2026-06-26 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get('/admin/room-availability?start_at=2026-06-28 14:00&end_at=2026-06-29 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id
                            && $r['availability'] === 'available'
                            && $r['booking_count'] === 0
                        )
                )
            );
    }

    public function test_checked_in_booking_starting_after_search_period_is_not_visible(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('DOUBLE');

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-10 14:00:00',
            'end_at' => '2026-08-12 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-02 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id
                            && $r['availability'] === 'available'
                            && $r['booking_count'] === 0
                        )
                )
            );
    }

    public function test_checked_in_booking_with_partial_overlap_is_visible(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('DOUBLE');

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-03 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get('/admin/room-availability?start_at=2026-08-02 14:00&end_at=2026-08-04 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id
                            && $r['availability'] === 'occupied'
                            && $r['booking_count'] === 1
                        )
                )
            );
    }

    public function test_checked_in_checkout_equals_search_start_is_not_visible(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('DOUBLE');

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-02 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        // search_start == assignment.end_at → no overlap (strict inequality)
        $this->get('/admin/room-availability?start_at=2026-08-02 12:00&end_at=2026-08-03 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'available'
                        )
                )
            );
    }

    public function test_checked_in_checkin_equals_search_end_is_not_visible(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('DOUBLE');

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-03 14:00:00',
            'end_at' => '2026-08-04 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        // search_end == assignment.start_at → no overlap (strict inequality)
        $this->get('/admin/room-availability?start_at=2026-08-02 14:00&end_at=2026-08-03 14:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id && $r['availability'] === 'available'
                        )
                )
            );
    }

    public function test_checked_in_overstay_with_overlapping_dates_is_visible(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('DOUBLE');

        $booking = Booking::factory()->create();
        // Checked in Jun 22, planned checkout Jun 26 — but still CHECKED_IN (overstay).
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-06-22 14:00:00',
            'end_at' => '2026-06-26 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        // Search Jun 24–27 overlaps planned dates (Jun 26 > Jun 24).
        $this->get('/admin/room-availability?start_at=2026-06-24 14:00&end_at=2026-06-27 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id
                            && in_array($r['availability'], ['overstay', 'occupied'], true)
                        )
                )
            );
    }

    public function test_released_assignment_within_search_range_is_not_visible(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('DOUBLE');

        $booking = Booking::factory()->create();
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-03 12:00:00',
            'status' => AssignmentStatus::Released,
            'assigned_by' => $this->admin->id,
            'released_by' => $this->admin->id,
            'released_at' => now(),
            'release_reason' => 'Test release',
        ]);

        $this->get('/admin/room-availability?start_at=2026-08-01 14:00&end_at=2026-08-03 12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availability.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            $r['room_id'] === $room->id
                            && $r['availability'] === 'available'
                            && $r['booking_count'] === 0
                        )
                )
            );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // View Booking permission — follows BookingPolicy::viewAny()
    // ═══════════════════════════════════════════════════════════════════════

    public function test_user_with_booking_view_policy_sees_view_booking_enabled(): void
    {
        // ACCOUNTANT has report.view → BookingPolicy::viewAny() returns true
        $accountant = User::factory()->create();
        $accountant->assignRole('ACCOUNTANT');

        $this->actingAs($accountant)
            ->get('/admin/room-availability')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.viewBooking', true)
            );
    }

    public function test_user_without_booking_view_policy_sees_view_booking_disabled(): void
    {
        // User with only room_availability.view and no booking-related permissions
        $restricted = User::factory()->create();
        $restricted->givePermissionTo('room_availability.view');

        $this->actingAs($restricted)
            ->get('/admin/room-availability')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.viewBooking', false)
            );
    }

    private function roomForType(string $code): Room
    {
        return Room::where('room_type_id', RoomType::where('code', $code)->firstOrFail()->id)->firstOrFail();
    }
}
