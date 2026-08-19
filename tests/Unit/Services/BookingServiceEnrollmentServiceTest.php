<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceBillingMode;
use App\Enums\ServiceFulfillmentStatus;
use App\Enums\ServiceScope;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingServiceEnrollmentService;
use App\Services\BusinessDateService;
use App\Services\ServicePricingResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Sections 6/7/9/10/11) —
 * scope enforcement, price snapshot + override-reason requirement,
 * quantity_enabled gating, and the fulfillment state machine.
 */
class BookingServiceEnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $businessDate;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->businessDate = Carbon::parse('2026-08-16');
        $this->user = User::factory()->create();
    }

    private function enrollmentService(): BookingServiceEnrollmentService
    {
        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn($this->businessDate);

        return new BookingServiceEnrollmentService(new ServicePricingResolver(), $mock);
    }

    private function makeService(array $overrides = []): Service
    {
        $category = ServiceCategory::create(['code' => 'TEST_CAT_'.uniqid(), 'name' => 'Test Category', 'is_active' => true]);

        return Service::create(array_merge([
            'category_id' => $category->id,
            'code' => 'TEST_SVC_'.uniqid(),
            'name' => 'Test Service',
            'is_chargeable' => true,
            'scope' => ServiceScope::Booking->value,
            'billing_mode' => ServiceBillingMode::OneTime->value,
            'quantity_enabled' => false,
            'default_quantity' => 1,
            'unit_label' => 'lần',
            'fulfillment_required' => false,
            'is_active' => true,
            'is_bookable' => true,
        ], $overrides));
    }

    private function makeStayWithRoom(): Stay
    {
        return Stay::factory()->create(['status' => StayStatus::CheckedIn]);
    }

    // -------------------------------------------------------------------------
    // Scope
    // -------------------------------------------------------------------------

    public function test_room_scoped_service_requires_a_room_assignment(): void
    {
        $service = $this->makeService(['scope' => ServiceScope::Room->value]);
        $booking = Booking::factory()->create();
        $service->prices()->create(['unit_price' => 50000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $this->expectException(ValidationException::class);

        $this->enrollmentService()->enroll($booking, $service, null, 1, null, null, null, $this->user);
    }

    public function test_booking_scoped_service_rejects_a_room_assignment(): void
    {
        $stay = $this->makeStayWithRoom();
        $booking = Booking::find($stay->booking_id);
        $service = $this->makeService(['scope' => ServiceScope::Booking->value]);
        $service->prices()->create(['unit_price' => 50000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $this->expectException(ValidationException::class);

        $this->enrollmentService()->enroll($booking, $service, $stay->roomAssignment, 1, null, null, null, $this->user);
    }

    public function test_room_scoped_service_rejects_a_room_assignment_from_a_different_booking(): void
    {
        $stayA = $this->makeStayWithRoom();
        $stayB = $this->makeStayWithRoom();
        $bookingA = Booking::find($stayA->booking_id);
        $service = $this->makeService(['scope' => ServiceScope::Room->value]);
        $service->prices()->create(['unit_price' => 50000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $this->expectException(ValidationException::class);

        $this->enrollmentService()->enroll($bookingA, $service, $stayB->roomAssignment, 1, null, null, null, $this->user);
    }

    // -------------------------------------------------------------------------
    // Pricing snapshot + override
    // -------------------------------------------------------------------------

    public function test_actual_price_defaults_to_suggested_price_when_not_overridden(): void
    {
        $booking = Booking::factory()->create();
        $service = $this->makeService();
        $service->prices()->create(['unit_price' => 100000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $bs = $this->enrollmentService()->enroll($booking, $service, null, 1, null, null, null, $this->user);

        $this->assertSame('100000.00', (string) $bs->suggested_price);
        $this->assertSame('100000.00', (string) $bs->actual_price);
        $this->assertFalse($bs->isPriceOverridden());
    }

    public function test_overridden_price_requires_a_reason(): void
    {
        $booking = Booking::factory()->create();
        $service = $this->makeService();
        $service->prices()->create(['unit_price' => 100000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $this->expectException(ValidationException::class);

        $this->enrollmentService()->enroll($booking, $service, null, 1, null, '150000', null, $this->user);
    }

    public function test_overridden_price_with_a_reason_is_accepted_and_snapshotted(): void
    {
        $booking = Booking::factory()->create();
        $service = $this->makeService();
        $service->prices()->create(['unit_price' => 100000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $bs = $this->enrollmentService()->enroll($booking, $service, null, 1, null, '150000', 'VIP giảm giá', $this->user);

        $this->assertSame('100000.00', (string) $bs->suggested_price);
        $this->assertSame('150000.00', (string) $bs->actual_price);
        $this->assertTrue($bs->isPriceOverridden());
        $this->assertSame('VIP giảm giá', $bs->price_override_reason);
    }

    public function test_changing_the_canonical_price_later_never_changes_an_existing_snapshot(): void
    {
        $booking = Booking::factory()->create();
        $service = $this->makeService();
        $service->prices()->create(['unit_price' => 150000, 'effective_from' => '2026-08-01', 'is_active' => true]);

        $bs = $this->enrollmentService()->enroll($booking, $service, null, 1, null, null, null, $this->user);
        $this->assertSame('150000.00', (string) $bs->actual_price);

        // Admin changes the canonical price the next day.
        $service->prices()->create(['unit_price' => 180000, 'effective_from' => '2026-08-17', 'is_active' => true]);

        $bs->refresh();
        $this->assertSame('150000.00', (string) $bs->actual_price, 'Historical snapshot must never be recomputed.');
    }

    public function test_enroll_fails_when_chargeable_service_has_no_price(): void
    {
        $booking = Booking::factory()->create();
        $service = $this->makeService();

        $this->expectException(ValidationException::class);

        $this->enrollmentService()->enroll($booking, $service, null, 1, null, null, null, $this->user);
    }

    public function test_free_service_never_produces_a_charge_amount(): void
    {
        $booking = Booking::factory()->create();
        $service = $this->makeService(['is_chargeable' => false]);

        $bs = $this->enrollmentService()->enroll($booking, $service, null, 1, null, null, null, $this->user);

        $this->assertSame('0.00', (string) $bs->actual_price);
    }

    // -------------------------------------------------------------------------
    // Quantity
    // -------------------------------------------------------------------------

    public function test_quantity_is_forced_to_1_when_quantity_disabled(): void
    {
        $booking = Booking::factory()->create();
        $service = $this->makeService(['quantity_enabled' => false]);
        $service->prices()->create(['unit_price' => 50000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $bs = $this->enrollmentService()->enroll($booking, $service, null, 5, null, null, null, $this->user);

        $this->assertSame(1, $bs->quantity);
    }

    public function test_quantity_is_honoured_when_enabled(): void
    {
        $booking = Booking::factory()->create();
        $service = $this->makeService(['quantity_enabled' => true]);
        $service->prices()->create(['unit_price' => 50000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $bs = $this->enrollmentService()->enroll($booking, $service, null, 3, null, null, null, $this->user);

        $this->assertSame(3, $bs->quantity);
    }

    // -------------------------------------------------------------------------
    // Billing mode BOTH must be chosen explicitly
    // -------------------------------------------------------------------------

    public function test_both_billing_mode_requires_an_explicit_selection(): void
    {
        $booking = Booking::factory()->create();
        $service = $this->makeService(['billing_mode' => ServiceBillingMode::Both->value]);
        $service->prices()->create(['unit_price' => 50000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $this->expectException(ValidationException::class);

        $this->enrollmentService()->enroll($booking, $service, null, 1, null, null, null, $this->user);
    }

    public function test_both_billing_mode_accepts_a_valid_selection(): void
    {
        $booking = Booking::factory()->create();
        $service = $this->makeService(['billing_mode' => ServiceBillingMode::Both->value]);
        $service->prices()->create(['unit_price' => 50000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $bs = $this->enrollmentService()->enroll($booking, $service, null, 1, ServiceBillingMode::PerNight, null, null, $this->user);

        $this->assertSame(ServiceBillingMode::PerNight, $bs->billing_mode_selected);
    }

    // -------------------------------------------------------------------------
    // Fulfillment lifecycle
    // -------------------------------------------------------------------------

    public function test_fulfillment_transitions_created_confirmed_completed(): void
    {
        $booking = Booking::factory()->create();
        $service = $this->makeService(['fulfillment_required' => true]);
        $service->prices()->create(['unit_price' => 50000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $enrollment = $this->enrollmentService();
        $bs = $enrollment->enroll($booking, $service, null, 1, null, null, null, $this->user);
        $this->assertSame(ServiceFulfillmentStatus::Created, $bs->fulfillment_status);

        $bs = $enrollment->confirm($bs, $this->user);
        $this->assertSame(ServiceFulfillmentStatus::Confirmed, $bs->fulfillment_status);
        $this->assertSame($this->user->id, $bs->confirmed_by);
        $this->assertNotNull($bs->confirmed_at);

        $bs = $enrollment->complete($bs, $this->user);
        $this->assertSame(ServiceFulfillmentStatus::Completed, $bs->fulfillment_status);
        $this->assertSame($this->user->id, $bs->completed_by);
    }

    /**
     * User request (2026-08-20 chat) — "cho phép hủy kể cả sau khi đã hoàn
     * thành". Was test_cannot_transition_out_of_completed (asserted the
     * opposite, pre-2026-08-20 behavior) — updated in place since the old
     * behavior is exactly what was asked to change, not a separate case to
     * keep. Completed -> Cancelled is now the ONE transition allowed out of
     * Completed; still nothing else (asserted below).
     */
    public function test_completed_can_only_transition_to_cancelled(): void
    {
        $booking = Booking::factory()->create();
        $service = $this->makeService();
        $service->prices()->create(['unit_price' => 50000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $enrollment = $this->enrollmentService();
        $bs = $enrollment->enroll($booking, $service, null, 1, null, null, null, $this->user);
        $bs = $enrollment->complete($bs, $this->user);

        $bs = $enrollment->cancel($bs, $this->user);
        $this->assertSame(ServiceFulfillmentStatus::Cancelled, $bs->fulfillment_status);

        // Cancelled is terminal — no further transition, including re-completing.
        $this->expectException(ValidationException::class);
        $enrollment->complete($bs, $this->user);
    }

    public function test_cancelled_row_records_who_and_when(): void
    {
        $booking = Booking::factory()->create();
        $service = $this->makeService();
        $service->prices()->create(['unit_price' => 50000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $enrollment = $this->enrollmentService();
        $bs = $enrollment->enroll($booking, $service, null, 1, null, null, null, $this->user);
        $bs = $enrollment->cancel($bs, $this->user);

        $this->assertSame(ServiceFulfillmentStatus::Cancelled, $bs->fulfillment_status);
        $this->assertSame($this->user->id, $bs->cancelled_by);
        $this->assertNotNull($bs->cancelled_at);
    }
}
