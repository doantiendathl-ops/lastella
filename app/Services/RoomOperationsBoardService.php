<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\RequestStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingSpecialRequest;
use App\Models\Floor;
use App\Models\RoomAssignment;
use App\Models\Stay;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Daily Room Operations Board — read model ONLY (Mục II: pure orchestration/UI
 * layer, no parallel business logic). Every status/flag below is read from an
 * already-established canonical source:
 *
 *  - clean/dirty        → Room::isRoomClean()/isRoomDirty() (normalizedCleaningStatus())
 *  - checked-in/out      → Stay::actual_checkin_at / actual_checkout_at
 *  - checkout inspection → Stay::inspectionStatus() (shared with BookingController)
 *  - extra bed           → room_assignments.extra_bed_quantity — ROOM-scoped (Room-Scoped
 *                          Bed Operations Correction; was BookingPackageFlag, booking-level,
 *                          which could not identify which room in a multi-room booking and
 *                          also caused a per-room over-posting bug — see ExtraBedPostingJob)
 *  - bed joined           → BookingSpecialRequest (category=bed_config, request_type=
 *                          twin_to_double), keyed by stay_id — the EXISTING Special Request
 *                          module, not a second, independent boolean (Room-Scoped Bed
 *                          Operations Correction; room_assignments.bed_joined was removed —
 *                          it would have been a duplicate, unsynchronized source of the
 *                          exact same fact)
 *  - quick note            → room_assignments.quick_note (new field, Mục VII)
 *  - date overlap semantics → same predicate as RoomAvailabilityRuleService::applyOverlap()
 *
 * Never writes to the database.
 */
class RoomOperationsBoardService
{
    public function __construct(
        private readonly RoomAvailabilityRuleService $rules,
        private readonly PaymentProjectionService $projection,
    ) {
    }

    public function boardForDate(CarbonInterface|string $date, User $user): array
    {
        $day = $date instanceof CarbonInterface ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();
        $dateStart = $day->copy();
        $dateEnd = $day->copy()->addDay();

        $assignments = RoomAssignment::query()
            ->whereIn('status', [AssignmentStatus::Assigned, AssignmentStatus::CheckedIn])
            ->where('start_at', '<', $dateEnd)
            ->where('end_at', '>', $dateStart)
            ->with([
                'room.roomType',
                'room.floor',
                'booking:id,booking_code,customer_name,booking_color,status',
                'stay.checkoutInspection',
            ])
            ->get()
            ->groupBy('room_id');

        $bookingIds = $assignments->flatten()->pluck('booking_id')->unique()->all();
        $bedJoinByStayId = $this->bedJoinRequestsByStayId($bookingIds);

        $pendingRequestCounts = BookingSpecialRequest::pendingCountByRoom(
            \App\Models\Room::query()->pluck('id')->all(),
        );

        $floors = Floor::query()
            ->whereHas('rooms')
            ->orderBy('sort_order')
            ->with(['rooms' => fn ($q) => $q->with('roomType')->orderBy('room_number')])
            ->get()
            ->map(function (Floor $floor) use ($assignments, $bedJoinByStayId, $user, $pendingRequestCounts): array {
                return [
                    'id' => $floor->id,
                    'code' => $floor->code,
                    'name' => $floor->name,
                    'rooms' => $floor->rooms->map(
                        fn ($room) => $this->buildRoomCell($room, $assignments->get($room->id, collect()), $bedJoinByStayId, $user, $pendingRequestCounts[$room->id] ?? 0),
                    )->values(),
                ];
            })
            ->filter(fn (array $f): bool => count($f['rooms']) > 0)
            ->values();

        return [
            'date' => $day->format('Y-m-d'),
            'floors' => $floors,
            'summary' => $this->boardTotals($floors),
        ];
    }

