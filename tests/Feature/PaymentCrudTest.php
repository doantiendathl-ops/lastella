<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\ChargeType;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\FolioEntry;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use Illuminate\Support\Facades\DB;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentCrudTest extends TestCase
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
    }

    public function test_admin_can_delete_payment(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $payment = $this->addDeposit($booking);

        $this->delete("/admin/bookings/{$booking->id}/payments/{$payment->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('booking_payments', ['id' => $payment->id]);
    }

    public function test_manager_can_delete_todays_payment(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');
        $this->actingAs($manager);

        $booking = $this->createBooking();
        $payment = $this->addDeposit($booking);

        $this->delete("/admin/bookings/{$booking->id}/payments/{$payment->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('booking_payments', ['id' => $payment->id]);
    }

    public function test_manager_cannot_delete_old_payment(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');
        $this->actingAs($manager);

        $booking = $this->createBooking();
        $payment = $this->addDeposit($booking);
        DB::table('booking_payments')->where('id', $payment->id)->update(['created_at' => now()->subDay()]);

        $this->delete("/admin/bookings/{$booking->id}/payments/{$payment->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('booking_payments', ['id' => $payment->id]);
    }

    public function test_reception_cannot_delete_payment(): void
    {
        $reception = User::factory()->create();
        $reception->assignRole('RECEPTION');
        $this->actingAs($reception);

        $booking = $this->createBooking();
        $payment = $this->addDeposit($booking);

        $this->delete("/admin/bookings/{$booking->id}/payments/{$payment->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('booking_payments', ['id' => $payment->id]);
    }

    public function test_cannot_delete_payment_belonging_to_different_booking(): void
    {
        $this->actingAs($this->admin);

        $booking1 = $this->createBooking(['customer_name' => 'Booking One']);
        $booking2 = $this->createBooking(['customer_name' => 'Booking Two']);
        $payment = $this->addDeposit($booking1);

        $this->delete("/admin/bookings/{$booking2->id}/payments/{$payment->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('booking_payments', ['id' => $payment->id]);
    }

    public function test_cannot_add_payment_with_zero_amount(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->from("/admin/bookings/{$booking->id}?tab=payments")
            ->post("/admin/bookings/{$booking->id}/payments", [
                'payment_type' => PaymentType::Deposit->value,
                'amount' => 0,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_at' => now()->toDateTimeString(),
            ])
            ->assertRedirect("/admin/bookings/{$booking->id}?tab=payments")
            ->assertSessionHasErrors('amount');
    }

    public function test_cannot_add_payment_with_invalid_payment_type(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->from("/admin/bookings/{$booking->id}?tab=payments")
            ->post("/admin/bookings/{$booking->id}/payments", [
                'payment_type' => 'INVALID_TYPE',
                'amount' => 500000,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_at' => now()->toDateTimeString(),
            ])
            ->assertRedirect("/admin/bookings/{$booking->id}?tab=payments")
            ->assertSessionHasErrors('payment_type');
    }

    public function test_cannot_add_payment_with_invalid_payment_method(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->from("/admin/bookings/{$booking->id}?tab=payments")
            ->post("/admin/bookings/{$booking->id}/payments", [
                'payment_type' => PaymentType::Deposit->value,
                'amount' => 500000,
                'payment_method' => 'BITCOIN',
                'payment_at' => now()->toDateTimeString(),
            ])
            ->assertRedirect("/admin/bookings/{$booking->id}?tab=payments")
            ->assertSessionHasErrors('payment_method');
    }

    public function test_refund_cannot_exceed_paid_total(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->post("/admin/bookings/{$booking->id}/payments", [
            'payment_type' => PaymentType::Deposit->value,
            'amount' => 500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ])->assertRedirect();

        $this->from("/admin/bookings/{$booking->id}?tab=payments")
            ->post("/admin/bookings/{$booking->id}/payments", [
                'payment_type' => PaymentType::Refund->value,
                'amount' => 600000,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_at' => now()->toDateTimeString(),
            ])
            ->assertRedirect("/admin/bookings/{$booking->id}?tab=payments")
            ->assertSessionHasErrors('amount');
    }

    public function test_refund_within_paid_total_succeeds(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->post("/admin/bookings/{$booking->id}/payments", [
            'payment_type' => PaymentType::Deposit->value,
            'amount' => 500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ])->assertRedirect();

        $this->post("/admin/bookings/{$booking->id}/payments", [
            'payment_type' => PaymentType::Refund->value,
            'amount' => 300000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('booking_payments', [
            'booking_id' => $booking->id,
            'payment_type' => PaymentType::Refund->value,
            'amount' => 300000,
        ]);
    }

    public function test_deposit_does_not_downgrade_checked_in_booking_status(): void
    {
        $booking = $this->createBooking();
        $booking->update(['status' => BookingStatus::CheckedIn]);

        $this->actingAs($this->admin);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);

        $this->assertSame(BookingStatus::CheckedIn, $booking->fresh()->status);
    }

    public function test_deposit_blocked_on_checked_out_booking(): void
    {
        $booking = $this->createBooking();
        $booking->update(['status' => BookingStatus::CheckedOut]);

        $this->actingAs($this->admin);

        $this->expectException(\App\Exceptions\BookingTerminalException::class);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => 500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_delete_payment_logs_audit_entry(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $payment = $this->addDeposit($booking);
        $paymentId = $payment->id;

        $this->delete("/admin/bookings/{$booking->id}/payments/{$payment->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => BookingPayment::class,
            'entity_id' => $paymentId,
            'action' => AuditAction::Deleted->value,
        ]);
    }

    public function test_payment_summary_includes_breakdown_fields(): void
    {
        $this->actingAs($this->admin);
        $twin = RoomType::where('code', 'TWIN')->firstOrFail();

        $booking = $this->createBooking([
            'requirements' => [
                ['room_type_id' => $twin->id, 'quantity' => 1, 'adults' => 2, 'children_under_6' => 0, 'children_over_6' => 0, 'room_price' => 1000000, 'price_source' => 'MANUAL'],
            ],
        ]);

        FolioEntry::factory()->for($booking->folio)->create([
            'charge_type' => ChargeType::Room,
            'amount'      => 1000000,
            'unit_price'  => 1000000,
            'quantity'    => 1,
        ]);

        $booking->bookingPayments()->createMany([
            ['payment_type' => PaymentType::Deposit, 'amount' => 200000, 'payment_method' => PaymentMethod::Cash->value, 'payment_at' => now(), 'confirmed_by' => $this->admin->id],
            ['payment_type' => PaymentType::AdditionalDeposit, 'amount' => 100000, 'payment_method' => PaymentMethod::Cash->value, 'payment_at' => now(), 'confirmed_by' => $this->admin->id],
            ['payment_type' => PaymentType::RoomPayment, 'amount' => 300000, 'payment_method' => PaymentMethod::BankTransfer->value, 'payment_at' => now(), 'confirmed_by' => $this->admin->id],
            ['payment_type' => PaymentType::Refund, 'amount' => 50000, 'payment_method' => PaymentMethod::Cash->value, 'payment_at' => now(), 'confirmed_by' => $this->admin->id],
            ['payment_type' => PaymentType::Adjustment, 'amount' => 20000, 'payment_method' => PaymentMethod::Cash->value, 'payment_at' => now(), 'confirmed_by' => $this->admin->id],
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.payment_summary.total_deposit', 300000)
                ->where('booking.payment_summary.total_payment', 300000)
                ->where('booking.payment_summary.total_refund', 50000)
                ->where('booking.payment_summary.total_adjustment', 20000)
                ->where('booking.payment_summary.paid_total', 570000)
                ->where('booking.payment_summary.expected_total', 1000000)
                ->where('booking.payment_summary.remaining_balance', 430000)
            );
    }

    public function test_booking_show_exposes_can_delete_payment_flag(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $payment = $this->addDeposit($booking);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.payments', fn ($payments): bool => collect($payments)->contains(
                    fn (array $item): bool => $item['id'] === $payment->id && $item['can_delete'] === true
                ))
                ->where('can.deletePayment', true)
            );
    }

    private function createBooking(array $overrides = []): Booking
    {
        $twin = RoomType::where('code', 'TWIN')->firstOrFail();

        $payload = array_merge([
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
        ], $overrides);

        if (! isset($payload['requirements'])) {
            $payload['requirements'] = [
                ['room_type_id' => $twin->id, 'quantity' => 1, 'adults' => 2, 'children_under_6' => 0, 'children_over_6' => 0, 'room_price' => 1800, 'price_source' => 'MANUAL'],
            ];
        }

        return app(BookingService::class)->createBooking($payload);
    }

    private function addDeposit(Booking $booking, int $amount = 500000): BookingPayment
    {
        return app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => $amount,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now()->toDateTimeString(),
        ]);
    }
}
