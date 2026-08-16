<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ServiceBillingMode;
use App\Enums\ServiceFulfillmentStatus;
use App\Enums\ServiceScope;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\RoomAssignment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Sections 6/7/8/9/10/11).
 *
 * The single place a staff member's "add a service/request to this
 * booking" action goes through — enroll() creates the booking_services
 * row with a permanent price snapshot; confirm()/complete()/cancel() drive
 * the fulfillment lifecycle, which is intentionally independent of
 * billing (billing correctness lives in FolioEntry.posting_key via
 * UnifiedServicePostingJob, never gated on fulfillment_status here).
 */
class BookingServiceEnrollmentService
{
    public function __construct(
        private readonly ServicePricingResolver $pricing,
        private readonly BusinessDateService $businessDateService,
    ) {}

    public function enroll(
        Booking $booking,
        Service $service,
        ?RoomAssignment $roomAssignment,
        int $quantity,
        ?ServiceBillingMode $billingModeSelected,
        ?string $actualPrice,
        ?string $priceOverrideReason,
        ?User $createdBy,
    ): BookingService {
        if (! $service->is_active || ! $service->is_bookable) {
            throw ValidationException::withMessages([
                'service_id' => 'Dịch vụ này hiện không cho phép đăng ký mới.',
            ]);
        }

        if ($service->scope->requiresRoom() && $roomAssignment === null) {
            throw ValidationException::withMessages([
                'room_assignment_id' => 'Dịch vụ này bắt buộc phải chọn phòng cụ thể.',
            ]);
        }

        if ($service->scope === ServiceScope::Booking && $roomAssignment !== null) {
            throw ValidationException::withMessages([
                'room_assignment_id' => 'Dịch vụ này áp dụng cho toàn booking, không chọn phòng cụ thể.',
            ]);
        }

        if ($roomAssignment !== null && $roomAssignment->booking_id !== $booking->id) {
            throw ValidationException::withMessages([
                'room_assignment_id' => 'Phòng này không thuộc booking đang thao tác.',
            ]);
        }

        $resolvedQuantity = $service->quantity_enabled ? max(1, $quantity) : 1;

        $billingMode = $this->resolveBillingMode($service, $billingModeSelected);

        $suggestedPrice = null;
        $resolvedActualPrice = '0.00';

        if ($service->is_chargeable) {
            $businessDate = $this->businessDateService->currentBusinessDate()->toDateString();
            $price = $this->pricing->resolve($service, $businessDate);

            if ($price === null) {
                throw ValidationException::withMessages([
                    'service_id' => 'Dịch vụ chưa có giá chuẩn — không thể đăng ký.',
                ]);
            }

            $suggestedPrice = (string) $price->unit_price;
            $resolvedActualPrice = $actualPrice !== null && $actualPrice !== '' ? $actualPrice : $suggestedPrice;

            if (bccomp($resolvedActualPrice, $suggestedPrice, 2) !== 0 && ($priceOverrideReason === null || trim($priceOverrideReason) === '')) {
                throw ValidationException::withMessages([
                    'price_override_reason' => 'Giá thực hiện khác giá chuẩn — bắt buộc nhập lý do.',
                ]);
            }
        }

        return BookingService::create([
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'room_assignment_id' => $roomAssignment?->id,
            'quantity' => $resolvedQuantity,
            'billing_mode_selected' => $billingMode->value,
            'suggested_price' => $suggestedPrice ?? '0.00',
            'actual_price' => $resolvedActualPrice,
            'price_override_reason' => $priceOverrideReason,
            'fulfillment_status' => ServiceFulfillmentStatus::Created->value,
            'created_by' => $createdBy?->id,
        ]);
    }

    public function confirm(BookingService $bookingService, User $user): BookingService
    {
        $this->transition($bookingService, ServiceFulfillmentStatus::Confirmed);

        $bookingService->update([
            'fulfillment_status' => ServiceFulfillmentStatus::Confirmed->value,
            'confirmed_by' => $user->id,
            'confirmed_at' => now(),
        ]);

        return $bookingService->refresh();
    }

    public function complete(BookingService $bookingService, User $user): BookingService
    {
        $this->transition($bookingService, ServiceFulfillmentStatus::Completed);

        $bookingService->update([
            'fulfillment_status' => ServiceFulfillmentStatus::Completed->value,
            'completed_by' => $user->id,
            'completed_at' => now(),
        ]);

        return $bookingService->refresh();
    }

    public function cancel(BookingService $bookingService, User $user): BookingService
    {
        $this->transition($bookingService, ServiceFulfillmentStatus::Cancelled);

        $bookingService->update([
            'fulfillment_status' => ServiceFulfillmentStatus::Cancelled->value,
            'cancelled_by' => $user->id,
            'cancelled_at' => now(),
        ]);

        return $bookingService->refresh();
    }

    private function transition(BookingService $bookingService, ServiceFulfillmentStatus $next): void
    {
        $current = $bookingService->fulfillment_status;

        if (! $current->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'fulfillment_status' => "Không thể chuyển trạng thái từ [{$current->label()}] sang [{$next->label()}].",
            ]);
        }
    }

    private function resolveBillingMode(Service $service, ?ServiceBillingMode $selected): ServiceBillingMode
    {
        if ($service->billing_mode->isChoosableAtEnrollment()) {
            if ($selected === null || ! in_array($selected, $service->billing_mode->allowedSelections(), true)) {
                throw ValidationException::withMessages([
                    'billing_mode_selected' => 'Dịch vụ này yêu cầu chọn cách tính: Một lần hoặc Qua đêm.',
                ]);
            }

            return $selected;
        }

        return $service->billing_mode;
    }
}
