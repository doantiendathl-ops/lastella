<?php

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Product Sprint 01 — Payment Projection: Booking Detail payload/permission readiness.
 */
class PaymentProjectionUiReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM_PRICE = 800000;

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

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    // 16. Booking Detail payload carries all three expected values.
    public function test_booking_detail_payload_includes_all_three_projection_values(): void
    {
        $booking = $this->bookingWithCheckedInStay();

        $this->actingAs($this->admin)
            ->get("/admin/bookings/{$booking->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.payment_projection.expected_total', self::ROOM_PRICE)
                ->has('booking.payment_projection.expected_deposit')
                ->has('booking.payment_projection.expected_balance')
            );
    }

    // Product Semantics Review follow-up: the Booking Detail payload also carries
    // the breakdown fields the UI needs to show room vs. non-room separately.
    public function test_booking_detail_payload_includes_breakdown_fields(): void
    {
        $booking = $this->bookingWithCheckedInStay();

        $this->actingAs($this->admin)
            ->get("/admin/bookings/{$booking->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.payment_projection.projected_room_total', self::ROOM_PRICE)
                ->where('booking.payment_projection.posted_non_room_total', 0)
            );
    }

    // Payment Reconciliation Review follow-up: the payload carries
    // recognized_paid_total, the direct reconciliation counterpart to
    // expected_balance (as distinct from expected_deposit, which is only ever
    // a subset shown as secondary breakdown).
    public function test_booking_detail_payload_includes_recognized_paid_total(): void
    {
        $booking = $this->bookingWithCheckedInStay();

        app(\App\Services\BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 200000,
            'payment_method' => 'CASH',
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(\App\Services\BookingPaymentService::class)->addPayment($booking, [
            'payment_type' => 'ROOM_PAYMENT',
            'amount' => 100000,
            'payment_method' => 'CASH',
            'payment_at' => now()->toDateTimeString(),
        ]);

        $this->actingAs($this->admin)
            ->get("/admin/bookings/{$booking->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.payment_projection.recognized_paid_total', 300000)
                ->where('booking.payment_projection.expected_deposit', 200000)
                ->where(
                    'booking.payment_projection.expected_balance',
                    fn ($value) => $value === self::ROOM_PRICE - 300000
                )
            );
    }

    // 15. A user without permission to view the Booking never receives the projection.
    public function test_user_without_view_permission_does_not_receive_projection(): void
    {
        $booking = $this->bookingWithCheckedInStay();

        $housekeeping = User::factory()->create();
        $housekeeping->assignRole('HOUSEKEEPING');

        $this->actingAs($housekeeping)
            ->get("/admin/bookings/{$booking->id}")
            ->assertForbidden();
    }

    private function bookingWithCheckedInStay(): \App\Models\Booking
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        $this->actingAs($this->admin);
        app(StayService::class)->checkIn($stay);

        return $booking->fresh();
    }

    private function createBooking(): \App\Models\Booking
    {
        $this->actingAs($this->admin);

        return app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'Payment Projection UI Guest',
            'customer_phone' => '0900000009',
            'customer_email' => 'payment-projection-ui@example.test',
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
                    'room_price' => self::ROOM_PRICE,
                    'price_source' => 'MANUAL',
                ],
            ],
        ]);
    }
}
