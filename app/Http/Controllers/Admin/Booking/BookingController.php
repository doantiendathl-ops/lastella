<?php

namespace App\Http\Controllers\Admin\Booking;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PaymentType;
use App\Enums\PriceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\BookingIndexRequest;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Http\Requests\Booking\UpdateBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RoomAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings)
    {
    }

    public function index(BookingIndexRequest $request): Response
    {
        $this->authorize('viewAny', Booking::class);

        $items = $this->bookings->paginate($request->validated())->through(fn (Booking $booking): array => [
            'id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'customer_name' => $booking->customer_name,
            'customer_phone' => $booking->customer_phone,
            'booking_type' => $booking->booking_type?->value,
            'checkin_at' => $booking->checkin_at?->format('Y-m-d H:i'),
            'checkout_at' => $booking->checkout_at?->format('Y-m-d H:i'),
            'adults' => $booking->adults,
            'children_under_6' => $booking->children_under_6,
            'children_over_6' => $booking->children_over_6,
            'status' => $booking->status?->value,
            'sales_user' => $booking->salesUser?->name,
            'booking_color' => $booking->booking_color,
            'created_at' => $booking->created_at?->format('Y-m-d H:i'),
        ]);

        return Inertia::render('Admin/Bookings/Index', [
            'bookings' => $items,
            'filters' => $request->validated(),
            'options' => $this->options(),
            'can' => $this->permissions(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Booking::class);

        return Inertia::render('Admin/Bookings/Form', [
            'booking' => null,
            'action' => route('admin.bookings.store'),
            'method' => 'post',
            'options' => $this->options(),
        ]);
    }

    public function store(StoreBookingRequest $request): RedirectResponse
    {
        $this->authorize('create', Booking::class);

        $booking = $this->bookings->createBooking($request->validated());

        return redirect()->route('admin.bookings.show', $booking)->with('success', 'Booking created.');
    }

    public function show(Request $request, Booking $booking, RoomAssignmentService $assignments): Response
    {
        $this->authorize('view', $booking);

        $booking->load([
            'salesUser',
            'bookingRequirements.roomType',
            'bookingPayments.confirmedBy',
            'roomAssignments.room.roomType',
            'roomAssignments.roomType',
            'roomAssignments.assignedBy',
            'roomAssignments.releasedBy',
            'stays.room',
        ]);

        return Inertia::render('Admin/Bookings/Show', [
            'booking' => $this->bookingPayload($booking),
            'activeTab' => $request->query('tab', 'overview'),
            'assignmentSummary' => $assignments->getAssignmentSummary($booking),
            'options' => $this->options(includeRooms: true),
            'can' => $this->permissions() + [
                'editBooking' => $this->canEdit($booking) && $request->user()?->can('booking.update'),
            ],
        ]);
    }

    public function edit(Booking $booking): Response
    {
        $this->authorize('update', $booking);
        abort_unless($this->canEdit($booking), 403);

        return Inertia::render('Admin/Bookings/Form', [
            'booking' => $this->formPayload($booking),
            'action' => route('admin.bookings.update', $booking),
            'method' => 'put',
            'options' => $this->options(),
        ]);
    }

    public function update(UpdateBookingRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('update', $booking);
        abort_unless($this->canEdit($booking), 403);

        $this->bookings->updateBooking($booking, $request->validated());

        return redirect()->route('admin.bookings.show', $booking)->with('success', 'Booking updated.');
    }

    public function cancel(CancelBookingRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('cancel', $booking);

        $this->bookings->cancelBooking($booking, $request->validated('cancellation_reason'));

        return redirect()->route('admin.bookings.show', $booking)->with('success', 'Booking cancelled.');
    }

    private function canEdit(Booking $booking): bool
    {
        return ! in_array($booking->status, [
            BookingStatus::CheckedOut,
            BookingStatus::Cancelled,
            BookingStatus::NoShow,
        ], true);
    }

    private function bookingPayload(Booking $booking): array
    {
        return [
            ...$this->formPayload($booking),
            'requirements' => $booking->bookingRequirements->map(fn ($requirement): array => [
                'id' => $requirement->id,
                'room_type_id' => $requirement->room_type_id,
                'room_type' => $requirement->roomType?->code,
                'quantity' => $requirement->quantity,
                'adults' => $requirement->adults,
                'children_under_6' => $requirement->children_under_6,
                'children_over_6' => $requirement->children_over_6,
                'room_price' => $requirement->room_price,
                'price_source' => $requirement->price_source?->value,
                'note' => $requirement->note,
            ])->values(),
            'payments' => $booking->bookingPayments->map(fn ($payment): array => [
                'id' => $payment->id,
                'payment_type' => $payment->payment_type?->value,
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method,
                'payment_at' => $payment->payment_at?->format('Y-m-d H:i'),
                'confirmed_by' => $payment->confirmedBy?->name,
                'note' => $payment->note,
            ])->values(),
            'assignments' => $booking->roomAssignments->map(fn ($assignment): array => [
                'id' => $assignment->id,
                'room_number' => $assignment->room?->room_number,
                'room_type' => $assignment->roomType?->code,
                'start_at' => $assignment->start_at?->format('Y-m-d H:i'),
                'end_at' => $assignment->end_at?->format('Y-m-d H:i'),
                'status' => $assignment->status?->value,
                'assigned_by' => $assignment->assignedBy?->name,
                'released_at' => $assignment->released_at?->format('Y-m-d H:i'),
                'release_reason' => $assignment->release_reason,
                'can_release' => in_array($assignment->status, [AssignmentStatus::Assigned, AssignmentStatus::CheckedIn], true),
            ])->values(),
            'stays' => $booking->stays->map(fn ($stay): array => [
                'id' => $stay->id,
                'room_number' => $stay->room?->room_number,
                'planned_checkin_at' => $stay->planned_checkin_at?->format('Y-m-d H:i'),
                'planned_checkout_at' => $stay->planned_checkout_at?->format('Y-m-d H:i'),
                'actual_checkin_at' => $stay->actual_checkin_at?->format('Y-m-d H:i'),
                'actual_checkout_at' => $stay->actual_checkout_at?->format('Y-m-d H:i'),
                'status' => $stay->status?->value,
            ])->values(),
        ];
    }

    private function formPayload(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'booking_color' => $booking->booking_color,
            'customer_name' => $booking->customer_name,
            'customer_phone' => $booking->customer_phone,
            'customer_email' => $booking->customer_email,
            'customer_type' => $booking->customer_type?->value,
            'booking_type' => $booking->booking_type?->value,
            'checkin_at' => $booking->checkin_at?->format('Y-m-d\TH:i'),
            'checkout_at' => $booking->checkout_at?->format('Y-m-d\TH:i'),
            'adults' => $booking->adults,
            'children_under_6' => $booking->children_under_6,
            'children_over_6' => $booking->children_over_6,
            'status' => $booking->status?->value,
            'sales_user_id' => $booking->sales_user_id,
            'sales_user' => $booking->salesUser?->name,
            'note' => $booking->note,
            'internal_note' => $booking->internal_note,
            'created_at' => $booking->created_at?->format('Y-m-d H:i'),
        ];
    }

    private function options(bool $includeRooms = false): array
    {
        $options = [
            'bookingTypes' => $this->enumOptions(BookingType::cases()),
            'customerTypes' => $this->enumOptions(CustomerType::cases()),
            'statuses' => $this->enumOptions(BookingStatus::cases()),
            'priceSources' => $this->enumOptions(PriceSource::cases()),
            'paymentTypes' => $this->enumOptions(PaymentType::cases()),
            'roomTypes' => RoomType::query()->orderBy('code')->get(['id', 'code', 'name'])->map(fn (RoomType $type): array => [
                'value' => $type->id,
                'label' => "{$type->code} - {$type->name}",
            ])->values(),
            'salesUsers' => User::query()->orderBy('name')->get(['id', 'name'])->map(fn (User $user): array => [
                'value' => $user->id,
                'label' => $user->name,
            ])->values(),
        ];

        if ($includeRooms) {
            $options['rooms'] = Room::query()
                ->with('roomType')
                ->orderBy('room_number')
                ->get()
                ->map(fn (Room $room): array => [
                    'value' => $room->id,
                    'label' => "{$room->room_number} - {$room->roomType?->code}",
                    'room_type_id' => $room->room_type_id,
                ])
                ->values();
        }

        return $options;
    }

    private function enumOptions(array $cases): array
    {
        return array_map(
            fn ($case): array => ['value' => $case->value, 'label' => str_replace('_', ' ', $case->value)],
            $cases,
        );
    }

    private function permissions(): array
    {
        $user = request()->user();

        return [
            'createBooking' => $user?->can('booking.create') ?? false,
            'updateBooking' => $user?->can('booking.update') ?? false,
            'cancelBooking' => $user?->can('booking.cancel') ?? false,
            'addPayment' => $user?->can('payment.create') ?? false,
            'assignRoom' => $user?->can('room.assign') ?? false,
            'releaseRoom' => $user?->can('room.unassign') ?? false,
            'checkIn' => $user?->can('stay.checkin') ?? false,
            'checkOut' => $user?->can('stay.checkout') ?? false,
        ];
    }
}
