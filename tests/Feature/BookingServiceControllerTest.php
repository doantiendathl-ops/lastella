<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FolioStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Folio;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Stay;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 12/26) — the
 * staff-facing HTTP flow: enroll, confirm/complete/cancel, permission
 * gating, and price-override-reason enforcement at the HTTP layer.
 */
class BookingServiceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $sales;
    private Stay $stay;
    private Booking $booking;
    private Service $roomService;
    private Service $bookingOneTimeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = tap(User::factory()->create())->assignRole('ADMIN');
        $this->sales = tap(User::factory()->create())->assignRole('SALES');

        $this->stay = Stay::factory()->create(['status' => StayStatus::CheckedIn]);
        $this->booking = Booking::find($this->stay->booking_id);
        Folio::factory()->for($this->booking)->create(['status' => FolioStatus::Open]);

        $category = ServiceCategory::create(['code' => 'CAT_CTRL', 'name' => 'Test', 'is_active' => true]);

        $this->roomService = Service::create([
            'category_id' => $category->id,
            'code' => 'ROOM_SVC_CTRL',
            'name' => 'Giường phụ',
            'is_chargeable' => true,
            'scope' => 'ROOM',
            'billing_mode' => 'PER_NIGHT',
            'quantity_enabled' => true,
            'default_quantity' => 1,
            'unit_label' => 'giường',
            'fulfillment_required' => true,
            'is_active' => true,
            'is_bookable' => true,
        ]);
        $this->roomService->prices()->create(['unit_price' => 150000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $this->bookingOneTimeService = Service::create([
            'category_id' => $category->id,
            'code' => 'BOOKING_SVC_CTRL',
            'name' => 'Đưa đón sân bay',
            'is_chargeable' => true,
            'scope' => 'BOOKING',
            'billing_mode' => 'ONE_TIME',
            'quantity_enabled' => false,
            'default_quantity' => 1,
            'unit_label' => 'lần',
            'fulfillment_required' => false,
            'is_active' => true,
            'is_bookable' => true,
        ]);
        $this->bookingOneTimeService->prices()->create(['unit_price' => 300000, 'effective_from' => '2026-01-01', 'is_active' => true]);
    }

    public function test_can_view_the_services_screen(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.bookings.services.show', $this->booking))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Booking/Services')
                ->has('available_services')
                ->has('rooms')
                ->has('booking_services')
            );
    }

    public function test_can_enroll_a_room_scoped_per_night_service(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.services.store', $this->booking), [
                'service_id' => $this->roomService->id,
                'room_assignment_id' => $this->stay->room_assignment_id,
                'quantity' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('booking_services', [
            'booking_id' => $this->booking->id,
            'service_id' => $this->roomService->id,
            'room_assignment_id' => $this->stay->room_assignment_id,
            'fulfillment_status' => 'CREATED',
        ]);
        // PER_NIGHT must NOT post immediately — only via Night Audit.
        $this->assertDatabaseCount('folio_entries', 0);
    }

    public function test_room_scoped_service_without_a_room_fails_validation(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.services.store', $this->booking), [
                'service_id' => $this->roomService->id,
            ])
            ->assertSessionHasErrors('room_assignment_id');

        $this->assertDatabaseCount('booking_services', 0);
    }

    public function test_one_time_service_posts_a_folio_entry_immediately_on_enroll(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.services.store', $this->booking), [
                'service_id' => $this->bookingOneTimeService->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('folio_entries', [
            'folio_id' => $this->booking->folio->id,
            'amount' => 300000,
        ]);
    }

    public function test_overridden_price_without_a_reason_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.services.store', $this->booking), [
                'service_id' => $this->bookingOneTimeService->id,
                'actual_price' => 250000,
            ])
            ->assertSessionHasErrors('price_override_reason');

        $this->assertDatabaseCount('booking_services', 0);
    }

    public function test_overridden_price_with_a_reason_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.services.store', $this->booking), [
                'service_id' => $this->bookingOneTimeService->id,
                'actual_price' => 250000,
                'price_override_reason' => 'Khách VIP',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('booking_services', ['actual_price' => 250000, 'price_override_reason' => 'Khách VIP']);
    }

    public function test_full_fulfillment_lifecycle_via_http(): void
    {
        $bs = app(\App\Services\BookingServiceEnrollmentService::class)->enroll(
            $this->booking, $this->roomService, $this->stay->roomAssignment, 1, null, null, null, $this->admin,
        );

        $this->actingAs($this->admin)
            ->patch(route('admin.bookings.services.confirm', [$this->booking, $bs]))
            ->assertRedirect();
        $this->assertSame('CONFIRMED', $bs->fresh()->fulfillment_status->value);

        $this->actingAs($this->admin)
            ->patch(route('admin.bookings.services.complete', [$this->booking, $bs]))
            ->assertRedirect();
        $this->assertSame('COMPLETED', $bs->fresh()->fulfillment_status->value);
    }

    /**
     * User request (2026-08-20 chat) — "cho phép hủy kể cả sau khi đã hoàn
     * thành". Was test_cannot_cancel_after_completed (asserted the opposite,
     * pre-2026-08-20 behavior) — updated in place since the old behavior is
     * exactly what was asked to change, not a separate case to keep.
     */
    public function test_can_cancel_after_completed(): void
    {
        $bs = app(\App\Services\BookingServiceEnrollmentService::class)->enroll(
            $this->booking, $this->roomService, $this->stay->roomAssignment, 1, null, null, null, $this->admin,
        );
        app(\App\Services\BookingServiceEnrollmentService::class)->complete($bs, $this->admin);

        $this->actingAs($this->admin)
            ->patch(route('admin.bookings.services.cancel', [$this->booking, $bs]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('CANCELLED', $bs->fresh()->fulfillment_status->value);
    }

    /** Cancelled stays terminal — cannot cancel an already-cancelled row. */
    public function test_cannot_cancel_already_cancelled(): void
    {
        $bs = app(\App\Services\BookingServiceEnrollmentService::class)->enroll(
            $this->booking, $this->roomService, $this->stay->roomAssignment, 1, null, null, null, $this->admin,
        );
        app(\App\Services\BookingServiceEnrollmentService::class)->cancel($bs, $this->admin);

        $this->actingAs($this->admin)
            ->patch(route('admin.bookings.services.cancel', [$this->booking, $bs]))
            ->assertSessionHasErrors('fulfillment_status');

        $this->assertSame('CANCELLED', $bs->fresh()->fulfillment_status->value);
    }

    public function test_sales_role_cannot_manage_booking_services(): void
    {
        $this->actingAs($this->sales)
            ->get(route('admin.bookings.services.show', $this->booking))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.bookings.services.show', $this->booking))->assertRedirect('/login');
    }
}
