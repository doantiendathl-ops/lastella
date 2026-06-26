<?php

namespace Database\Seeders;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\PriceSource;
use App\Enums\RoomStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

class LastellaQaSeederV2 extends Seeder
{
    private const PALETTE = [
        '#8B5CF6', '#10B981', '#F59E0B', '#EF4444', '#3B82F6',
        '#EC4899', '#14B8A6', '#6366F1', '#84CC16', '#F97316',
        '#06B6D4', '#A855F7', '#22C55E', '#EAB308', '#F43F5E',
        '#64748B',
    ];

    private const ROOM_PRICES = [
        'DOUBLE' => 900000,
        'TWIN' => 800000,
        'TRIP' => 1200000,
        'TRIP_FAMILY' => 1400000,
        'FAMILY' => 1600000,
    ];

    private int $colorIndex = 0;

    private ?User $admin = null;

    private BookingService $bookingService;

    private RoomAssignmentService $assignmentService;

    private StayService $stayService;

    /** @var array<string, RoomType> */
    private array $roomTypes = [];

    /** @var array<string, \Illuminate\Support\Collection<int, Room>> */
    private array $roomsByType = [];

    private Carbon $now;

    public function run(): void
    {
        $this->admin = User::whereHas('roles', fn ($q) => $q->where('name', 'ADMIN'))->firstOrFail();
        Auth::login($this->admin);

        $this->bookingService = app(BookingService::class);
        $this->assignmentService = app(RoomAssignmentService::class);
        $this->stayService = app(StayService::class);

        $this->now = Carbon::now();
        $this->cleanPreviousQaData();
        $this->loadRooms();
        $this->markOneRoomOutOfOrder();

        $this->command->info('Creating QA V2 seed data (50 bookings)...');

        // SEED ORDER matters: checked-in assignments block ALL future dates.
        // Seed historical (checked-out) and future (assigned-only) FIRST,
        // then current (checked-in / overstay) LAST.
        $this->seedGroupE();      // 3 Checked Out (historical, rooms freed)
        $this->seedGroupA();      // 10 Fully Assigned, future (ASSIGNED status)
        $this->seedGroupD();      // 10 Partially Assigned, future
        $this->seedConflict();    // 2 Conflict bookings (QA-BK-0049, 0050)
        $this->seedGroupB();      // 10 Checked In (blocks all future for those rooms)
        $this->seedGroupC();      // 5 Overstay (blocks all future for those rooms)
        $this->seedGroupF();      // 2 Cancelled (no rooms)
        $this->seedGroupG();      // 8 Unassigned (no rooms)
        $this->seedSpecialScenarios();

        $total = Booking::where('customer_name', 'like', 'QA %')->count();
        $this->command->info("QA V2 seed complete: {$total} bookings created.");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CLEANUP
    // ═══════════════════════════════════════════════════════════════════════

    private function cleanPreviousQaData(): void
    {
        $qaBookingIds = Booking::where('customer_name', 'like', 'QA %')
            ->orWhere('booking_code', 'like', 'QA-%')
            ->pluck('id');

        if ($qaBookingIds->isEmpty()) {
            return;
        }

        $this->command->info('Cleaning previous QA data...');

        Stay::whereIn('booking_id', $qaBookingIds)->delete();
        RoomAssignment::whereIn('booking_id', $qaBookingIds)->delete();
        FolioEntry::whereIn('folio_id', Folio::whereIn('booking_id', $qaBookingIds)->pluck('id'))->delete();
        Folio::whereIn('booking_id', $qaBookingIds)->delete();
        BookingPayment::whereIn('booking_id', $qaBookingIds)->delete();
        BookingRequirement::whereIn('booking_id', $qaBookingIds)->delete();
        Booking::whereIn('id', $qaBookingIds)->delete();

        Room::where('status', RoomStatus::OutOfOrder)->update(['status' => RoomStatus::VacantClean]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SETUP
    // ═══════════════════════════════════════════════════════════════════════

    private function loadRooms(): void
    {
        $this->roomTypes = RoomType::all()->keyBy('code')->all();

        foreach (['TWIN', 'DOUBLE', 'TRIP', 'FAMILY', 'TRIP_FAMILY'] as $code) {
            $this->roomsByType[$code] = Room::where('room_type_id', $this->roomTypes[$code]->id)
                ->where('status', '!=', RoomStatus::OutOfOrder)
                ->orderBy('room_number')
                ->get();
        }
    }

    private function markOneRoomOutOfOrder(): void
    {
        $room = Room::where('room_number', '802')->first();
        if ($room) {
            $room->update(['status' => RoomStatus::OutOfOrder]);
            $typeCode = $this->typeCodeById($room->room_type_id);
            $this->roomsByType[$typeCode] = $this->roomsByType[$typeCode]
                ->reject(fn (Room $r) => $r->id === $room->id)->values();
            $this->command->info("  Room 802 set to OutOfOrder.");
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP A: 10 Fully Assigned — Not Checked In (future)
    // Codes: QA-BK-0001 → QA-BK-0010
    // Status: FULLY_ASSIGNED
    // ═══════════════════════════════════════════════════════════════════════

    private function seedGroupA(): void
    {
        $this->command->info('  → Group A: 10 Fully Assigned, Not Checked In...');

        $specs = [
            ['rooms' => 3,  'offset' => 7,  'nights' => 2, 'type' => CustomerType::Group],
            ['rooms' => 4,  'offset' => 9,  'nights' => 1, 'type' => CustomerType::Individual],
            ['rooms' => 5,  'offset' => 11, 'nights' => 3, 'type' => CustomerType::Company],
            ['rooms' => 6,  'offset' => 14, 'nights' => 2, 'type' => CustomerType::Tour],
            ['rooms' => 8,  'offset' => 17, 'nights' => 1, 'type' => CustomerType::Group],
            ['rooms' => 5,  'offset' => 19, 'nights' => 2, 'type' => CustomerType::Individual],
            ['rooms' => 4,  'offset' => 22, 'nights' => 3, 'type' => CustomerType::Company],
            ['rooms' => 3,  'offset' => 26, 'nights' => 2, 'type' => CustomerType::Group],
            ['rooms' => 6,  'offset' => 29, 'nights' => 1, 'type' => CustomerType::Tour],
            ['rooms' => 4,  'offset' => 32, 'nights' => 2, 'type' => CustomerType::Individual],
        ];

        for ($i = 0; $i < 10; $i++) {
            $s = $specs[$i];
            $checkin = $this->future($s['offset'], 14);
            $checkout = $this->future($s['offset'] + $s['nights'], 12);

            $booking = $this->createBookingWithRequirements(
                code: sprintf('QA-BK-%04d', $i + 1),
                name: sprintf('QA Reserved %02d', $i + 1),
                type: $s['type'],
                checkin: $checkin,
                checkout: $checkout,
                roomCount: $s['rooms'],
            );

            $this->assignAllRooms($booking, $checkin, $checkout);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP B: 10 Fully Assigned — Checked In (current)
    // Codes: QA-BK-0011 → QA-BK-0020
    // Status: CHECKED_IN
    // ═══════════════════════════════════════════════════════════════════════

    private function seedGroupB(): void
    {
        $this->command->info('  → Group B: 10 Fully Assigned, Checked In...');

        // Explicit type mix to distribute evenly across all 5 room types.
        // Totals: 6D + 7T + 8TR + 6TF + 5F = 32 rooms.
        $specs = [
            ['mix' => ['TRIP' => 1, 'TRIP_FAMILY' => 1, 'FAMILY' => 1], 'past' => 1, 'future' => 2, 'type' => CustomerType::Company],
            ['mix' => ['DOUBLE' => 1, 'TWIN' => 1, 'TRIP' => 1],       'past' => 0, 'future' => 3, 'type' => CustomerType::Group],
            ['mix' => ['TRIP_FAMILY' => 1, 'FAMILY' => 1, 'TRIP' => 1], 'past' => 2, 'future' => 1, 'type' => CustomerType::Company],
            ['mix' => ['DOUBLE' => 1, 'TWIN' => 1, 'TRIP' => 1, 'TRIP_FAMILY' => 1], 'past' => 1, 'future' => 2, 'type' => CustomerType::Group],
            ['mix' => ['DOUBLE' => 1, 'TWIN' => 1, 'FAMILY' => 1],     'past' => 0, 'future' => 1, 'type' => CustomerType::Individual],
            ['mix' => ['TRIP' => 1, 'TRIP_FAMILY' => 1, 'TWIN' => 1],  'past' => 1, 'future' => 3, 'type' => CustomerType::Group],
            ['mix' => ['DOUBLE' => 1, 'FAMILY' => 1, 'TRIP' => 1],     'past' => 2, 'future' => 2, 'type' => CustomerType::Tour],
            ['mix' => ['TWIN' => 1, 'TRIP_FAMILY' => 1, 'DOUBLE' => 1], 'past' => 0, 'future' => 2, 'type' => CustomerType::Group],
            ['mix' => ['DOUBLE' => 1, 'TWIN' => 1, 'TRIP' => 1, 'FAMILY' => 1], 'past' => 1, 'future' => 1, 'type' => CustomerType::Company],
            ['mix' => ['TWIN' => 1, 'TRIP' => 1, 'TRIP_FAMILY' => 1],  'past' => 1, 'future' => 3, 'type' => CustomerType::Group],
        ];

        for ($i = 0; $i < 10; $i++) {
            $s = $specs[$i];
            $checkin = $this->past($s['past'], 14);
            $checkout = $this->future($s['future'], 12);
            $roomCount = array_sum($s['mix']);

            $booking = $this->createBookingWithExplicitTypes(
                code: sprintf('QA-BK-%04d', 10 + $i + 1),
                name: sprintf('QA CheckedIn %02d', $i + 1),
                type: $s['type'],
                checkin: $checkin,
                checkout: $checkout,
                typeMix: $s['mix'],
            );

            $assignments = $this->assignAllRooms($booking, $checkin, $checkout);
            $this->checkInAllAssignments($assignments, $checkin);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP C: 5 Fully Assigned — Overstay
    // Codes: QA-BK-0021 → QA-BK-0025
    // Status: CHECKED_IN, checkout_at < now
    // ═══════════════════════════════════════════════════════════════════════

    private function seedGroupC(): void
    {
        $this->command->info('  → Group C: 5 Overstay...');

        // Explicit type mix to avoid exhausting DOUBLE/TWIN.
        // Totals: 3D + 3T + 4TR + 3TF + 3F = 16 rooms.
        $specs = [
            ['mix' => ['DOUBLE' => 1, 'TWIN' => 1, 'TRIP' => 1],                  'ciPast' => 5, 'coPast' => 2, 'type' => CustomerType::Tour],
            ['mix' => ['TRIP_FAMILY' => 1, 'FAMILY' => 1, 'DOUBLE' => 1],          'ciPast' => 4, 'coPast' => 1, 'type' => CustomerType::Group],
            ['mix' => ['TWIN' => 1, 'TRIP' => 1, 'TRIP_FAMILY' => 1, 'FAMILY' => 1], 'ciPast' => 6, 'coPast' => 2, 'type' => CustomerType::Tour],
            ['mix' => ['DOUBLE' => 1, 'TWIN' => 1, 'TRIP' => 1],                  'ciPast' => 3, 'coPast' => 1, 'type' => CustomerType::Company],
            ['mix' => ['TRIP_FAMILY' => 1, 'FAMILY' => 1, 'TRIP' => 1],            'ciPast' => 5, 'coPast' => 1, 'type' => CustomerType::Tour],
        ];

        for ($i = 0; $i < 5; $i++) {
            $s = $specs[$i];
            $checkin = $this->past($s['ciPast'], 14);
            $checkout = $this->past($s['coPast'], 12);

            $booking = $this->createBookingWithExplicitTypes(
                code: sprintf('QA-BK-%04d', 20 + $i + 1),
                name: sprintf('QA Overstay %02d', $i + 1),
                type: $s['type'],
                checkin: $checkin,
                checkout: $checkout,
                typeMix: $s['mix'],
            );

            $assignments = $this->assignAllRooms($booking, $checkin, $checkout);
            $this->checkInAllAssignments($assignments, $checkin);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP D: 10 Partially Assigned (future)
    // Codes: QA-BK-0026 → QA-BK-0035
    // Status: PARTIALLY_ASSIGNED
    // ═══════════════════════════════════════════════════════════════════════

    private function seedGroupD(): void
    {
        $this->command->info('  → Group D: 10 Partially Assigned...');

        // Stagger dates so no two partial bookings overlap heavily.
        $specs = [
            ['need' => 10, 'assign' => 5,  'offset' => 8,  'nights' => 2, 'type' => CustomerType::Group],
            ['need' => 15, 'assign' => 8,  'offset' => 11, 'nights' => 3, 'type' => CustomerType::Company],
            ['need' => 8,  'assign' => 4,  'offset' => 15, 'nights' => 1, 'type' => CustomerType::Tour],
            ['need' => 12, 'assign' => 6,  'offset' => 17, 'nights' => 2, 'type' => CustomerType::Group],
            ['need' => 6,  'assign' => 3,  'offset' => 20, 'nights' => 2, 'type' => CustomerType::Company],
            ['need' => 10, 'assign' => 4,  'offset' => 23, 'nights' => 1, 'type' => CustomerType::Group],
            ['need' => 8,  'assign' => 3,  'offset' => 26, 'nights' => 3, 'type' => CustomerType::Tour],
            ['need' => 15, 'assign' => 7,  'offset' => 30, 'nights' => 2, 'type' => CustomerType::Company],
            ['need' => 5,  'assign' => 3,  'offset' => 33, 'nights' => 1, 'type' => CustomerType::Group],
            ['need' => 10, 'assign' => 5,  'offset' => 36, 'nights' => 2, 'type' => CustomerType::Tour],
        ];

        for ($i = 0; $i < 10; $i++) {
            $s = $specs[$i];
            $checkin = $this->future($s['offset'], 14);
            $checkout = $this->future($s['offset'] + $s['nights'], 12);

            $booking = $this->createBookingWithRequirements(
                code: sprintf('QA-BK-%04d', 25 + $i + 1),
                name: sprintf('QA Partial %02d', $i + 1),
                type: $s['type'],
                checkin: $checkin,
                checkout: $checkout,
                roomCount: $s['need'],
            );

            $this->assignPartialRooms($booking, $s['assign'], $checkin, $checkout);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP E: 3 Checked Out (historical)
    // Codes: QA-BK-0036 → QA-BK-0038
    // Status: CHECKED_OUT
    // ═══════════════════════════════════════════════════════════════════════

    private function seedGroupE(): void
    {
        $this->command->info('  → Group E: 3 Checked Out...');

        $specs = [
            ['rooms' => 4, 'ciPast' => 15, 'nights' => 2],
            ['rooms' => 5, 'ciPast' => 20, 'nights' => 3],
            ['rooms' => 3, 'ciPast' => 25, 'nights' => 1],
        ];

        for ($i = 0; $i < 3; $i++) {
            $s = $specs[$i];
            $checkin = $this->past($s['ciPast'], 14);
            $checkout = $this->past($s['ciPast'] - $s['nights'], 12);

            $booking = $this->createBookingWithRequirements(
                code: sprintf('QA-BK-%04d', 35 + $i + 1),
                name: sprintf('QA History %02d', $i + 1),
                type: CustomerType::Individual,
                checkin: $checkin,
                checkout: $checkout,
                roomCount: $s['rooms'],
            );

            $assignments = $this->assignAllRooms($booking, $checkin, $checkout);
            $this->checkInAllAssignments($assignments, $checkin);
            $this->checkOutAllAssignments($assignments, $checkout);

            $totalCharge = $booking->bookingRequirements()
                ->get()
                ->sum(fn ($r) => $r->room_price * $r->quantity);
            $booking->bookingPayments()->create([
                'payment_type' => PaymentType::RoomPayment,
                'amount' => $totalCharge,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_at' => $checkout,
                'confirmed_by' => $this->admin->id,
            ]);
            $this->bookingService->updateBookingStayStatus($booking->refresh());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP F: 2 Cancelled
    // Codes: QA-BK-0039, QA-BK-0040
    // Status: CANCELLED
    // ═══════════════════════════════════════════════════════════════════════

    private function seedGroupF(): void
    {
        $this->command->info('  → Group F: 2 Cancelled...');

        $b1 = $this->createBookingWithRequirements(
            code: 'QA-BK-0039',
            name: 'QA Cancelled 01',
            type: CustomerType::Group,
            checkin: $this->future(15, 14),
            checkout: $this->future(17, 12),
            roomCount: 5,
        );
        $this->bookingService->cancelBooking($b1, 'QA test — guest cancelled trip');

        $b2 = $this->createBookingWithRequirements(
            code: 'QA-BK-0040',
            name: 'QA Cancelled 02',
            type: CustomerType::Company,
            checkin: $this->future(20, 14),
            checkout: $this->future(22, 12),
            roomCount: 8,
        );
        $this->bookingService->cancelBooking($b2, 'QA test — schedule conflict');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP G: 8 Unassigned (requirements only)
    // Codes: QA-BK-0041 → QA-BK-0048
    // Status: PENDING_ASSIGNMENT
    // ═══════════════════════════════════════════════════════════════════════

    private function seedGroupG(): void
    {
        $this->command->info('  → Group G: 8 Unassigned...');

        $specs = [
            ['rooms' => 3,  'offset' => 10, 'nights' => 2, 'type' => CustomerType::Individual],
            ['rooms' => 5,  'offset' => 12, 'nights' => 1, 'type' => CustomerType::Group],
            ['rooms' => 10, 'offset' => 15, 'nights' => 3, 'type' => CustomerType::Tour],
            ['rooms' => 15, 'offset' => 18, 'nights' => 2, 'type' => CustomerType::Company],
            ['rooms' => 20, 'offset' => 22, 'nights' => 1, 'type' => CustomerType::Group],
            ['rooms' => 25, 'offset' => 25, 'nights' => 2, 'type' => CustomerType::Tour],
            ['rooms' => 30, 'offset' => 28, 'nights' => 3, 'type' => CustomerType::Group],
            ['rooms' => 12, 'offset' => 32, 'nights' => 1, 'type' => CustomerType::Company],
        ];

        for ($i = 0; $i < 8; $i++) {
            $s = $specs[$i];
            $checkin = $this->future($s['offset'], 14);
            $checkout = $this->future($s['offset'] + $s['nights'], 12);

            $this->createBookingWithRequirements(
                code: sprintf('QA-BK-%04d', 40 + $i + 1),
                name: sprintf('QA Unassigned %02d', $i + 1),
                type: $s['type'],
                checkin: $checkin,
                checkout: $checkout,
                roomCount: $s['rooms'],
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CONFLICT: 2 bookings with intentional room overlap
    // Codes: QA-BK-0049, QA-BK-0050
    // ═══════════════════════════════════════════════════════════════════════

    private function seedConflict(): void
    {
        $this->command->info('  → Conflict: QA-BK-0049 & QA-BK-0050...');

        $room = $this->findAvailableRoom('TRIP', $this->future(40, 14), $this->future(44, 12));
        if (! $room) {
            $this->command->warn('    No room for conflict scenario.');
            return;
        }

        $startA = $this->future(40, 14);
        $endA = $this->future(42, 12);
        $bookingA = $this->createBookingWithRequirements(
            code: 'QA-BK-0049',
            name: 'QA Conflict Room A',
            type: CustomerType::Individual,
            checkin: $startA,
            checkout: $endA,
            roomCount: 3,
        );
        $this->forceAssignment($bookingA, $room, $startA, $endA);

        $startB = $this->future(41, 14);
        $endB = $this->future(43, 12);
        $bookingB = $this->createBookingWithRequirements(
            code: 'QA-BK-0050',
            name: 'QA Conflict Room B',
            type: CustomerType::Individual,
            checkin: $startB,
            checkout: $endB,
            roomCount: 3,
        );
        $this->forceAssignment($bookingB, $room, $startB, $endB);

        $bookingB->update([
            'internal_note' => "QA CONFLICT: Room {$room->room_number} overlaps with QA-BK-0049",
        ]);

        $this->command->info("    Conflict on room {$room->room_number}.");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SPECIAL SCENARIOS (use existing bookings)
    // ═══════════════════════════════════════════════════════════════════════

    private function seedSpecialScenarios(): void
    {
        $this->command->info('  → Special scenarios...');

        $this->scenarioReleasedAssignment();
        $this->scenarioBoundaryCase();
        $this->scenarioMultiBookingSameRoom();
        $this->scenarioRoomTypeMismatch();

        $this->command->info('    Scenario 1 (Future Reserved): QA-BK-0001 to 0010.');
        $this->command->info('    Scenario 2 (Checked In): QA-BK-0011 to 0020.');
        $this->command->info('    Scenario 3 (Overstay): QA-BK-0021 to 0025.');
        $this->command->info('    Scenario 4 (Checked Out): QA-BK-0036 to 0038.');
        $this->command->info('    Scenario 7 (Conflict): QA-BK-0049 & 0050.');
        $this->command->info('    Scenario 9 (Large Group): QA-BK-0047 (need 30 rooms).');
    }

    /**
     * Scenario 5: Released Assignment — add a released room to QA-BK-0001.
     */
    private function scenarioReleasedAssignment(): void
    {
        $booking = Booking::where('booking_code', 'QA-BK-0001')->first();
        if (! $booking) {
            return;
        }

        $room = $this->findAvailableRoom('DOUBLE', $booking->checkin_at, $booking->checkout_at);
        if (! $room) {
            $this->command->warn('    Scenario 5 (Released): No room found.');
            return;
        }

        $assignment = RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => $booking->checkin_at,
            'end_at' => $booking->checkout_at,
            'status' => AssignmentStatus::Released,
            'assigned_by' => $this->admin->id,
            'released_by' => $this->admin->id,
            'released_at' => now(),
            'release_reason' => 'QA: Guest requested room change',
        ]);

        Stay::create([
            'booking_id' => $booking->id,
            'room_assignment_id' => $assignment->id,
            'room_id' => $room->id,
            'planned_checkin_at' => $booking->checkin_at,
            'planned_checkout_at' => $booking->checkout_at,
            'status' => StayStatus::Cancelled,
        ]);

        $this->command->info("    Scenario 5: Released assignment on room {$room->room_number}.");
    }

    /**
     * Scenario 6: Boundary — Booking A checkout 12:00, Booking B checkin 12:00, same room.
     */
    private function scenarioBoundaryCase(): void
    {
        $room = $this->findAvailableRoom('FAMILY', $this->future(45, 14), $this->future(47, 12));
        if (! $room) {
            $this->command->warn('    Scenario 6 (Boundary): No room found.');
            return;
        }

        $bookingA = Booking::where('booking_code', 'QA-BK-0008')->first();
        $bookingB = Booking::where('booking_code', 'QA-BK-0009')->first();

        if (! $bookingA || ! $bookingB) {
            $this->command->warn('    Scenario 6 (Boundary): Reference bookings not found.');
            return;
        }

        $this->forceAssignment($bookingA, $room, $this->future(45, 14), $this->future(46, 12));
        $this->forceAssignment($bookingB, $room, $this->future(46, 12), $this->future(47, 12));

        $this->command->info("    Scenario 6: Boundary on room {$room->room_number} (checkout/checkin 12:00).");
    }

    /**
     * Scenario 8: Multi-Booking Same Room, non-overlapping.
     */
    private function scenarioMultiBookingSameRoom(): void
    {
        $room = $this->findAvailableRoom('TRIP_FAMILY', $this->future(48, 14), $this->future(51, 12));
        if (! $room) {
            $this->command->warn('    Scenario 8 (MultiBook): No room found.');
            return;
        }

        $bookingA = Booking::where('booking_code', 'QA-BK-0001')->first();
        $bookingB = Booking::where('booking_code', 'QA-BK-0002')->first();

        if (! $bookingA || ! $bookingB) {
            return;
        }

        $this->forceAssignment($bookingA, $room, $this->future(48, 14), $this->future(49, 12));
        $this->forceAssignment($bookingB, $room, $this->future(50, 14), $this->future(51, 12));

        $this->command->info("    Scenario 8: Multi-booking on room {$room->room_number} (non-overlapping).");
    }

    /**
     * Scenario 10: Room Type Mismatch — assign a TWIN room to a DOUBLE requirement.
     */
    private function scenarioRoomTypeMismatch(): void
    {
        $booking = Booking::where('booking_code', 'QA-BK-0026')->first();
        if (! $booking) {
            return;
        }

        $doubleTypeId = $this->roomTypes['DOUBLE']->id;
        $twinTypeId = $this->roomTypes['TWIN']->id;

        $hasDoubleReq = $booking->bookingRequirements()->where('room_type_id', $doubleTypeId)->exists();
        if (! $hasDoubleReq) {
            $booking->bookingRequirements()->create([
                'room_type_id' => $doubleTypeId,
                'quantity' => 1,
                'adults' => 2,
                'children_under_6' => 0,
                'children_over_6' => 0,
                'room_price' => self::ROOM_PRICES['DOUBLE'],
                'price_source' => PriceSource::Manual,
                'note' => 'QA: Mismatch test — requires DOUBLE',
            ]);
        }

        $twinRoom = $this->findAvailableRoom('TWIN', $booking->checkin_at, $booking->checkout_at);
        if (! $twinRoom) {
            $this->command->warn('    Scenario 10 (Mismatch): No TWIN room available.');
            return;
        }

        $assignment = RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $twinRoom->id,
            'room_type_id' => $twinTypeId,
            'start_at' => $booking->checkin_at,
            'end_at' => $booking->checkout_at,
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        Stay::create([
            'booking_id' => $booking->id,
            'room_assignment_id' => $assignment->id,
            'room_id' => $twinRoom->id,
            'planned_checkin_at' => $booking->checkin_at,
            'planned_checkout_at' => $booking->checkout_at,
            'status' => StayStatus::Reserved,
        ]);

        $this->command->info("    Scenario 10: Mismatch — TWIN room {$twinRoom->room_number} for DOUBLE req.");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // BOOKING CREATION
    // ═══════════════════════════════════════════════════════════════════════

    private function createBookingWithExplicitTypes(
        string $code,
        string $name,
        CustomerType $type,
        Carbon $checkin,
        Carbon $checkout,
        array $typeMix,
    ): Booking {
        $roomCount = array_sum($typeMix);
        [$adults, $under6, $over6] = $this->guestCounts($roomCount);
        $requirements = [];

        foreach ($typeMix as $typeCode => $qty) {
            $requirements[] = [
                'room_type_id' => $this->roomTypes[$typeCode]->id,
                'quantity' => $qty,
                'adults' => $this->roomTypes[$typeCode]->standard_adults * $qty,
                'children_under_6' => 0,
                'children_over_6' => 0,
                'room_price' => self::ROOM_PRICES[$typeCode],
                'price_source' => PriceSource::Manual->value,
                'note' => null,
            ];
        }

        return $this->bookingService->createBooking([
            'booking_code' => $code,
            'booking_color' => $this->nextColor(),
            'customer_name' => $name,
            'customer_phone' => '09' . str_pad((string) rand(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'customer_email' => strtolower(str_replace(' ', '.', $name)) . '@qa.test',
            'customer_type' => $type->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => $checkin,
            'checkout_at' => $checkout,
            'adults' => $adults,
            'children_under_6' => $under6,
            'children_over_6' => $over6,
            'sales_user_id' => $this->admin->id,
            'note' => "QA test booking {$code}",
            'internal_note' => 'Seeded by LastellaQaSeederV2',
            'requirements' => $requirements,
        ]);
    }

    private function createBookingWithRequirements(
        string $code,
        string $name,
        CustomerType $type,
        Carbon $checkin,
        Carbon $checkout,
        int $roomCount,
    ): Booking {
        [$adults, $under6, $over6] = $this->guestCounts($roomCount);
        $mix = $this->roomTypeMix($roomCount);
        $requirements = [];

        foreach ($mix as $typeCode => $qty) {
            $requirements[] = [
                'room_type_id' => $this->roomTypes[$typeCode]->id,
                'quantity' => $qty,
                'adults' => $this->roomTypes[$typeCode]->standard_adults * $qty,
                'children_under_6' => 0,
                'children_over_6' => 0,
                'room_price' => self::ROOM_PRICES[$typeCode],
                'price_source' => PriceSource::Manual->value,
                'note' => null,
            ];
        }

        return $this->bookingService->createBooking([
            'booking_code' => $code,
            'booking_color' => $this->nextColor(),
            'customer_name' => $name,
            'customer_phone' => '09' . str_pad((string) rand(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'customer_email' => strtolower(str_replace(' ', '.', $name)) . '@qa.test',
            'customer_type' => $type->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => $checkin,
            'checkout_at' => $checkout,
            'adults' => $adults,
            'children_under_6' => $under6,
            'children_over_6' => $over6,
            'sales_user_id' => $this->admin->id,
            'note' => "QA test booking {$code}",
            'internal_note' => 'Seeded by LastellaQaSeederV2',
            'requirements' => $requirements,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ROOM ASSIGNMENT
    // ═══════════════════════════════════════════════════════════════════════

    private function assignAllRooms(Booking $booking, Carbon $start, Carbon $end): array
    {
        $booking->load('bookingRequirements');
        $roomsToAssign = [];
        $pickedRoomIds = [];

        foreach ($booking->bookingRequirements as $req) {
            $typeCode = $this->typeCodeById($req->room_type_id);
            for ($r = 0; $r < $req->quantity; $r++) {
                $room = $this->findAvailableRoomExcluding($typeCode, $start, $end, $pickedRoomIds);
                if (! $room) {
                    $this->command->warn("    No {$typeCode} room for {$booking->booking_code}");
                    continue;
                }
                $pickedRoomIds[] = $room->id;
                $roomsToAssign[] = [
                    'room_id' => $room->id,
                    'room_type_id' => $room->room_type_id,
                    'start_at' => $start,
                    'end_at' => $end,
                ];
            }
        }

        if (empty($roomsToAssign)) {
            return [];
        }

        $assignments = $this->assignmentService->assignRooms($booking, $roomsToAssign);

        foreach ($assignments as $assignment) {
            $this->stayService->createStayFromAssignment($assignment);
        }

        return $assignments;
    }

    private function assignPartialRooms(Booking $booking, int $assignCount, Carbon $start, Carbon $end): array
    {
        $mix = $this->roomTypeMix($assignCount);
        $roomsToAssign = [];
        $pickedRoomIds = [];

        foreach ($mix as $typeCode => $qty) {
            for ($r = 0; $r < $qty; $r++) {
                $room = $this->findAvailableRoomExcluding($typeCode, $start, $end, $pickedRoomIds);
                if (! $room) {
                    $this->command->warn("    No {$typeCode} room for {$booking->booking_code}");
                    continue;
                }
                $pickedRoomIds[] = $room->id;
                $roomsToAssign[] = [
                    'room_id' => $room->id,
                    'room_type_id' => $room->room_type_id,
                    'start_at' => $start,
                    'end_at' => $end,
                ];
            }
        }

        if (empty($roomsToAssign)) {
            return [];
        }

        $assignments = $this->assignmentService->assignRooms($booking, $roomsToAssign);

        foreach ($assignments as $assignment) {
            $this->stayService->createStayFromAssignment($assignment);
        }

        return $assignments;
    }

    private function checkInAllAssignments(array $assignments, Carbon $checkin): void
    {
        foreach ($assignments as $assignment) {
            $stay = Stay::where('room_assignment_id', $assignment->id)->first();
            if ($stay) {
                $this->stayService->checkIn($stay, $checkin->copy()->addMinutes(rand(5, 90)));
            }
        }
    }

    private function checkOutAllAssignments(array $assignments, Carbon $checkout): void
    {
        foreach ($assignments as $assignment) {
            $stay = Stay::where('room_assignment_id', $assignment->id)->first();
            if ($stay && $stay->actual_checkin_at !== null) {
                $this->stayService->checkOut($stay, $checkout->copy()->addMinutes(rand(-30, 30)));
            }
        }
    }

    private function forceAssignment(Booking $booking, Room $room, Carbon $start, Carbon $end): RoomAssignment
    {
        $assignment = RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => $start,
            'end_at' => $end,
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        Stay::create([
            'booking_id' => $booking->id,
            'room_assignment_id' => $assignment->id,
            'room_id' => $room->id,
            'planned_checkin_at' => $start,
            'planned_checkout_at' => $end,
            'status' => StayStatus::Reserved,
        ]);

        return $assignment;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ROOM FINDING
    // ═══════════════════════════════════════════════════════════════════════

    private function findAvailableRoom(string $typeCode, Carbon $start, Carbon $end): ?Room
    {
        return $this->findAvailableRoomExcluding($typeCode, $start, $end, []);
    }

    private function findAvailableRoomExcluding(string $typeCode, Carbon $start, Carbon $end, array $excludeIds): ?Room
    {
        $rooms = $this->roomsByType[$typeCode] ?? collect();

        foreach ($rooms as $room) {
            if (in_array($room->id, $excludeIds, true)) {
                continue;
            }
            if (! $this->assignmentService->checkRoomConflict($room, $start, $end)) {
                return $room;
            }
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function nextColor(): string
    {
        $color = self::PALETTE[$this->colorIndex % count(self::PALETTE)];
        $this->colorIndex++;
        return $color;
    }

    private function guestCounts(int $roomCount): array
    {
        $adults = (int) round($roomCount * 2.2);
        $under6 = (int) max(0, round($roomCount * 0.3));
        $over6 = (int) max(0, round($roomCount * 0.15));
        return [$adults, $under6, $over6];
    }

    private function roomTypeMix(int $count): array
    {
        $types = ['DOUBLE', 'TWIN', 'TRIP', 'TRIP_FAMILY', 'FAMILY'];
        $weights = [0.30, 0.30, 0.15, 0.15, 0.10];
        $mix = [];
        $remaining = $count;

        foreach ($types as $idx => $type) {
            $qty = ($idx === count($types) - 1)
                ? $remaining
                : (int) round($count * $weights[$idx]);
            $qty = min($qty, $remaining);
            if ($qty > 0) {
                $mix[$type] = $qty;
                $remaining -= $qty;
            }
        }

        if ($remaining > 0) {
            $mix['TWIN'] = ($mix['TWIN'] ?? 0) + $remaining;
        }

        return $mix;
    }

    private function typeCodeById(int $roomTypeId): string
    {
        foreach ($this->roomTypes as $code => $type) {
            if ($type->id === $roomTypeId) {
                return $code;
            }
        }

        return 'TWIN';
    }

    private function past(int $days, int $hour): Carbon
    {
        return $this->now->copy()->subDays($days)->setTime($hour, 0, 0);
    }

    private function future(int $days, int $hour): Carbon
    {
        return $this->now->copy()->addDays($days)->setTime($hour, 0, 0);
    }
}
