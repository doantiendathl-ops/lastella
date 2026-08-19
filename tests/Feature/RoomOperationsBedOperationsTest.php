<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\RequestCategory;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PackageEnrollmentService;
use App\Services\RoomAssignmentService;
use App\Services\RoomOperationsBoardService;
use App\Services\RoomSwapService;
use App\Services\SpecialRequestService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Room-Scoped Bed Operations Correction — Extra Bed (room_assignments.
 * extra_bed_quantity) and Ghép giường (BookingSpecialRequest, category
 * bed_config, request_type twin_to_double) test matrices, Mục XVI/XXIX.
 */
class RoomOperationsBedOperationsTest extends TestCase
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
        $this->actingAs($this->admin);

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    // -------------------------------------------------------------------------
    // Extra Bed — room-scoped board display
    // -------------------------------------------------------------------------

    public function test_multi_room_booking_board_only_shows_extra_bed_badge_on_enrolled_rooms(): void
    {
        $booking = $this->createBooking();
        $roomA = Room::where('room_number', '104')->firstOrFail();
        $roomB = Room::where('room_number', '105')->firstOrFail();
        $roomC = Room::where('room_number', '106')->firstOrFail();

        [$aA, $aB, $aC] = $this->assignRooms($booking, [$roomA, $roomB, $roomC]);
        $aB->update(['extra_bed_quantity' => 1]);
        $aC->update(['extra_bed_quantity' => 2]);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);

        $this->assertSame(0, $this->findRoom($board, '104')['occupant']['extra_bed_quantity']);
        $this->assertSame(1, $this->findRoom($board, '105')['occupant']['extra_bed_quantity']);
        $this->assertSame(2, $this->findRoom($board, '106')['occupant']['extra_bed_quantity']);
    }

    public function test_updating_extra_bed_room_quantities_via_service(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);

        $package = \App\Models\ServicePackage::firstOrCreate(
            ['code' => PackageEnrollmentService::EXTRA_BED_PER_NIGHT],
            [
                'name' => 'Giường phụ / đêm',
                'description' => null,
                'charge_type' => 'EXTRA_BED',
                'calculation_strategy' => 'MANUAL_QUANTITY_PER_NIGHT',
                'quantity_mode' => 'MANUAL_INPUT',
                'default_quantity' => 1,
                'unit_label' => 'giường',
                'posting_frequency' => 'PER_NIGHT',
                'is_active' => true,
                'is_bookable' => true,
                'display_order' => 92,
            ],
        );
        \App\Models\ServicePackageRate::create([
            'service_package_id' => $package->id,
            'unit_price' => '150000.00',
            'effective_from' => '2026-01-01',
            'is_active' => true,
            'tax_rate' => '0.0000',
        ]);

        $result = app(PackageEnrollmentService::class)->updateExtraBedRoomQuantities($booking, [
            ['assignment_id' => $assignment->id, 'quantity' => 2],
        ]);

        $this->assertSame(2, $assignment->fresh()->extra_bed_quantity);
        $this->assertSame(2, $result[0]['quantity']);
    }

    public function test_extra_bed_room_breakdown_empty_when_no_room_assigned(): void
    {
        $booking = $this->createBooking();

        $breakdown = app(PackageEnrollmentService::class)->extraBedRoomBreakdown($booking);

        $this->assertSame([], $breakdown);
    }

    public function test_generic_enroll_rejects_extra_bed_per_night(): void
    {
        $booking = $this->createBooking();

        $this->expectException(\App\Exceptions\PackageNotEnrollableException::class);
        app(PackageEnrollmentService::class)->enroll($booking, PackageEnrollmentService::EXTRA_BED_PER_NIGHT, $this->admin);
    }

    public function test_move_room_preserves_extra_bed_quantity(): void
    {
        $booking = $this->createBooking();
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room105 = Room::where('room_number', '105')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room104]);
        $assignment->update(['extra_bed_quantity' => 2]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        app(StayService::class)->moveRoom($stay, $room105, $this->admin);

        $this->assertSame(2, $assignment->fresh()->extra_bed_quantity);
    }

    /**
     * User request (2026-08-19 chat) — the board previously only read the
     * legacy room_assignments.extra_bed_quantity column, which has no
     * reachable UI input; staff actually add "Giường phụ" via the new
     * Dịch vụ & Yêu cầu screen (BookingService row, Service code
     * EXTRA_BED_PER_NIGHT). Proves the board now picks that up too.
     */
    public function test_board_extra_bed_quantity_includes_unified_service_enrollment(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        app(StayService::class)->createStayFromAssignment($assignment);

        $service = $this->makeExtraBedUnifiedService();
        app(\App\Services\BookingServiceEnrollmentService::class)->enroll(
            booking: $booking,
            service: $service,
            roomAssignment: $assignment,
            quantity: 2,
            billingModeSelected: null,
            actualPrice: null,
            priceOverrideReason: null,
            createdBy: $this->admin,
        );

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);

        $this->assertSame(2, $this->findRoom($board, '104')['occupant']['extra_bed_quantity']);
    }

    /** Neither source is deprecated (docs/yeucaumoi.txt Mục 1) — both count, summed. */
    public function test_board_extra_bed_quantity_sums_legacy_and_unified_sources(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        $assignment->update(['extra_bed_quantity' => 1]);
        app(StayService::class)->createStayFromAssignment($assignment);

        $service = $this->makeExtraBedUnifiedService();
        app(\App\Services\BookingServiceEnrollmentService::class)->enroll(
            booking: $booking,
            service: $service,
            roomAssignment: $assignment,
            quantity: 1,
            billingModeSelected: null,
            actualPrice: null,
            priceOverrideReason: null,
            createdBy: $this->admin,
        );

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);

        $this->assertSame(2, $this->findRoom($board, '104')['occupant']['extra_bed_quantity']);
    }

    private function makeExtraBedUnifiedService(): \App\Models\Service
    {
        $category = \App\Models\ServiceCategory::firstOrCreate(
            ['code' => 'BED_CONFIG_TEST'],
            ['name' => 'Giường & nệm', 'is_active' => true],
        );

        $service = \App\Models\Service::create([
            'category_id' => $category->id,
            'code' => 'EXTRA_BED_PER_NIGHT',
            'name' => 'Giường phụ',
            'is_chargeable' => true,
            'scope' => 'ROOM',
            'billing_mode' => 'PER_NIGHT',
            'quantity_enabled' => true,
            'default_quantity' => 1,
            'unit_label' => 'giường',
            'fulfillment_required' => false,
            'is_active' => true,
            'is_bookable' => true,
        ]);
        $service->prices()->create(['unit_price' => 150000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        return $service;
    }

    // -------------------------------------------------------------------------
    // "Yêu cầu & dịch vụ khác" popup summary (User request, 2026-08-20 chat)
    // -------------------------------------------------------------------------

    public function test_board_other_services_shows_room_scoped_enrollment_only_on_its_room(): void
    {
        $booking = $this->createBooking();
        $roomA = Room::where('room_number', '104')->firstOrFail();
        $roomB = Room::where('room_number', '105')->firstOrFail();
        [$assignmentA] = $this->assignRooms($booking, [$roomA, $roomB]);
        app(StayService::class)->createStayFromAssignment($assignmentA);

        $service = $this->makeOtherUnifiedService('LATE_CHECKOUT_TEST', 'Trả phòng muộn', 'ROOM');
        app(\App\Services\BookingServiceEnrollmentService::class)->enroll(
            booking: $booking,
            service: $service,
            roomAssignment: $assignmentA,
            quantity: 1,
            billingModeSelected: null,
            actualPrice: null,
            priceOverrideReason: null,
            createdBy: $this->admin,
        );

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);

        $otherServicesA = $this->findRoom($board, '104')['occupant']['other_services'];
        $this->assertCount(1, $otherServicesA);
        $this->assertSame('Trả phòng muộn', $otherServicesA[0]['name']);
        $this->assertSame('CREATED', $otherServicesA[0]['status']);

        $roomBOccupant = $this->findRoom($board, '105')['occupant'];
        $this->assertSame([], $roomBOccupant['other_services'] ?? []);
    }

    public function test_board_other_services_shows_booking_scoped_enrollment_on_every_room(): void
    {
        $booking = $this->createBooking();
        $roomA = Room::where('room_number', '104')->firstOrFail();
        $roomB = Room::where('room_number', '105')->firstOrFail();
        [$assignmentA, $assignmentB] = $this->assignRooms($booking, [$roomA, $roomB]);
        app(StayService::class)->createStayFromAssignment($assignmentA);
        app(StayService::class)->createStayFromAssignment($assignmentB);

        $service = $this->makeOtherUnifiedService('AIRPORT_PICKUP_TEST', 'Đưa đón sân bay', 'BOOKING');
        app(\App\Services\BookingServiceEnrollmentService::class)->enroll(
            booking: $booking,
            service: $service,
            roomAssignment: null,
            quantity: 1,
            billingModeSelected: null,
            actualPrice: null,
            priceOverrideReason: null,
            createdBy: $this->admin,
        );

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);

        foreach (['104', '105'] as $roomNumber) {
            $items = $this->findRoom($board, $roomNumber)['occupant']['other_services'];
            $this->assertCount(1, $items, "Room {$roomNumber} should carry the booking-wide enrollment.");
            $this->assertSame('Đưa đón sân bay', $items[0]['name']);
        }
    }

    public function test_board_other_services_excludes_bed_join_extra_bed_and_cancelled(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        app(StayService::class)->createStayFromAssignment($assignment);

        $enrollment = app(\App\Services\BookingServiceEnrollmentService::class);
        $enrollArgs = fn ($service) => [
            'booking' => $booking,
            'service' => $service,
            'roomAssignment' => $assignment,
            'quantity' => 1,
            'billingModeSelected' => null,
            'actualPrice' => null,
            'priceOverrideReason' => null,
            'createdBy' => $this->admin,
        ];

        // Already has its own dedicated badge — must not duplicate into "other".
        $enrollment->enroll(...$enrollArgs($this->makeExtraBedUnifiedService()));

        // Cancelled — excluded regardless of Service code.
        $cancelledService = $this->makeOtherUnifiedService('SPA_TEST', 'Spa', 'ROOM');
        $cancelledEnrollment = $enrollment->enroll(...$enrollArgs($cancelledService));
        $enrollment->cancel($cancelledEnrollment, $this->admin);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);

        $this->assertSame([], $this->findRoom($board, '104')['occupant']['other_services']);
    }

    private function makeOtherUnifiedService(string $code, string $name, string $scope): \App\Models\Service
    {
        $category = \App\Models\ServiceCategory::firstOrCreate(
            ['code' => 'OTHER_SERVICE_TEST'],
            ['name' => 'Khác', 'is_active' => true],
        );

        return \App\Models\Service::create([
            'category_id' => $category->id,
            'code' => $code,
            'name' => $name,
            'is_chargeable' => false,
            'scope' => $scope,
            'billing_mode' => 'ONE_TIME',
            'quantity_enabled' => false,
            'default_quantity' => 1,
            'unit_label' => '',
            'fulfillment_required' => true,
            'is_active' => true,
            'is_bookable' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Ghép giường (twin_to_double Special Request) — canonical, room-scoped
    // -------------------------------------------------------------------------

    public function test_pending_bed_join_request_shows_badge_on_correct_room(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        app(SpecialRequestService::class)->addRequest($booking, RequestCategory::BedConfig, 'twin_to_double', 1, null, $this->admin->id, $stay->id);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $bedJoin = $this->findRoom($board, '104')['occupant']['bed_join'];

        $this->assertNotNull($bedJoin);
        $this->assertSame('pending', $bedJoin['status']);
        $this->assertSame('Chờ xử lý', $bedJoin['status_label']);
    }

    public function test_acknowledged_bed_join_request_still_shows_badge(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        $request = app(SpecialRequestService::class)->addRequest($booking, RequestCategory::BedConfig, 'twin_to_double', 1, null, $this->admin->id, $stay->id);
        app(SpecialRequestService::class)->acknowledge($request, $this->admin->id);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $bedJoin = $this->findRoom($board, '104')['occupant']['bed_join'];

        $this->assertSame('acknowledged', $bedJoin['status']);
    }

    /** Mục XXV: completed workflow != beds no longer joined — must stay visible for the active stay. */
    public function test_fulfilled_bed_join_request_still_shows_badge(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        $request = app(SpecialRequestService::class)->addRequest($booking, RequestCategory::BedConfig, 'twin_to_double', 1, null, $this->admin->id, $stay->id);
        app(SpecialRequestService::class)->acknowledge($request, $this->admin->id);
        app(SpecialRequestService::class)->fulfill($request, $this->admin->id);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $bedJoin = $this->findRoom($board, '104')['occupant']['bed_join'];

        $this->assertSame('fulfilled', $bedJoin['status']);
    }

    public function test_cancelled_bed_join_request_does_not_show_badge(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        $request = app(SpecialRequestService::class)->addRequest($booking, RequestCategory::BedConfig, 'twin_to_double', 1, null, $this->admin->id, $stay->id);
        app(SpecialRequestService::class)->cancel($request, $this->admin->id);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);

        $this->assertNull($this->findRoom($board, '104')['occupant']['bed_join']);
    }

    public function test_multi_room_booking_only_selected_room_shows_bed_join_badge(): void
    {
        $booking = $this->createBooking();
        $roomA = Room::where('room_number', '104')->firstOrFail();
        $roomB = Room::where('room_number', '105')->firstOrFail();
        [$assignmentA, $assignmentB] = $this->assignRooms($booking, [$roomA, $roomB]);
        $stayA = app(StayService::class)->createStayFromAssignment($assignmentA);
        app(StayService::class)->createStayFromAssignment($assignmentB);

        app(SpecialRequestService::class)->addRequest($booking, RequestCategory::BedConfig, 'twin_to_double', 1, null, $this->admin->id, $stayA->id);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);

        $this->assertNotNull($this->findRoom($board, '104')['occupant']['bed_join']);
        $this->assertNull($this->findRoom($board, '105')['occupant']['bed_join']);
    }

    /** Mục XXIII: legacy ambiguous (stay_id null, multi-room) must NOT be guessed onto any room. */
    public function test_ambiguous_unlinked_bed_join_request_shown_on_no_room_but_surfaced_in_summary(): void
    {
        $booking = $this->createBooking();
        $roomA = Room::where('room_number', '104')->firstOrFail();
        $roomB = Room::where('room_number', '105')->firstOrFail();
        [$assignmentA, $assignmentB] = $this->assignRooms($booking, [$roomA, $roomB]);
        app(StayService::class)->createStayFromAssignment($assignmentA);
        app(StayService::class)->createStayFromAssignment($assignmentB);

        // stay_id left null — two active stays exist, autoLinkSingleStayRequests cannot resolve it.
        app(SpecialRequestService::class)->addRequest($booking, RequestCategory::BedConfig, 'twin_to_double', 1, null, $this->admin->id, null);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $this->assertNull($this->findRoom($board, '104')['occupant']['bed_join']);
        $this->assertNull($this->findRoom($board, '105')['occupant']['bed_join']);

        $summary = app(RoomOperationsBoardService::class)->dailySummaryForDate('2026-08-01', $this->admin);
        $bookingSummary = collect($summary)->firstWhere('booking_id', $booking->id);
        $this->assertSame(1, $bookingSummary['bed_join_unresolved_count']);
    }

    /** Single-active-stay booking: existing autoLinkSingleStayRequests resolves it automatically — no guess needed. */
    public function test_single_room_booking_auto_links_bed_join_request(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);

        // Request created BEFORE the stay exists (stay_id null) — same as the existing UI flow.
        app(SpecialRequestService::class)->addRequest($booking, RequestCategory::BedConfig, 'twin_to_double', 1, null, $this->admin->id, null);

        // createStayFromAssignment calls autoLinkSingleStayRequests() internally — existing behavior.
        app(StayService::class)->createStayFromAssignment($assignment);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $this->assertNotNull($this->findRoom($board, '104')['occupant']['bed_join']);
    }

    public function test_checked_in_stay_still_shows_bed_join_badge(): void
    {
        $booking = $this->createBooking();
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = $this->assignRooms($booking, [$room]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(SpecialRequestService::class)->addRequest($booking, RequestCategory::BedConfig, 'twin_to_double', 1, null, $this->admin->id, $stay->id);

        app(StayService::class)->checkIn($stay);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $this->assertNotNull($this->findRoom($board, '104')['occupant']['bed_join']);
    }

    /** Mục XXVI: swap must rebind the request to the NEW assignment/stay, never leave it on the vacated physical room. */
    public function test_swap_moves_bed_join_association_to_new_assignment(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        $booking = $this->createBooking();
        [$assignment] = $this->assignRooms($booking, [$room104]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(SpecialRequestService::class)->addRequest($booking, RequestCategory::BedConfig, 'twin_to_double', 1, null, $this->admin->id, $stay->id);

        app(RoomSwapService::class)->execute(
            [['source_assignment_id' => $assignment->id, 'target_room_id' => $room201->id]],
            $this->admin,
            warningsAcknowledged: true,
        );

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $this->assertNull($this->findRoom($board, '104')['occupant']);
        $this->assertNotNull($this->findRoom($board, '201')['occupant']['bed_join']);
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

    /**
     * @param  Room[]  $rooms
     * @return RoomAssignment[]
     */
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

    private function createBooking(): \App\Models\Booking
    {
        return app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'Bed Ops Guest',
            'customer_phone' => '0900000070',
            'customer_email' => 'bed-ops-'.uniqid().'@example.test',
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
                    'quantity' => 3,
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