    /**
     * Mục XXXIV: ALL bookings intersecting the date, not only ones with a room
     * assignment. Cancelled/NoShow bookings never occupied an operational room
     * that day, so they are excluded — every other status is shown.
     */
    public function dailySummaryForDate(CarbonInterface|string $date, User $canViewBooking): array
    {
        $day = $date instanceof CarbonInterface ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();
        $dateStart = $day->copy();
        $dateEnd = $day->copy()->addDay();

        $bookings = Booking::query()
            ->whereNotIn('status', [\App\Enums\BookingStatus::Cancelled, \App\Enums\BookingStatus::NoShow])
            ->whereNotNull('checkin_at')
            ->whereNotNull('checkout_at')
            ->where('checkin_at', '<', $dateEnd)
            ->where('checkout_at', '>', $dateStart)
            ->with([
                'salesUser:id,name',
                'stays.room.roomType',
                'stays.roomAssignment',
                'packageFlags',
                'specialRequests',
                'folio.folioEntries',
                'bookingPayments',
                'bookingRequirements',
            ])
            ->orderBy('checkin_at')
            ->get();

        return $bookings->map(function (Booking $booking) use ($canViewBooking): array {
            $projection = $this->projection->project($booking);
            $activeStays = $booking->stays->whereNotIn('status', [StayStatus::Cancelled, StayStatus::NoShow]);

            // Mục XXXII: booking-level summaries — "Giường phụ: 201 x1, 202 x2" /
            // "Ghép giường: 202" — room cells stay room-specific; this is purely
            // an aggregate roll-up for the summary table.
            $extraBedSummary = $activeStays
                ->filter(fn (Stay $s) => ($s->roomAssignment?->extra_bed_quantity ?? 0) > 0)
                ->map(fn (Stay $s) => "{$s->room?->room_number} x{$s->roomAssignment->extra_bed_quantity}")
                ->values();

            $bedJoinRequests = $booking->specialRequests
                ->where('category', \App\Enums\RequestCategory::BedConfig)
                ->where('request_type', 'twin_to_double')
                ->where('status', '!=', \App\Enums\RequestStatus::Cancelled);
            $bedJoinResolved = $bedJoinRequests->whereNotNull('stay_id')
                ->map(fn (BookingSpecialRequest $r) => $activeStays->firstWhere('id', $r->stay_id)?->room?->room_number)
                ->filter()
                ->values();
            $bedJoinUnresolvedCount = $bedJoinRequests->whereNull('stay_id')->count();

            return [
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'customer_name' => $booking->customer_name,
                'status' => $booking->status->value,
                'booking_type' => $booking->booking_type?->value,
                'rooms' => $activeStays->map(fn (Stay $stay) => [
                    'room_number' => $stay->room?->room_number,
                    'room_type' => $stay->room?->roomType?->code,
                ])->values(),
                'planned_checkin_at' => $booking->checkin_at?->toDateTimeString(),
                'planned_checkout_at' => $booking->checkout_at?->toDateTimeString(),
                'actual_checkin_at' => $activeStays->pluck('actual_checkin_at')->filter()->min()?->toDateTimeString(),
                'actual_checkout_at' => $activeStays->isNotEmpty() && $activeStays->every(fn (Stay $s) => $s->actual_checkout_at !== null)
                    ? $activeStays->pluck('actual_checkout_at')->filter()->max()?->toDateTimeString()
                    : null,
                'adults' => $booking->adults,
                'children' => (int) $booking->children_under_6 + (int) $booking->children_over_6,
                'sales_person' => $booking->salesUser?->name,
                'note' => $booking->note,
                'service_packages' => $booking->packageFlags->pluck('package_key')->values(),
                'extra_bed_summary' => $extraBedSummary,
                'bed_join_rooms' => $bedJoinResolved,
                // Mục XXIII: surfaced here (booking-level operations context),
                // never guessed onto a room card.
                'bed_join_unresolved_count' => $bedJoinUnresolvedCount,
                'folio_total' => $projection['expected_total'],
                'paid' => $projection['recognized_paid_total'],
                'outstanding' => $projection['expected_balance'],
                'can_view' => true,
            ];
        })->values()->all();
    }

