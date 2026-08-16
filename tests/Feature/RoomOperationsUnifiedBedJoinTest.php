<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\RequestCategory;
use App\Enums\ServiceFulfillmentStatus;
use App\Models\BookingService as UnifiedBookingService;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RoomAssignmentService;
use App\Services\RoomOperationsBoardService;
use App\Services\SpecialRequestService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Database\Seeders\UnifiedRequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt) — Slice 3:
 * RoomOperationsBoardService::bedJoinRequestsByStayId() /
 * dailySummaryForDate() now merge legacy BookingSpecialRequest with the new
 * catalog's BookingService(TWIN_TO_DOUBLE) rows. These tests cover the NEW
 * source and the merge behavior; RoomOperationsBedOperationsTest.php covers
 * the legacy source alone (still 100% passing, unchanged) and stays the
 * regression fence proving the old path was not broken by this addition.
 *
 * `App\Models\BookingService` (the new unified catalog transaction model)
 * is aliased here — `App\Services\BookingService` (the pre-existing
 * booking-creation domain service) already owns that short class name.
 */
class RoomOperationsUnifiedBedJoinTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM_PRICE = 800000;

    private User $admin;
    private RoomType $twinType;
    private Service $twinToDoubleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
            RoomSeeder::class,
            UnifiedRequestCatalogSeeder::class,
        ]);

        $this->travelTo('2026-08-01 10:00:00');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
        $this->twinToDoubleService = Service::where('code', 'TWIN_TO_DOUBLE')->firstOrFail();
    }

    private function createBooking(): \App\Models\Booking
    {
        return app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'Unified Bed Join Guest',
            'customer_phone' => '0900000071',
            'customer_email' => 'unified-bed-join-'.uniqid().'@example.test',
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
                'quantity' => 3,
                'adults' => 2,
                'children_under_6' => 0,
                'children_over_6' => 0,
                'room_price' => self::ROOM_PRICE,
                'price_source' => 'MANUAL',
            ]],
        ]);
    }

    /** @return RoomAssignment[] */
    private function assignRooms(\App\Models\Booking $booking, array $rooms): array
    {
        $payload = collect($rooms)->map(fn (Room $r) => [
            'room_id' => $r->id,
            'room_type_id' => $r->room_type_id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-02 12:00:00',
        ])->all();

        return app(RoomAssignmentService::class)->assignRooms($booking, $payload);
    }

    private function findRoom(array $board, string $roomNumber): array
    {
        foreach ($board['floors'] as $floor) {
            foreach ($floor['rooms'] as $room) {
                if ($room['room_number'] === $roomNumber) {
                    return $room;
                }
            }
        }

        $this->fail("Room {$roomNumber} not found on board.");
    }

    private function enrollTwinToDouble(\App\Models\Booking $booking, RoomAssignment $assignment, string $status = 'CREATED'): UnifiedBookingService
    {
        return UnifiedBookingService::create([
            'booking_id' => $booking->id,
            'service_id' => $this->twinToDoubleService->id,
            'room_assignment_id' => $assignment->id,
            'quantity' => 1,
            'billing_mode_selected' => 'ONE_TIME',
            'suggested_price' => '0.00',
            'actual_price' => '0.00',
            'fulfillment_status' => $status,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_unified_bed_join_request_shows_badge_on_correct_room(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        app(StayService::class)->createStayFromAssignment($assignment);

        $this->enrollTwinToDouble($booking, $assignment);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $bedJoin = $this->findRoom($board, '104')['occupant']['bed_join'];

        $this->assertNotNull($bedJoin);
        $this->assertSame('CREATED', $bedJoin['status']);
        $this->assertSame('Đã tạo', $bedJoin['status_label']);
    }

    public function test_confirmed_unified_bed_join_still_shows_badge(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        app(StayService::class)->createStayFromAssignment($assignment);

        $this->enrollTwinToDouble($booking, $assignment, ServiceFulfillmentStatus::Confirmed->value);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $bedJoin = $this->findRoom($board, '104')['occupant']['bed_join'];

        $this->assertNotNull($bedJoin);
        $this->assertSame('CONFIRMED', $bedJoin['status']);
    }

    public function test_completed_unified_bed_join_still_shows_badge(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        app(StayService::class)->createStayFromAssignment($assignment);

        $this->enrollTwinToDouble($booking, $assignment, ServiceFulfillmentStatus::Completed->value);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $bedJoin = $this->findRoom($board, '104')['occupant']['bed_join'];

        $this->assertNotNull($bedJoin);
        $this->assertSame('COMPLETED', $bedJoin['status']);
    }

    /** Mirrors RoomOperationsBedOperationsTest::test_swap_moves_bed_join_association_to_new_assignment for the unified source. */
    public function test_swap_moves_unified_bed_join_association_to_new_assignment(): void
    {
        $booking = $this->createBooking();
        $roomOld = Room::where('room_number', '104')->firstOrFail();
        $roomNew = Room::where('room_number', '201')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$roomOld]);
        app(StayService::class)->createStayFromAssignment($assignment);

        $this->enrollTwinToDouble($booking, $assignment);

        app(\App\Services\RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignment->id, 'target_room_id' => $roomNew->id]],
            $this->admin,
            warningsAcknowledged: true,
        );

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);

        $this->assertNull($this->findRoom($board, '104')['occupant']);
        $this->assertNotNull($this->findRoom($board, '201')['occupant']['bed_join']);
    }

    public function test_cancelled_unified_bed_join_does_not_show_badge(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        app(StayService::class)->createStayFromAssignment($assignment);

        $this->enrollTwinToDouble($booking, $assignment, ServiceFulfillmentStatus::Cancelled->value);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);

        $this->assertNull($this->findRoom($board, '104')['occupant']['bed_join']);
    }

    public function test_multi_room_booking_only_selected_room_shows_unified_bed_join_badge(): void
    {
        $booking = $this->createBooking();
        $roomA = Room::where('room_number', '104')->firstOrFail();
        $roomB = Room::where('room_number', '105')->firstOrFail();
        [$aA, $aB] = $this->assignRooms($booking, [$roomA, $roomB]);
        app(StayService::class)->createStayFromAssignment($aA);
        app(StayService::class)->createStayFromAssignment($aB);

        $this->enrollTwinToDouble($booking, $aB);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);

        $this->assertNull($this->findRoom($board, '104')['occupant']['bed_join']);
        $this->assertNotNull($this->findRoom($board, '105')['occupant']['bed_join']);
    }

    /**
     * The core regression this whole slice's board change is for: a room
     * whose bed-join came from the LEGACY special-request panel and a
     * different room whose bed-join came from the NEW unified screen must
     * both show correctly on the same board at the same time.
     */
    public function test_legacy_and_unified_bed_join_coexist_on_the_same_board(): void
    {
        $booking = $this->createBooking();
        $roomLegacy = Room::where('room_number', '104')->firstOrFail();
        $roomUnified = Room::where('room_number', '105')->firstOrFail();
        [$aLegacy, $aUnified] = $this->assignRooms($booking, [$roomLegacy, $roomUnified]);
        $stayLegacy = app(StayService::class)->createStayFromAssignment($aLegacy);
        app(StayService::class)->createStayFromAssignment($aUnified);

        app(SpecialRequestService::class)->addRequest($booking, RequestCategory::BedConfig, 'twin_to_double', 1, null, $this->admin->id, $stayLegacy->id);
        $this->enrollTwinToDouble($booking, $aUnified);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);

        $legacyBadge = $this->findRoom($board, '104')['occupant']['bed_join'];
        $unifiedBadge = $this->findRoom($board, '105')['occupant']['bed_join'];

        $this->assertNotNull($legacyBadge);
        $this->assertSame('pending', $legacyBadge['status']);
        $this->assertNotNull($unifiedBadge);
        $this->assertSame('CREATED', $unifiedBadge['status']);
    }

    public function test_daily_summary_includes_room_from_unified_bed_join(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        app(StayService::class)->createStayFromAssignment($assignment);

        $this->enrollTwinToDouble($booking, $assignment);

        $summary = app(RoomOperationsBoardService::class)->dailySummaryForDate('2026-08-01', $this->admin);
        $row = collect($summary)->firstWhere('booking_id', $booking->id);

        $this->assertContains('104', $row['bed_join_rooms']->all());
    }

    public function test_daily_summary_never_double_counts_a_room_present_in_both_sources(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        app(SpecialRequestService::class)->addRequest($booking, RequestCategory::BedConfig, 'twin_to_double', 1, null, $this->admin->id, $stay->id);
        $this->enrollTwinToDouble($booking, $assignment);

        $summary = app(RoomOperationsBoardService::class)->dailySummaryForDate('2026-08-01', $this->admin);
        $row = collect($summary)->firstWhere('booking_id', $booking->id);

        $this->assertSame(['104'], $row['bed_join_rooms']->all());
    }
}
