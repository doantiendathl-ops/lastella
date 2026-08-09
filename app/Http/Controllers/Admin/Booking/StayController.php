<?php

namespace App\Http\Controllers\Admin\Booking;

use App\Exceptions\FinalCheckoutConfirmationRequiredException;
use App\Exceptions\OutstandingBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CheckInStayRequest;
use App\Http\Requests\Booking\CheckOutStayRequest;
use App\Http\Requests\Booking\ExtendStayRequest;
use App\Http\Requests\Booking\MoveRoomRequest;
use App\Http\Requests\Booking\SkipCheckoutInspectionRequest;
use App\Http\Requests\Booking\UpdateActualCheckInRequest;
use App\Http\Requests\Booking\UpdateActualCheckOutRequest;
use App\Models\Booking;
use App\Models\Room;
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

    /**
     * Records an authorized, reasoned skip of the checkout inspection for this stay only.
     * Standalone action — does not itself perform checkout. The frontend calls this first
     * (when the acting user has permission and chooses to skip), then submits the normal,
     * unmodified checkout request. Never blocks or alters checkOut() in any way.
     */
    public function skipInspection(SkipCheckoutInspectionRequest $request, Booking $booking, Stay $stay): RedirectResponse
    {
        abort_unless($stay->booking_id === $booking->id, 404);

        $this->stays->skipCheckoutInspection($stay, $request->user(), $request->validated('reason'));

        return redirect()
            ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])
            ->with('success', 'Đã ghi nhận bỏ qua kiểm đồ cho phòng này.');
    }

    public function extend(ExtendStayRequest $request, Booking $booking, Stay $stay): RedirectResponse
    {
        $this->authorize('extend', $stay);
        abort_unless($stay->booking_id === $booking->id, 404);

        $this->stays->extendStay($stay, $request->validated('new_planned_checkout_at'), $request->user());

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])->with('success', 'Đã gia hạn lưu trú.');
    }

    public function moveRoom(MoveRoomRequest $request, Booking $booking, Stay $stay): RedirectResponse
    {
        $this->authorize('moveRoom', $stay);
        abort_unless($stay->booking_id === $booking->id, 404);

        $newRoom = Room::findOrFail($request->validated('new_room_id'));

        $this->stays->moveRoom($stay, $newRoom, $request->user(), $request->validated('reason'));

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])->with('success', 'Đã đổi phòng.');
    }

    /** ADMIN-only: correct an already-recorded actual check-in time. */
    public function updateActualCheckIn(UpdateActualCheckInRequest $request, Booking $booking, Stay $stay): RedirectResponse
    {
        $this->authorize('updateActualCheckIn', $stay);
        abort_unless($stay->booking_id === $booking->id, 404);

        $this->stays->updateActualCheckIn($stay, $request->date('actual_checkin_at'), $request->user());

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])->with('success', 'Đã cập nhật thời gian nhận phòng thực tế.');
    }

    /** ADMIN-only: correct an already-recorded actual check-out time. */
    public function updateActualCheckOut(UpdateActualCheckOutRequest $request, Booking $booking, Stay $stay): RedirectResponse
    {
        $this->authorize('updateActualCheckOut', $stay);
        abort_unless($stay->booking_id === $booking->id, 404);

        $this->stays->updateActualCheckOut($stay, $request->date('actual_checkout_at'), $request->user());

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])->with('success', 'Đã cập nhật thời gian trả phòng thực tế.');
    }
}