    private function buildRoomCell($room, Collection $roomAssignments, Collection $bedJoinByStayId, User $user, int $pendingRequestCount): array
    {
        $ordered = $roomAssignments->sortBy([
            fn ($a, $b) => ($b->status === AssignmentStatus::CheckedIn ? 1 : 0) <=> ($a->status === AssignmentStatus::CheckedIn ? 1 : 0),
            fn ($a, $b) => $a->start_at <=> $b->start_at,
        ])->values();

        /** @var RoomAssignment|null $primary */
        $primary = $ordered->first();
        $stay = $primary?->stay;

        $inspectionStatus = $stay?->inspectionStatus() ?? 'none';
        /** @var BookingSpecialRequest|null $bedJoinRequest */
        $bedJoinRequest = $stay !== null ? $bedJoinByStayId->get($stay->id) : null;

        // Room-Conflict Detection follow-up + Pre-Commit Critical Safety
        // Closure (Blocker #2): more than one Assigned/CheckedIn assignment
        // can land in $roomAssignments for the same room+day for two very
        // different reasons — (a) a benign same-day handoff (one guest
        // checks out at 12:00, the next checks in at 14:00, ranges never
        // actually overlap) or (b) a genuine double-booking (two live
        // assignments whose ranges DO overlap in time — both would need the
        // same physical key at once). The board previously collapsed both
        // cases into a single, unused `has_same_day_turnover` flag and
        // silently picked one occupant to display, hiding a real conflict.
        //
        // First implementation only compared every OTHER assignment against
        // the displayed `primary` — a genuine gap: with 3+ assignments, a
        // true overlap between two NON-primary assignments (B-C, with A the
        // primary having no overlap with either) went completely undetected.
        // conflictingAssignments() below checks every pair, not just
        // primary-vs-others, so any true overlap anywhere in the set is
        // caught and every participant is reported — never silently dropped.
        $conflicting = $this->conflictingAssignments($ordered, $primary);

        return [
            'id' => $room->id,
            'room_number' => $room->room_number,
            'room_type' => $room->roomType?->code,
            'room_type_name' => $room->roomType?->name,
            // Inspection Financial Correction Mục XII: canonical room-standard-occupancy
            // source for the fast inspection popup's water complimentary display.
            'room_type_standard_adults' => $room->roomType?->standard_adults,
            'floor_id' => $room->floor_id,
            'status' => $room->status?->value,
            'status_theme' => $this->statusTheme($room, $primary),
            'is_clean' => $room->isRoomClean(),
            'cleaning_status_label' => $room->normalizedCleaningStatus()->label(),
            'is_unavailable' => $this->rules->isRoomUnavailable($room),
            'occupant' => $primary === null ? null : [
                'booking_id' => $primary->booking_id,
                'booking_code' => $primary->booking?->booking_code,
                'customer_name' => $primary->booking?->customer_name,
                'assignment_id' => $primary->id,
                'stay_id' => $stay?->id,
                'assignment_status' => $primary->status->value,
                'is_checked_in' => $stay?->actual_checkin_at !== null,
                'is_checked_out' => $stay?->actual_checkout_at !== null,
                'bed_join' => $bedJoinRequest === null ? null : [
                    'status' => $bedJoinRequest->status->value,
                    'status_label' => $bedJoinRequest->status->label(),
                ],
                'extra_bed_quantity' => $primary->extra_bed_quantity,
                'inspection_status' => $inspectionStatus,
                'quick_note' => $primary->quick_note,
                'start_at' => $primary->start_at?->format('Y-m-d H:i'),
                'end_at' => $primary->end_at?->format('Y-m-d H:i'),
            ],
            'has_same_day_turnover' => $ordered->count() > 1 && $conflicting->isEmpty(),
            'has_room_conflict' => $conflicting->isNotEmpty(),
            'conflicting_bookings' => $conflicting->map(fn (RoomAssignment $c): array => [
                'booking_id' => $c->booking_id,
                'booking_code' => $c->booking?->booking_code,
                'customer_name' => $c->booking?->customer_name,
                'assignment_id' => $c->id,
                'start_at' => $c->start_at?->format('Y-m-d H:i'),
                'end_at' => $c->end_at?->format('Y-m-d H:i'),
            ])->values(),
            'pending_special_requests' => $pendingRequestCount,
            'actions' => $this->actionFlags($room, $primary, $stay, $user),
        ];
    }

