<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3.1B2 — Payment UI: flash error routing for terminal/refund exceptions.
 */
class PaymentUiTest extends TestCase
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

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);
    }

    // ── Payment creation ──────────────────────────────────────────────────────

    public function test_admin_can_add_deposit(): void
    {
        $booking = $this->createBooking();

        $this->post("/admin/bookings/{$booking->id}/payments", [
            'payment_type'   => PaymentType::Deposit->value,
            'amount'         => 500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
            'note'           => '',
        ])->assertRedirect();

        $this->assertDatabaseHas('booking_payments', [
            'booking_id'   => $booking->id,
            'payment_type' => PaymentType::Deposit->value,
            'amount'       => 500000,
        ]);
    }

    public function test_payment_amount_must_be_positive(): void
    {
        $booking = $this->createBooking();

        $this->post("/admin/bookings/{$booking->id}/payments", [
            'payment_type'   => PaymentType::Deposit->value,
            'amount'         => 0,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ])->assertSessionHasErrors('amount');
    }

    // ── Payment deletion ──────────────────────────────────────────────────────

    public function test_admin_can_delete_payment(): void
    {
        $booking = $this->createBooking();
        $payment = BookingPayment::factory()->create([
            'booking_id'   => $booking->id,
            'payment_type' => PaymentType::Deposit->value,
            'amount'       => 200000,
        ]);

        $this->delete("/admin/bookings/{$booking->id}/payments/{$payment->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('booking_payments', ['id' => $payment->id]);
    }

    // ── BookingTerminalException → flash error (not validation error) ─────────

    public function test_delete_payment_on_terminal_booking_returns_flash_error(): void
    {
        $booking = $this->createBooking();

        // Add a payment
        $payment = BookingPayment::factory()->create([
            'booking_id'   => $booking->id,
            'payment_type' => PaymentType::Deposit->value,
            'amount'       => 800000,
        ]);

        // Force terminal status
        $booking->update(['status' => 'CANCELLED']);

        $response = $this->delete("/admin/bookings/{$booking->id}/payments/{$payment->id}");

        $response->assertSessionHas('error');
        $response->assertSessionMissing('errors');
    }

    // ── payment_summary in payload ─────────────────────────────────────────────

    public function test_booking_show_includes_payment_summary(): void
    {
        $booking = $this->createBooking();

        $this->get("/admin/bookings/{$booking->id}")
            ->assertInertia(fn ($page) => $page
                ->has('booking.payment_summary')
                ->has('booking.payment_summary.total_charges')
                ->has('booking.payment_summary.paid_total')
                ->has('booking.payment_summary.balance_due')
                ->has('booking.payment_summary.total_deposit')
                ->has('booking.payment_summary.total_refund')
                ->has('booking.payment_summary.total_adjustment')
            );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createBooking(): Booking
    {
        $roomType = RoomType::where('code', 'TWIN')->firstOrFail();

        return app(BookingService::class)->createBooking([
            'booking_color'    => '#196251',
            'customer_name'    => 'Test Guest',
            'customer_phone'   => '0901234567',
            'customer_type'    => CustomerType::Individual->value,
            'booking_type'     => BookingType::Overnight->value,
            'checkin_at'       => now()->toDateTimeString(),
            'checkout_at'      => now()->addDays(2)->toDateTimeString(),
            'adults'           => 2,
            'children_under_6' => 0,
            'children_over_6'  => 0,
            'sales_user_id'    => $this->admin->id,
            'requirements'     => [[
                'room_type_id'     => $roomType->id,
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
