<?php

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\StayEventType;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutInspectionSkipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $reception;
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

        $this->travelTo('2026-07-12 14:00:00');

        $this->admin = tap(User::factory()->create())->assignRole('ADMIN');
        $this->reception = tap(User::factory()->create())->assignRole('RECEPTION');

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_authorized_user_can_skip_inspection_with_reason(): void
    {
        $stay = $this->checkedInStay();

        app(StayService::class)->skipCheckoutInspection($stay, $this->admin, 'Khách gấp, không kịp kiểm tra');

        $fresh = $stay->fresh();
        $this->assertNotNull($fresh->inspection_skipped_at);
        $this->assertSame($this->admin->id, $fresh->inspection_skipped_by);
        $this->assertSame('Khách gấp, không kịp kiểm tra', $fresh->inspection_skip_reason);

        $event = $fresh->stayEvents()->where('event_type', StayEventType::InspectionSkipped)->firstOrFail();
        $this->assertSame($this->admin->id, $event->actor_id);
    }

    public function test_user_without_permission_cannot_skip_inspection(): void
    {
        $stay = $this->checkedInStay();

        $this->expectException(AuthorizationException::class);
        app(StayService::class)->skipCheckoutInspection($stay, $this->reception, 'Lý do');
    }

    public function test_skip_endpoint_forbidden_without_permission(): void
    {
        $stay = $this->checkedInStay();
        $booking = $stay->booking;

        $this->actingAs($this->reception)
            ->post(route('admin.bookings.stays.inspection-skip', ['booking' => $booking, 'stay' => $stay]), [
                'reason' => 'Test',
            ])
            ->assertForbidden();

        $this->assertNull($stay->fresh()->inspection_skipped_at);
    }

    public function test_skip_endpoint_requires_reason(): void
    {
        $stay = $this->checkedInStay();
        $booking = $stay->booking;

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.stays.inspection-skip', ['booking' => $booking, 'stay' => $stay]), [])
            ->assertSessionHasErrors('reason');
    }

    public function test_skipping_inspection_does_not_by_itself_check_out_the_stay(): void
    {
        // Standalone action — checkOut() must remain completely independent.
        $stay = $this->checkedInStay();

        app(StayService::class)->skipCheckoutInspection($stay, $this->admin, 'Lý do');

        $this->assertSame('CHECKED_IN', $stay->fresh()->status->value);
    }

    public function test_checkout_still_succeeds_normally_when_inspection_was_never_touched(): void
    {
        // The core regression-safety guarantee: checkOut() never requires or references
        // inspection state in any way — this must always just work, exactly as before.
        $stay = $this->checkedInStay();

        app(BookingPaymentService::class)->addDeposit($stay->booking, [
            'amount' => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $result = app(StayService::class)->checkOut($stay, null, true);

        $this->assertSame('CHECKED_OUT', $result->status->value);
    }

    public function test_skipping_inspection_for_one_stay_does_not_affect_sibling_stay_in_same_booking(): void
    {
        $rooms = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(2)->get();
        $booking = $this->createMultiRoomBooking();

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $rooms[0]->id, 'room_type_id' => $rooms[0]->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
            ['room_id' => $rooms[1]->id, 'room_type_id' => $rooms[1]->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);

        $stay1 = app(StayService::class)->createStayFromAssignment($assignments[0]);
        $stay2 = app(StayService::class)->createStayFromAssignment($assignments[1]);
        app(StayService::class)->checkIn($stay1);
        app(StayService::class)->checkIn($stay2);

        app(StayService::class)->skipCheckoutInspection($stay1, $this->admin, 'Phòng 1 bỏ qua');

        $this->assertNotNull($stay1->fresh()->inspection_skipped_at);
        $this->assertNull($stay2->fresh()->inspection_skipped_at);
    }

    private function createMultiRoomBooking(): Booking
    {
        return app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'Multi Room Skip Guest',
            'customer_phone' => '0900000010',
            'customer_email' => 'multi-skip@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-12 14:00:00',
            'checkout_at' => '2026-07-13 12:00:00',
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
            'requirements' => [
                [
                    'room_type_id' => $this->twinType->id,
                    'quantity' => 2,
                    'adults' => 2,
                    'children_under_6' => 0,
                    'children_over_6' => 0,
                    'room_price' => 800000,
                    'price_source' => 'MANUAL',
                ],
            ],
        ]);
    }

    private function checkedInStay(): Stay
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        return $stay->fresh();
    }

    private function createBooking(): Booking
    {
        return app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'Skip Inspection Guest',
            'customer_phone' => '0900000009',
            'customer_email' => 'skip-inspection@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-12 14:00:00',
            'checkout_at' => '2026-07-13 12:00:00',
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
                    'room_price' => 800000,
                    'price_source' => 'MANUAL',
                ],
            ],
        ]);
    }
}
