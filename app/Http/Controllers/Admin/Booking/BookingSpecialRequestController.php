<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Booking;

use App\Enums\RequestCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StoreBookingSpecialRequestRequest;
use App\Models\Booking;
use App\Models\BookingSpecialRequest;
use App\Services\SpecialRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class BookingSpecialRequestController extends Controller
{
    public function __construct(private readonly SpecialRequestService $specialRequests)
    {
    }

    public function index(Request $request, Booking $booking): InertiaResponse
    {
        $this->authorize('view', [$booking->specialRequests()->first() ?? new BookingSpecialRequest()]);
        abort_unless($request->user()->can('special_request.create')
            || $request->user()->can('special_request.fulfill')
            || $request->user()->can('special_request.cancel'), 403);

        $requests = $booking->specialRequests()
            ->with(['requestedBy', 'acknowledgedBy', 'fulfilledBy', 'cancelledBy', 'stay.room'])
            ->latest()
            ->get();

        return Inertia::render('Admin/Booking/SpecialRequests', [
            'booking'          => $booking->only('id', 'booking_code', 'customer_name', 'status'),
            'special_requests' => $requests,
            'can'              => [
                'create'  => $request->user()->can('special_request.create'),
                'fulfill' => $request->user()->can('special_request.fulfill'),
                'cancel'  => $request->user()->can('special_request.cancel'),
            ],
        ]);
    }

    public function store(StoreBookingSpecialRequestRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('create', [BookingSpecialRequest::class, $booking]);

        $data = $request->validated();

        $this->specialRequests->addRequest(
            booking:     $booking,
            category:    RequestCategory::from($data['category']),
            requestType: $data['request_type'],
            quantity:    (int) $data['quantity'],
            note:        $data['note'] ?? null,
            requestedBy: Auth::id(),
            stayId:      isset($data['stay_id']) ? (int) $data['stay_id'] : null,
        );

        return redirect()
            ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'special_requests'])
            ->with('success', 'Đã thêm yêu cầu.');
    }

    public function acknowledge(Booking $booking, BookingSpecialRequest $specialRequest): RedirectResponse
    {
        $this->authorize('fulfill', $specialRequest);
        abort_unless($specialRequest->booking_id === $booking->id, 403);

        $this->specialRequests->acknowledge($specialRequest, Auth::id());

        return redirect()
            ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'special_requests'])
            ->with('success', 'Đã tiếp nhận yêu cầu.');
    }

    public function fulfill(Booking $booking, BookingSpecialRequest $specialRequest): RedirectResponse
    {
        $this->authorize('fulfill', $specialRequest);
        abort_unless($specialRequest->booking_id === $booking->id, 403);

        $this->specialRequests->fulfill($specialRequest, Auth::id());

        return redirect()
            ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'special_requests'])
            ->with('success', 'Đã hoàn thành yêu cầu.');
    }

    public function destroy(Booking $booking, BookingSpecialRequest $specialRequest): RedirectResponse
    {
        $this->authorize('cancel', $specialRequest);
        abort_unless($specialRequest->booking_id === $booking->id, 403);

        $this->specialRequests->cancel($specialRequest, Auth::id());

        return redirect()
            ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'special_requests'])
            ->with('success', 'Đã hủy yêu cầu.');
    }
}
