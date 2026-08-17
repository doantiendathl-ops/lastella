<?php

namespace App\Http\Controllers\Admin\Booking;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ChargeType;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\PriceSource;
use App\Enums\StayStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\BookingIndexRequest;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Requests\Booking\RestoreBookingRequest;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Http\Requests\Booking\UpdateBookingRequest;
use App\Models\Booking;
use App\Models\ProductService;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingColorService;
use App\Services\BookingService;
use App\Services\BusinessDateService;
use App\Services\PaymentProjectionService;
use App\Services\RoomAssignmentService;
use App\Services\RoomRateService;
use App\Services\ServiceRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    private const CLOSED_BOOKING_EDIT_MESSAGE = 'Booking đã kết thúc hoặc đã hủy, không thể chỉnh sửa.';
    private const CHECKED_IN_CANCEL_MESSAGE = 'Booking đã có phòng nhận khách, không thể hủy thông thường. Vui lòng xử lý trả phòng hoặc liên hệ quản trị viên.';
    private const CANCEL_WARNING = 'Hành động này sẽ hủy booking và giải phóng các phòng đã phân.';
    private const RESTORE_SUCCESS_MESSAGE = 'Booking đã được khôi phục. Vui lòng kiểm tra lại phân phòng.';

    public function __construct(
        private readonly BookingService $bookings,
        private readonly RoomRateService $roomRates,
        private readonly PaymentProjectionService $paymentProjection,
        private readonly BookingColorService $bookingColors,
    ) {
    }

    public function index(BookingIndexRequest $request): Response
    {
        $this->authorize('viewAny', Booking::class);
        $canUpdate = $request->user()?->can('booking.update') ?? false;

        $items = $this->bookings->paginate($request->validated())->through(function (Booking $booking) use ($request, $canUpdate): array {
            $canEdit = $this->canEdit($booking, $request->user());
            $canCancel = ($request->user()?->can('booking.cancel') ?? false) && $booking->status !== BookingStatus::Cancelled;
            $hasCheckedInStays = (bool) $booking->has_checked_in_stays;

            return [
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
                'can_edit' => $canUpdate && $canEdit,
                'edit_disabled_reason' => $canUpdate && ! $canEdit ? self::CLOSED_BOOKING_EDIT_MESSAGE : null,
                'can_cancel' => $canCancel && ! $hasCheckedInStays,
                'cancel_disabled_reason' => $canCancel && $hasCheckedInStays ? self::CHECKED_IN_CANCEL_MESSAGE : null,
                'cancel_confirmation' => $this->cancelConfirmationPayload($booking),
                'can_restore' => $request->user()?->can('restore', $booking) ?? false,
            ];
        });

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

        return redirect()->route('admin.bookings.show', $booking)->with('success', 'Đã tạo đặt phòng.');
    }

    public function show(Request $request, Booking $booking, RoomAssignmentService $assignments, ServiceRateService $serviceRates, BusinessDateService $businessDate): Response
    {
        $this->authorize('view', $booking);

        $booking->load([
            'salesUser',
            'bookingRequirements.roomType',
            'bookingPayments.confirmedBy',
            'folio.folioEntries.postedBy',
            'folio.folioEntries.voidedBy',
            'roomAssignments.room.roomType',
            'roomAssignments.roomType',
            'roomAssignments.assignedBy',
            'roomAssignments.releasedBy',
            'roomAssignments.stay',
            'stays.room',
            'stays.roomAssignment',
            'stays.checkoutInspection',
            'packageFlags',
            'specialRequests.requestedBy',
            'specialRequests.acknowledgedBy',
            'specialRequests.fulfilledBy',
            'specialRequests.cancelledBy',
            'specialRequests.stay.room',
        ]);

        $currentBusinessDate = $businessDate->currentBusinessDate();

        $activeRates = $serviceRates->activeRatesGrouped($currentBusinessDate);

        $checkableStays = $booking->stays
            ->filter(fn (Stay $stay): bool => $stay->status === StayStatus::CheckedIn)
            ->map(fn (Stay $stay): array => [
                'id'          => $stay->id,
                'room_number' => $stay->room?->room_number ?? "Stay #{$stay->id}",
            ])
            ->values();

        return Inertia::render('Admin/Bookings/Show', [
            'booking'             => $this->bookingPayload($booking),
            'activeTab'           => $this->normalizeDetailTab((string) $request->query('tab', 'info')),
            'tabs'                => $this->detailTabs(),
            'assignmentSummary'   => $assignments->getAssignmentSummary($booking),
            'roomBoard'           => $assignments->getRoomBoard($booking),
            'options'             => $this->options(includeRooms: true, booking: $booking),
            // activeRatesGrouped() groups rates by charge_type (charge_type is the array key,
            // not a field on each entry), so flatten per group and reattach charge_type.
            'serviceRates'        => collect($activeRates)
                ->flatMap(fn (array $rates, string $chargeType): array => array_map(
                    fn (array $rate): array => [
                        'id'          => $rate['id'],
                        'name'        => $rate['name'],
                        'charge_type' => $chargeType,
                        'unit_price'  => (float) $rate['unit_price'],
                        'unit_label'  => $rate['unit_label'],
                    ],
                    $rates
                ))
                ->values()
                ->all(),
            'productServices'     => ProductService::addableToBooking()
                ->with('category')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (ProductService $p): array => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'category_name' => $p->category?->name,
                    'charge_type' => $p->resolveChargeType()->value,
                    'unit_price' => (float) $p->price,
                    'unit_label' => $p->unit,
                ])
                ->values(),
            'checkableStays'      => $checkableStays,
            'currentBusinessDate' => $currentBusinessDate->toDateString(),
            'can' => $this->permissions() + [
                'overrideProductPrice' => $request->user()?->can('product_services.manage') ?? false,
                'editBooking' => $this->canEdit($booking, $request->user()) && $request->user()?->can('booking.update'),
                'editDisabledReason' => $this->canEdit($booking, $request->user()) ? null : self::CLOSED_BOOKING_EDIT_MESSAGE,
                'cancelBookingNormally' => ($request->user()?->can('booking.cancel') ?? false)
                    && $booking->status !== BookingStatus::Cancelled
                    && $this->bookings->canCancelNormally($booking),
                'cancelDisabledReason' => $booking->status !== BookingStatus::Cancelled && ! $this->bookings->canCancelNormally($booking)
                    ? self::CHECKED_IN_CANCEL_MESSAGE
                    : null,
                'restoreBooking' => $request->user()?->can('restore', $booking) ?? false,
            ],
        ]);
    }

    public function edit(Booking $booking): Response|RedirectResponse
    {
        $this->authorize('update', $booking);

        if (! $this->canEdit($booking, request()->user())) {
            return redirect()
                ->route('admin.bookings.show', $booking)
                ->with('error', self::CLOSED_BOOKING_EDIT_MESSAGE);
        }

        return Inertia::render('Admin/Bookings/Form', [
            'booking' => $this->formPayload($booking),
            'action' => route('admin.bookings.update', $booking),
            'method' => 'put',
            'options' => $this->options(booking: $booking),
            'bookingTimeConflicts' => session('booking_time_conflicts'),
        ]);
    }

    public function update(UpdateBookingRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('update', $booking);

        if (! $this->canEdit($booking, $request->user())) {
            return redirect()
                ->route('admin.bookings.show', $booking)
                ->with('error', self::CLOSED_BOOKING_EDIT_MESSAGE);
        }

        $this->bookings->updateBooking($booking, $request->validated());

        return redirect()->route('admin.bookings.show', $booking)->with('success', 'Đã cập nhật đặt phòng.');
    }

    public function cancel(CancelBookingRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('cancel', $booking);

        if (! $this->bookings->canCancelNormally($booking)) {
            return redirect()
                ->route('admin.bookings.show', $booking)
                ->with('error', self::CHECKED_IN_CANCEL_MESSAGE);
        }

        $this->bookings->cancelBooking($booking, $request->validated('cancellation_reason'));

        return redirect()->route('admin.bookings.show', $booking)->with('success', 'Đã hủy đặt phòng.');
    }

    public function restore(RestoreBookingRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('restore', $booking);

        $this->bookings->restoreCancelledBooking($booking);

        return redirect()->route('admin.bookings.show', $booking)->with('success', self::RESTORE_SUCCESS_MESSAGE);
    }

    private function inspectionStatusFor(Stay $stay): string
    {
        // Daily Room Operations Board Mục XXIV: delegates to the canonical
        // Stay::inspectionStatus() so this screen and the new Room Operations
        // Board can never drift into two different inspection-status readings.
        return $stay->inspectionStatus();
    }

    private function canEdit(Booking $booking, ?User $user): bool
    {
        if ($user?->hasRole('ADMIN')) {
            return true;
        }

        return ! in_array($booking->status, [
            BookingStatus::CheckedOut,
            BookingStatus::Cancelled,
            BookingStatus::NoShow,
        ], true);
    }

    private function roomAssignmentMismatch(Booking $booking): array
    {
        $activeStatuses = [
            AssignmentStatus::Assigned->value,
            AssignmentStatus::CheckedIn->value,
            AssignmentStatus::CheckedOut->value,
        ];

        $required = $booking->bookingRequirements
            ->groupBy('room_type_id')
            ->map(fn ($reqs) => (int) $reqs->sum('quantity'));

        $assigned = $booking->roomAssignments
            ->filter(fn ($a) => in_array($a->status?->value, $activeStatuses, true))
            ->groupBy('room_type_id')
            ->map(fn ($assignments) => $assignments->count());

        $allTypeIds = $required->keys()->merge($assigned->keys())->unique();

        $items = [];
        foreach ($allTypeIds as $roomTypeId) {
            $requiredQty = $required->get($roomTypeId) ?? 0;
            $assignedQty = $assigned->get($roomTypeId) ?? 0;
            $difference = $assignedQty - $requiredQty;

            if ($difference === 0) {
                continue;
            }

            $req = $booking->bookingRequirements->firstWhere('room_type_id', $roomTypeId);
            $asg = $booking->roomAssignments->firstWhere('room_type_id', $roomTypeId);
            $roomTypeName = $req?->roomType?->code ?? $asg?->roomType?->code ?? 'N/A';

            $items[] = [
                'room_type_id' => $roomTypeId,
                'room_type_name' => $roomTypeName,
                'required_quantity' => $requiredQty,
                'assigned_quantity' => $assignedQty,
                'difference' => $difference,
                'status' => $difference < 0 ? 'missing' : 'excess',
            ];
        }

        return [
            'has_mismatch' => count($items) > 0,
            'items' => $items,
        ];
    }

    private function bookingPayload(Booking $booking): array
    {
        // Room Demand/Room Board Unification M3 (Implementation Plan Mục XVII):
        // active_assignment_count/remaining/is_folio_locked per requirement line,
        // needed by the Room-Board-first confirmation panel. Both relations are
        // already eager-loaded by show() (roomAssignments, folio.folioEntries),
        // so this is computed from in-memory collections — zero extra queries.
        $activeAssignmentCountByRequirement = $booking->roomAssignments
            ->whereNotNull('booking_requirement_id')
            ->whereIn('status', [AssignmentStatus::Assigned, AssignmentStatus::CheckedIn, AssignmentStatus::CheckedOut])
            ->countBy('booking_requirement_id');

        $isFolioLocked = $booking->folio?->folioEntries
            ->contains(fn ($entry) => $entry->charge_type === ChargeType::Room && $entry->voided_at === null) ?? false;

        return [
            ...$this->formPayload($booking),
            'room_assignment_mismatch' => $this->roomAssignmentMismatch($booking),
            'requirements' => $booking->bookingRequirements->map(function ($requirement) use ($activeAssignmentCountByRequirement, $isFolioLocked): array {
                $activeCount = (int) ($activeAssignmentCountByRequirement[$requirement->id] ?? 0);

                return [
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
                    'active_assignment_count' => $activeCount,
                    'remaining' => max($requirement->quantity - $activeCount, 0),
                    'is_folio_locked' => $isFolioLocked,
                ];
            })->values(),
            'payment_summary'  => $this->bookings->paymentSummary($booking),
            'payment_projection' => $this->paymentProjection->project($booking),
            'folio'            => $this->folioPayload($booking),
            'packageFlags'     => $booking->packageFlags->map(fn ($flag): array => [
                'package_key' => $flag->package_key,
                'created_at'  => $flag->created_at?->format('Y-m-d H:i'),
            ])->values(),
            'payments' => $booking->bookingPayments->map(fn ($payment): array => [
                'id' => $payment->id,
                'payment_type' => $payment->payment_type?->value,
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method,
                'payment_at' => $payment->payment_at?->format('Y-m-d H:i'),
                'confirmed_by' => $payment->confirmedBy?->name,
                'note' => $payment->note,
                'can_delete' => request()->user()?->can('delete', $payment) ?? false,
            ])->values(),
            'assignments' => $booking->roomAssignments->map(fn ($assignment): array => [
                'id' => $assignment->id,
                'room_number' => $assignment->room?->room_number,
                'room_type' => $assignment->roomType?->code,
                // Room Demand/Room Board Unification M4: needed by the bulk-release
                // reduce-demand preview to cross-reference booking.requirements[].
                'room_type_id' => $assignment->room_type_id,
                'booking_requirement_id' => $assignment->booking_requirement_id,
                'start_at' => $assignment->start_at?->format('Y-m-d H:i'),
                'end_at' => $assignment->end_at?->format('Y-m-d H:i'),
                'status' => $assignment->status?->value,
                'assigned_by' => $assignment->assignedBy?->name,
                'released_at' => $assignment->released_at?->format('Y-m-d H:i'),
                'release_reason' => $assignment->release_reason,
                'is_released' => $assignment->status === AssignmentStatus::Released,
                'is_checked_in' => $assignment->status === AssignmentStatus::CheckedIn,
                'is_checked_out' => $assignment->status === AssignmentStatus::CheckedOut,
                // can_release intentionally UNCHANGED (M4 scope: bulk release must
                // not alter single-release eligibility semantics) — the actual
                // check-in-fact/booking-status guards are re-validated server-side
                // regardless of this flag, same as before M4.
                'can_release' => $assignment->status === AssignmentStatus::Assigned,
                // Early check-in is allowed (Active Pilot decision) — the
                // planned_checkin_at gate was removed here; checkin_too_early
                // is kept (always false) only so any template still reading it
                // does not need a separate change.
                'can_check_in' => $assignment->status === AssignmentStatus::Assigned,
                'checkin_too_early' => false,
                'planned_checkin_label' => $assignment->stay?->planned_checkin_at?->format('d/m/Y H:i'),
                'can_check_out' => $assignment->status === AssignmentStatus::CheckedIn,
                'action_disabled_reason' => match(true) {
                    $assignment->status === AssignmentStatus::Released => 'Phòng đã được giải phóng. Không thể nhận/trả phòng.',
                    $assignment->status === AssignmentStatus::CheckedIn => 'Phòng đã nhận phòng thực tế. Vui lòng trả phòng trước khi giải phóng.',
                    $assignment->status === AssignmentStatus::CheckedOut => 'Phòng đã trả phòng.',
                    default => null,
                },
                'stay_id' => $assignment->stay?->id,
            ])->values(),
            'stays' => $booking->stays->map(fn ($stay): array => [
                'id' => $stay->id,
                'room_number' => $stay->room?->room_number,
                'room_type_id' => $stay->room?->room_type_id,
                'planned_checkin_at' => $stay->planned_checkin_at?->format('Y-m-d H:i'),
                'planned_checkout_at' => $stay->planned_checkout_at?->format('Y-m-d H:i'),
                'actual_checkin_at' => $stay->actual_checkin_at?->format('Y-m-d H:i'),
                'actual_checkout_at' => $stay->actual_checkout_at?->format('Y-m-d H:i'),
                'status' => $stay->status?->value,
                'is_released' => $stay->roomAssignment?->status === AssignmentStatus::Released,
                // Early check-in is allowed (Active Pilot decision) — see the
                // matching comment on the assignments[] mapping above.
                'can_check_in' => $stay->status === StayStatus::Reserved
                    && $stay->roomAssignment?->status === AssignmentStatus::Assigned,
                'checkin_too_early' => false,
                'planned_checkin_label' => $stay->planned_checkin_at?->format('d/m/Y H:i'),
                'can_check_out' => $stay->status === StayStatus::CheckedIn
                    && $stay->roomAssignment?->status === AssignmentStatus::CheckedIn,
                'can_extend' => $stay->status === StayStatus::CheckedIn
                    && $stay->roomAssignment?->status === AssignmentStatus::CheckedIn,
                'can_move_room' => $stay->status === StayStatus::CheckedIn
                    && $stay->roomAssignment?->status === AssignmentStatus::CheckedIn,
                'inspection_status' => $this->inspectionStatusFor($stay),
                'inspection_skip_reason' => $stay->inspection_skip_reason,
                'special_requests' => $booking->specialRequests
                    ->filter(fn ($r) => $r->stay_id === $stay->id)
                    ->map(fn ($r) => [
                        'request_type' => $r->request_type,
                        'status'       => $r->status->value,
                    ])->values(),
            ])->values(),
            'specialRequests' => $booking->specialRequests->sortByDesc('created_at')->map(fn ($r) => [
                'id'              => $r->id,
                'category'        => $r->category->value,
                'category_label'  => $r->category->label(),
                'request_type'    => $r->request_type,
                'quantity'        => $r->quantity,
                'note'            => $r->note,
                'status'          => $r->status->value,
                'stay_id'         => $r->stay_id,
                'room_number'     => $r->stay?->room?->room_number,
                'requested_by'    => $r->requestedBy?->name,
                'acknowledged_by' => $r->acknowledgedBy?->name,
                'acknowledged_at' => $r->acknowledged_at?->format('d/m/Y H:i'),
                'fulfilled_by'    => $r->fulfilledBy?->name,
                'fulfilled_at'    => $r->fulfilled_at?->format('d/m/Y H:i'),
                'cancelled_by'    => $r->cancelledBy?->name,
                'cancelled_at'    => $r->cancelled_at?->format('d/m/Y H:i'),
                'created_at'      => $r->created_at->format('d/m/Y H:i'),
            ])->values(),
            'pendingCount' => $booking->specialRequests
                ->filter(fn ($r) => ! $r->status->isTerminal())
                ->count(),
            'availableStays' => $booking->stays
                ->filter(fn ($s) => $s->status === StayStatus::Reserved || $s->status === StayStatus::CheckedIn)
                ->map(fn ($s) => [
                    'id'    => $s->id,
                    'label' => sprintf(
                        'Phòng %s (%s - %s)',
                        $s->room?->room_number ?? '?',
                        $s->planned_checkin_at?->format('d/m') ?? '?',
                        $s->planned_checkout_at?->format('d/m') ?? '?',
                    ),
                ])->values(),
        ];
    }

    private function formPayload(Booking $booking): array
    {
        $booking->loadMissing(['roomAssignments', 'stays']);

        $hasActiveAssignments = $booking->roomAssignments
            ->whereIn('status', [AssignmentStatus::Assigned, AssignmentStatus::CheckedIn])
            ->isNotEmpty();
        $hasCheckedIn = $booking->stays->contains(fn ($stay) => $stay->actual_checkin_at !== null);
        $hasCheckedOut = $booking->stays->contains(fn ($stay) => $stay->actual_checkout_at !== null);

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
            'quick_note' => $booking->quick_note,
            'created_at' => $booking->created_at?->format('Y-m-d H:i'),
            'cancel_confirmation' => $this->cancelConfirmationPayload($booking),
            'has_active_assignments' => $hasActiveAssignments,
            'has_checked_in' => $hasCheckedIn,
            'has_checked_out' => $hasCheckedOut,
            'is_cancelled' => $booking->status === BookingStatus::Cancelled,
        ];
    }

    /**
     * Only Bookings whose occupancy interval overlaps the one being edited
     * (or the default new-booking window, when nothing is chosen yet) need
     * their color avoided — mirrors BookingColorService::overlappingColors().
     */
    private function colorSuggestionRange(?Booking $booking): array
    {
        // The in-progress form state (Form.vue's debounced partial reload)
        // always wins over the booking's last-saved dates, so the
        // suggestion/conflict list stays live while the user is still
        // editing checkin/checkout — including on the Edit form, where
        // $booking's stored dates would otherwise never change.
        $checkin = request()->query('checkin_at');
        $checkout = request()->query('checkout_at');

        if ($checkin && $checkout) {
            try {
                return [Carbon::parse($checkin), Carbon::parse($checkout)];
            } catch (\Exception) {
                // fall through
            }
        }

        if ($booking !== null) {
            return [$booking->checkin_at, $booking->checkout_at];
        }

        return [now()->setTime(14, 0), now()->addDay()->setTime(12, 0)];
    }

    private function options(bool $includeRooms = false, ?Booking $booking = null): array
    {
        [$colorCheckin, $colorCheckout] = $this->colorSuggestionRange($booking);
        $excludeBookingId = $booking?->id;

        $options = [
            'booking_color_theme_groups' => $this->bookingColors->themeGroups(),
            'booking_color_standard_colors' => $this->bookingColors->standardColors(),
            'recommended_booking_color' => $this->bookingColors->suggestColor($colorCheckin, $colorCheckout, $excludeBookingId),
            'used_booking_colors' => $this->bookingColors->overlappingColors($colorCheckin, $colorCheckout, $excludeBookingId)->all(),
            'bookingTypes' => $this->enumOptions(BookingType::cases()),
            'customerTypes' => $this->enumOptions(CustomerType::cases()),
            'statuses' => $this->enumOptions(BookingStatus::cases()),
            'priceSources' => $this->enumOptions(PriceSource::cases()),
            'paymentTypes' => $this->enumOptions(PaymentType::cases()),
            'paymentMethods' => PaymentMethod::options(),
            'chargeTypes' => ChargeType::options(),
            'roomTypes' => RoomType::query()->orderBy('code')->get(['id', 'code', 'name'])->map(function (RoomType $type) use ($booking): array {
                $option = [
                    'value' => $type->id,
                    'label' => "{$type->code} - {$type->name}",
                ];

                if ($booking !== null) {
                    $option['suggested_price'] = $this->roomRates->suggestedPrice(
                        (int) $type->id,
                        $booking->booking_type,
                        $booking->checkin_at,
                    );
                }

                return $option;
            })->values(),
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

    /**
     * Unified Services & Requests (docs/yeucaumoi.txt) — the "Yêu cầu" tab
     * (legacy BookingSpecialRequestController/SpecialRequestPanel.vue) has
     * been decommissioned, fully superseded by the "Dịch vụ & Yêu cầu"
     * screen (bookings.services.show) linked from the header above these
     * tabs. Historical BookingSpecialRequest data is untouched and still
     * read elsewhere (e.g. RoomOperationsBoardService's bed-join merge).
     */
    private function detailTabs(): array
    {
        return [
            ['key' => 'info', 'label' => 'Thông tin Booking'],
            ['key' => 'room_map', 'label' => 'Sơ đồ phòng'],
            ['key' => 'payments', 'label' => 'Tài chính'],
            ['key' => 'history', 'label' => 'Lịch sử'],
        ];
    }

    private function normalizeDetailTab(string $tab): string
    {
        return match ($tab) {
            'overview', 'requirements'          => 'info',
            'assignments', 'stays'              => 'room_map',
            'payments', 'history', 'room_map', 'info' => $tab,
            default                              => 'info',
        };
    }

    private function cancelConfirmationPayload(Booking $booking): array
    {
        return [
            'booking_code' => $booking->booking_code,
            'customer_name' => $booking->customer_name,
            'checkin_at' => $booking->checkin_at?->format('Y-m-d H:i'),
            'checkout_at' => $booking->checkout_at?->format('Y-m-d H:i'),
            'warning' => self::CANCEL_WARNING,
        ];
    }

    private function folioPayload(Booking $booking): ?array
    {
        $folio = $booking->folio;

        if ($folio === null) {
            return null;
        }

        return [
            'folio_number' => $folio->folio_number,
            'status'       => $folio->status?->value,
            'can_close'    => request()->user()?->can('close', $folio) ?? false,
            'can_reopen'   => (request()->user()?->can('reopen', $folio) ?? false) && ! $booking->status->isTerminal(),
            'entries'      => $folio->folioEntries->map(fn ($entry): array => [
                'id'                => $entry->id,
                'charge_type'       => $entry->charge_type?->value,
                'charge_type_label' => $entry->charge_type?->label(),
                'description'       => $entry->description,
                'quantity'          => $entry->quantity,
                'unit_price'        => $entry->unit_price,
                'amount'            => $entry->amount,
                'entry_date'        => $entry->entry_date?->format('Y-m-d'),
                'posted_by'         => $entry->postedBy?->name,
                'voided_at'         => $entry->voided_at?->format('Y-m-d H:i'),
                'voided_by'         => $entry->voidedBy?->name,
                'void_reason'       => $entry->void_reason,
                'is_voided'         => $entry->voided_at !== null,
                'is_system_entry'   => $entry->posting_key !== null,
                'can_void'          => request()->user()?->can('void', $entry) ?? false,
                'stay_id'           => $entry->stay_id,
                'posting_source'    => $entry->posting_source,
            ])->values(),
        ];
    }

    private function permissions(): array
    {
        $user = request()->user();

        return [
            'createBooking' => $user?->can('booking.create') ?? false,
            'updateBooking' => $user?->can('booking.update') ?? false,
            'cancelBooking' => $user?->can('booking.cancel') ?? false,
            'addPayment'    => $user?->can('payment.create') ?? false,
            'deletePayment' => $user?->can('payment.delete') ?? false,
            'createCharge'  => $user?->can('charge.create') ?? false,
            'voidCharge'    => $user?->can('charge.void') ?? false,
            'closeFolio'    => $user?->can('folio.close') ?? false,
            'reopenFolio'   => $user?->hasRole('ADMIN') ?? false,
            'assignRoom'              => $user?->can('room.assign') ?? false,
            'releaseRoom'             => $user?->can('room.unassign') ?? false,
            // Room Demand/Room Board Unification M4: gates the "Đồng thời giảm
            // nhu cầu phòng tương ứng" checkbox in the bulk-release panel.
            // permissions() has no $booking in scope (also called from the
            // bookings index, with no single booking context) — BookingPolicy::
            // update() only ever checks this same `booking.update` permission
            // string regardless of the model instance, so checking it directly
            // here is equivalent and avoids threading a nullable $booking
            // parameter through every call site. Backend re-checks the real
            // permission in BulkReleaseAssignmentRequest::authorize() regardless
            // of this flag.
            'reduceDemand'            => $user?->can('booking.update') ?? false,
            'checkIn'                 => $user?->can('stay.checkin') ?? false,
            'checkOut'                => $user?->can('stay.checkout') ?? false,
            // ADMIN-only: shows the actual check-in/check-out time override
            // field and the post-event "edit time" action. Distinct from the
            // ordinary checkIn/checkOut permission RECEPTION also holds.
            'adjustActualTime'        => $user?->hasRole('ADMIN') ?? false,
            'extend'                  => $user?->can('stay.extend') ?? false,
            'moveRoom'                => $user?->can('stay.room_move') ?? false,
            'managePackage'           => $user?->can('booking.package.manage') ?? false,
            'createSpecialRequest'    => $user?->can('special_request.create') ?? false,
            'fulfillSpecialRequest'   => $user?->can('special_request.fulfill') ?? false,
            'cancelSpecialRequest'    => $user?->can('special_request.cancel') ?? false,
            'overrideCheckoutInspection' => $user?->can('checkout_inspection.override') ?? false,
        ];
    }
}
