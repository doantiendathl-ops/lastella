<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
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
use Tests\TestCase;

/**
 * Phase 3.1B2 — Checkout UI: ADR-52 (disabled UX) and ADR-53 (OBE flash redirect).
 */
class CheckoutUiTest extends TestCase
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

    // ── ADR-53: OBE → flash error + redirect to payments tab ─────────────────

    public function test_checkout_with_outstanding_balance_redirects_to_payments_tab_with_flash_error(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        // No payment — balance outstanding

        // Pass confirmed=true to reach the OBE guard (confirmation gate fires before OBE).
        $response = $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", ['confirmed' => true]);

        $response->assertRedirect(route('admin.bookings.show', [
            'booking' => $booking->id,
            'tab'     => 'payments',
        ]));

        $response->assertSessionHas('error');
        $response->assertSessionMissing('errors');
    }

    public function test_checkout_with_outstanding_balance_does_not_change_booking_status(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        // Pass confirmed=true so the OBE guard is reached and the booking stays unchanged.
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", ['confirmed' => true]);

        $this->assertNotSame(BookingStatus::CheckedOut, $booking->fresh()->status);
    }

    // ── Checkout success ─────────────────────────────────────────────────────

    public function test_checkout_with_zero_balance_succeeds_and_redirects_to_room_map(): void
    {
        [$booking, $stay] = $this->bookingWithCheckedInStay();

        // Pay full amount
        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        $response = $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", ['confirmed' => true]);

        $response->assertRedirect(route('admin.bookings.show', [
            'booking' => $booking->id,
            'tab'     => 'room_map',
        ]));

        $response->assertSessionHas('success');
    }

    // ── Authorization ────────────────────────────────────────────────────────

    public function test_checkout_requires_authentication(): void
    {
        $this->app['auth']->logout();

        [$booking, $stay] = $this->bookingWithCheckedInStay();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out")
            ->assertRedirect('/login');
    }

    public function test_checkout_fails_for_nonexistent_stay(): void
    {
        [$booking] = $this->bookingWithCheckedInStay();

        $this->post("/admin/bookings/{$booking->id}/stays/99999/check-out")
            ->assertStatus(404);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function bookingWithCheckedInStay(): array
    {
        $booking = $this->createBooking();
        $room    = Room::where('room_type_id', $this->twinType->id)->firstOrFail();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            [
                'room_id'      => $room->id,
                'room_type_id' => $room->room_type_id,
                'start_at'     => '2026-07-01 14:00:00',
                'end_at'       => '2026-07-02 12:00:00',
            ],
        ]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        return [$booking, $stay];
    }

    private function createBooking(): Booking
    {
        return app(BookingService::class)->createBooking([
            'booking_color'    => '#196251',
            'customer_name'    => 'Checkout Test Guest',
            'customer_phone'   => '0900000001',
            'customer_type'    => CustomerType::Individual->value,
            'booking_type'     => BookingType::Overnight->value,
            'checkin_at'       => '2026-07-01 14:00:00',
            'checkout_at'      => '2026-07-02 12:00:00',
            'adults'           => 2,
            'children_under_6' => 0,
            'children_over_6'  => 0,
            'sales_user_id'    => $this->admin->id,
            'requirements'     => [[
                'room_type_id'     => $this->twinType->id,
                'quantity'         => 1,
                'adults'           => 2,
                'children_under_6' => 0,
                'children_over_6'  => 0,
                'room_price'       => 800000,
                'price_source'     => 'MANUAL',
            ]],
        ]);
    }
}