    /**
     * Pre-Commit Critical Safety Closure (Blocker #2, Mục VIII-XII): checks
     * EVERY pair among $assignments for a genuine time overlap — not merely
     * each one against $primary. Returns every assignment (other than
     * $primary, which is already shown as the room's `occupant`) that
     * participates in at least one true-overlapping pair with ANY other
     * assignment in the set — so a B-C overlap is reported even when $primary
     * (say, A) has no overlap with either of them. Deduplicated by
     * booking_id (Mục XII item 9).
     *
     * O(n²) pair scan is intentional (Mục IX): correctness over
     * micro-optimization — the number of assignments touching one room on
     * one day is always small in practice.
     *
     * @param Collection<int, RoomAssignment> $assignments
     * @return Collection<int, RoomAssignment>
     */
    private function conflictingAssignments(Collection $assignments, ?RoomAssignment $primary): Collection
    {
        $list = $assignments->values();
        $conflictedIds = [];

        for ($i = 0; $i < $list->count(); $i++) {
            for ($j = $i + 1; $j < $list->count(); $j++) {
                /** @var RoomAssignment $a */
                $a = $list->get($i);
                /** @var RoomAssignment $b */
                $b = $list->get($j);

                if ($a->start_at < $b->end_at && $b->start_at < $a->end_at) {
                    $conflictedIds[$a->id] = true;
                    $conflictedIds[$b->id] = true;
                }
            }
        }

        return $list
            ->filter(fn (RoomAssignment $a): bool => isset($conflictedIds[$a->id]) && $a->id !== $primary?->id)
            ->unique('booking_id')
            ->values();
    }

    /**
     * Mục XXVII: per-room action capability flags. Business eligibility AND
     * the acting user's existing Spatie permission — never one without the
     * other, and never a frontend-guessed rule.
     */
    private function actionFlags($room, ?RoomAssignment $assignment, ?Stay $stay, User $user): array
    {
        $isUnavailable = $this->rules->isRoomUnavailable($room);

        // Mục IX: swap is only allowed BEFORE check-in — an already checked-in
        // stay must use the existing, separate StayService::moveRoom() flow.
        $canSwap = $assignment !== null && $assignment->status !== AssignmentStatus::CheckedIn;
        $reasonNoSwap = $assignment === null
            ? 'Phòng chưa có booking để đổi.'
            : ($assignment->status === AssignmentStatus::CheckedIn ? 'Khách đã nhận phòng — dùng chức năng Chuyển phòng hiện có.' : null);

        $canCheckIn = $stay !== null && $stay->status === StayStatus::Reserved && $assignment?->status === AssignmentStatus::Assigned;
        $canCheckOut = $stay !== null && $stay->status === StayStatus::CheckedIn;
        // Product Owner UI/Inspection Corrections: checkout inspection is a
        // PRE-checkout workflow — CheckoutInspectionController::index() itself
        // only ever lists stays with status CheckedIn. The prior gate here
        // (CheckedOut) made "Kiểm đồ" always ineligible on the board and
        // silently hid the inspection warning step before checkout — this was
        // the root cause of the reported "checkout without warning" bug.
        //
        // Inspection Financial Correction follow-up: a Completed inspection is
        // no longer a dead end — CheckoutInspectionService::editCompleted()
        // allows correcting it up until the stay actually checks out. Gating
        // this purely on "not yet completed" reintroduced the same class of
        // bug (button permanently disabled once inspected, even though the
        // record is still editable) — status CheckedIn already implies
        // actual_checkout_at is null, so it alone is the correct condition;
        // getOrCreateDraft()/the modal already handle none/draft/completed
        // correctly once opened.
        $canInspect = $stay !== null && $stay->status === StayStatus::CheckedIn;
        $canClean = ! $isUnavailable;

        return [
            'can_swap' => $canSwap && $user->can('room.assign'),
            'reason_not_swap' => $canSwap ? null : $reasonNoSwap,
            'can_check_in' => $canCheckIn && $user->can('stay.checkin'),
            'reason_not_check_in' => $canCheckIn ? null : 'Phòng chưa ở trạng thái chờ nhận phòng.',
            'can_check_out' => $canCheckOut && $user->can('stay.checkout'),
            'reason_not_check_out' => $canCheckOut ? null : 'Phòng chưa nhận phòng hoặc đã trả phòng.',
            'can_inspect' => $canInspect && $user->can('checkout_inspection.perform'),
            'reason_not_inspect' => $canInspect ? null : 'Phòng chưa nhận phòng hoặc đã trả phòng.',
            'can_clean' => $canClean && $user->can('room.cleaning.update'),
            'reason_not_clean' => $canClean ? null : 'Phòng đang bảo trì/ngừng phục vụ.',
        ];
    }

