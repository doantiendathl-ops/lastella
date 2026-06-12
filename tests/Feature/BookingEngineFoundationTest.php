<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PaymentType;
use App\Enums\PriceSource;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingPaymentService;
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

class BookingEngineFoundationTest extends TestCase
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

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);
    }

    public function test_booking_can_be_created(): void
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());

        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'customer_name' => 'Jane Guest',
            'booking_type' => BookingType::Overnight->value,
            'customer_type' => CustomerType::Individual->value,
        ]);
    }

    public function test_booking_code_auto_generated(): void
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());

        $this->assertNotEmpty($booking->booking_code);
        $this->assertStringStartsWith('BK-', $booking->booking_code);
    }

    public function test_booking_can_have_multiple_requirements(): void
    {
        $twin = RoomType::where('code', 'TWIN')->firstOrFail();
        $double = RoomType::where('code', 'DOUBLE')->firstOrFail();

        $booking = $this->bookingService()->createBooking($this->bookingPayload([
            'requirements' => [
                $this->requirementPayload($twin, ['quantity' => 2]),
                $this->requirementPayload($double, ['quantity' => 1]),
            ],
        ]));

        $this->assertCount(2, $booking->bookingRequirements);
        $this->assertDatabaseHas('booking_requirements', [
            'booking_id' => $booking->id,
            'room_type_id' => $twin->id,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('booking_requirements', [
            'booking_id' => $booking->id,
            'room_type_id' => $double->id,
            'quantity' => 1,
        ]);
    }

    public function test_booking_can_store_manual_room_price(): void
    {
        $twin = RoomType::where('code', 'TWIN')->firstOrFail();

        $booking = $this->bookingService()->createBooking($this->bookingPayload([
            'requirements' => [
                $this->requirementPayload($twin, [
                    'room_price' => 1999.99,
                    'price_source' => PriceSource::Manual->value,
                ]),
            ],
        ]));

        $this->assertDatabaseHas('booking_requirements', [
            'booking_id' => $booking->id,
            'room_type_id' => $twin->id,
            'room_price' => 1999.99,
            'price_source' => PriceSource::Manual->value,
        ]);
    }

    public function test_booking_can_record_deposit(): void
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());

        $payment = app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 1500,
            'payment_method' => 'cash',
            'payment_at' => '2026-07-01 10:00:00',
            'note' => 'Front desk deposit',
        ]);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'payment_type' => PaymentType::AdditionalDeposit->value,
            'amount' => 500,
            'payment_method' => 'transfer',
            'payment_at' => '2026-07-01 12:00:00',
        ]);

        $this->assertDatabaseHas('booking_payments', [
            'id' => $payment->id,
            'booking_id' => $booking->id,
            'payment_type' => PaymentType::Deposit->value,
            'amount' => 1500,
        ]);
        $this->assertSame(2, $booking->bookingPayments()->count());
    }

    public function test_room_conflict_detection_works(): void
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());
        $room = $this->roomForType('TWIN');
        $assignmentService = app(RoomAssignmentService::class);

        $assignmentService->assignRooms($booking, [
            [
                'room_id' => $room->id,
                'room_type_id' => $room->room_type_id,
                'start_at' => '2026-07-01 14:00:00',
                'end_at' => '2026-07-02 12:00:00',
            ],
        ]);

        $this->assertTrue($assignmentService->checkRoomConflict($room, '2026-07-02 10:00:00', '2026-07-02 18:00:00'));
        $this->assertFalse($assignmentService->checkRoomConflict($room, '2026-07-02 12:00:00', '2026-07-02 18:00:00'));
    }

    public function test_room_assignment_succeeds_when_no_conflict_exists(): void
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());
        $room = $this->roomForType('TWIN');

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            [
                'room_id' => $room->id,
                'room_type_id' => $room->room_type_id,
            ],
        ]);

        $this->assertCount(1, $assignments);
        $this->assertDatabaseHas('room_assignments', [
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'status' => AssignmentStatus::Assigned->value,
        ]);
    }

    public function test_booking_becomes_partially_assigned(): void
    {
        $twin = RoomType::where('code', 'TWIN')->firstOrFail();
        $booking = $this->bookingService()->createBooking($this->bookingPayload([
            'requirements' => [
                $this->requirementPayload($twin, ['quantity' => 2]),
            ],
        ]));
        $room = $this->roomForType('TWIN');

        app(RoomAssignmentService::class)->assignRooms($booking, [
            [
                'room_id' => $room->id,
                'room_type_id' => $room->room_type_id,
            ],
        ]);

        $this->assertSame(BookingStatus::PartiallyAssigned, $booking->refresh()->status);
    }

    public function test_booking_becomes_fully_assigned(): void
    {
        $twin = RoomType::where('code', 'TWIN')->firstOrFail();
        $rooms = Room::where('room_type_id', $twin->id)->take(2)->get();
        $booking = $this->bookingService()->createBooking($this->bookingPayload([
            'requirements' => [
                $this->requirementPayload($twin, ['quantity' => 2]),
            ],
        ]));

        app(RoomAssignmentService::class)->assignRooms($booking, $rooms->map(fn (Room $room): array => [
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
        ])->all());

        $this->assertSame(BookingStatus::FullyAssigned, $booking->refresh()->status);
    }

    public function test_stay_can_be_created_from_assignment(): void
    {
        $assignment = $this->createSingleAssignment();

        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        $this->assertDatabaseHas('stays', [
            'id' => $stay->id,
            'booking_id' => $assignment->booking_id,
            'room_assignment_id' => $assignment->id,
            'room_id' => $assignment->room_id,
            'status' => StayStatus::Reserved->value,
        ]);
    }

    public function test_stay_can_check_in(): void
    {
        $assignment = $this->createSingleAssignment();
        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        $checkedIn = app(StayService::class)->checkIn($stay, '2026-07-01 15:00:00');

        $this->assertSame(StayStatus::CheckedIn, $checkedIn->status);
        $this->assertSame(AssignmentStatus::CheckedIn, $assignment->refresh()->status);
        $this->assertSame(BookingStatus::CheckedIn, $assignment->booking->refresh()->status);
        $this->assertNotNull($checkedIn->actual_checkin_at);
    }

    public function test_stay_can_check_out(): void
    {
        $assignment = $this->createSingleAssignment();
        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        $stayService = app(StayService::class);
        $checkedIn = $stayService->checkIn($stay, '2026-07-01 15:00:00');
        $checkedOut = $stayService->checkOut($checkedIn, '2026-07-02 11:00:00');

        $this->assertSame(StayStatus::CheckedOut, $checkedOut->status);
        $this->assertSame(AssignmentStatus::CheckedOut, $assignment->refresh()->status);
        $this->assertSame(BookingStatus::CheckedOut, $assignment->booking->refresh()->status);
        $this->assertNotNull($checkedOut->actual_checkout_at);
    }

    public function test_assigning_conflicting_room_throws_validation_exception(): void
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());
        $conflictingBooking = $this->bookingService()->createBooking($this->bookingPayload([
            'customer_name' => 'Conflicting Guest',
        ]));
        $room = $this->roomForType('TWIN');
        $assignmentService = app(RoomAssignmentService::class);

        $assignmentService->assignRooms($booking, [
            [
                'room_id' => $room->id,
                'room_type_id' => $room->room_type_id,
            ],
        ]);

        $this->expectException(ValidationException::class);

        $assignmentService->assignRooms($conflictingBooking, [
            [
                'room_id' => $room->id,
                'room_type_id' => $room->room_type_id,
            ],
        ]);
    }

    private function createSingleAssignment()
    {
        $booking = $this->bookingService()->createBooking($this->bookingPayload());
        $room = $this->roomForType('TWIN');

        return app(RoomAssignmentService::class)->assignRooms($booking, [
            [
                'room_id' => $room->id,
                'room_type_id' => $room->room_type_id,
            ],
        ])[0];
    }

    private function bookingService(): BookingService
    {
        return app(BookingService::class);
    }

    private function bookingPayload(array $overrides = []): array
    {
        $twin = RoomType::where('code', 'TWIN')->firstOrFail();

        return [
            'booking_color' => '#196251',
            'customer_name' => 'Jane Guest',
            'customer_phone' => '0800000000',
            'customer_email' => 'jane@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
            'note' => null,
            'internal_note' => null,
            'requirements' => [
                $this->requirementPayload($twin),
            ],
            ...$overrides,
        ];
    }

    private function requirementPayload(RoomType $roomType, array $overrides = []): array
    {
        return [
            'room_type_id' => $roomType->id,
            'quantity' => 1,
            'adults' => $roomType->standard_adults,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'room_price' => 1800,
            'price_source' => PriceSource::RateTable->value,
            'note' => null,
            ...$overrides,
        ];
    }

    private function roomForType(string $roomTypeCode): Room
    {
        $roomType = RoomType::where('code', $roomTypeCode)->firstOrFail();

        return Room::where('room_type_id', $roomType->id)->firstOrFail();
    }
}
