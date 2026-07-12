<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethod;
use App\Enums\StayEventType;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 4.3A Milestone 3: Partial Checkout — StayEvent formalization.
 */
class PartialCheckoutStayEventTest extends TestCase
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

        $this->travelTo('2026-07-12 14:00:00');

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_partial_checkout_records_partial_checkout_event_with_correct_metadata_and_actor(): void
    {
        [$booking, $stays, $assignments] = $this->threeRoomCheckedInBooking();
        [$stay1, $stay2, $stay3] = $stays;
        [$assignment1] = $assignments;

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 2400000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay1);

        $event = $stay1->stayEvents()->orderBy('occurred_at')->orderBy('id')->where('event_type', StayEventType::PartialCheckout)->firstOrFail();

        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertSame(1, $event->metadata['version']);
        $this->assertSame($booking->id, $event->metadata['booking_id']);
        $this->assertSame($assignment1->id, $event->metadata['room_assignment_id']);
        $this->assertSame(2, $event->metadata['remaining_active_stays']);
    }

    public function test_final_checkout_records_checkout_event_with_correct_metadata(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay, null, true);

        $event = $stay->stayEvents()->orderBy('occurred_at')->orderBy('id')->where('event_type', StayEventType::Checkout)->firstOrFail();

        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertSame(1, $event->metadata['version']);
        $this->assertSame($booking->id, $event->metadata['booking_id']);
        $this->assertSame($assignment->id, $event->metadata['room_assignment_id']);
        $this->assertSame(0, $event->metadata['remaining_active_stays']);
    }

    public function test_partial_checkout_leaves_booking_active_and_does_not_finalize(): void
    {
        [$booking, $stays] = $this->threeRoomCheckedInBooking();
        [$stay1] = $stays;

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 2400000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay1);

        $this->assertNotSame(BookingStatus::CheckedOut, $booking->fresh()->status);
        $this->assertSame(BookingStatus::PartiallyCheckedOut, $booking->fresh()->status);
    }

    public function test_partial_checkout_does_not_close_folio(): void
    {
        [$booking, $stays] = $this->threeRoomCheckedInBooking();
        [$stay1] = $stays;

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 2400000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        app(StayService::class)->checkOut($stay1);

        $this->assertSame(FolioStatus::Open, $booking->folio()->firstOrFail()->status);
    }

    public function test_partial_checkout_leaves_other_active_stays_unchanged(): void
    {
        [$booking, $stays] = $this->threeRoomCheckedInBooking();
        [$stay1, $stay2, $stay3] = $stays;

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 2400000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $stay2Before = $stay2->refresh()->getAttributes();
        $stay3Before = $stay3->refresh()->getAttributes();

        app(StayService::class)->checkOut($stay1);

        $this->assertSame(StayStatus::CheckedIn, $stay2->fresh()->status);
        $this->assertSame(StayStatus::CheckedIn, $stay3->fresh()->status);
        $this->assertSame($stay2Before, $stay2->fresh()->getAttributes());
        $this->assertSame($stay3Before, $stay3->fresh()->getAttributes());
    }

    public function test_partial_checkout_creates_no_child_booking(): void
    {
        [$booking, $stays] = $this->threeRoomCheckedInBooking();
        [$stay1] = $stays;

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 2400000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $bookingCountBefore = Booking::count();

        app(StayService::class)->checkOut($stay1);

        $this->assertSame($bookingCountBefore, Booking::count());
    }

    public function test_partial_checkout_creates_no_new_payment_row(): void
    {
        [$booking, $stays] = $this->threeRoomCheckedInBooking();
        [$stay1] = $stays;

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 2400000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $paymentCountBefore = $booking->bookingPayments()->count();

        app(StayService::class)->checkOut($stay1);

        $this->assertSame($paymentCountBefore, $booking->bookingPayments()->count());
    }

    public function test_no_new_route_or_permission_introduced_for_partial_checkout(): void
    {
        $routeNames = collect(Route::getRoutes())->map(fn ($route) => $route->getName());

        $this->assertFalse($routeNames->contains(fn (?string $name) => $name !== null && str_contains($name, 'partial')));

        $this->assertNull(DB::table('permissions')->where('name', 'stay.partial_checkout')->first());
    }

    /**
     * @return array{0: Booking, 1: array<int, \App\Models\Stay>, 2: array<int, \App\Models\RoomAssignment>}
     */
    private function threeRoomCheckedInBooking(): array
    {
        $booking = $this->createBooking([
            'requirements' => [
                [
                    'room_type_id' => $this->twinType->id,
                    'quantity' => 3,
                    'adults' => 2,
                    'children_under_6' => 0,
                    'children_over_6' => 0,
                    'room_price' => 800000,
                    'price_source' => 'MANUAL',
                ],
            ],
        ]);
        $rooms = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->limit(3)->get();

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $rooms[0]->id, 'room_type_id' => $rooms[0]->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
            ['room_id' => $rooms[1]->id, 'room_type_id' => $rooms[1]->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
            ['room_id' => $rooms[2]->id, 'room_type_id' => $rooms[2]->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);

        $stays = array_map(
            fn ($assignment) => app(StayService::class)->createStayFromAssignment($assignment),
            $assignments,
        );

        foreach ($stays as $stay) {
            app(StayService::class)->checkIn($stay);
        }

        return [$booking, $stays, $assignments];
    }

    private function createBooking(array $overrides = []): Booking
    {
        $payload = array_merge([
            'booking_color' => '#196251',
            'customer_name' => 'Partial Checkout Guest',
            'customer_phone' => '0900000005',
            'customer_email' => 'partial-checkout@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-12 14:00:00',
            'checkout_at' => '2026-07-13 12:00:00',
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
