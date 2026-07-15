<?php

namespace App\Services;

use App\Enums\ChargeType;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Stay;
use Illuminate\Support\Carbon;

/**
 * Read-only forward-looking payment projection. This is a forecast, not a
 * ledger — it never writes to the database, never posts a FolioEntry, and
 * never affects the checkout outstanding-balance guard (which remains
 * governed exclusively by BookingService::finaliseBookingCheckout()'s
 * posted-Folio calculation).
 */
class PaymentProjectionService
{
    public function __construct(
        private readonly BookingService $bookings,
    ) {
    }

    public function project(Booking $booking): array
    {
        $booking->loadMissing(['stays.room', 'stays.roomAssignment', 'bookingRequirements', 'folio.folioEntries', 'bookingPayments']);

        $stayBreakdown = $this->projectStayRoomCharges($booking);
        $roomTotal = array_sum(array_column($stayBreakdown, 'subtotal'));

        $postedNonRoomTotal = $this->postedNonRoomTotal($booking);

        $expectedTotal = $roomTotal + $postedNonRoomTotal;

        // Reuse the existing, already-tested payment classification (deposit vs.
        // payment vs. refund vs. adjustment) verbatim — never re-invent it here.
        $paymentSummary = $this->bookings->paymentSummary($booking);

        $expectedDeposit = $paymentSummary['total_deposit'];
        $expectedBalance = $expectedTotal - $paymentSummary['paid_total'];

        return [
            // Room cost projected through the Stay's current plan (future-inclusive)
            // — never read from posted FolioEntry rows, computed independently.
            'projected_room_total' => round($roomTotal, 2),
            // Non-room charges (service, fees, etc.) already posted to Folio to date
            // — NOT forward-projected for recurring services not yet posted.
            'posted_non_room_total' => round($postedNonRoomTotal, 2),
            // Hybrid figure: projected_room_total + posted_non_room_total. Labeled
            // "current" in the UI because it mixes a future-inclusive room figure
            // with a posted-to-date non-room figure — see the M1 semantics review.
            'expected_total' => round($expectedTotal, 2),
            'expected_deposit' => round($expectedDeposit, 2),
            // The direct reconciliation counterpart to expected_balance — reused
            // verbatim from paymentSummary(), never re-derived. Deliberately
            // distinct from expected_deposit: deposit is a subset shown only as a
            // secondary breakdown, never the number expected_balance reconciles
            // against (that was the reconciliation bug this field fixes).
            'recognized_paid_total' => round($paymentSummary['paid_total'], 2),
            'expected_balance' => round($expectedBalance, 2),
            'calculation_context' => [
                'room_total' => round($roomTotal, 2),
                'posted_non_room_total' => round($postedNonRoomTotal, 2),
                'paid_total' => round($paymentSummary['paid_total'], 2),
                'stays' => $stayBreakdown,
            ],
        ];
    }

    /**
     * @return array<int, array{stay_id: int, nights: int, unit_price: float, subtotal: float}>
     */
    private function projectStayRoomCharges(Booking $booking): array
    {
        $activeStays = $booking->stays->whereNotIn('status', [StayStatus::Cancelled, StayStatus::NoShow]);

        if ($activeStays->isEmpty()) {
            return $this->projectFromRequirementsOnly($booking);
        }

        return $activeStays->map(function (Stay $stay) use ($booking): array {
            [$start, $end] = $this->effectiveStayRange($stay);
            $nights = $this->nightsBetween($start, $end);
            $unitPrice = $this->resolveUnitPrice($booking, $this->commercialRoomTypeId($stay));

            return [
                'stay_id' => $stay->id,
                'nights' => $nights,
                'unit_price' => $unitPrice,
                'subtotal' => round($nights * $unitPrice, 2),
            ];
        })->values()->all();
    }

    /**
     * Before any Stay/RoomAssignment exists (pure PendingAssignment/Draft Booking),
     * project from the Booking-level requirements and dates — the only source of
     * truth available at that stage. Once Stays exist, per-Stay dates take over
     * (§ effectiveStayRange) so Split Stay divergence is never flattened.
     *
     * @return array<int, array{stay_id: int, nights: int, unit_price: float, subtotal: float}>
     */
    private function projectFromRequirementsOnly(Booking $booking): array
    {
        if ($booking->checkin_at === null || $booking->checkout_at === null) {
            return [];
        }

        $nights = $this->nightsBetween($booking->checkin_at, $booking->checkout_at);

        return $booking->bookingRequirements->map(function ($requirement) use ($nights): array {
            $unitPrice = (float) $requirement->room_price;
            $quantity = (int) $requirement->quantity;

            return [
                'stay_id' => null,
                'nights' => $nights,
                'unit_price' => $unitPrice,
                'subtotal' => round($nights * $unitPrice * $quantity, 2),
            ];
        })->values()->all();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function effectiveStayRange(Stay $stay): array
    {
        return match ($stay->status) {
            // Already finished — the actual, final duration, not an open-ended projection.
            StayStatus::CheckedOut => [$stay->actual_checkin_at, $stay->actual_checkout_at],
            // Currently occupying — projected through the CURRENT planned checkout,
            // which already reflects any Stay Extension or Booking amendment.
            StayStatus::CheckedIn => [$stay->actual_checkin_at, $stay->planned_checkout_at],
            // Not yet arrived — the current plan.
            default => [$stay->planned_checkin_at, $stay->planned_checkout_at],
        };
    }

    private function nightsBetween(Carbon $checkin, Carbon $checkout): int
    {
        $nights = (int) $checkin->copy()->startOfDay()->diffInDays($checkout->copy()->startOfDay());

        return max(0, $nights);
    }

    /**
     * Commercial Source Principle (Product Sprint 03): the room type used to
     * key the rate lookup. Prefers RoomAssignment.room_type_id — the
     * commercial requirement slot this Stay's assignment fulfills, stable
     * across a Change Room operational move — over the Stay's live physical
     * room type, which may have changed. Falls back to the physical type
     * only when no RoomAssignment link exists. Mirrors
     * RoomChargePostingJob::resolveUnitPrice()'s resolution exactly, so
     * projection never diverges from what Night Audit will actually post.
     */
    private function commercialRoomTypeId(Stay $stay): ?int
    {
        return $stay->roomAssignment?->room_type_id ?? $stay->room?->room_type_id;
    }

    /**
     * Mirrors RoomChargePostingJob::resolveUnitPrice() exactly — the frozen,
     * already-resolved BookingRequirement.room_price, never a live RoomRate
     * lookup — so the projection never diverges from what Night Audit will
     * actually post.
     */
    private function resolveUnitPrice(Booking $booking, ?int $roomTypeId): float
    {
        if ($roomTypeId === null) {
            return 0.0;
        }

        $requirement = $booking->bookingRequirements->firstWhere('room_type_id', $roomTypeId);

        return $requirement !== null ? (float) $requirement->room_price : 0.0;
    }

    private function postedNonRoomTotal(Booking $booking): float
    {
        $folio = $booking->folio;

        if ($folio === null) {
            return 0.0;
        }

        return (float) $folio->folioEntries
            ->whereNull('voided_at')
            ->where('charge_type', '!=', ChargeType::Room)
            ->sum('amount');
    }
}
