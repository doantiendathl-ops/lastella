<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RoomOperationsBoardService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Room-Conflict Detection follow-up (reported against QA-BK-0049): the board
 * previously picked ONE occupant per room per day and silently hid any other
 * overlapping live assignment on the same physical room — a real
 * double-booking (two bookings both holding the same key for the same
 * night) was invisible on "Sơ đồ thao tác" even though the per-booking Room
 * Board correctly flagged it as a conflict. `has_room_conflict` distinguishes
 * a genuine time-range overlap from a benign same-day handoff
 * (`has_same_day_turnover`), which must NOT trigger the conflict warning.
 */
class RoomOperationsConflictDetectionTest extends TestCase
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

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_two_overlapping_assignments_on_same_room_flag_conflict(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();

        $bookingA = $this->createBooking('Guest A', '2026-09-10 14:00:00', '2026-09-12 12:00:00');
        $bookingB = $this->createBooking('Guest B', '2026-09-11 14:00:00', '2026-09-13 12:00:00');

        $this->assignRoom($bookingA, $room, '2026-09-10 14:00:00', '2026-09-12 12:00:00');
        $this->assignRoom($bookingB, $room, '2026-09-11 14:00:00', '2026-09-13 12:00:00');

        // 09-10: only A is live yet — no conflict.
        $cellDay1 = $this->findRoomInBoard('2026-09-10', $room->room_number);
        $this->assertFalse($cellDay1['has_room_conflict']);

        // 09-11 and 09-12: both A and B's ranges genuinely overlap here.
        foreach (['2026-09-11', '2026-09-12'] as $date) {
            $cell = $this->findRoomInBoard($date, $room->room_number);
            $this->assertTrue($cell['has_room_conflict'], "Room must be flagged conflicted on {$date}.");
            $this->assertFalse($cell['has_same_day_turnover'], 'A genuine overlap is not a benign turnover.');
            $codes = collect($cell['conflicting_bookings'])->pluck('booking_code')->all();
            $this->assertNotEmpty($codes);
        }

        // 09-13: only B remains — no conflict.
        $cellDay4 = $this->findRoomInBoard('2026-09-13', $room->room_number);
        $this->assertFalse($cellDay4['has_room_conflict']);
    }

    public function test_benign_same_day_turnover_is_not_flagged_as_conflict(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->skip(1)->firstOrFail();

        $bookingA = $this->createBooking('Guest C', '2026-09-10 14:00:00', '2026-09-12 12:00:00');
        $bookingB = $this->createBooking('Guest D', '2026-09-12 14:00:00', '2026-09-14 12:00:00');

        $this->assignRoom($bookingA, $room, '2026-09-10 14:00:00', '2026-09-12 12:00:00');
        $this->assignRoom($bookingB, $room, '2026-09-12 14:00:00', '2026-09-14 12:00:00');

        // 09-12 is both A's checkout day and B's check-in day — both rows fall
        // inside the day window, but their ranges never actually overlap.
        $cell = $this->findRoomInBoard('2026-09-12', $room->room_number);
        $this->assertFalse($cell['has_room_conflict']);
        $this->assertTrue($cell['has_same_day_turnover']);
    }

    public function test_single_assignment_flags_neither_conflict_nor_turnover(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->skip(2)->firstOrFail();
        $booking = $this->createBooking('Guest E', '2026-09-10 14:00:00', '2026-09-12 12:00:00');
        $this->assignRoom($booking, $room, '2026-09-10 14:00:00', '2026-09-12 12:00:00');

        $cell = $this->findRoomInBoard('2026-09-10', $room->room_number);
        $this->assertFalse($cell['has_room_conflict']);
        $this->assertFalse($cell['has_same_day_turnover']);
        $this->assertCount(0, $cell['conflicting_bookings']);
    }

    /**
     * Pre-Commit Critical Safety Closure Mục IX/XII item 3: three assignments
     * that all genuinely overlap each other must all be reported — not just
     * the pair touching the displayed primary.
     */
    public function test_three_way_all_overlap_flags_conflict_for_all_participants(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();

        $bookingA = $this->createBooking('Guest A3', '2026-09-10 14:00:00', '2026-09-13 12:00:00');
        $bookingB = $this->createBooking('Guest B3', '2026-09-11 14:00:00', '2026-09-14 12:00:00');
        $bookingC = $this->createBooking('Guest C3', '2026-09-12 14:00:00', '2026-09-15 12:00:00');

        $this->assignRoom($bookingA, $room, '2026-09-10 14:00:00', '2026-09-13 12:00:00');
        $this->assignRoom($bookingB, $room, '2026-09-11 14:00:00', '2026-09-14 12:00:00');
        $this->assignRoom($bookingC, $room, '2026-09-12 14:00:00', '2026-09-15 12:00:00');

        $cell = $this->findRoomInBoard('2026-09-12', $room->room_number);

        $this->assertTrue($cell['has_room_conflict']);
        // Primary is A (earliest start_at) — B and C must BOTH be reported, not just one.
        $codes = collect($cell['conflicting_bookings'])->pluck('booking_code')->sort()->values()->all();
        $this->assertCount(2, $codes);
        $this->assertContains($bookingB->booking_code, $codes);
        $this->assertContains($bookingC->booking_code, $codes);
    }

    /**
     * Pre-Commit Critical Safety Closure Mục IX/XII item 4: the root-cause
     * gap the first implementation had — primary (A) has NO overlap with
     * either B or C, but B and C overlap each other. Must still report a
     * conflict, and must report BOTH B and C (neither is silently dropped
     * just because neither is the primary).
     */
    public function test_primary_non_overlapping_but_secondary_pair_overlap_still_conflicts(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->skip(3)->firstOrFail();

        $bookingA = $this->createBooking('Guest A4', '2026-09-12 14:00:00', '2026-09-13 08:00:00');
        $bookingB = $this->createBooking('Guest B4', '2026-09-13 10:00:00', '2026-09-14 12:00:00');
        $bookingC = $this->createBooking('Guest C4', '2026-09-13 15:00:00', '2026-09-15 12:00:00');

        $this->assignRoom($bookingA, $room, '2026-09-12 14:00:00', '2026-09-13 08:00:00');
        $this->assignRoom($bookingB, $room, '2026-09-13 10:00:00', '2026-09-14 12:00:00');
        $this->assignRoom($bookingC, $room, '2026-09-13 15:00:00', '2026-09-15 12:00:00');

        $cell = $this->findRoomInBoard('2026-09-13', $room->room_number);

        // A (primary, earliest start) has zero overlap with B or C individually —
        // a primary-vs-others-only check would have reported NO conflict here.
        $this->assertSame($bookingA->booking_code, $cell['occupant']['booking_code']);
        $this->assertTrue($cell['has_room_conflict'], 'B-C overlap must still be caught even though primary A does not participate.');
        $codes = collect($cell['conflicting_bookings'])->pluck('booking_code')->sort()->values()->all();
        $this->assertCount(2, $codes);
        $this->assertContains($bookingB->booking_code, $codes);
        $this->assertContains($bookingC->booking_code, $codes);
    }

    /**
     * Pre-Commit Critical Safety Closure Mục XII item 5: 4 assignments on one
     * room/day forming 2 INDEPENDENT overlapping pairs (A-B, C-D) — both
     * pairs must be reported together, none dropped.
     */
    public function test_four_assignments_two_independent_conflict_pairs_all_reported(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->skip(4)->firstOrFail();

        $bookingA = $this->createBooking('Guest A5', '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $bookingB = $this->createBooking('Guest B5', '2026-09-10 09:00:00', '2026-09-10 11:00:00');
        $bookingC = $this->createBooking('Guest C5', '2026-09-10 15:00:00', '2026-09-10 17:00:00');
        $bookingD = $this->createBooking('Guest D5', '2026-09-10 16:00:00', '2026-09-10 18:00:00');

        $this->assignRoom($bookingA, $room, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $this->assignRoom($bookingB, $room, '2026-09-10 09:00:00', '2026-09-10 11:00:00');
        $this->assignRoom($bookingC, $room, '2026-09-10 15:00:00', '2026-09-10 17:00:00');
        $this->assignRoom($bookingD, $room, '2026-09-10 16:00:00', '2026-09-10 18:00:00');

        $cell = $this->findRoomInBoard('2026-09-10', $room->room_number);

        $this->assertTrue($cell['has_room_conflict']);
        // Primary is A (earliest). B (A's own pair partner) AND the entirely
        // independent C-D pair must ALL appear — 3 other bookings total.
        $codes = collect($cell['conflicting_bookings'])->pluck('booking_code')->sort()->values()->all();
        $this->assertCount(3, $codes);
        foreach ([$bookingB, $bookingC, $bookingD] as $booking) {
            $this->assertContains($booking->booking_code, $codes);
        }
    }

    /** Pre-Commit Critical Safety Closure Mục XII item 9: the same OTHER booking with two separately-overlapping assignments must appear only once. */
    public function test_conflict_list_has_no_duplicate_booking(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->skip(5)->firstOrFail();

        $bookingA = $this->createBooking('Guest A6', '2026-09-10 07:00:00', '2026-09-10 12:00:00');
        $bookingBeta = $this->createBooking('Guest Beta6', '2026-09-10 08:00:00', '2026-09-10 09:00:00');

        $this->assignRoom($bookingA, $room, '2026-09-10 07:00:00', '2026-09-10 12:00:00');
        // Booking Beta gets TWO separate assignments to the same room, each independently
        // overlapping primary A (a synthetic edge case, constructed directly like the rest
        // of this file, to prove the dedup is defensive even if it were ever reachable).
        $this->assignRoom($bookingBeta, $room, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $this->assignRoom($bookingBeta, $room, '2026-09-10 10:00:00', '2026-09-10 11:00:00');

        $cell = $this->findRoomInBoard('2026-09-10', $room->room_number);

        $this->assertTrue($cell['has_room_conflict']);
        $codes = collect($cell['conflicting_bookings'])->pluck('booking_code')->all();
        $this->assertCount(1, $codes, 'Booking Beta must appear exactly once despite having 2 conflicting assignments.');
        $this->assertSame($bookingBeta->booking_code, $codes[0]);
    }

    public function test_board_summary_counts_conflicted_rooms(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $bookingA = $this->createBooking('Guest F', '2026-09-10 14:00:00', '2026-09-12 12:00:00');
        $bookingB = $this->createBooking('Guest G', '2026-09-11 14:00:00', '2026-09-13 12:00:00');
        $this->assignRoom($bookingA, $room, '2026-09-10 14:00:00', '2026-09-12 12:00:00');
        $this->assignRoom($bookingB, $room, '2026-09-11 14:00:00', '2026-09-13 12:00:00');

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-09-11', $this->admin);

        $this->assertSame(1, $board['summary']['conflicted_rooms']);
    }

    private function findRoomInBoard(string $date, string $roomNumber): array
    {
        $board = app(RoomOperationsBoardService::class)->boardForDate($date, $this->admin);

        foreach ($board['floors'] as $floor) {
            foreach ($floor['rooms'] as $room) {
                if ($room['room_number'] === $roomNumber) {
                    return $room;
                }
            }
        }

        $this->fail("Room {$roomNumber} not found on board for {$date}.");
    }

    /**
     * Direct create() rather than RoomAssignment::factory()->create() — the
     * factory's definition() always creates (and discards) an extra throwaway
     * Room::factory() row before the override array applies, which can
     * flakily collide with a real seeded room_number/code under heavy use
     * (this file creates many assignments per test). Every field this table
     * actually needs is supplied explicitly below, so the factory adds
     * nothing but risk here.
     */
    private function assignRoom(Booking $booking, Room $room, string $startAt, string $endAt): RoomAssignment
    {
        return RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'status' => AssignmentStatus::Assigned,
        ]);
    }

    private function createBooking(string $customerName, string $checkinAt, string $checkoutAt): Booking
    {
        return app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => $customerName,
            'customer_phone' => '090000' . random_int(1000, 9999),
            'customer_email' => strtolower(str_replace(' ', '.', $customerName)) . '@example.test',
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
    }
}
