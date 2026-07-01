<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\ChargeType;
use App\Enums\CustomerType;
use App\Models\Booking;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3.1B2 — Folio UI: entry visibility, system entry guard, folio open/close/reopen.
 */
class FolioUiTest extends TestCase
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

    // ── ADR-50: System entry void guard ─────────────────────────────────────

    public function test_system_entry_with_posting_key_cannot_be_voided(): void
    {
        $booking = $this->createBooking();
        $folio   = $booking->fresh()->folio;

        $systemEntry = FolioEntry::factory()->create([
            'folio_id'    => $folio->id,
            'posting_key' => 'ROOM_CHARGE_123_AGGREGATE',
        ]);

        $this->patch("/admin/bookings/{$booking->id}/folio/entries/{$systemEntry->id}", [
            'void_reason' => 'Trying to void system entry',
        ])->assertSessionHas('error');

        $this->assertDatabaseHas('folio_entries', [
            'id'        => $systemEntry->id,
            'voided_at' => null,
        ]);
    }

    public function test_system_entry_void_sets_flash_error_not_validation_error(): void
    {
        $booking = $this->createBooking();
        $folio   = $booking->fresh()->folio;

        $systemEntry = FolioEntry::factory()->create([
            'folio_id'    => $folio->id,
            'posting_key' => 'ROOM_CHARGE_999_AGGREGATE',
        ]);

        $response = $this->patch("/admin/bookings/{$booking->id}/folio/entries/{$systemEntry->id}", [
            'void_reason' => 'Trying to void system entry',
        ]);

        $response->assertSessionHas('error');
        $response->assertSessionMissing('errors');
    }

    public function test_non_system_entry_can_be_voided_normally(): void
    {
        $booking = $this->createBooking();
        $folio   = $booking->fresh()->folio;

        $entry = FolioEntry::factory()->create([
            'folio_id'    => $folio->id,
            'posting_key' => null,
        ]);

        $this->patch("/admin/bookings/{$booking->id}/folio/entries/{$entry->id}", [
            'void_reason' => 'Customer request to remove charge',
        ])->assertRedirect();

        $this->assertNotNull(FolioEntry::find($entry->id)->voided_at);
    }

    // ── Folio open/close/reopen ──────────────────────────────────────────────

    public function test_admin_can_close_open_folio(): void
    {
        $booking = $this->createBooking();
        $folio   = $booking->fresh()->folio;

        $this->patch("/admin/bookings/{$booking->id}/folio/close")
            ->assertRedirect();

        $this->assertSame('CLOSED', Folio::find($folio->id)->status->value);
    }

    public function test_admin_can_reopen_closed_folio(): void
    {
        $booking = $this->createBooking();
        $folio   = $booking->fresh()->folio;

        // Close first
        $this->patch("/admin/bookings/{$booking->id}/folio/close")->assertRedirect();

        // Then reopen
        $this->patch("/admin/bookings/{$booking->id}/folio/reopen")->assertRedirect();

        $this->assertSame('OPEN', Folio::find($folio->id)->status->value);
    }

    public function test_charge_cannot_be_added_to_closed_folio(): void
    {
        $booking = $this->createBooking();

        // Close the folio
        $this->patch("/admin/bookings/{$booking->id}/folio/close");

        // FolioEntryPolicy::create returns false for closed folio → 403
        $this->post("/admin/bookings/{$booking->id}/folio/entries", [
            'charge_type' => ChargeType::Other->value,
            'description' => 'Test charge after close',
            'quantity'    => 1,
            'unit_price'  => 50000,
            'entry_date'  => now()->toDateString(),
        ])->assertStatus(403);
    }

    // ── is_system_entry payload ──────────────────────────────────────────────

    public function test_booking_show_payload_includes_is_system_entry_for_entries(): void
    {
        $booking = $this->createBooking();
        $folio   = $booking->fresh()->folio;

        FolioEntry::factory()->create([
            'folio_id'    => $folio->id,
            'posting_key' => 'ROOM_CHARGE_1_AGGREGATE',
        ]);

        FolioEntry::factory()->create([
            'folio_id'    => $folio->id,
            'posting_key' => null,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertInertia(fn ($page) => $page
                ->has('booking.folio.entries', 2)
                ->where('booking.folio.entries.0.is_system_entry', true)
                ->where('booking.folio.entries.1.is_system_entry', false)
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
