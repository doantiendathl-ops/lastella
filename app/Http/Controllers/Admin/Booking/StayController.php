<?php

namespace App\Http\Controllers\Admin\Booking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CheckInStayRequest;
use App\Http\Requests\Booking\CheckOutStayRequest;
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

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'stays'])->with('success', 'Stay checked in.');
    }

    public function checkOut(CheckOutStayRequest $request, Booking $booking, Stay $stay): RedirectResponse
    {
        $this->authorize('checkOut', $stay);
        abort_unless($stay->booking_id === $booking->id, 404);

        $this->stays->checkOut($stay, $request->validated('actual_checkout_at'));

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'stays'])->with('success', 'Stay checked out.');
    }
}
