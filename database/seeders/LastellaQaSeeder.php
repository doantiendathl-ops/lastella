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
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingService;
use App\Services\FolioService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class LastellaQaSeeder extends Seeder
{
    private const QA_PREFIX = 'QA ';

    private BookingService $bookings;

    private RoomAssignmentService $assignments;

    private StayService $stays;

    private array $roomTypes = [];

    private array $roomsByType = [];

    private Carbon $today;

    private array $colors = [
        '#8B5CF6', '#10B981', '#F59E0B', '#EF4444',
        '#3B82F6', '#EC4899', '#14B8A6', '#6366F1',
        '#0EA5E9', '#D946EF', '#F97316', '#84CC16',
        '#06B6D4', '#A855F7', '#FB923C', '#22D3EE',
        '#E11D48', '#65A30D', '#7C3AED', '#0891B2',
        '#DC2626', '#059669', '#7C3AED', '#DB2777',
        '#2563EB', '#16A34A', '#9333EA', '#CA8A04',
        '#4F46E5', '#15803D',
    ];

    private int $colorIndex = 0;

    public function run(): void
    {
        $this->bookings = app(BookingService::class);
        $this->assignments = app(RoomAssignmentService::class);
        $this->stays = app(StayService::class);

        $admin = User::where('email', 'admin@lastella.local')->firstOrFail();
        Auth::login($admin);

        $this->today = Carbon::today();
        $this->loadReferenceData();
        $this->cleanPreviousQaData();

        $this->command->info('Creating QA seed data...');

        $this->seedRequirementOnlyBookings();
        $this->seedAssignedBookings();
        $this->seedCompletedBookings();
        $this->seedEdgeCases();

        $total = Booking::where('customer_name', 'like', self::QA_PREFIX.'%')->count();
        $this->command->info("QA seed complete: {$total} bookings created.");
    }

    private function loadReferenceData(): void
    {
        $this->roomTypes = RoomType::all()->keyBy('code')->all();

        foreach (['TWIN', 'DOUBLE', 'TRIP', 'FAMILY', 'TRIP_FAMILY'] as $code) {
            $typeId = $this->roomTypes[$code]->id;
            $this->roomsByType[$code] = Room::where('room_type_id', $typeId)
                ->orderBy('room_number')
                ->get()
                ->all();
        }
    }

    private function cleanPreviousQaData(): void
    {
        $qaBookingIds = Booking::where('customer_name', 'like', self::QA_PREFIX.'%')->pluck('id');

        if ($qaBookingIds->isEmpty()) {
            return;
        }

        $this->command->info('Cleaning previous QA data...');

        Stay::whereIn('booking_id', $qaBookingIds)->delete();
        RoomAssignment::whereIn('booking_id', $qaBookingIds)->delete();
        \App\Models\FolioEntry::whereIn(
            'folio_id',
            \App\Models\Folio::whereIn('booking_id', $qaBookingIds)->pluck('id'),
        )->delete();
        \App\Models\Folio::whereIn('booking_id', $qaBookingIds)->delete();
        \App\Models\BookingPayment::whereIn('booking_id', $qaBookingIds)->delete();
        \App\Models\BookingRequirement::whereIn('booking_id', $qaBookingIds)->delete();
        Booking::whereIn('id', $qaBookingIds)->delete();

        // Reset the OOO room we might have set in a previous run.
        Room::where('status', RoomStatus::OutOfOrder)->update(['status' => RoomStatus::VacantClean]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Group 1: Requirement-only bookings (9)
    // ──────────────────────────────────────────────────────────────────────────

    private function seedRequirementOnlyBookings(): void
    {
        $this->command->info('  → Requirement-only bookings (9)...');

        $this->createBooking('QA Draft 01', [
            'checkin_at' => $this->future(7, 14),
            'checkout_at' => $this->future(8, 12),
        ]);

        $this->createBooking('QA Pending 01', [
            'checkin_at' => $this->future(3, 14),
            'checkout_at' => $this->future(4, 12),
            'requirements' => [$this->req('TWIN', 1, 1600)],
        ]);

        $this->createBooking('QA Pending 02', [
            'checkin_at' => $this->future(5, 14),
            'checkout_at' => $this->future(7, 12),
            'adults' => 4,
            'customer_type' => CustomerType::Group->value,
            'requirements' => [$this->req('DOUBLE', 2, 1800)],
        ]);

        $this->createBooking('QA Pending 03', [
            'checkin_at' => $this->future(4, 14),
            'checkout_at' => $this->future(6, 12),
            'adults' => 7,
            'children_under_6' => 2,
            'customer_type' => CustomerType::Group->value,
            'requirements' => [
                $this->req('TRIP', 1, 2300),
                $this->req('FAMILY', 1, 3000),
            ],
        ]);

        $this->createBooking('QA Pending 04', [
            'checkin_at' => $this->future(10, 14),
            'checkout_at' => $this->future(12, 12),
            'adults' => 6,
            'customer_type' => CustomerType::Tour->value,
            'requirements' => [$this->req('TWIN', 3, 1600)],
        ]);

        $this->createBooking('QA Pending 05', [
            'checkin_at' => $this->future(30, 14),
            'checkout_at' => $this->future(32, 12),
            'requirements' => [$this->req('TWIN', 1, 1600)],
        ]);

        $this->createBooking('QA Pending 06', [
            'checkin_at' => $this->nextWeekend(14),
            'checkout_at' => $this->nextWeekend(12, 2),
            'requirements' => [$this->req('DOUBLE', 1, 1800)],
        ]);

        $this->createBooking('QA DayUse 01', [
            'checkin_at' => $this->future(2, 8),
            'checkout_at' => $this->future(2, 17),
            'booking_type' => BookingType::DayUse->value,
            'requirements' => [$this->req('TRIP_FAMILY', 1, 1300)],
        ]);

        $this->createBooking('QA Pending 07', [
            'checkin_at' => $this->future(14, 14),
            'checkout_at' => $this->future(16, 12),
            'adults' => 10,
            'customer_type' => CustomerType::Company->value,
            'customer_email' => 'corp@example.test',
            'requirements' => [
                $this->req('FAMILY', 2, 3000),
                $this->req('TRIP_FAMILY', 1, 2600),
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Group 2: Assigned bookings (12)
    // ──────────────────────────────────────────────────────────────────────────

    private function seedAssignedBookings(): void
    {
        $this->command->info('  → Assigned bookings (12)...');

        // Correct type — 1×TWIN
        $b = $this->createBooking('QA Assigned 01', [
            'checkin_at' => $this->future(1, 14),
            'checkout_at' => $this->future(2, 12),
            'requirements' => [$this->req('TWIN', 1, 1600)],
        ]);
        $this->assignAvailable($b, 'TWIN', $this->future(1, 14), $this->future(2, 12));

        // Correct type — 2×DOUBLE
        $b = $this->createBooking('QA Assigned 02', [
            'checkin_at' => $this->future(3, 14),
            'checkout_at' => $this->future(5, 12),
            'adults' => 4,
            'requirements' => [$this->req('DOUBLE', 2, 1800)],
        ]);
        $this->assignAvailable($b, 'DOUBLE', $this->future(3, 14), $this->future(5, 12));
        $this->assignAvailable($b, 'DOUBLE', $this->future(3, 14), $this->future(5, 12));

        // Wrong type — DOUBLE room for TWIN requirement (triggers mismatch)
        $b = $this->createBooking('QA Mismatch 01', [
            'checkin_at' => $this->future(2, 14),
            'checkout_at' => $this->future(3, 12),
            'requirements' => [$this->req('TWIN', 1, 1600)],
        ]);
        $this->assignAvailable($b, 'DOUBLE', $this->future(2, 14), $this->future(3, 12));

        // Partially assigned — 2×TWIN required, only 1 assigned
        $b = $this->createBooking('QA Partial 01', [
            'checkin_at' => $this->future(4, 14),
            'checkout_at' => $this->future(6, 12),
            'adults' => 4,
            'requirements' => [$this->req('TWIN', 2, 1600)],
        ]);
        $this->assignAvailable($b, 'TWIN', $this->future(4, 14), $this->future(6, 12));

        // Released history — assigned then released
        $b = $this->createBooking('QA Released History 01', [
            'checkin_at' => $this->future(5, 14),
            'checkout_at' => $this->future(6, 12),
            'requirements' => [$this->req('TRIP', 1, 2300)],
        ]);
        $a = $this->assignAvailable($b, 'TRIP', $this->future(5, 14), $this->future(6, 12));
        if ($a) {
            $this->assignments->releaseAssignment($a, 'QA test — guest requested room change');
        }

        // Future reservation — blocks availability in planned range
        $b = $this->createBooking('QA Future Reserved 01', [
            'checkin_at' => $this->future(8, 14),
            'checkout_at' => $this->future(10, 12),
            'requirements' => [$this->req('FAMILY', 1, 3000)],
        ]);
        $this->assignAvailable($b, 'FAMILY', $this->future(8, 14), $this->future(10, 12));

        // Checked-in stay — blocks regardless of planned checkout
        $b = $this->createBooking('QA CheckedIn 01', [
            'checkin_at' => $this->past(1, 14),
            'checkout_at' => $this->future(1, 12),
            'requirements' => [$this->req('TWIN', 1, 1600)],
        ]);
        $a = $this->assignAvailable($b, 'TWIN', $this->past(1, 14), $this->future(1, 12));
        if ($a) {
            $stay = Stay::where('room_assignment_id', $a->id)->firstOrFail();
            $this->stays->checkIn($stay, $this->past(1, 14)->addMinutes(15));
        }

        // Multiple rooms checked in
        $b = $this->createBooking('QA CheckedIn 02', [
            'checkin_at' => $this->past(2, 14),
            'checkout_at' => $this->future(2, 12),
            'adults' => 6,
            'customer_type' => CustomerType::Group->value,
            'requirements' => [$this->req('TRIP', 2, 2300)],
        ]);
        $a1 = $this->assignAvailable($b, 'TRIP', $this->past(2, 14), $this->future(2, 12));
        $a2 = $this->assignAvailable($b, 'TRIP', $this->past(2, 14), $this->future(2, 12));
        foreach ([$a1, $a2] as $a) {
            if ($a) {
                $stay = Stay::where('room_assignment_id', $a->id)->firstOrFail();
                $this->stays->checkIn($stay, $this->past(2, 14)->addMinutes(rand(5, 30)));
            }
        }

        // Overstay — planned checkout in the past, still checked in
        $b = $this->createBooking('QA Overstay 01', [
            'checkin_at' => $this->past(3, 14),
            'checkout_at' => $this->past(1, 12),
            'requirements' => [$this->req('TRIP_FAMILY', 1, 2600)],
        ]);
        $a = $this->assignAvailable($b, 'TRIP_FAMILY', $this->past(3, 14), $this->past(1, 12));
        if ($a) {
            $stay = Stay::where('room_assignment_id', $a->id)->firstOrFail();
            $this->stays->checkIn($stay, $this->past(3, 14)->addMinutes(5));
        }

        // Assigned with deposit
        $b = $this->createBooking('QA Assigned Deposit 01', [
            'checkin_at' => $this->future(6, 14),
            'checkout_at' => $this->future(8, 12),
            'requirements' => [$this->req('DOUBLE', 1, 1800)],
        ]);
        $this->assignAvailable($b, 'DOUBLE', $this->future(6, 14), $this->future(8, 12));
        $b->bookingPayments()->create([
            'payment_type' => PaymentType::Deposit,
            'amount' => 900000,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'payment_at' => now()->subDay(),
            'confirmed_by' => Auth::id(),
        ]);

        // Walk-in same day
        $b = $this->createBooking('QA WalkIn 01', [
            'checkin_at' => $this->today->copy()->setHour(14),
            'checkout_at' => $this->future(1, 12),
            'customer_type' => CustomerType::WalkIn->value,
            'requirements' => [$this->req('TWIN', 1, 1600)],
        ]);
        $this->assignAvailable($b, 'TWIN', $this->today->copy()->setHour(14), $this->future(1, 12));

        // Excess rooms (more assigned than required)
        $b = $this->createBooking('QA Excess 01', [
            'checkin_at' => $this->future(9, 14),
            'checkout_at' => $this->future(10, 12),
            'requirements' => [$this->req('FAMILY', 1, 3000)],
        ]);
        $this->assignAvailable($b, 'FAMILY', $this->future(9, 14), $this->future(10, 12));
        $this->assignAvailable($b, 'FAMILY', $this->future(9, 14), $this->future(10, 12));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Group 3: Completed / Closed bookings (9)
    // ──────────────────────────────────────────────────────────────────────────

    private function seedCompletedBookings(): void
    {
        $this->command->info('  → Completed/closed bookings (9)...');

        // Checked out — basic
        $b = $this->createBooking('QA CheckedOut 01', [
            'checkin_at' => $this->past(5, 14),
            'checkout_at' => $this->past(4, 12),
            'requirements' => [$this->req('TWIN', 1, 1600)],
        ]);
        $a = $this->assignAvailable($b, 'TWIN', $this->past(5, 14), $this->past(4, 12));
        if ($a) {
            $stay = Stay::where('room_assignment_id', $a->id)->firstOrFail();
            $this->stays->checkIn($stay, $this->past(5, 14)->addMinutes(10));
            $this->stays->checkOut($stay, $this->past(4, 12)->subMinutes(30));
        }

        // Checked out with full payment
        $b = $this->createBooking('QA CheckedOut 02', [
            'checkin_at' => $this->past(8, 14),
            'checkout_at' => $this->past(6, 12),
            'requirements' => [$this->req('DOUBLE', 1, 1800)],
        ]);
        $a = $this->assignAvailable($b, 'DOUBLE', $this->past(8, 14), $this->past(6, 12));
        if ($a) {
            $stay = Stay::where('room_assignment_id', $a->id)->firstOrFail();
            $this->stays->checkIn($stay, $this->past(8, 14)->addMinutes(5));
            $this->stays->checkOut($stay, $this->past(6, 12)->subMinutes(15));
        }
        $b->bookingPayments()->create([
            'payment_type' => PaymentType::Deposit,
            'amount' => 1000000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => $this->past(8, 10),
            'confirmed_by' => Auth::id(),
        ]);
        $b->bookingPayments()->create([
            'payment_type' => PaymentType::RoomPayment,
            'amount' => 800000,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'payment_at' => $this->past(6, 11),
            'confirmed_by' => Auth::id(),
        ]);

        // Cancelled — had assignment (released on cancel)
        $b = $this->createBooking('QA Cancelled 01', [
            'checkin_at' => $this->future(20, 14),
            'checkout_at' => $this->future(21, 12),
            'requirements' => [$this->req('TRIP', 1, 2300)],
        ]);
        $this->assignAvailable($b, 'TRIP', $this->future(20, 14), $this->future(21, 12));
        $this->bookings->cancelBooking($b, 'QA test — guest cancelled');

        // Cancelled — before any assignment
        $b = $this->createBooking('QA Cancelled 02', [
            'checkin_at' => $this->future(22, 14),
            'checkout_at' => $this->future(24, 12),
            'requirements' => [$this->req('FAMILY', 1, 3000)],
        ]);
        $this->bookings->cancelBooking($b, 'QA test — schedule conflict');

        // Cancelled with deposit + refund
        $b = $this->createBooking('QA Cancelled 03', [
            'checkin_at' => $this->future(25, 14),
            'checkout_at' => $this->future(27, 12),
            'requirements' => [$this->req('DOUBLE', 1, 1800)],
        ]);
        $b->bookingPayments()->create([
            'payment_type' => PaymentType::Deposit,
            'amount' => 500000,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'payment_at' => now()->subDays(3),
            'confirmed_by' => Auth::id(),
        ]);
        $this->bookings->cancelBooking($b, 'QA test — cancelled after deposit');
        $b->bookingPayments()->create([
            'payment_type' => PaymentType::Refund,
            'amount' => 500000,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'payment_at' => now()->subDay(),
            'confirmed_by' => Auth::id(),
        ]);

        // No-show
        $b = $this->createBooking('QA NoShow 01', [
            'checkin_at' => $this->past(2, 14),
            'checkout_at' => $this->past(1, 12),
            'requirements' => [$this->req('TWIN', 1, 1600)],
        ]);
        $b->update(['status' => BookingStatus::NoShow]);

        // Completed multi-room
        $b = $this->createBooking('QA Completed 01', [
            'checkin_at' => $this->past(10, 14),
            'checkout_at' => $this->past(8, 12),
            'adults' => 4,
            'requirements' => [$this->req('TWIN', 2, 1600)],
        ]);
        $a1 = $this->assignAvailable($b, 'TWIN', $this->past(10, 14), $this->past(8, 12));
        $a2 = $this->assignAvailable($b, 'TWIN', $this->past(10, 14), $this->past(8, 12));
        foreach ([$a1, $a2] as $a) {
            if ($a) {
                $stay = Stay::where('room_assignment_id', $a->id)->firstOrFail();
                $this->stays->checkIn($stay, $this->past(10, 14)->addMinutes(rand(5, 20)));
                $this->stays->checkOut($stay, $this->past(8, 11)->addMinutes(rand(0, 30)));
            }
        }
        $b->bookingPayments()->create([
            'payment_type' => PaymentType::RoomPayment,
            'amount' => 3200000,
            'payment_method' => PaymentMethod::Card->value,
            'payment_at' => $this->past(8, 11),
            'confirmed_by' => Auth::id(),
        ]);

        // Completed single
        $b = $this->createBooking('QA Completed 02', [
            'checkin_at' => $this->past(7, 14),
            'checkout_at' => $this->past(5, 12),
            'requirements' => [$this->req('TRIP_FAMILY', 1, 2600)],
        ]);
        $a = $this->assignAvailable($b, 'TRIP_FAMILY', $this->past(7, 14), $this->past(5, 12));
        if ($a) {
            $stay = Stay::where('room_assignment_id', $a->id)->firstOrFail();
            $this->stays->checkIn($stay, $this->past(7, 14)->addMinutes(10));
            $this->stays->checkOut($stay, $this->past(5, 12)->subMinutes(20));
        }

        // Long-ago archive
        $b = $this->createBooking('QA Archive 01', [
            'checkin_at' => $this->past(30, 14),
            'checkout_at' => $this->past(28, 12),
            'requirements' => [$this->req('FAMILY', 1, 3000)],
        ]);
        $a = $this->assignAvailable($b, 'FAMILY', $this->past(30, 14), $this->past(28, 12));
        if ($a) {
            $stay = Stay::where('room_assignment_id', $a->id)->firstOrFail();
            $this->stays->checkIn($stay, $this->past(30, 14)->addMinutes(5));
            $this->stays->checkOut($stay, $this->past(28, 11));
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Edge cases
    // ──────────────────────────────────────────────────────────────────────────

    private function seedEdgeCases(): void
    {
        $this->command->info('  → Edge cases...');

        // Edge 6: Boundary — checkout at 12:00 / next checkin at 12:00, same room
        $boundaryRoom = $this->findAvailableRoom('DOUBLE', $this->future(11, 14), $this->future(13, 12));
        if ($boundaryRoom) {
            $bA = $this->createBooking('QA Boundary A', [
                'checkin_at' => $this->future(11, 14),
                'checkout_at' => $this->future(12, 12),
                'requirements' => [$this->req('DOUBLE', 1, 1800)],
            ]);
            $this->assignRoomById($bA, $boundaryRoom, $this->future(11, 14), $this->future(12, 12));

            $bB = $this->createBooking('QA Boundary B', [
                'checkin_at' => $this->future(12, 12),
                'checkout_at' => $this->future(13, 12),
                'requirements' => [$this->req('DOUBLE', 1, 1800)],
            ]);
            $this->assignRoomById($bB, $boundaryRoom, $this->future(12, 12), $this->future(13, 12));
        }

        // Edge 8: Multi-booking same room, non-overlapping
        $multiRoom = $this->findAvailableRoom('TWIN', $this->future(14, 14), $this->future(16, 12));
        if ($multiRoom) {
            $m1 = $this->createBooking('QA MultiBook A', [
                'checkin_at' => $this->future(14, 14),
                'checkout_at' => $this->future(15, 12),
                'requirements' => [$this->req('TWIN', 1, 1600)],
            ]);
            $this->assignRoomById($m1, $multiRoom, $this->future(14, 14), $this->future(15, 12));

            $m2 = $this->createBooking('QA MultiBook B', [
                'checkin_at' => $this->future(15, 14),
                'checkout_at' => $this->future(16, 12),
                'requirements' => [$this->req('TWIN', 1, 1600)],
            ]);
            $this->assignRoomById($m2, $multiRoom, $this->future(15, 14), $this->future(16, 12));
        }

        // Edge 7: Intentional overlap (bypasses service)
        $overlapRoom = $this->findAvailableRoom('TRIP', $this->future(17, 14), $this->future(20, 12));
        if ($overlapRoom) {
            $o1 = $this->createBooking('QA Overlap X', [
                'checkin_at' => $this->future(17, 14),
                'checkout_at' => $this->future(19, 12),
                'note' => 'QA INTENTIONAL OVERLAP — not a real booking',
                'requirements' => [$this->req('TRIP', 1, 2300)],
            ]);
            $this->assignRoomById($o1, $overlapRoom, $this->future(17, 14), $this->future(19, 12));

            $o2 = $this->createBooking('QA Overlap Y', [
                'checkin_at' => $this->future(18, 14),
                'checkout_at' => $this->future(20, 12),
                'note' => 'QA INTENTIONAL OVERLAP — not a real booking',
                'requirements' => [$this->req('TRIP', 1, 2300)],
            ]);
            $this->forceAssignment($o2, $overlapRoom, $this->future(18, 14), $this->future(20, 12));
        }

        // Out-of-order room — pick any FAMILY room not currently checked-in
        $oorRoom = Room::where('room_type_id', $this->roomTypes['FAMILY']->id)
            ->where('status', '!=', RoomStatus::OutOfOrder)
            ->whereDoesntHave('roomAssignments', fn ($q) => $q->where('status', AssignmentStatus::CheckedIn))
            ->orderByDesc('room_number')
            ->first();
        if ($oorRoom) {
            $oorRoom->update(['status' => RoomStatus::OutOfOrder]);
            $this->command->info("    Room {$oorRoom->room_number} set to OutOfOrder.");
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Room selection helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function findAvailableRoom(string $typeCode, Carbon $startAt, Carbon $endAt): ?Room
    {
        foreach ($this->roomsByType[$typeCode] as $room) {
            if (! $this->assignments->checkRoomConflict($room, $startAt, $endAt)) {
                return $room;
            }
        }

        $this->command->warn("    No available {$typeCode} room for range {$startAt} — {$endAt}");

        return null;
    }

    private function assignAvailable(Booking $booking, string $typeCode, Carbon $startAt, Carbon $endAt): ?RoomAssignment
    {
        $room = $this->findAvailableRoom($typeCode, $startAt, $endAt);
        if (! $room) {
            return null;
        }

        return $this->assignRoomById($booking, $room, $startAt, $endAt);
    }

    private function assignRoomById(Booking $booking, Room $room, Carbon $startAt, Carbon $endAt): RoomAssignment
    {
        $result = $this->assignments->assignRooms($booking, [[
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ]]);

        $assignment = $result[0];
        $this->stays->createStayFromAssignment($assignment);

        return $assignment;
    }

    private function forceAssignment(Booking $booking, Room $room, Carbon $startAt, Carbon $endAt): RoomAssignment
    {
        $assignment = RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => Auth::id(),
        ]);

        Stay::create([
            'booking_id' => $booking->id,
            'room_assignment_id' => $assignment->id,
            'room_id' => $room->id,
            'planned_checkin_at' => $startAt,
            'planned_checkout_at' => $endAt,
            'status' => StayStatus::Reserved,
        ]);

        return $assignment;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Generic helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function createBooking(string $name, array $overrides = []): Booking
    {
        $requirements = $overrides['requirements'] ?? [];
        unset($overrides['requirements']);

        $data = [
            'booking_color' => $this->nextColor(),
            'customer_name' => $name,
            'customer_phone' => '0900'.str_pad((string) random_int(100000, 999999), 6, '0'),
            'customer_email' => strtolower(str_replace(' ', '.', $name)).'@qa.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => $this->future(1, 14),
            'checkout_at' => $this->future(2, 12),
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => Auth::id(),
            'note' => null,
            'internal_note' => 'QA seed data',
            ...$overrides,
            'requirements' => $requirements,
        ];

        return $this->bookings->createBooking($data);
    }

    private function req(string $typeCode, int $quantity, int $price): array
    {
        return [
            'room_type_id' => $this->roomTypes[$typeCode]->id,
            'quantity' => $quantity,
            'adults' => $this->roomTypes[$typeCode]->standard_adults * $quantity,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'room_price' => $price,
            'price_source' => PriceSource::Manual->value,
            'note' => null,
        ];
    }

    private function past(int $days, int $hour): Carbon
    {
        return $this->today->copy()->subDays($days)->setHour($hour)->setMinute(0)->setSecond(0);
    }

    private function future(int $days, int $hour): Carbon
    {
        return $this->today->copy()->addDays($days)->setHour($hour)->setMinute(0)->setSecond(0);
    }

    private function nextWeekend(int $hour, int $offsetDays = 0): Carbon
    {
        return $this->today->copy()->next('Saturday')->addDays($offsetDays)
            ->setHour($hour)->setMinute(0)->setSecond(0);
    }

    private function nextColor(): string
    {
        $color = $this->colors[$this->colorIndex % count($this->colors)];
        $this->colorIndex++;

        return $color;
    }
}
