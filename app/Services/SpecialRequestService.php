<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\RequestCategory;
use App\Enums\RequestStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingSpecialRequest;
use App\Models\Stay;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SpecialRequestService
{
    /**
     * Create a new special request on a booking.
     *
     * Blocks terminal booking statuses: CHECKED_OUT, CANCELLED, NO_SHOW.
     * $requestedBy is always set — never null (ADR-83).
     */
    public function addRequest(
        Booking $booking,
        RequestCategory $category,
        string $requestType,
        int $quantity,
        ?string $note,
        int $requestedBy,
        ?int $stayId = null
    ): BookingSpecialRequest {
        $this->assertBookingIsNotTerminal($booking);

        return BookingSpecialRequest::create([
            'booking_id'   => $booking->id,
            'stay_id'      => $stayId,
            'category'     => $category,
            'request_type' => $requestType,
            'quantity'     => $quantity,
            'note'         => $note,
            'status'       => RequestStatus::Pending,
            'requested_by' => $requestedBy,
        ]);
    }

    /**
     * Link a request to a specific stay.
     * Idempotent: already linked to the same stay → returns request unchanged.
     * Rejects if the stay does not belong to the same booking.
     */
    public function linkToStay(BookingSpecialRequest $request, Stay $stay): BookingSpecialRequest
    {
        if ($request->stay_id === $stay->id) {
            return $request;
        }

        if ($request->booking_id !== $stay->booking_id) {
            throw ValidationException::withMessages([
                'stay' => 'Stay không thuộc cùng booking với yêu cầu này.',
            ]);
        }

        $request->update(['stay_id' => $stay->id]);

        return $request->refresh();
    }

    /**
     * Transition: pending → acknowledged.
     * Sets acknowledged_by and acknowledged_at.
     */
    public function acknowledge(BookingSpecialRequest $request, int $actorId): BookingSpecialRequest
    {
        if ($request->status !== RequestStatus::Pending) {
            throw ValidationException::withMessages([
                'request' => 'Chỉ có thể tiếp nhận yêu cầu đang ở trạng thái chờ xử lý.',
            ]);
        }

        $request->update([
            'status'          => RequestStatus::Acknowledged,
            'acknowledged_by' => $actorId,
            'acknowledged_at' => now(),
        ]);

        return $request->refresh();
    }

    /**
     * Transition: acknowledged → fulfilled.
     * Idempotent if already fulfilled.
     * Throws if request is cancelled.
     */
    public function fulfill(BookingSpecialRequest $request, int $actorId): BookingSpecialRequest
    {
        if ($request->status === RequestStatus::Fulfilled) {
            return $request;
        }

        if ($request->status === RequestStatus::Cancelled) {
            throw ValidationException::withMessages([
                'request' => 'Không thể hoàn thành yêu cầu đã bị hủy.',
            ]);
        }

        if ($request->status !== RequestStatus::Acknowledged) {
            throw ValidationException::withMessages([
                'request' => 'Chỉ có thể hoàn thành yêu cầu đã được tiếp nhận.',
            ]);
        }

        $request->update([
            'status'       => RequestStatus::Fulfilled,
            'fulfilled_by' => $actorId,
            'fulfilled_at' => now(),
        ]);

        return $request->refresh();
    }

    /**
     * Transition: pending|acknowledged → cancelled.
     * Throws if the request is already in a terminal state.
     */
    public function cancel(BookingSpecialRequest $request, int $actorId): BookingSpecialRequest
    {
        if ($request->status->isTerminal()) {
            throw ValidationException::withMessages([
                'request' => 'Không thể hủy yêu cầu đã ở trạng thái kết thúc.',
            ]);
        }

        $request->update([
            'status'       => RequestStatus::Cancelled,
            'cancelled_by' => $actorId,
            'cancelled_at' => now(),
        ]);

        return $request->refresh();
    }

    /**
     * Bulk-cancel all pending/acknowledged requests for a booking.
     * Called from BookingService::cancelBooking() INSIDE its DB::transaction.
     * Returns count of cancelled requests.
     */
    public function autoCancelForBooking(Booking $booking, int $actorId): int
    {
        return BookingSpecialRequest::query()
            ->where('booking_id', $booking->id)
            ->whereNotIn('status', [
                RequestStatus::Fulfilled->value,
                RequestStatus::Cancelled->value,
            ])
            ->update([
                'status'       => RequestStatus::Cancelled->value,
                'cancelled_by' => $actorId,
                'cancelled_at' => now(),
            ]);
    }

    /**
     * Auto-link unlinked requests to a newly created stay when the booking has exactly
     * one active stay. MUST NOT throw — all failures are logged as warnings.
     * Called from StayService::createStayFromAssignment() outside any transaction.
     */
    public function autoLinkSingleStayRequests(Booking $booking, Stay $newStay): void
    {
        try {
            $activeStayCount = $booking->stays()
                ->whereNotIn('status', [
                    StayStatus::Cancelled->value,
                    StayStatus::CheckedOut->value,
                    StayStatus::NoShow->value,
                ])
                ->count();

            if ($activeStayCount !== 1) {
                return;
            }

            BookingSpecialRequest::query()
                ->where('booking_id', $booking->id)
                ->whereNull('stay_id')
                ->whereNotIn('status', [
                    RequestStatus::Fulfilled->value,
                    RequestStatus::Cancelled->value,
                ])
                ->update(['stay_id' => $newStay->id]);
        } catch (\Throwable $e) {
            Log::warning('SpecialRequestService::autoLinkSingleStayRequests failed', [
                'booking_id' => $booking->id,
                'stay_id'    => $newStay->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function assertBookingIsNotTerminal(Booking $booking): void
    {
        if ($booking->status->isTerminal()) {
            throw ValidationException::withMessages([
                'booking' => 'Không thể thêm yêu cầu cho booking đã kết thúc.',
            ]);
        }
    }
}
