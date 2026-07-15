<?php

namespace Tests\Unit\Services;

use App\Enums\BookingType;
use App\Enums\ChargeType;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\RoomStatus;
use App\Enums\StayEventType;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\FolioEntry;
use App\Models\NightAuditBookingLog;
use App\Models\NightAuditRun;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use App\Services\PaymentProjectionService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Product Sprint 02 — Room Move M1.
 */
class StayServiceMoveRoomTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM_PRICE = 800000;

    private User $admin;
    private RoomType $twinType;
    private RoomType $doubleType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
            RoomSeeder::class,
        ]);

        $this->travelTo('2026-07-14 14:00:00');

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
        $this->doubleType = RoomType::where('code', '!=', 'TWIN')->firstOrFail();
    }

    public function test_move_to_same_room_is_rejected(): void
    {
        [$booking, $stay, $room] = $this->checkedInStayInRoom();

        $this->expectException(ValidationException::class);
        app(StayService::class)->moveRoom($stay, $room, $this->admin);
    }

    public function test_move_to_occupied_room_is_rejected(): void
    {
        [$bookingA, $stayA] = $this->checkedInStayInRoom();
        $rooms = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(3)->get();
        $targetRoom = $rooms[1];

        // Booking B occupies the target room for the same period.
        $bookingB = $this->createBooking(['customer_email' => 'guest-b@example.test']);
        [$assignmentB] = app(RoomAssignmentService::class)->assignRooms($bookingB, [
            ['room_id' => $targetRoom->id, 'room_type_id' => $targetRoom->room_type_id, 'start_at' => '2026-07-14 14:00:00', 'end_at' => '2026-07-15 12:00:00'],
        ]);
        $stayB = app(StayService::class)->createStayFromAssignment($assignmentB);
        app(StayService::class)->checkIn($stayB);

        $this->expectException(ValidationException::class);
        app(StayService::class)->moveRoom($stayA, $targetRoom, $this->admin);
    }

    public function test_move_to_maintenance_room_is_rejected(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $targetRoom = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', RoomStatus::OutOfOrder)->orderBy('id')->limit(3)->get()[1];
        $targetRoom->update(['status' => RoomStatus::OutOfOrder]);

        $this->expectException(ValidationException::class);
        app(StayService::class)->moveRoom($stay, $targetRoom, $this->admin);
    }

    public function test_move_to_different_room_type_is_rejected(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $differentTypeRoom = Room::where('room_type_id', $this->doubleType->id)->firstOrFail();

        $this->expectException(ValidationException::class);
        app(StayService::class)->moveRoom($stay, $differentTypeRoom, $this->admin);
    }

    public function test_move_to_available_room_succeeds(): void
    {
        [$booking, $stay, $oldRoom] = $this->checkedInStayInRoom();
        $targetRoom = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', RoomStatus::OutOfOrder)->orderBy('id')->limit(3)->get()[1];

        $moved = app(StayService::class)->moveRoom($stay, $targetRoom, $this->admin, 'Điều hòa hỏng');

        $this->assertSame($targetRoom->id, $moved->room_id);
        $this->assertSame($targetRoom->id, $stay->roomAssignment()->first()->refresh()->room_id);
    }

    public function test_move_room_rejects_non_checked_in_stay(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-14 14:00:00', 'end_at' => '2026-07-15 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        $targetRoom = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', RoomStatus::OutOfOrder)->orderBy('id')->limit(3)->get()[1];

        $this->assertSame(StayStatus::Reserved, $stay->status);

        $this->expectException(ValidationException::class);
        app(StayService::class)->moveRoom($stay, $targetRoom, $this->admin);
    }

    public function test_move_room_updates_projection_correctly(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $targetRoom = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', RoomStatus::OutOfOrder)->orderBy('id')->limit(3)->get()[1];

        $before = app(PaymentProjectionService::class)->project($booking->fresh());

        app(StayService::class)->moveRoom($stay, $targetRoom, $this->admin);

        $after = app(PaymentProjectionService::class)->project($booking->fresh());

        // Same room type ⇒ same rate ⇒ projected total unaffected by the move.
        $this->assertSame($before['projected_room_total'], $after['projected_room_total']);
        $this->assertSame($before['expected_total'], $after['expected_total']);
    }

    public function test_move_room_leaves_booking_unchanged(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $targetRoom = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', RoomStatus::OutOfOrder)->orderBy('id')->limit(3)->get()[1];

        $bookingBefore = $booking->fresh()->getAttributes();

        app(StayService::class)->moveRoom($stay, $targetRoom, $this->admin);

        $bookingAfter = $booking->fresh()->getAttributes();
        unset($bookingBefore['updated_at'], $bookingAfter['updated_at']);

        $this->assertSame($bookingBefore, $bookingAfter);
    }

    public function test_move_room_keeps_the_same_stay_row_and_creates_no_child_stay(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $targetRoom = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', RoomStatus::OutOfOrder)->orderBy('id')->limit(3)->get()[1];
        $stayCountBefore = Stay::count();
        $originalStayId = $stay->id;

        $moved = app(StayService::class)->moveRoom($stay, $targetRoom, $this->admin);

        $this->assertSame($originalStayId, $moved->id);
        $this->assertSame($stayCountBefore, Stay::count());
    }

    public function test_move_room_creates_no_child_booking(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $targetRoom = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', RoomStatus::OutOfOrder)->orderBy('id')->limit(3)->get()[1];
        $bookingCountBefore = Booking::count();

        app(StayService::class)->moveRoom($stay, $targetRoom, $this->admin);

        $this->assertSame($bookingCountBefore, Booking::count());
    }

    public function test_move_room_does_not_alter_historical_folio_entries(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $targetRoom = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', RoomStatus::OutOfOrder)->orderBy('id')->limit(3)->get()[1];

        $folio = $booking->fresh()->folio()->firstOrFail();
        $entriesBefore = $folio->folioEntries()->orderBy('id')->get()->toArray();
        $this->assertNotEmpty($entriesBefore, 'Sanity check: check-in should already have posted the first night.');

        app(StayService::class)->moveRoom($stay, $targetRoom, $this->admin);

        $entriesAfter = $folio->folioEntries()->orderBy('id')->get()->toArray();
        $this->assertEquals($entriesBefore, $entriesAfter);
    }

    public function test_move_room_does_not_alter_historical_payments(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $targetRoom = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', RoomStatus::OutOfOrder)->orderBy('id')->limit(3)->get()[1];

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => self::ROOM_PRICE,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);
        $paymentsBefore = $booking->bookingPayments()->orderBy('id')->get()->toArray();

        app(StayService::class)->moveRoom($stay, $targetRoom, $this->admin);

        $paymentsAfter = $booking->bookingPayments()->orderBy('id')->get()->toArray();
        $this->assertEquals($paymentsBefore, $paymentsAfter);
    }

    public function test_move_room_creates_no_night_audit_records(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $targetRoom = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', RoomStatus::OutOfOrder)->orderBy('id')->limit(3)->get()[1];
        $runsBefore = NightAuditRun::count();
        $logsBefore = NightAuditBookingLog::count();

        app(StayService::class)->moveRoom($stay, $targetRoom, $this->admin);

        $this->assertSame($runsBefore, NightAuditRun::count());
        $this->assertSame($logsBefore, NightAuditBookingLog::count());
    }

    public function test_move_room_updates_room_status_old_dirty_new_occupied(): void
    {
        [$booking, $stay, $oldRoom] = $this->checkedInStayInRoom();
        $targetRoom = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', RoomStatus::OutOfOrder)->orderBy('id')->limit(3)->get()[1];

        $this->assertSame(RoomStatus::Occupied, $oldRoom->fresh()->status);

        app(StayService::class)->moveRoom($stay, $targetRoom, $this->admin);

        $this->assertSame(RoomStatus::VacantDirty, $oldRoom->fresh()->status);
        $this->assertSame(RoomStatus::Occupied, $targetRoom->fresh()->status);
        $this->assertDatabaseHas('housekeeping_assignments', [
            'room_id' => $oldRoom->id,
        ]);
    }

    public function test_move_room_updates_availability(): void
    {
        [$booking, $stay, $oldRoom] = $this->checkedInStayInRoom();
        $targetRoom = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', RoomStatus::OutOfOrder)->orderBy('id')->limit(3)->get()[1];

        app(StayService::class)->moveRoom($stay, $targetRoom, $this->admin);

        $rules = app(\App\Services\RoomAvailabilityRuleService::class);

        // Old room is now free for the remaining period (this booking no longer occupies it).
        $this->assertFalse($rules->hasConflict($oldRoom->id, now(), $stay->fresh()->planned_checkout_at));
        // New room now conflicts (this booking's own CheckedIn assignment occupies it).
        $this->assertTrue($rules->hasConflict($targetRoom->id, now(), $stay->fresh()->planned_checkout_at));
    }

    public function test_move_room_creates_audit_event(): void
    {
        [$booking, $stay, $oldRoom] = $this->checkedInStayInRoom();
        $targetRoom = Room::where('room_type_id', $this->twinType->id)->where('status', '!=', RoomStatus::OutOfOrder)->orderBy('id')->limit(3)->get()[1];

        app(StayService::class)->moveRoom($stay, $targetRoom, $this->admin, 'TV hỏng');

        $event = $stay->stayEvents()->where('event_type', StayEventType::RoomMove)->firstOrFail();

        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertSame($oldRoom->id, $event->metadata['old_room_id']);
        $this->assertSame($targetRoom->id, $event->metadata['new_room_id']);
        $this->assertSame('TV hỏng', $event->metadata['reason']);
    }

    /**
     * @return array{0: Booking, 1: Stay, 2: Room}
     */
    private function checkedInStayInRoom(): array
    {
        $room = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-14 14:00:00', 'end_at' => '2026-07-15 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        return [$booking, $stay, $room];
    }

    private function createBooking(array $overrides = []): Booking
    {
        $payload = array_merge([
            'booking_color' => '#196251',
            'customer_name' => 'Room Move Guest',
            'customer_phone' => '0900000010',
            'customer_email' => 'room-move@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-14 14:00:00',
            'checkout_at' => '2026-07-15 12:00:00',
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
        ], $overrides);

        if (! isset($payload['requirements'])) {
            $payload['requirements'] = [
                [
                    'room_type_id' => $this->twinType->id,
                    'quantity' => 1,
                    'adults' => 2,
                    'children_under_6' => 0,
                    'children_over_6' => 0,
                    'room_price' => self::ROOM_PRICE,
                    'price_source' => 'MANUAL',
                ],
            ];
        }

        return app(BookingService::class)->createBooking($payload);
    }
}
