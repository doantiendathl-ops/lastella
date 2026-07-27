<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use App\Enums\PaymentMethod;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4.2 Milestone 2: ADR-84 non-throwing StayService hooks.
 */
class StayServiceHousekeepingHookTest extends TestCase
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

        $this->travelTo('2026-07-01 14:00:00');

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_checkin_auto_marks_room_occupied(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        app(StayService::class)->checkIn($stay);

        $this->assertEquals(RoomStatus::Occupied, $room->refresh()->status);
    }

    /**
     * Room Operations Simplification: checkout marks the room BẨN directly —
     * no mandatory HousekeepingAssignment is created anymore (there is no
     * "chờ dọn" step in the simplified workflow; a single markClean() tap
     * is all that's needed).
     */
    public function test_checkout_marks_room_dirty_without_mandatory_assignment(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $room->refresh();
        $this->assertEquals(RoomStatus::VacantDirty, $room->status);
        $this->assertEquals('DIRTY', $room->cleaning_status->value);
        $this->assertDatabaseMissing('housekeeping_assignments', ['room_id' => $room->id]);
    }

    /**
     * Final Consistency Review (section VI.4): checkOut() must not dirty the room
     * when the transaction fails before reaching autoMarkDirtyOnCheckout() — here,
     * calling checkOut() on a stay that was never checked in fails the very first
     * guard (assignment status !== CheckedIn), so the whole transaction rolls back
     * and the room's pre-existing status/cleaning_status must be untouched.
     */
    public function test_failed_checkout_does_not_dirty_the_room(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $statusBefore = $room->status;
        $cleaningStatusBefore = $room->cleaning_status;
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        // Deliberately never checked in — checkOut() must reject this before any DML.

        try {
            app(StayService::class)->checkOut($stay, null, true);
            $this->fail('Expected checkOut() to throw for a stay that was never checked in.');
        } catch (\Illuminate\Validation\ValidationException) {
            // expected
        }

        $room->refresh();
        $this->assertEquals($statusBefore, $room->status);
        $this->assertEquals($cleaningStatusBefore, $room->cleaning_status);
    }

    private function createBooking(array $overrides = []): Booking
    {
        $payload = array_merge([
            'booking_color'    => '#196251',
            'customer_name'    => 'Housekeeping Hook Guest',
            'customer_phone'   => '0900000001',
            'customer_email'   => 'hk-hook@example.test',
            'customer_type'    => CustomerType::Individual->value,
            'booking_type'     => BookingType::Overnight->value,
            'checkin_at'       => '2026-07-01 14:00:00',
            'checkout_at'      => '2026-07-02 12:00:00',
            'adults'           => 2,
            'children_under_6' => 0,
            'children_over_6'  => 0,
            'sales_user_id'    => $this->admin->id,
        ], $overrides);

        if (! isset($payload['requirements'])) {
            $payload['requirements'] = [
                [
                    'room_type_id'     => $this->twinType->id,
                    'quantity'         => 1,
                    'adults'           => 2,
                    'children_under_6' => 0,
                    'children_over_6'  => 0,
                    'room_price'       => 800000,
                    'price_source'     => 'MANUAL',
                ],
            ];
        }

        return app(BookingService::class)->createBooking($payload);
    }
}