    /**
     * Fast Inspection Popup & Compact Room Card UX (Mục X-XII): the PRIMARY
     * room/occupancy status — one of exactly 5 states, unchanged from the
     * original hex-keyed statusColor() this replaces (same classification,
     * same 5 cases — unavailable / vacant-clean / vacant-dirty / assigned /
     * checked-in). Returned as a semantic key, not a color, so the frontend
     * (the single place allowed to own Tailwind class strings, matching the
     * existing roomStatusBadges.js convention elsewhere in this codebase)
     * maps it to a full-card tint instead of the old border-left-only accent.
     * No second color palette — same 5 states, new rendering only.
     */
    private function statusTheme($room, ?RoomAssignment $assignment): string
    {
        if ($this->rules->isRoomUnavailable($room)) {
            return 'unavailable';
        }

        if ($assignment === null) {
            return $room->isRoomClean() ? 'vacant_clean' : 'vacant_dirty';
        }

        return $assignment->status === AssignmentStatus::CheckedIn ? 'checked_in' : 'assigned';
    }

    /**
     * Mục XIX/XXI: "Ghép giường" (twin_to_double) canonical lookup, keyed by
     * stay_id — a room only shows the badge for ITS OWN linked request, never
     * for every room of the booking. Cancelled requests are excluded (Mục
     * XXV keeps Fulfilled/Pending/Acknowledged all visible — cancellation is
     * the only terminal state that means "no longer relevant"). Requests
     * with stay_id still NULL (ambiguous — ADR: SpecialRequestService::
     * autoLinkSingleStayRequests() only resolves this automatically for a
     * single-active-stay booking) are intentionally excluded here — they
     * cannot be safely attributed to one room and must not be guessed onto
     * any of them (Mục XXIII); see ambiguousBedJoinCount() for surfacing
     * that state instead.
     *
     * @param  int[]  $bookingIds
     * @return Collection<int, BookingSpecialRequest> keyed by stay_id
     */
    private function bedJoinRequestsByStayId(array $bookingIds): Collection
    {
        if ($bookingIds === []) {
            return collect();
        }

        return BookingSpecialRequest::whereIn('booking_id', $bookingIds)
            ->where('category', 'bed_config')
            ->where('request_type', 'twin_to_double')
            ->where('status', '!=', RequestStatus::Cancelled->value)
            ->whereNotNull('stay_id')
            ->get()
            ->keyBy('stay_id');
    }

    private function boardTotals(Collection $floors): array
    {
        $allRooms = $floors->flatMap(fn (array $f) => $f['rooms']);

        return [
            'total_rooms' => $allRooms->count(),
            'assigned_rooms' => $allRooms->filter(fn (array $r) => $r['occupant'] !== null)->count(),
            'checked_in' => $allRooms->filter(fn (array $r) => $r['occupant']['is_checked_in'] ?? false)->count(),
            'not_yet_checked_in' => $allRooms->filter(fn (array $r) => $r['occupant'] !== null && ! ($r['occupant']['is_checked_in'] ?? false))->count(),
            'checked_out_today' => $allRooms->filter(fn (array $r) => $r['occupant']['is_checked_out'] ?? false)->count(),
            'dirty_rooms' => $allRooms->filter(fn (array $r) => ! $r['is_clean'])->count(),
            'not_yet_inspected' => $allRooms->filter(fn (array $r) => ($r['occupant']['is_checked_out'] ?? false) && ($r['occupant']['inspection_status'] ?? 'none') !== 'completed')->count(),
            // Room-Conflict Detection follow-up: headline count so a double-
            // booking is visible at the top of the board, not only buried on
            // one room card among many.
            'conflicted_rooms' => $allRooms->filter(fn (array $r) => $r['has_room_conflict'] ?? false)->count(),
        ];
    }
}
