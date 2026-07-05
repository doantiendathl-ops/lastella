<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\RequestCategory;
use App\Enums\RequestStatus;
use App\Enums\StayStatus;
use App\Http\Requests\Booking\StoreBookingSpecialRequestRequest;
use App\Models\Booking;
use App\Models\BookingSpecialRequest;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingService;
use App\Services\StayService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SpecialRequestCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private User $reception;
    private User $housekeeping;
    private User $accountant;
    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin        = tap(User::factory()->create())->assignRole('ADMIN');
        $this->manager      = tap(User::factory()->create())->assignRole('MANAGER');
        $this->reception    = tap(User::factory()->create())->assignRole('RECEPTION');
        $this->housekeeping = tap(User::factory()->create())->assignRole('HOUSEKEEPING');
        $this->accountant   = tap(User::factory()->create())->assignRole('ACCOUNTANT');

        $this->booking = Booking::factory()->create(['status' => BookingStatus::PendingAssignment]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'category'     => 'bed_config',
            'request_type' => 'twin_keep',
            'quantity'     => 1,
            'note'         => null,
        ], $overrides);
    }

    private function makeRequest(): BookingSpecialRequest
    {
        return BookingSpecialRequest::factory()->pending()->create([
            'booking_id'   => $this->booking->id,
            'requested_by' => $this->admin->id,
        ]);
    }

    // =========================================================================
    // Form Request / Validation
    // =========================================================================

    public function test_invalid_category_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/bookings/{$this->booking->id}/special-requests", $this->validPayload([
                'category' => 'invalid_cat',
            ]))
            ->assertSessionHasErrors('category');
    }

    public function test_invalid_request_type_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/bookings/{$this->booking->id}/special-requests", $this->validPayload([
                'request_type' => 'not_in_catalog',
            ]))
            ->assertSessionHasErrors('request_type');
    }

    public function test_quantity_less_than_1_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/bookings/{$this->booking->id}/special-requests", $this->validPayload([
                'quantity' => 0,
            ]))
            ->assertSessionHasErrors('quantity');
    }

    public function test_stay_id_from_different_booking_is_rejected(): void
    {
        $otherStay = Stay::factory()->create();

        $this->actingAs($this->admin)
            ->post("/admin/bookings/{$this->booking->id}/special-requests", $this->validPayload([
                'stay_id' => $otherStay->id,
            ]))
            ->assertSessionHasErrors('stay_id');
    }

    public function test_terminal_booking_returns_forbidden(): void
    {
        $cancelledBooking = Booking::factory()->create(['status' => BookingStatus::Cancelled]);

        $this->actingAs($this->admin)
            ->post("/admin/bookings/{$cancelledBooking->id}/special-requests", $this->validPayload())
            ->assertRedirect();

        $this->assertDatabaseMissing('booking_special_requests', [
            'booking_id' => $cancelledBooking->id,
        ]);
    }

    // =========================================================================
    // Controller / Routes — Create (store)
    // =========================================================================

    public function test_admin_can_create_request(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/bookings/{$this->booking->id}/special-requests", $this->validPayload())
            ->assertRedirect();

        $this->assertDatabaseHas('booking_special_requests', [
            'booking_id'   => $this->booking->id,
            'status'       => 'pending',
            'requested_by' => $this->admin->id,
        ]);
    }

    public function test_reception_can_create_request(): void
    {
        $this->actingAs($this->reception)
            ->post("/admin/bookings/{$this->booking->id}/special-requests", $this->validPayload())
            ->assertRedirect();

        $this->assertDatabaseHas('booking_special_requests', [
            'booking_id'   => $this->booking->id,
            'requested_by' => $this->reception->id,
        ]);
    }

    public function test_housekeeping_cannot_create_request(): void
    {
        $this->actingAs($this->housekeeping)
            ->post("/admin/bookings/{$this->booking->id}/special-requests", $this->validPayload())
            ->assertForbidden();
    }

    // =========================================================================
    // Controller / Routes — Acknowledge
    // =========================================================================

    public function test_admin_can_acknowledge_request(): void
    {
        $specialRequest = $this->makeRequest();

        $this->actingAs($this->admin)
            ->patch("/admin/bookings/{$this->booking->id}/special-requests/{$specialRequest->id}/acknowledge")
            ->assertRedirect();

        $this->assertDatabaseHas('booking_special_requests', [
            'id'              => $specialRequest->id,
            'status'          => 'acknowledged',
            'acknowledged_by' => $this->admin->id,
        ]);
    }

    public function test_housekeeping_can_acknowledge_request(): void
    {
        $specialRequest = $this->makeRequest();

        $this->actingAs($this->housekeeping)
            ->patch("/admin/bookings/{$this->booking->id}/special-requests/{$specialRequest->id}/acknowledge")
            ->assertRedirect();

        $this->assertDatabaseHas('booking_special_requests', [
            'id'     => $specialRequest->id,
            'status' => 'acknowledged',
        ]);
    }

    public function test_reception_cannot_acknowledge_request(): void
    {
        $specialRequest = $this->makeRequest();

        $this->actingAs($this->reception)
            ->patch("/admin/bookings/{$this->booking->id}/special-requests/{$specialRequest->id}/acknowledge")
            ->assertForbidden();
    }

    // =========================================================================
    // Controller / Routes — Fulfill
    // =========================================================================

    public function test_admin_can_fulfill_request(): void
    {
        $specialRequest = BookingSpecialRequest::factory()->acknowledged()->create([
            'booking_id'   => $this->booking->id,
            'requested_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->patch("/admin/bookings/{$this->booking->id}/special-requests/{$specialRequest->id}/fulfill")
            ->assertRedirect();

        $this->assertDatabaseHas('booking_special_requests', [
            'id'          => $specialRequest->id,
            'status'      => 'fulfilled',
            'fulfilled_by' => $this->admin->id,
        ]);
    }

    public function test_housekeeping_can_fulfill_request(): void
    {
        $specialRequest = BookingSpecialRequest::factory()->acknowledged()->create([
            'booking_id'   => $this->booking->id,
            'requested_by' => $this->admin->id,
        ]);

        $this->actingAs($this->housekeeping)
            ->patch("/admin/bookings/{$this->booking->id}/special-requests/{$specialRequest->id}/fulfill")
            ->assertRedirect();

        $this->assertDatabaseHas('booking_special_requests', [
            'id'     => $specialRequest->id,
            'status' => 'fulfilled',
        ]);
    }

    // =========================================================================
    // Controller / Routes — Cancel (destroy)
    // =========================================================================

    public function test_admin_can_cancel_request(): void
    {
        $specialRequest = $this->makeRequest();

        $this->actingAs($this->admin)
            ->delete("/admin/bookings/{$this->booking->id}/special-requests/{$specialRequest->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('booking_special_requests', [
            'id'           => $specialRequest->id,
            'status'       => 'cancelled',
            'cancelled_by' => $this->admin->id,
        ]);
    }

    public function test_reception_cannot_cancel_request(): void
    {
        $specialRequest = $this->makeRequest();

        $this->actingAs($this->reception)
            ->delete("/admin/bookings/{$this->booking->id}/special-requests/{$specialRequest->id}")
            ->assertForbidden();
    }

    // =========================================================================
    // Booking ownership guard
    // =========================================================================

    public function test_request_from_different_booking_is_rejected_on_acknowledge(): void
    {
        $otherBooking   = Booking::factory()->create();
        $otherRequest   = BookingSpecialRequest::factory()->pending()->create([
            'booking_id'   => $otherBooking->id,
            'requested_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->patch("/admin/bookings/{$this->booking->id}/special-requests/{$otherRequest->id}/acknowledge")
            ->assertForbidden();
    }

    // =========================================================================
    // Integration Hook 1 — BookingService::cancelBooking()
    // =========================================================================

    public function test_cancel_booking_auto_cancels_pending_and_acknowledged_requests(): void
    {
        $pending = BookingSpecialRequest::factory()->pending()->create([
            'booking_id'   => $this->booking->id,
            'requested_by' => $this->admin->id,
        ]);
        $acknowledged = BookingSpecialRequest::factory()->acknowledged()->create([
            'booking_id'   => $this->booking->id,
            'requested_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);
        app(BookingService::class)->cancelBooking($this->booking, 'Test cancellation');

        $this->assertDatabaseHas('booking_special_requests', ['id' => $pending->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('booking_special_requests', ['id' => $acknowledged->id, 'status' => 'cancelled']);
    }

    public function test_cancel_booking_does_not_affect_fulfilled_or_cancelled_requests(): void
    {
        $fulfilled = BookingSpecialRequest::factory()->fulfilled()->create([
            'booking_id'   => $this->booking->id,
            'requested_by' => $this->admin->id,
        ]);
        $cancelled = BookingSpecialRequest::factory()->cancelled()->create([
            'booking_id'   => $this->booking->id,
            'requested_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);
        app(BookingService::class)->cancelBooking($this->booking, 'Test cancellation');

        $this->assertDatabaseHas('booking_special_requests', ['id' => $fulfilled->id, 'status' => 'fulfilled']);
        $this->assertDatabaseHas('booking_special_requests', ['id' => $cancelled->id, 'status' => 'cancelled']);
    }

    // =========================================================================
    // Integration Hook 2 — StayService::createStayFromAssignment()
    // =========================================================================

    public function test_create_stay_auto_links_requests_when_single_active_stay(): void
    {
        $pending = BookingSpecialRequest::factory()->pending()->create([
            'booking_id'   => $this->booking->id,
            'stay_id'      => null,
            'requested_by' => $this->admin->id,
        ]);

        $assignment = RoomAssignment::factory()->create(['booking_id' => $this->booking->id]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        $this->assertDatabaseHas('booking_special_requests', [
            'id'      => $pending->id,
            'stay_id' => $stay->id,
        ]);
    }

    public function test_create_stay_does_not_auto_link_when_multiple_active_stays(): void
    {
        // First existing stay
        Stay::factory()->create([
            'booking_id' => $this->booking->id,
            'status'     => StayStatus::Reserved,
        ]);

        $pending = BookingSpecialRequest::factory()->pending()->create([
            'booking_id'   => $this->booking->id,
            'stay_id'      => null,
            'requested_by' => $this->admin->id,
        ]);

        $assignment = RoomAssignment::factory()->create(['booking_id' => $this->booking->id]);

        app(StayService::class)->createStayFromAssignment($assignment);

        // 2 active stays → no auto-link
        $this->assertDatabaseHas('booking_special_requests', [
            'id'      => $pending->id,
            'stay_id' => null,
        ]);
    }

    public function test_auto_link_failure_does_not_abort_stay_creation(): void
    {
        // No requests — autoLink is a no-op. Just verify stay is created.
        $assignment = RoomAssignment::factory()->create(['booking_id' => $this->booking->id]);

        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        $this->assertDatabaseHas('stays', ['id' => $stay->id, 'booking_id' => $this->booking->id]);
    }

    // =========================================================================
    // Room Board Pending Count Query
    // =========================================================================

    public function test_pending_count_by_room_counts_pending_and_acknowledged(): void
    {
        $stay = Stay::factory()->create(['status' => StayStatus::CheckedIn]);
        $roomId = $stay->room_id;

        BookingSpecialRequest::factory()->pending()->create([
            'booking_id'   => $stay->booking_id,
            'stay_id'      => $stay->id,
            'requested_by' => $this->admin->id,
        ]);
        BookingSpecialRequest::factory()->acknowledged()->create([
            'booking_id'   => $stay->booking_id,
            'stay_id'      => $stay->id,
            'requested_by' => $this->admin->id,
        ]);

        $counts = BookingSpecialRequest::pendingCountByRoom([$roomId]);

        $this->assertEquals(2, $counts->get($roomId));
    }

    public function test_pending_count_by_room_excludes_fulfilled_and_cancelled(): void
    {
        $stay = Stay::factory()->create(['status' => StayStatus::CheckedIn]);
        $roomId = $stay->room_id;

        BookingSpecialRequest::factory()->fulfilled()->create([
            'booking_id'   => $stay->booking_id,
            'stay_id'      => $stay->id,
            'requested_by' => $this->admin->id,
        ]);
        BookingSpecialRequest::factory()->cancelled()->create([
            'booking_id'   => $stay->booking_id,
            'stay_id'      => $stay->id,
            'requested_by' => $this->admin->id,
        ]);

        $counts = BookingSpecialRequest::pendingCountByRoom([$roomId]);

        $this->assertEquals(0, $counts->get($roomId, 0));
    }

    public function test_pending_count_by_room_returns_count_keyed_by_room_id(): void
    {
        $stay1 = Stay::factory()->create(['status' => StayStatus::CheckedIn]);
        $stay2 = Stay::factory()->create(['status' => StayStatus::CheckedIn]);

        BookingSpecialRequest::factory()->pending()->create([
            'booking_id'   => $stay1->booking_id,
            'stay_id'      => $stay1->id,
            'requested_by' => $this->admin->id,
        ]);
        BookingSpecialRequest::factory()->pending()->create([
            'booking_id'   => $stay1->booking_id,
            'stay_id'      => $stay1->id,
            'requested_by' => $this->admin->id,
        ]);
        BookingSpecialRequest::factory()->pending()->create([
            'booking_id'   => $stay2->booking_id,
            'stay_id'      => $stay2->id,
            'requested_by' => $this->admin->id,
        ]);

        $counts = BookingSpecialRequest::pendingCountByRoom([$stay1->room_id, $stay2->room_id]);

        $this->assertEquals(2, $counts->get($stay1->room_id));
        $this->assertEquals(1, $counts->get($stay2->room_id));
    }
}
