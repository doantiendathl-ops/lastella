<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\ServiceBillingMode;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\RoomAssignment;
use App\Models\Service;
use App\Services\BookingServiceEnrollmentService;
use App\Services\BusinessDateService;
use App\Services\Posting\UnifiedServicePostingJob;
use App\Services\ServicePricingResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 12) — the one
 * staff-facing "Dịch vụ & Yêu cầu" screen for a booking. Deliberately a
 * new, separate page for Slice 1 rather than folded into the existing
 * Packages.vue (which stays untouched, still serving legacy packages) —
 * consolidating the booking UI into a single tab is later-slice work
 * (Section 1 explicitly allows this staging).
 */
class BookingServiceController extends Controller
{
    public function show(Request $request, Booking $booking, BusinessDateService $businessDate, ServicePricingResolver $pricing): Response
    {
        abort_unless($request->user()->can('booking.package.manage'), 403);

        $today = $businessDate->currentBusinessDate()->toDateString();

        $booking->load(['bookingServices.service.category', 'bookingServices.roomAssignment.room', 'bookingServices.createdBy', 'bookingServices.confirmedBy', 'bookingServices.completedBy', 'bookingServices.cancelledBy']);

        $availableServices = Service::with('category')
            ->active()
            ->bookable()
            ->orderBy('sort_order')
            ->get()
            ->map(function (Service $service) use ($today, $pricing): array {
                $price = $service->is_chargeable ? $pricing->resolve($service, $today) : null;

                return [
                    'id' => $service->id,
                    'category_name' => $service->category?->name,
                    'name' => $service->name,
                    'description' => $service->description,
                    'is_chargeable' => $service->is_chargeable,
                    'scope' => $service->scope->value,
                    'billing_mode' => $service->billing_mode->value,
                    'quantity_enabled' => $service->quantity_enabled,
                    'default_quantity' => $service->default_quantity,
                    'unit_label' => $service->unit_label,
                    'fulfillment_required' => $service->fulfillment_required,
                    'current_price' => $price !== null ? (float) $price->unit_price : null,
                    'has_price' => ! $service->is_chargeable || $price !== null,
                ];
            });

        $rooms = RoomAssignment::where('booking_id', $booking->id)
            ->whereIn('status', [AssignmentStatus::Assigned, AssignmentStatus::CheckedIn])
            ->with('room')
            ->get()
            ->map(fn (RoomAssignment $a): array => ['id' => $a->id, 'room_number' => $a->room?->room_number ?? '—']);

        return Inertia::render('Admin/Booking/Services', [
            'booking' => [
                'id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'customer_name' => $booking->customer_name,
                'status' => $booking->status,
            ],
            'available_services' => $availableServices,
            'rooms' => $rooms,
            'booking_services' => $booking->bookingServices->map(fn (BookingService $bs): array => [
                'id' => $bs->id,
                'service_name' => $bs->service->name,
                'category_name' => $bs->service->category?->name,
                'room_number' => $bs->roomAssignment?->room?->room_number,
                'quantity' => $bs->quantity,
                'billing_mode_selected' => $bs->billing_mode_selected->value,
                'suggested_price' => (float) $bs->suggested_price,
                'actual_price' => (float) $bs->actual_price,
                'is_price_overridden' => $bs->isPriceOverridden(),
                'price_override_reason' => $bs->price_override_reason,
                'fulfillment_status' => $bs->fulfillment_status->value,
                'fulfillment_status_label' => $bs->fulfillment_status->label(),
                'created_by' => $bs->createdBy?->name,
                'confirmed_by' => $bs->confirmedBy?->name,
                'completed_by' => $bs->completedBy?->name,
                'cancelled_by' => $bs->cancelledBy?->name,
            ])->values(),
            'can' => [
                'manage' => $request->user()->can('booking.package.manage'),
            ],
        ]);
    }

    public function store(Request $request, Booking $booking, BookingServiceEnrollmentService $enrollment, UnifiedServicePostingJob $postingJob): RedirectResponse
    {
        abort_unless($request->user()->can('booking.package.manage'), 403);
        abort_if(
            in_array($booking->status, [BookingStatus::CheckedOut, BookingStatus::Cancelled, BookingStatus::NoShow], true),
            403,
            'Đặt phòng đã kết thúc.'
        );

        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'room_assignment_id' => ['nullable', 'integer', 'exists:room_assignments,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'billing_mode_selected' => ['nullable', Rule::in(['ONE_TIME', 'PER_NIGHT'])],
            'actual_price' => ['nullable', 'numeric', 'min:0'],
            'price_override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $service = Service::findOrFail($data['service_id']);
        $roomAssignment = isset($data['room_assignment_id']) ? RoomAssignment::find($data['room_assignment_id']) : null;
        $billingMode = isset($data['billing_mode_selected']) ? ServiceBillingMode::from($data['billing_mode_selected']) : null;

        try {
            $bookingService = $enrollment->enroll(
                booking: $booking,
                service: $service,
                roomAssignment: $roomAssignment,
                quantity: $data['quantity'] ?? $service->default_quantity,
                billingModeSelected: $billingMode,
                actualPrice: isset($data['actual_price']) ? (string) $data['actual_price'] : null,
                priceOverrideReason: $data['price_override_reason'] ?? null,
                createdBy: $request->user(),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        if ($bookingService->billing_mode_selected === ServiceBillingMode::OneTime) {
            $postingJob->postOneTime($bookingService, $request->user());
        }

        return back()->with('success', 'Đã thêm dịch vụ vào booking.');
    }

    public function confirm(Request $request, Booking $booking, BookingService $bookingService, BookingServiceEnrollmentService $enrollment): RedirectResponse
    {
        return $this->transition($request, $booking, $bookingService, fn () => $enrollment->confirm($bookingService, $request->user()));
    }

    public function complete(Request $request, Booking $booking, BookingService $bookingService, BookingServiceEnrollmentService $enrollment): RedirectResponse
    {
        return $this->transition($request, $booking, $bookingService, fn () => $enrollment->complete($bookingService, $request->user()));
    }

    public function cancel(Request $request, Booking $booking, BookingService $bookingService, BookingServiceEnrollmentService $enrollment): RedirectResponse
    {
        return $this->transition($request, $booking, $bookingService, fn () => $enrollment->cancel($bookingService, $request->user()));
    }

    private function transition(Request $request, Booking $booking, BookingService $bookingService, callable $action): RedirectResponse
    {
        abort_unless($request->user()->can('booking.package.manage'), 403);
        abort_unless($bookingService->booking_id === $booking->id, 404);

        try {
            $action();
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Đã cập nhật trạng thái dịch vụ.');
    }
}
