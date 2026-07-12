<?php

namespace App\Http\Controllers\Admin\Booking;

use App\Exceptions\FinalCheckoutConfirmationRequiredException;
use App\Exceptions\OutstandingBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CheckInStayRequest;
use App\Http\Requests\Booking\CheckOutStayRequest;
use App\Http\Requests\Booking\ExtendStayRequest;
use App\Models\Booking;
use App\Models\Stay;
use App\Services\StayService;
use Illuminate\Http\RedirectResponse;

class StayController extends Controller
{
    public function __construct(private readonly StayService $stays)
    {
    }

    public function checkIn(CheckInStayRequest $request, Booking $booking, Stay $stay): RedirectResponse
    {
        $this->authorize('checkIn', $stay);
        abort_unless($stay->booking_id === $booking->id, 404);

        $this->stays->checkIn($stay, $request->validated('actual_checkin_at'));

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])->with('success', 'Đã nhận phòng.');
    }

    public function checkOut(CheckOutStayRequest $request, Booking $booking, Stay $stay): RedirectResponse
    {
        $this->authorize('checkOut', $stay);
        abort_unless($stay->booking_id === $booking->id, 404);

        try {
            $this->stays->checkOut(
                $stay,
                $request->validated('actual_checkout_at'),
                $request->boolean('confirmed', false),
            );
        } catch (FinalCheckoutConfirmationRequiredException) {
            // ADR-55: final checkout gate — redirect back to room_map so the frontend
            // shows the charge-review confirmation dialog and retries with confirmed=true.
            return redirect()
                ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])
                ->with('final_checkout_confirmation_required', $stay->id);
        } catch (OutstandingBalanceException $e) {
            // ADR-53: caught per-controller; redirects to payments tab (most actionable destination).
            return redirect()
                ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'payments'])
                ->with('error', 'Không thể trả phòng: ' . $e->getMessage() . ' Vui lòng thanh toán trên tab Tài chính.');
        }

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])->with('success', 'Đã trả phòng.');
    }

    public function extend(ExtendStayRequest $request, Booking $booking, Stay $stay): RedirectResponse
    {
        $this->authorize('extend', $stay);
        abort_unless($stay->booking_id === $booking->id, 404);

        $this->stays->extendStay($stay, $request->validated('new_planned_checkout_at'), $request->user());

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])->with('success', 'Đã gia hạn lưu trú.');
    }
}
