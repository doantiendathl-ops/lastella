<?php

namespace Tests\Unit\Services;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\StayEventType;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingService;
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
 * Phase 4.3A Milestone 2: StayService::extendStay().
 */
class StayServiceExtendTest extends TestCase
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

        $this->travelTo('2026-07-11 14:00:00');

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_successfully_extends_a_checked_in_stay(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-11 14:00:00', 'end_at' => '2026-07-12 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        $extended = app(StayService::class)->extendStay($stay, '2026-07-14 12:00:00', $this->admin);

        $this->assertSame('2026-07-14 12:00:00', $extended->planned_checkout_at->toDateTimeString());
        $this->assertSame('2026-07-14 12:00:00', $assignment->refresh()->end_at->toDateTimeString());
    }

    public function test_extension_creates_an_extend_stay_event_with_correct_metadata_and_actor(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-11 14:00:00', 'end_at' => '2026-07-12 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        app(StayService::class)->extendStay($stay, '2026-07-14 12:00:00', $this->admin);

        $event = $stay->stayEvents()->orderBy('occurred_at')->orderBy('id')->where('event_type', StayEventType::ExtendStay)->firstOrFail();

        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertSame(1, $event->metadata['version']);
        $this->assertSame('2026-07-12T12:00:00+07:00', $event->metadata['old_planned_checkout_at']);
        $this->assertSame('2026-07-14T12:00:00+07:00', $event->metadata['new_planned_checkout_at']);
    }

    public function test_rejects_extension_of_a_non_checked_in_stay(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-11 14:00:00', 'end_at' => '2026-07-12 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        $this->assertSame(StayStatus::Reserved, $stay->status);

        $this->expectException(ValidationException::class);
        app(StayService::class)->extendStay($stay, '2026-07-14 12:00:00', $this->admin);
    }

    public function test_rejects_same_checkout_date(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-11 14:00:00', 'end_at' => '2026-07-12 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        $this->expectException(ValidationException::class);
        app(StayService::class)->extendStay($stay, '2026-07-12 12:00:00', $this->admin);
    }

    public function test_rejects_earlier_checkout_date(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-11 14:00:00', 'end_at' => '2026-07-12 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        $this->expectException(ValidationException::class);
        app(StayService::class)->extendStay($stay, '2026-07-12 08:00:00', $this->admin);
    }

    public function test_rejects_extension_conflicting_with_another_booking(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $bookingA = $this->createBooking(['customer_email' => 'guest-a@example.test']);

        [$assignmentA] = app(RoomAssignmentService::class)->assignRooms($bookingA, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-11 14:00:00', 'end_at' => '2026-07-12 12:00:00'],
        ]);
        $stayA = app(StayService::class)->createStayFromAssignment($assignmentA);
        app(StayService::class)->checkIn($stayA);

        // Booking B takes the same room starting right after A's ORIGINAL checkout — no
        // conflict at creation time, but it blocks any extension of A past 2026-07-12 12:00.
        $bookingB = $this->createBooking([
            'customer_email' => 'guest-b@example.test',
            'checkin_at' => '2026-07-12 12:00:00',
            'checkout_at' => '2026-07-13 12:00:00',
        ]);
        app(RoomAssignmentService::class)->assignRooms($bookingB, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-12 12:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);

        $this->expectException(ValidationException::class);
        app(StayService::class)->extendStay($stayA, '2026-07-13 08:00:00', $this->admin);
    }

    public function test_extension_creates_no_child_booking(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-11 14:00:00', 'end_at' => '2026-07-12 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        $bookingCountBefore = Booking::count();

        app(StayService::class)->extendStay($stay, '2026-07-14 12:00:00', $this->admin);

        $this->assertSame($bookingCountBefore, Booking::count());
    }

    public function test_extension_does_not_touch_folio_or_payments(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-11 14:00:00', 'end_at' => '2026-07-12 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        $folio = $booking->folio()->firstOrFail();
        $folioBefore = $folio->refresh()->getAttributes();
        $folioEntriesBefore = $folio->folioEntries()->orderBy('id')->get()->toArray();
        $paymentsBefore = $booking->bookingPayments()->orderBy('id')->get()->toArray();

        app(StayService::class)->extendStay($stay, '2026-07-14 12:00:00', $this->admin);

        $this->assertSame($folioBefore, $folio->refresh()->getAttributes());
        $this->assertEquals($folioEntriesBefore, $folio->folioEntries()->orderBy('id')->get()->toArray());
        $this->assertEquals($paymentsBefore, $booking->bookingPayments()->orderBy('id')->get()->toArray());
    }

    private function createBooking(array $overrides = []): Booking
    {
        $payload = array_merge([
            'booking_color' => '#196251',
            'customer_name' => 'Stay Extend Guest',
            'customer_phone' => '0900000003',
            'customer_email' => 'stay-extend@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-11 14:00:00',
            'checkout_at' => '2026-07-12 12:00:00',
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
                    'room_price' => 800000,
                    'price_source' => 'MANUAL',
                ],
            ];
        }

        return app(BookingService::class)->createBooking($payload);
    }
}
