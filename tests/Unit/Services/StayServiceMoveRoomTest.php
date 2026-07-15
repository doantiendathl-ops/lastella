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

    public function test_move_to_available_room_of_different_type_succeeds(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $differentTypeRoom = Room::where('room_type_id', $this->doubleType->id)
            ->where('status', '!=', RoomStatus::OutOfOrder)
            ->firstOrFail();

        $moved = app(StayService::class)->moveRoom($stay, $differentTypeRoom, $this->admin);

        $this->assertSame($differentTypeRoom->id, $moved->room_id);
    }

    public function test_cross_type_move_preserves_commercial_room_type_on_assignment(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $originalAssignmentId = $stay->room_assignment_id;
        $differentTypeRoom = Room::where('room_type_id', $this->doubleType->id)
            ->where('status', '!=', RoomStatus::OutOfOrder)
            ->firstOrFail();

        app(StayService::class)->moveRoom($stay, $differentTypeRoom, $this->admin);

        $assignment = \App\Models\RoomAssignment::findOrFail($originalAssignmentId);

        // RoomAssignment.room_id follows the physical move; room_type_id (the
        // commercial requirement slot) must NOT — see RoomAssignment Semantic
        // Review in the Sprint 03 report.
        $this->assertSame($differentTypeRoom->id, $assignment->room_id);
        $this->assertSame($this->twinType->id, $assignment->room_type_id);
    }

    public function test_cross_type_move_updates_physical_room_type_via_relation(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $differentTypeRoom = Room::where('room_type_id', $this->doubleType->id)
            ->where('status', '!=', RoomStatus::OutOfOrder)
            ->firstOrFail();

        $moved = app(StayService::class)->moveRoom($stay, $differentTypeRoom, $this->admin);

        $this->assertSame($this->doubleType->id, $moved->room->room_type_id);
        $this->assertSame($this->doubleType->id, $moved->roomAssignment->room->room_type_id);
    }

    public function test_cross_type_move_does_not_alter_booking_requirement(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $differentTypeRoom = Room::where('room_type_id', $this->doubleType->id)
            ->where('status', '!=', RoomStatus::OutOfOrder)
            ->firstOrFail();

        $requirementBefore = $booking->fresh()->bookingRequirements()->orderBy('id')->get()->toArray();

        app(StayService::class)->moveRoom($stay, $differentTypeRoom, $this->admin);

        $requirementAfter = $booking->fresh()->bookingRequirements()->orderBy('id')->get()->toArray();
        $this->assertEquals($requirementBefore, $requirementAfter);
    }

    public function test_cross_type_move_does_not_change_projected_room_total(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $differentTypeRoom = Room::where('room_type_id', $this->doubleType->id)
            ->where('status', '!=', RoomStatus::OutOfOrder)
            ->firstOrFail();

        $before = app(PaymentProjectionService::class)->project($booking->fresh());

        app(StayService::class)->moveRoom($stay, $differentTypeRoom, $this->admin);

        $after = app(PaymentProjectionService::class)->project($booking->fresh());

        // Commercial Source Principle: physical room type changed, commercial
        // rate source did not ⇒ projected total is unaffected by the move.
        $this->assertSame($before['projected_room_total'], $after['projected_room_total']);
        $this->assertSame($before['expected_total'], $after['expected_total']);
    }

    public function test_cross_type_move_does_not_create_false_assignment_mismatch(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $differentTypeRoom = Room::where('room_type_id', $this->doubleType->id)
            ->where('status', '!=', RoomStatus::OutOfOrder)
            ->firstOrFail();

        app(StayService::class)->moveRoom($stay, $differentTypeRoom, $this->admin);

        $summary = app(RoomAssignmentService::class)->getAssignmentSummary($booking->fresh());
        $twinRow = collect($summary)->firstWhere('room_type_id', $this->twinType->id);

        $this->assertNotNull($twinRow, 'Original Twin requirement row must still be present.');
        $this->assertSame(1, $twinRow['required']);
        $this->assertSame(1, $twinRow['assigned']);
        $this->assertSame(0, $twinRow['remaining']);
        // No requirement exists for Double ⇒ it must not appear as a summary row at all.
        $this->assertNull(collect($summary)->firstWhere('room_type_id', $this->doubleType->id));
    }

    public function test_cross_type_move_does_not_make_night_audit_post_zero(): void
    {
        [$booking, $stay] = $this->checkedInStayInRoom();
        $differentTypeRoom = Room::where('room_type_id', $this->doubleType->id)
            ->where('status', '!=', RoomStatus::OutOfOrder)
            ->firstOrFail();

        $moved = app(StayService::class)->moveRoom($stay, $differentTypeRoom, $this->admin);

        $folio = $booking->fresh()->folio()->firstOrFail();
        $context = new \App\Services\Posting\PostingContext(
            booking: $booking->fresh(),
            folio: $folio,
            businessDate: \Illuminate\Support\Carbon::parse('2026-07-15'),
            stay: $moved->fresh(),
            postedBy: $this->admin,
        );

        $result = app(\App\Services\Posting\RoomChargePostingJob::class)->execute($context);

        // Must post the ORIGINAL commercial (Twin) rate, not skip with a zero
        // unit price just because the physical room is now a Double.
        $this->assertNotNull($result->entry, 'Night Audit must not skip posting after a cross-type Change Room move.');
        $this->assertEquals(self::ROOM_PRICE, (float) $result->entry->unit_price);
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

    public function test_cross_type_move_records_room_type_metadata(): void
    {
        [$booking, $stay, $oldRoom] = $this->checkedInStayInRoom();
        $differentTypeRoom = Room::where('room_type_id', $this->doubleType->id)
            ->where('status', '!=', RoomStatus::OutOfOrder)
            ->firstOrFail();

        app(StayService::class)->moveRoom($stay, $differentTypeRoom, $this->admin, 'Khách muốn nâng hạng phòng');

        $event = $stay->stayEvents()->where('event_type', StayEventType::RoomMove)->latest('id')->firstOrFail();

        $this->assertSame(2, $event->metadata['version']);
        $this->assertSame($this->twinType->id, $event->metadata['old_room_type_id']);
        $this->assertSame($this->doubleType->id, $event->metadata['new_room_type_id']);
        $this->assertSame($this->twinType->name, $event->metadata['old_room_type_name']);
        $this->assertSame($this->doubleType->name, $event->metadata['new_room_type_name']);
        $this->assertArrayNotHasKey('upgrade_price', $event->metadata);
        $this->assertArrayNotHasKey('downgrade_refund', $event->metadata);
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
