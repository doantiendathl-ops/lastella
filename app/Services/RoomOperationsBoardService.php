<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\RequestStatus;
use App\Enums\ServiceFulfillmentStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingService;
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
 *  - extra bed           → merged from TWO sources, keyed by stay_id, ADDED together (User
 *                          request, 2026-08-19 chat — display-only fix, does not touch
 *                          billing): legacy room_assignments.extra_bed_quantity (Room-Scoped
 *                          Bed Operations Correction; was BookingPackageFlag, booking-level,
 *                          which could not identify which room in a multi-room booking and
 *                          also caused a per-room over-posting bug — see ExtraBedPostingJob;
 *                          note this column has no reachable input anywhere in the UI today,
 *                          its only writer is never wired to a route) UNION BookingService
 *                          rows for the new catalog's EXTRA_BED_PER_NIGHT Service, created via
 *                          the new "Dịch vụ & Yêu cầu" screen — the actual way staff add extra
 *                          beds now. See extraBedQuantityByStayId() for the exact merge. This
 *                          combined total is display-only; it does NOT feed either
 *                          ExtraBedPostingJob or UnifiedServicePostingJob, so it carries no
 *                          double-billing risk.
 *  - bed joined           → merged from TWO sources, keyed by stay_id (Unified Services &
 *                          Requests, Slice 3): legacy BookingSpecialRequest (category=
 *                          bed_config, request_type=twin_to_double) — unchanged, staff can
 *                          still create these via the old "Yêu cầu đặc biệt" panel — UNION
 *                          BookingService rows for the new catalog's TWIN_TO_DOUBLE Service,
 *                          created via the new "Dịch vụ & Yêu cầu" screen. Neither source was
 *                          deprecated; this is the "introduce → verify" step (Mục 1 of
 *                          docs/yeucaumoi.txt), not yet "switch runtime". See
 *                          bedJoinRequestsByStayId() for the exact merge.
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
        private readonly BusinessDateService $businessDate,
    ) {
    }

    /**
     * docs/yeucaumoi.txt mục 11 — Historical Room Map: a genuinely PAST
     * business date must resolve occupancy from the assignment's INTERVAL,
     * not live operational status — a booking that has since CheckedOut
     * still occupied this room that day and must still render. Today/future
     * stays on the live operational filter (Assigned/CheckedIn only) so a
     * guest who already checked out TODAY correctly shows the room as free
     * right now.
     *
     * Released is deliberately NEVER included, historical or not:
     * releaseAssignmentWithinTransaction() only ever transitions an
     * Assigned row (never CheckedIn) and requires actual_checkin_at to
     * still be null — by construction, a Released assignment never had a
     * guest physically in the room. It is the same "never occupied" case
     * as Cancelled/NoShow (mục 11's own example), just a different status
     * name; rendering it as historically-occupied would be wrong.
     *
     * Uses the same BusinessDateService as Night Audit (mục 27 — one
     * occupancy resolver semantics, not two disagreeing "today"s).
     */
    private function eligibleAssignmentStatuses(CarbonInterface $dateStart): array
    {
        $isHistoricalView = $dateStart->lt($this->businessDate->currentBusinessDate());

        return $isHistoricalView
            ? [AssignmentStatus::Assigned, AssignmentStatus::CheckedIn, AssignmentStatus::CheckedOut]
            : [AssignmentStatus::Assigned, AssignmentStatus::CheckedIn];
    }

    public function boardForDate(CarbonInterface|string $date, User $user): array
    {
        $day = $date instanceof CarbonInterface ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();
        $dateStart = $day->copy();
        $dateEnd = $day->copy()->addDay();

        $assignments = RoomAssignment::query()
            ->whereIn('status', $this->eligibleAssignmentStatuses($dateStart))
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
        $extraBedQuantityByStayId = $this->extraBedQuantityByStayId($bookingIds);
        // User request (2026-08-20 chat) — needed so a BOOKING-scoped Service
        // enrollment (room_assignment_id null) can be attached to every stay
        // of that booking currently on the board, same "applies to every
        // stay" rule bedJoinRequestsByStayId()'s docblock already documents.
        $bookingIdToStayIds = $assignments->flatten()
            ->filter(fn (RoomAssignment $a): bool => $a->stay !== null)
            ->groupBy('booking_id')
            ->map(fn (Collection $group) => $group->pluck('stay.id')->unique()->values());
        $otherServicesByStayId = $this->otherServicesByStayId($bookingIds, $bookingIdToStayIds);
        // User request (2026-08-23 chat) — A4 print redesign needs the actual
        // CONTENT of each stay's special requests, not just the badge count
        // pendingRequestCounts below already provides. See its docblock.
        $specialRequestsByStayId = $this->specialRequestsByStayId($bookingIds);

        $pendingRequestCounts = BookingSpecialRequest::pendingCountByRoom(
            \App\Models\Room::query()->pluck('id')->all(),
        );

        $floors = Floor::query()
            ->whereHas('rooms')
            ->orderBy('sort_order')
            ->with(['rooms' => fn ($q) => $q->with('roomType')->orderBy('room_number')])
            ->get()
            ->map(function (Floor $floor) use ($assignments, $bedJoinByStayId, $extraBedQuantityByStayId, $otherServicesByStayId, $specialRequestsByStayId, $user, $pendingRequestCounts): array {
                return [
                    'id' => $floor->id,
                    'code' => $floor->code,
                    'name' => $floor->name,
                    'rooms' => $floor->rooms->map(
                        fn ($room) => $this->buildRoomCell($room, $assignments->get($room->id, collect()), $bedJoinByStayId, $extraBedQuantityByStayId, $otherServicesByStayId, $specialRequestsByStayId, $user, $pendingRequestCounts[$room->id] ?? 0),
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
                // Unified Services & Requests, Slice 3 — see bedJoinRequestsByStayId()'s
                // docblock for why TWIN_TO_DOUBLE is merged in alongside specialRequests.
                'bookingServices.service',
                'bookingServices.roomAssignment.stay',
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
            // .toBase(): see bedJoinRequestsByStayId()'s docblock — Eloquent Collection's
            // merge()/unique() assume Model items once the underlying collection is that
            // subtype, even after map() turns the values into plain strings.
            $legacyBedJoinResolved = $bedJoinRequests->whereNotNull('stay_id')
                ->map(fn (BookingSpecialRequest $r) => $activeStays->firstWhere('id', $r->stay_id)?->room?->room_number)
                ->filter()
                ->toBase();
            // Legacy-only: the new TWIN_TO_DOUBLE Service is scope=ROOM (see
            // UnifiedRequestCatalogSeeder), so a unified row can never be "unresolved".
            $bedJoinUnresolvedCount = $bedJoinRequests->whereNull('stay_id')->count();

            $unifiedBedJoinResolved = $booking->bookingServices
                ->filter(fn (BookingService $bs): bool => $bs->service->code === 'TWIN_TO_DOUBLE'
                    && $bs->fulfillment_status !== ServiceFulfillmentStatus::Cancelled)
                ->map(fn (BookingService $bs) => $bs->roomAssignment?->stay?->id !== null
                    ? $activeStays->firstWhere('id', $bs->roomAssignment->stay->id)?->room?->room_number
                    : null)
                ->filter()
                ->toBase();

            $bedJoinResolved = $legacyBedJoinResolved->merge($unifiedBedJoinResolved)->unique()->values();

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

    private function buildRoomCell($room, Collection $roomAssignments, Collection $bedJoinByStayId, Collection $extraBedQuantityByStayId, Collection $otherServicesByStayId, Collection $specialRequestsByStayId, User $user, int $pendingRequestCount): array
    {
        $ordered = $roomAssignments->sortBy([
            fn ($a, $b) => ($b->status === AssignmentStatus::CheckedIn ? 1 : 0) <=> ($a->status === AssignmentStatus::CheckedIn ? 1 : 0),
            fn ($a, $b) => $a->start_at <=> $b->start_at,
        ])->values();

        /** @var RoomAssignment|null $primary */
        $primary = $ordered->first();
        $stay = $primary?->stay;

        $inspectionStatus = $stay?->inspectionStatus() ?? 'none';
        /** @var array{status: string, status_label: string}|null $bedJoinRequest */
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
                // docs/yeucaumoi.txt mục 7 — Room Tile background; reuses the
                // Booking's own stored color, no second palette.
                'booking_color' => $primary->booking?->booking_color,
                'assignment_id' => $primary->id,
                'stay_id' => $stay?->id,
                'assignment_status' => $primary->status->value,
                'is_checked_in' => $stay?->actual_checkin_at !== null,
                'is_checked_out' => $stay?->actual_checkout_at !== null,
                // User request (2026-08-18 chat) — raw values so the board's
                // ADMIN-only "edit already-recorded actual time" dialog can
                // pre-fill, same convention (Y-m-d H:i) RoomBoardPanel.vue
                // already uses for the identical field on the booking-detail page.
                'actual_checkin_at' => $stay?->actual_checkin_at?->format('Y-m-d H:i'),
                'actual_checkout_at' => $stay?->actual_checkout_at?->format('Y-m-d H:i'),
                'bed_join' => $bedJoinRequest,
                // User request (2026-08-19 chat) — legacy column + new Dịch vụ & Yêu cầu
                // enrollment ADDED together; see extraBedQuantityByStayId() docblock.
                'extra_bed_quantity' => $primary->extra_bed_quantity
                    + ($stay !== null ? ($extraBedQuantityByStayId->get($stay->id) ?? 0) : 0),
                // User request (2026-08-20 chat) — every OTHER active Dịch vụ &
                // Yêu cầu enrollment (i.e. everything except the TWIN_TO_DOUBLE/
                // EXTRA_BED_PER_NIGHT ones already surfaced as their own badge
                // above), summarized above the quick-note box, click opens a
                // popup list + a link to the booking's own Dịch vụ & Yêu cầu page.
                'other_services' => $stay !== null ? ($otherServicesByStayId->get($stay->id, collect())->values()->all()) : [],
                // User request (2026-08-23 chat) — A4 print redesign's "Yêu cầu
                // đặc biệt" field: see specialRequestsByStayId()'s docblock.
                'special_requests' => $stay !== null ? ($specialRequestsByStayId->get($stay->id, collect())->values()->all()) : [],
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
     * Unified Services & Requests, Slice 3: merges the legacy
     * BookingSpecialRequest source with the new catalog's BookingService
     * rows for the TWIN_TO_DOUBLE Service (hard-coded code match — a
     * deliberate, narrow, documented compatibility bridge between the old
     * and new "Ghép giường" entry points, not a new general pattern; see
     * UnifiedRequestCatalogSeeder's class docblock for the full reasoning
     * and why this is the one exception to Mục 25's "never hard-code a
     * service code" rule). Both entries are normalized to the same plain
     * array shape ({status, status_label}) so buildRoomCell() never needs
     * to know which of the two models a given room's badge came from.
     *
     * @param  int[]  $bookingIds
     * @return Collection<int, array{status: string, status_label: string}> keyed by stay_id
     */
    private function bedJoinRequestsByStayId(array $bookingIds): Collection
    {
        if ($bookingIds === []) {
            return collect();
        }

        // .toBase(): mapWithKeys() on an Eloquent Collection still returns an
        // Eloquent Collection even once the values are plain arrays, not
        // Models — and Eloquent Collection::merge()/unique() without a key
        // assume Model items and call ->getKey() on them, fatal-erroring on
        // a plain array. Drop to the base Support Collection before merging.
        $legacy = BookingSpecialRequest::whereIn('booking_id', $bookingIds)
            ->where('category', 'bed_config')
            ->where('request_type', 'twin_to_double')
            ->where('status', '!=', RequestStatus::Cancelled->value)
            ->whereNotNull('stay_id')
            ->get()
            ->mapWithKeys(fn (BookingSpecialRequest $r): array => [
                $r->stay_id => ['status' => $r->status->value, 'status_label' => $r->status->label()],
            ])
            ->toBase();

        $unified = BookingService::whereIn('booking_id', $bookingIds)
            ->whereHas('service', fn ($q) => $q->where('code', 'TWIN_TO_DOUBLE'))
            // "!= Cancelled" (not an allowlist of the other 3 statuses) — matches the
            // legacy query above and dailySummaryForDate()'s equivalent check, so a
            // future 5th ServiceFulfillmentStatus case stays visible by default instead
            // of silently disappearing from the board until this array is updated.
            ->where('fulfillment_status', '!=', ServiceFulfillmentStatus::Cancelled->value)
            ->whereNotNull('room_assignment_id')
            ->with('roomAssignment.stay')
            ->get()
            ->filter(fn (BookingService $bs): bool => $bs->roomAssignment?->stay !== null)
            ->mapWithKeys(fn (BookingService $bs): array => [
                $bs->roomAssignment->stay->id => [
                    'status' => $bs->fulfillment_status->value,
                    'status_label' => $bs->fulfillment_status->label(),
                ],
            ])
            ->toBase();

        // union(), NOT merge(): stay_id keys are integers, and Collection::merge()
        // treats integer keys as list items to append/renumber (PHP array_merge
        // semantics), silently discarding the stay_id keying entirely. union()
        // preserves the base collection's keys and values on conflict — exactly
        // "legacy wins" — and fills in any additional keys only unified has.
        return $legacy->union($unified);
    }

    /**
     * User request (2026-08-19 chat) — the board's "Giường phụ" icon only
     * ever read room_assignments.extra_bed_quantity, but that column has no
     * reachable input in the UI (its only writer,
     * PackageEnrollmentService::updateExtraBedRoomQuantities(), is never
     * wired to a route) — staff actually add "Giường phụ" through the new
     * Dịch vụ & Yêu cầu screen, which creates a BookingService row (Service
     * code EXTRA_BED_PER_NIGHT) instead, a source this board never read, so
     * the icon silently never lit up for bookings using that flow.
     *
     * Unlike bedJoinRequestsByStayId() ("legacy wins" on conflict — a
     * status, not summable), this ADDS both sources per stay: the legacy
     * column is de facto always 0 in production today (no UI path sets it),
     * so summing is a safe superset with no realistic double-counting risk,
     * and avoids having to decide which source is "authoritative". This
     * figure is DISPLAY-ONLY — it does not feed ExtraBedPostingJob or
     * UnifiedServicePostingJob, so it carries no double-billing risk.
     *
     * @param  int[]  $bookingIds
     * @return Collection<int, int> quantity keyed by stay_id
     */
    private function extraBedQuantityByStayId(array $bookingIds): Collection
    {
        if ($bookingIds === []) {
            return collect();
        }

        return BookingService::whereIn('booking_id', $bookingIds)
            ->whereHas('service', fn ($q) => $q->where('code', 'EXTRA_BED_PER_NIGHT'))
            ->where('fulfillment_status', '!=', ServiceFulfillmentStatus::Cancelled->value)
            ->whereNotNull('room_assignment_id')
            ->with('roomAssignment.stay')
            ->get()
            ->filter(fn (BookingService $bs): bool => $bs->roomAssignment?->stay !== null)
            ->groupBy(fn (BookingService $bs) => $bs->roomAssignment->stay->id)
            ->map(fn (Collection $rows): int => (int) $rows->sum('quantity'));
    }

    /**
     * User request (2026-08-20 chat) — "ngoài các yêu cầu hiển thị trực tiếp
     * trên ô Phòng" (bed join, extra bed — each has its own dedicated badge)
     * "thì các yêu cầu và dịch vụ khác" — every other active BookingService
     * enrollment, summarized into a click-to-expand popup instead of its own
     * badge. ROOM-scoped rows attach only to their own stay; BOOKING-scoped
     * rows (room_assignment_id null) attach to every stay of that booking
     * currently on the board — same rule bedJoinRequestsByStayId() already
     * documents for TWIN_TO_DOUBLE.
     *
     * Deliberately does NOT include legacy BookingSpecialRequest rows (the
     * pre-Unified-Services "yêu cầu đặc biệt" checklist, still surfaced
     * separately via $pendingRequestCount): there is no longer any UI to
     * create new ones (superseded by the Unified Request Catalog), so they
     * are historical-only data, not part of the active service-management
     * workflow this popup's "Xem" link opens into (/admin/bookings/{id}/
     * services never manages BookingSpecialRequest rows).
     *
     * @param  int[]  $bookingIds
     * @param  Collection<int, Collection<int, int>>  $bookingIdToStayIds
     * @return Collection<int, array<int, array{id: int, name: string, category_name: ?string, quantity: int, unit_label: ?string, status: string, status_label: string}>> keyed by stay_id
     */
    private function otherServicesByStayId(array $bookingIds, Collection $bookingIdToStayIds): Collection
    {
        if ($bookingIds === []) {
            return collect();
        }

        $excludedCodes = ['TWIN_TO_DOUBLE', 'EXTRA_BED_PER_NIGHT'];

        $rows = BookingService::whereIn('booking_id', $bookingIds)
            ->whereHas('service', fn ($q) => $q->whereNotIn('code', $excludedCodes))
            ->where('fulfillment_status', '!=', ServiceFulfillmentStatus::Cancelled->value)
            ->with(['service.category', 'roomAssignment.stay'])
            ->get();

        $byStayId = collect();
        $appendTo = function (int $stayId, array $item) use ($byStayId): void {
            $byStayId->put($stayId, $byStayId->get($stayId, collect())->push($item));
        };

        foreach ($rows as $bs) {
            $item = [
                'id' => $bs->id,
                'name' => $bs->service->name,
                'category_name' => $bs->service->category?->name,
                'quantity' => $bs->quantity,
                'unit_label' => $bs->service->unit_label,
                'status' => $bs->fulfillment_status->value,
                'status_label' => $bs->fulfillment_status->label(),
            ];

            if ($bs->room_assignment_id !== null) {
                $stayId = $bs->roomAssignment?->stay?->id;
                if ($stayId !== null) {
                    $appendTo($stayId, $item);
                }

                continue;
            }

            foreach ($bookingIdToStayIds->get($bs->booking_id, collect()) as $stayId) {
                $appendTo($stayId, $item);
            }
        }

        return $byStayId;
    }

    /**
     * User request (2026-08-23 chat) — the A4 print view's "Yêu cầu đặc
     * biệt" field must spell out the actual CONTENT of each stay's special
     * requests ("ghép giường/tách giường/giường phụ/..."), not just the
     * pendingRequestCounts() badge total the interactive board already uses.
     *
     * Legacy BookingSpecialRequest rows only — the Unified Request Catalog's
     * equivalents are read separately via bedJoinRequestsByStayId() (bed
     * join)/extraBedQuantityByStayId() (extra bed)/otherServicesByStayId()
     * (everything else), each already merged into the room cell above.
     * Excludes Cancelled (no longer relevant, same rule every other method
     * in this file uses) and excludes the bed_config/twin_to_double pair
     * specifically — that one is already the dedicated `bed_join` badge and
     * would otherwise be listed twice.
     *
     * request_type is a free-text column, not an enum (see
     * BookingSpecialRequest model) — requestTypeLabel() below is a
     * best-effort Vietnamese label for every code the old "Yêu cầu đặc
     * biệt" creation panel ever offered (mirrors RoomBoardPanel.vue's
     * REQUEST_TYPE_EMOJI map, the one other place these codes are
     * interpreted); any code not in that map still renders via a humanized
     * fallback instead of silently showing nothing.
     *
     * @param  int[]  $bookingIds
     * @return Collection<int, array<int, array{request_type: string, label: string, note: ?string, status: string, status_label: string}>> keyed by stay_id
     */
    private function specialRequestsByStayId(array $bookingIds): Collection
    {
        if ($bookingIds === []) {
            return collect();
        }

        return BookingSpecialRequest::whereIn('booking_id', $bookingIds)
            ->whereNotNull('stay_id')
            ->where('status', '!=', RequestStatus::Cancelled->value)
            ->where(fn ($q) => $q->where('category', '!=', 'bed_config')
                ->orWhere('request_type', '!=', 'twin_to_double'))
            ->get()
            ->groupBy('stay_id')
            ->map(fn (Collection $rows) => $rows->map(fn (BookingSpecialRequest $r): array => [
                'request_type' => $r->request_type,
                'label' => $this->requestTypeLabel($r->request_type),
                'note' => $r->note,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
            ])->values());
    }

    /** See specialRequestsByStayId()'s docblock. */
    private function requestTypeLabel(string $requestType): string
    {
        return self::REQUEST_TYPE_LABELS[$requestType] ?? ucfirst(str_replace('_', ' ', $requestType));
    }

    /** Mirrors RoomBoardPanel.vue's REQUEST_TYPE_EMOJI key set — keep both in sync. */
    private const REQUEST_TYPE_LABELS = [
        'twin_keep' => 'Giữ 2 giường đơn',
        'twin_to_double' => 'Ghép giường',
        'separate_beds' => 'Tách giường',
        'extra_bed' => 'Giường phụ',
        'baby_cot' => 'Nôi em bé',
        'extra_pillow' => 'Thêm gối',
        'non_feather_pillow' => 'Gối không lông vũ',
        'extra_blanket' => 'Thêm chăn',
        'extra_towel' => 'Thêm khăn',
        'welcome_fruit' => 'Trái cây chào mừng',
        'welcome_amenity' => 'Quà chào mừng',
        'anniversary' => 'Kỷ niệm ngày cưới',
        'honeymoon' => 'Trăng mật',
        'birthday' => 'Sinh nhật',
        'vip_setup' => 'Setup VIP',
        'flower_arrangement' => 'Trang trí hoa',
        'wheelchair' => 'Hỗ trợ xe lăn',
        'non_smoking_prep' => 'Phòng không khói thuốc',
        'ground_floor' => 'Tầng trệt',
        'near_elevator' => 'Gần thang máy',
        'late_arrival' => 'Đến muộn',
        'airport_pickup' => 'Đón sân bay',
        'connecting_room' => 'Phòng thông nhau',
        'other' => 'Khác',
    ];

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
