<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ChargeType;
use App\Enums\CustomerType;
use App\Enums\FolioStatus;
use App\Models\Booking;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingService;
use App\Services\FolioService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FolioCrudTest extends TestCase
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

    public function test_folio_is_auto_created_when_booking_is_created(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->assertDatabaseHas('folios', ['booking_id' => $booking->id]);
        $this->assertNotNull($booking->fresh()->folio);
    }

    public function test_folio_number_has_expected_prefix_format(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $folio = $booking->fresh()->folio;
        $this->assertMatchesRegularExpression('/^FLO-\d{8}-\d{6}$/', $folio->folio_number);
    }

    public function test_admin_can_add_charge_to_open_folio(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->post("/admin/bookings/{$booking->id}/folio/entries", [
            'charge_type' => ChargeType::Other->value,
            'description' => 'Minibar items',
            'quantity' => 2,
            'unit_price' => 50000,
            'entry_date' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('folio_entries', [
            'folio_id' => $booking->fresh()->folio->id,
            'charge_type' => ChargeType::Other->value,
            'amount' => 100000,
        ]);
    }

    public function test_voided_entry_excluded_from_total_charges(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $folio = $booking->fresh()->folio;

        // Add two charges
        FolioEntry::factory()->create([
            'folio_id' => $folio->id,
            'amount' => 200000,
            'voided_at' => null,
        ]);
        FolioEntry::factory()->create([
            'folio_id' => $folio->id,
            'amount' => 100000,
            'voided_at' => now(),
            'voided_by' => $this->admin->id,
            'void_reason' => 'Test void',
        ]);

        $total = app(FolioService::class)->getFolioTotal($booking->fresh());

        $this->assertEquals(200000.0, $total);
    }

    public function test_cannot_void_already_voided_entry(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $folio = $booking->fresh()->folio;

        $entry = FolioEntry::factory()->voided()->create([
            'folio_id' => $folio->id,
        ]);

        // Wave 2: AlreadyVoidedException::render() uses with('error', ...) not withErrors()
        $this->patch("/admin/bookings/{$booking->id}/folio/entries/{$entry->id}", [
            'void_reason' => 'Trying to void again',
        ])->assertSessionHas('error');
    }

    public function test_void_requires_reason_of_at_least_5_chars(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $folio = $booking->fresh()->folio;

        $entry = FolioEntry::factory()->create(['folio_id' => $folio->id]);

        $this->from("/admin/bookings/{$booking->id}?tab=payments")
            ->patch("/admin/bookings/{$booking->id}/folio/entries/{$entry->id}", [
                'void_reason' => 'abc',
            ])
            ->assertRedirect("/admin/bookings/{$booking->id}?tab=payments")
            ->assertSessionHasErrors('void_reason');
    }

    public function test_invalid_charge_type_is_rejected(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->from("/admin/bookings/{$booking->id}?tab=payments")
            ->post("/admin/bookings/{$booking->id}/folio/entries", [
                'charge_type' => 'INVALID_TYPE',
                'description' => 'Test',
                'quantity' => 1,
                'unit_price' => 100000,
                'entry_date' => now()->toDateString(),
            ])
            ->assertRedirect("/admin/bookings/{$booking->id}?tab=payments")
            ->assertSessionHasErrors('charge_type');
    }

    public function test_cannot_add_charge_to_closed_folio(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $folio = $booking->fresh()->folio;

        app(FolioService::class)->closeFolio($folio, $this->admin);

        $this->post("/admin/bookings/{$booking->id}/folio/entries", [
            'charge_type' => ChargeType::Other->value,
            'description' => 'Test charge',
            'quantity' => 1,
            'unit_price' => 50000,
            'entry_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_manager_can_close_open_folio(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');
        $this->actingAs($manager);

        $booking = $this->createBooking();
        $folio = $booking->fresh()->folio;

        $this->patch("/admin/bookings/{$booking->id}/folio/close")
            ->assertRedirect();

        $this->assertSame(FolioStatus::Closed, $folio->fresh()->status);
    }

    public function test_admin_can_reopen_closed_folio(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $folio = $booking->fresh()->folio;

        app(FolioService::class)->closeFolio($folio, $this->admin);

        $this->patch("/admin/bookings/{$booking->id}/folio/reopen")
            ->assertRedirect();

        $this->assertSame(FolioStatus::Open, $folio->fresh()->status);
    }

    public function test_manager_cannot_reopen_closed_folio(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');
        $this->actingAs($manager);

        $booking = $this->createBooking();
        $folio = $booking->fresh()->folio;
        app(FolioService::class)->closeFolio($folio, $this->admin);

        $this->patch("/admin/bookings/{$booking->id}/folio/reopen")
            ->assertForbidden();

        $this->assertSame(FolioStatus::Closed, $folio->fresh()->status);
    }

    public function test_adding_charge_creates_audit_log(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->post("/admin/bookings/{$booking->id}/folio/entries", [
            'charge_type' => ChargeType::Minibar->value,
            'description' => 'Beer',
            'quantity' => 1,
            'unit_price' => 30000,
            'entry_date' => now()->toDateString(),
        ])->assertRedirect();

        $entry = $booking->fresh()->folio->folioEntries->first();

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => FolioEntry::class,
            'entity_id' => $entry->id,
            'action' => AuditAction::Created->value,
        ]);
    }

    public function test_voiding_entry_creates_audit_log(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $folio = $booking->fresh()->folio;

        $entry = FolioEntry::factory()->create(['folio_id' => $folio->id]);

        $this->patch("/admin/bookings/{$booking->id}/folio/entries/{$entry->id}", [
            'void_reason' => 'Ordered by mistake',
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => FolioEntry::class,
            'entity_id' => $entry->id,
            'action' => AuditAction::Updated->value,
        ]);
    }

    public function test_booking_controller_exposes_folio_in_show_response(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('booking.folio')
                ->where('booking.folio.status', FolioStatus::Open->value)
                ->has('booking.folio.folio_number')
                ->has('booking.folio.entries')
            );
    }

    public function test_balance_due_equals_total_charges_minus_paid_total(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        // ADR-43: post room charge so calculateGuardedFolioTotal uses raw sum (not estimate + non-room).
        app(FolioService::class)->autoPostRoomCharge($booking, $this->admin);

        $booking->bookingPayments()->create([
            'payment_type' => 'DEPOSIT',
            'amount' => 300000,
            'payment_method' => 'CASH',
            'payment_at' => now(),
            'confirmed_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.payment_summary.total_charges', 800000)
                ->where('booking.payment_summary.paid_total', 300000)
                ->where('booking.payment_summary.balance_due', 500000)
            );
    }

    public function test_idor_guard_prevents_voiding_entry_from_different_booking(): void
    {
        $this->actingAs($this->admin);

        $booking1 = $this->createBooking(['customer_name' => 'Booking One']);
        $booking2 = $this->createBooking(['customer_name' => 'Booking Two']);

        $folio1 = $booking1->fresh()->folio;
        $entry = FolioEntry::factory()->create(['folio_id' => $folio1->id]);

        $this->patch("/admin/bookings/{$booking2->id}/folio/entries/{$entry->id}", [
            'void_reason' => 'Trying to void other booking entry',
        ])->assertForbidden();

        $this->assertNull($entry->fresh()->voided_at);
    }

    public function test_reception_cannot_add_charge_without_permission(): void
    {
        $reception = User::factory()->create();
        $reception->assignRole('RECEPTION');
        $this->actingAs($reception);

        $booking = $this->createBooking();

        // RECEPTION has charge.create so this should succeed
        $this->post("/admin/bookings/{$booking->id}/folio/entries", [
            'charge_type' => ChargeType::Other->value,
            'description' => 'Extra towels',
            'quantity' => 1,
            'unit_price' => 20000,
            'entry_date' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('folio_entries', [
            'folio_id' => $booking->fresh()->folio->id,
            'amount' => 20000,
        ]);
    }

    public function test_housekeeping_cannot_add_charge(): void
    {
        $housekeeping = User::factory()->create();
        $housekeeping->assignRole('HOUSEKEEPING');
        $this->actingAs($housekeeping);

        $booking = $this->createBooking();

        $this->post("/admin/bookings/{$booking->id}/folio/entries", [
            'charge_type' => ChargeType::Other->value,
            'description' => 'Test',
            'quantity' => 1,
            'unit_price' => 20000,
            'entry_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    // ─── Phase 3.1A: new tests ───────────────────────────────────────────────

    public function test_folio_has_currency_code_vnd(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->assertDatabaseHas('folios', [
            'booking_id'    => $booking->id,
            'currency_code' => 'VND',
        ]);
    }

    public function test_amount_is_prohibited_in_store_entry_request(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->from("/admin/bookings/{$booking->id}?tab=payments")
            ->post("/admin/bookings/{$booking->id}/folio/entries", [
                'charge_type' => ChargeType::Other->value,
                'description' => 'Test',
                'quantity'    => 1,
                'unit_price'  => 50000,
                'amount'      => 50000,
                'entry_date'  => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('amount');
    }

    public function test_posting_key_is_prohibited_in_store_entry_request(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->from("/admin/bookings/{$booking->id}?tab=payments")
            ->post("/admin/bookings/{$booking->id}/folio/entries", [
                'posting_key' => 'MANUAL_KEY',
                'charge_type' => ChargeType::Other->value,
                'description' => 'Test',
                'quantity'    => 1,
                'unit_price'  => 50000,
                'entry_date'  => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('posting_key');
    }

    public function test_amount_is_computed_server_side_from_quantity_and_unit_price(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->post("/admin/bookings/{$booking->id}/folio/entries", [
            'charge_type' => ChargeType::Other->value,
            'description' => 'Laundry',
            'quantity'    => 3,
            'unit_price'  => 25000,
            'entry_date'  => now()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('folio_entries', [
            'folio_id' => $booking->fresh()->folio->id,
            'amount'   => 75000,
        ]);
    }

    public function test_new_entry_has_null_posting_key(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->post("/admin/bookings/{$booking->id}/folio/entries", [
            'charge_type' => ChargeType::Other->value,
            'description' => 'Breakfast',
            'quantity'    => 1,
            'unit_price'  => 45000,
            'entry_date'  => now()->toDateString(),
        ])->assertRedirect();

        $entry = $booking->fresh()->folio->folioEntries->first();
        $this->assertNull($entry->posting_key);
    }

    public function test_calculate_guarded_folio_total_returns_requirements_when_no_system_charge(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking(); // requirement: 1 × 800000

        $service = app(FolioService::class);
        $total   = $service->calculateGuardedFolioTotal($booking->fresh());

        // No system room charge posted → falls back to requirements estimate
        $this->assertEquals(800000.0, $total);
    }

    public function test_calculate_guarded_folio_total_uses_raw_sum_when_system_charge_posted(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $service = app(FolioService::class);
        $service->autoPostRoomCharge($booking, $this->admin);

        // Add a non-room charge
        FolioEntry::factory()->create([
            'folio_id' => $booking->fresh()->folio->id,
            'amount'   => 50000,
            'voided_at' => null,
        ]);

        $total = $service->calculateGuardedFolioTotal($booking->fresh());

        // System charge posted → raw sum: 800000 + 50000
        $this->assertEquals(850000.0, $total);
    }

    public function test_calculate_guarded_folio_total_returns_zero_for_voided_folio(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $folio = $booking->fresh()->folio;
        $folio->update(['status' => FolioStatus::Voided]);

        $total = app(FolioService::class)->calculateGuardedFolioTotal($booking->fresh());

        $this->assertEquals(0.0, $total);
    }

    public function test_auto_post_room_charge_creates_entry_with_posting_key(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $service = app(FolioService::class);
        $entry   = $service->autoPostRoomCharge($booking, $this->admin);

        $this->assertNotNull($entry);
        $this->assertSame("ROOM_CHARGE_{$booking->id}_AGGREGATE", $entry->posting_key);
        $this->assertEquals('800000.00', $entry->amount);
    }

    public function test_auto_post_room_charge_is_idempotent(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $service = app(FolioService::class);
        $service->autoPostRoomCharge($booking, $this->admin);
        $service->autoPostRoomCharge($booking, $this->admin); // second call is a no-op

        $count = FolioEntry::where('folio_id', $booking->fresh()->folio->id)
            ->where('posting_key', "ROOM_CHARGE_{$booking->id}_AGGREGATE")
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_auto_close_folio_is_idempotent_on_already_closed(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $folio   = $booking->fresh()->folio;

        $service = app(FolioService::class);
        $service->closeFolio($folio, $this->admin);

        // Calling autoCloseFolio on an already-closed folio must not throw
        $service->autoCloseFolio($folio->fresh(), $this->admin);

        $this->assertSame(FolioStatus::Closed, $folio->fresh()->status);
    }

    public function test_void_folio_on_cancellation_with_no_entries_voids_folio(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $folio   = $booking->fresh()->folio;

        app(FolioService::class)->voidFolioOnCancellation($folio);

        $this->assertSame(FolioStatus::Voided, $folio->fresh()->status);
    }

    public function test_void_folio_on_cancellation_with_active_entries_leaves_folio_open(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $folio   = $booking->fresh()->folio;

        FolioEntry::factory()->create(['folio_id' => $folio->id, 'voided_at' => null]);

        $this->expectException(\App\Exceptions\FolioHasActiveEntriesException::class);

        app(FolioService::class)->voidFolioOnCancellation($folio);

        $this->assertSame(FolioStatus::Open, $folio->fresh()->status);
    }

    public function test_cancelled_booking_with_no_entries_voids_folio(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        app(BookingService::class)->cancelBooking($booking, 'Guest changed plans');

        $folio = $booking->fresh()->folio;
        $this->assertSame(FolioStatus::Voided, $folio->status);
    }

    public function test_cancelled_booking_with_active_entries_blocks_cancellation(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $folio   = $booking->fresh()->folio;

        FolioEntry::factory()->create(['folio_id' => $folio->id, 'voided_at' => null]);

        $this->expectException(\App\Exceptions\FolioHasActiveEntriesException::class);

        app(BookingService::class)->cancelBooking($booking, 'Test');

        // Booking must remain in original status (transaction rolled back)
        $this->assertNotSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_update_requirement_is_locked_after_room_charge_posted(): void
    {
        $this->actingAs($this->admin);
        $booking     = $this->createBooking();
        $requirement = $booking->bookingRequirements->first();

        // Post the aggregate room charge
        app(FolioService::class)->autoPostRoomCharge($booking, $this->admin);

        $this->expectException(\App\Exceptions\RequirementLockedAfterRoomChargeException::class);

        app(BookingService::class)->updateRequirement($requirement, ['room_price' => 900000]);
    }

    public function test_folio_number_sequence_is_atomic_and_unique(): void
    {
        $this->actingAs($this->admin);

        $booking1 = $this->createBooking(['customer_name' => 'Guest A']);
        $booking2 = $this->createBooking(['customer_name' => 'Guest B']);

        $num1 = $booking1->fresh()->folio->folio_number;
        $num2 = $booking2->fresh()->folio->folio_number;

        $this->assertNotSame($num1, $num2);
        $this->assertMatchesRegularExpression('/^FLO-\d{8}-\d{6}$/', $num1);
        $this->assertMatchesRegularExpression('/^FLO-\d{8}-\d{6}$/', $num2);
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
                ['room_type_id' => $twin->id, 'quantity' => 1, 'adults' => 2, 'children_under_6' => 0, 'children_over_6' => 0, 'room_price' => 800000, 'price_source' => 'MANUAL'],
            ];
        }

        return app(BookingService::class)->createBooking($payload);
    }
}
