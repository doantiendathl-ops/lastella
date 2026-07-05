<?php

namespace Tests\Unit\Services;

use App\Enums\BookingStatus;
use App\Enums\RequestCategory;
use App\Enums\RequestStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingSpecialRequest;
use App\Models\Stay;
use App\Models\User;
use App\Services\SpecialRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SpecialRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private SpecialRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SpecialRequestService();
    }

    // -------------------------------------------------------------------------
    // addRequest
    // -------------------------------------------------------------------------

    public function test_add_request_creates_pending_request(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::PendingAssignment]);
        $actor   = User::factory()->create();

        $request = $this->service->addRequest(
            booking:     $booking,
            category:    RequestCategory::BedConfig,
            requestType: 'twin_beds',
            quantity:    1,
            note:        'Please prepare two single beds',
            requestedBy: $actor->id,
        );

        $this->assertDatabaseHas('booking_special_requests', [
            'id'           => $request->id,
            'booking_id'   => $booking->id,
            'category'     => 'bed_config',
            'request_type' => 'twin_beds',
            'status'       => 'pending',
            'requested_by' => $actor->id,
            'stay_id'      => null,
        ]);
        $this->assertEquals(RequestStatus::Pending, $request->status);
    }

    public function test_add_request_rejects_checked_out_booking(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::CheckedOut]);
        $actor   = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->addRequest($booking, RequestCategory::General, 'general', 1, null, $actor->id);
    }

    public function test_add_request_rejects_cancelled_booking(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Cancelled]);
        $actor   = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->addRequest($booking, RequestCategory::General, 'general', 1, null, $actor->id);
    }

    public function test_add_request_rejects_no_show_booking(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::NoShow]);
        $actor   = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->addRequest($booking, RequestCategory::General, 'general', 1, null, $actor->id);
    }

    // -------------------------------------------------------------------------
    // linkToStay
    // -------------------------------------------------------------------------

    public function test_link_to_stay_idempotent_same_stay(): void
    {
        $stay    = Stay::factory()->create();
        $request = BookingSpecialRequest::factory()->create([
            'booking_id' => $stay->booking_id,
            'stay_id'    => $stay->id,
        ]);

        $result = $this->service->linkToStay($request, $stay);

        $this->assertEquals($stay->id, $result->stay_id);
    }

    public function test_link_to_stay_rejects_stay_from_different_booking(): void
    {
        $request    = BookingSpecialRequest::factory()->create();
        $otherStay  = Stay::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->linkToStay($request, $otherStay);
    }

    // -------------------------------------------------------------------------
    // acknowledge
    // -------------------------------------------------------------------------

    public function test_acknowledge_pending_request(): void
    {
        $request = BookingSpecialRequest::factory()->pending()->create();
        $actor   = User::factory()->create();

        $result = $this->service->acknowledge($request, $actor->id);

        $this->assertEquals(RequestStatus::Acknowledged, $result->status);
        $this->assertEquals($actor->id, $result->acknowledged_by);
        $this->assertNotNull($result->acknowledged_at);
    }

    public function test_acknowledge_throws_if_not_pending(): void
    {
        $request = BookingSpecialRequest::factory()->acknowledged()->create();
        $actor   = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->acknowledge($request, $actor->id);
    }

    // -------------------------------------------------------------------------
    // fulfill
    // -------------------------------------------------------------------------

    public function test_fulfill_acknowledged_request(): void
    {
        $request = BookingSpecialRequest::factory()->acknowledged()->create();
        $actor   = User::factory()->create();

        $result = $this->service->fulfill($request, $actor->id);

        $this->assertEquals(RequestStatus::Fulfilled, $result->status);
        $this->assertEquals($actor->id, $result->fulfilled_by);
        $this->assertNotNull($result->fulfilled_at);
    }

    public function test_fulfill_already_fulfilled_is_idempotent(): void
    {
        $actor   = User::factory()->create();
        $request = BookingSpecialRequest::factory()->fulfilled()->create();

        $result = $this->service->fulfill($request, $actor->id);

        $this->assertEquals(RequestStatus::Fulfilled, $result->status);
    }

    public function test_fulfill_throws_if_cancelled(): void
    {
        $request = BookingSpecialRequest::factory()->cancelled()->create();
        $actor   = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->fulfill($request, $actor->id);
    }

    public function test_fulfill_throws_if_still_pending(): void
    {
        $request = BookingSpecialRequest::factory()->pending()->create();
        $actor   = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->fulfill($request, $actor->id);
    }

    // -------------------------------------------------------------------------
    // cancel
    // -------------------------------------------------------------------------

    public function test_cancel_pending_request(): void
    {
        $request = BookingSpecialRequest::factory()->pending()->create();
        $actor   = User::factory()->create();

        $result = $this->service->cancel($request, $actor->id);

        $this->assertEquals(RequestStatus::Cancelled, $result->status);
        $this->assertEquals($actor->id, $result->cancelled_by);
        $this->assertNotNull($result->cancelled_at);
    }

    public function test_cancel_acknowledged_request(): void
    {
        $request = BookingSpecialRequest::factory()->acknowledged()->create();
        $actor   = User::factory()->create();

        $result = $this->service->cancel($request, $actor->id);

        $this->assertEquals(RequestStatus::Cancelled, $result->status);
    }

    public function test_cannot_cancel_fulfilled_request(): void
    {
        $request = BookingSpecialRequest::factory()->fulfilled()->create();
        $actor   = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->cancel($request, $actor->id);
    }

    public function test_cannot_cancel_already_cancelled_request(): void
    {
        $request = BookingSpecialRequest::factory()->cancelled()->create();
        $actor   = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->cancel($request, $actor->id);
    }

    // -------------------------------------------------------------------------
    // autoCancelForBooking
    // -------------------------------------------------------------------------

    public function test_auto_cancel_for_booking_cancels_pending_and_acknowledged(): void
    {
        $booking = Booking::factory()->create();
        $actor   = User::factory()->create();

        BookingSpecialRequest::factory()->pending()->create(['booking_id' => $booking->id, 'requested_by' => $actor->id]);
        BookingSpecialRequest::factory()->acknowledged()->create(['booking_id' => $booking->id, 'requested_by' => $actor->id]);

        $count = $this->service->autoCancelForBooking($booking, $actor->id);

        $this->assertEquals(2, $count);
        $this->assertDatabaseMissing('booking_special_requests', [
            'booking_id' => $booking->id,
            'status'     => 'pending',
        ]);
        $this->assertDatabaseMissing('booking_special_requests', [
            'booking_id' => $booking->id,
            'status'     => 'acknowledged',
        ]);
    }

    public function test_auto_cancel_for_booking_does_not_touch_fulfilled_or_cancelled(): void
    {
        $booking = Booking::factory()->create();
        $actor   = User::factory()->create();

        $fulfilled = BookingSpecialRequest::factory()->fulfilled()->create(['booking_id' => $booking->id, 'requested_by' => $actor->id]);
        $cancelled = BookingSpecialRequest::factory()->cancelled()->create(['booking_id' => $booking->id, 'requested_by' => $actor->id]);

        $count = $this->service->autoCancelForBooking($booking, $actor->id);

        $this->assertEquals(0, $count);
        $this->assertDatabaseHas('booking_special_requests', ['id' => $fulfilled->id, 'status' => 'fulfilled']);
        $this->assertDatabaseHas('booking_special_requests', ['id' => $cancelled->id, 'status' => 'cancelled']);
    }

    // -------------------------------------------------------------------------
    // autoLinkSingleStayRequests
    // -------------------------------------------------------------------------

    public function test_auto_link_single_stay_requests_does_not_throw(): void
    {
        $booking = Booking::factory()->create();
        $stay    = Stay::factory()->create(['booking_id' => $booking->id]);

        $this->service->autoLinkSingleStayRequests($booking, $stay);

        $this->assertTrue(true);
    }

    public function test_auto_link_links_unlinked_requests_when_single_active_stay(): void
    {
        $booking = Booking::factory()->create();
        $actor   = User::factory()->create();
        $stay    = Stay::factory()->create([
            'booking_id' => $booking->id,
            'status'     => StayStatus::Reserved,
        ]);

        $request = BookingSpecialRequest::factory()->pending()->create([
            'booking_id'   => $booking->id,
            'stay_id'      => null,
            'requested_by' => $actor->id,
        ]);

        $this->service->autoLinkSingleStayRequests($booking, $stay);

        $this->assertDatabaseHas('booking_special_requests', [
            'id'      => $request->id,
            'stay_id' => $stay->id,
        ]);
    }

    public function test_auto_link_does_not_link_when_multiple_active_stays(): void
    {
        $booking = Booking::factory()->create();
        $actor   = User::factory()->create();

        $stay1 = Stay::factory()->create(['booking_id' => $booking->id, 'status' => StayStatus::Reserved]);
        $stay2 = Stay::factory()->create(['booking_id' => $booking->id, 'status' => StayStatus::Reserved]);

        $request = BookingSpecialRequest::factory()->pending()->create([
            'booking_id'   => $booking->id,
            'stay_id'      => null,
            'requested_by' => $actor->id,
        ]);

        $this->service->autoLinkSingleStayRequests($booking, $stay1);

        $this->assertDatabaseHas('booking_special_requests', [
            'id'      => $request->id,
            'stay_id' => null,
        ]);
    }
}
