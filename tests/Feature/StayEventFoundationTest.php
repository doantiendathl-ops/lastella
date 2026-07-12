<?php

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\StayEventType;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use App\Services\RoomAssignmentService;
use App\Services\StayEventService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4.3A Milestone 1: Stay Event Foundation.
 */
class StayEventFoundationTest extends TestCase
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

        $this->travelTo('2026-07-10 14:00:00');

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_checkin_creates_a_stay_event(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-10 14:00:00', 'end_at' => '2026-07-11 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        app(StayService::class)->checkIn($stay);

        $this->assertDatabaseHas('stay_events', [
            'stay_id' => $stay->id,
            'event_type' => StayEventType::CheckIn->value,
            'actor_id' => $this->admin->id,
        ]);
        $this->assertSame(1, $stay->stayEvents()->count());
    }

    public function test_recording_a_stay_event_does_not_mutate_stay_booking_folio_or_payment(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-10 14:00:00', 'end_at' => '2026-07-11 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $folio = $booking->folio()->firstOrFail();

        $stayBefore = $stay->refresh()->getAttributes();
        $bookingBefore = $booking->refresh()->getAttributes();
        $folioBefore = $folio->refresh()->getAttributes();
        $folioEntriesBefore = $folio->folioEntries()->orderBy('id')->get()->toArray();
        $paymentsBefore = $booking->bookingPayments()->orderBy('id')->get()->toArray();
        $stayEventCountBefore = $stay->stayEvents()->count();

        // Act: record an additional, independent Stay Event directly through the
        // dedicated persistence-only service — nothing else should move.
        app(StayEventService::class)->record($stay, StayEventType::CheckIn, $this->admin, ['reported_by' => 'Housekeeping']);

        $this->assertSame($stayBefore, $stay->refresh()->getAttributes());
        $this->assertSame($bookingBefore, $booking->refresh()->getAttributes());
        $this->assertSame($folioBefore, $folio->refresh()->getAttributes());
        $this->assertEquals($folioEntriesBefore, $folio->folioEntries()->orderBy('id')->get()->toArray());
        $this->assertEquals($paymentsBefore, $booking->bookingPayments()->orderBy('id')->get()->toArray());
        $this->assertSame($stayEventCountBefore + 1, $stay->stayEvents()->count());
    }

    private function createBooking(array $overrides = []): Booking
    {
        $payload = array_merge([
            'booking_color' => '#196251',
            'customer_name' => 'Stay Event Foundation Guest',
            'customer_phone' => '0900000002',
            'customer_email' => 'stay-event-foundation@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-10 14:00:00',
            'checkout_at' => '2026-07-11 12:00:00',
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
