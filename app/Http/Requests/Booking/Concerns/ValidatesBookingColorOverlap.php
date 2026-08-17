<?php

namespace App\Http\Requests\Booking\Concerns;

use App\Services\BookingColorService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Carbon;

/**
 * Shared by StoreBookingRequest/UpdateBookingRequest: enforce
 * "hai Booking có thời gian chiếm phòng trùng nhau không được có cùng
 * booking color" at save time (docs/Prompt_1.txt mục VI — Manual
 * Selection). This is the actual hard guarantee; the picker's suggested
 * color/disabled swatches are only a best-effort, client-side nudge and
 * are not themselves authoritative.
 */
trait ValidatesBookingColorOverlap
{
    /**
     * @param  int|null  $excludeBookingId  the booking being edited, so it never conflicts with itself
     * @param  string|null  $currentColor  the booking's color BEFORE this request, if any
     * @param  \Illuminate\Support\Carbon|null  $currentCheckinAt  the booking's checkin_at BEFORE this request
     * @param  \Illuminate\Support\Carbon|null  $currentCheckoutAt  the booking's checkout_at BEFORE this request
     *
     * The check is skipped only when NEITHER the color NOR the occupancy
     * interval actually changes from what's already persisted (mục VI —
     * Historical Stability: a pre-existing grandfathered conflict must not
     * start blocking every future, unrelated edit to that booking). A date
     * change is never "unrelated" here — extending/shifting the interval
     * can itself create a brand-new overlap, so it must always re-run the
     * conflict check even when the color field is untouched.
     */
    protected function addBookingColorConflictRule(
        Validator $validator,
        ?int $excludeBookingId,
        ?string $currentColor = null,
        ?Carbon $currentCheckinAt = null,
        ?Carbon $currentCheckoutAt = null,
    ): void {
        $validator->after(function (Validator $validator) use ($excludeBookingId, $currentColor, $currentCheckinAt, $currentCheckoutAt): void {
            $color = $this->input('booking_color');
            $checkinAt = $this->input('checkin_at');
            $checkoutAt = $this->input('checkout_at');

            // Field-level rules already report missing/malformed input;
            // do not pile a second, confusing error on top of those.
            if (! $color || ! $checkinAt || ! $checkoutAt || $validator->errors()->has('booking_color') || $validator->errors()->has('checkin_at') || $validator->errors()->has('checkout_at')) {
                return;
            }

            $checkinAt = Carbon::parse($checkinAt);
            $checkoutAt = Carbon::parse($checkoutAt);

            $nothingRelevantChanged = $currentColor !== null
                && BookingColorService::sameColor($color, $currentColor)
                && $currentCheckinAt !== null && $checkinAt->equalTo($currentCheckinAt)
                && $currentCheckoutAt !== null && $checkoutAt->equalTo($currentCheckoutAt);

            if ($nothingRelevantChanged) {
                return;
            }

            $hasConflict = app(BookingColorService::class)->hasConflict(
                $color,
                $checkinAt,
                $checkoutAt,
                $excludeBookingId,
            );

            if ($hasConflict) {
                $validator->errors()->add(
                    'booking_color',
                    'Màu này đang được dùng bởi một booking khác có thời gian chiếm phòng trùng lặp. Vui lòng chọn màu khác.',
                );
            }
        });
    }
}
