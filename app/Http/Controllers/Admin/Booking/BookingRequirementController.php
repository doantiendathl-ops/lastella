<?php

namespace App\Http\Controllers\Admin\Booking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StoreBookingRequirementRequest;
use App\Http\Requests\Booking\UpdateBookingRequirementRequest;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Services\BookingService;
use Illuminate\Http\RedirectResponse;

class BookingRequirementController extends Controller
{
    public function __construct(private readonly BookingService $bookings)
    {
    }

    public function store(StoreBookingRequirementRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('update', $booking);
        $this->bookings->addRequirement($booking, $request->validated());

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'info'])->with('success', 'Đã thêm nhu cầu phòng.');
    }

    public function update(UpdateBookingRequirementRequest $request, Booking $booking, BookingRequirement $requirement): RedirectResponse
    {
        $this->authorize('update', $booking);
        abort_unless($requirement->booking_id === $booking->id, 404);

        $this->bookings->updateRequirement($requirement, $request->validated());

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'info'])->with('success', 'Đã cập nhật nhu cầu phòng.');
    }

    public function destroy(Booking $booking, BookingRequirement $requirement): RedirectResponse
    {
        $this->authorize('update', $booking);
        abort_unless($requirement->booking_id === $booking->id, 404);

        $this->bookings->deleteRequirement($requirement);

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'info'])->with('success', 'Đã xóa nhu cầu phòng.');
    }
}
