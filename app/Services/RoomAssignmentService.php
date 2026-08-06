<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Stay;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoomAssignmentService
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly RoomAvailabilityRuleService $rules,
        private readonly RoomRequirementAllocationService $allocation,
        private readonly StayService $stays,
    ) {
    }

    /**
     * Legacy entry point — public signature, input/output and exception behaviour
     * are UNCHANGED (Room Demand/Room Board Unification M2, Implementation Plan
     * Mục IX). Internally refactored to call the shared private helper instead of
     * inlining the lock/recheck/create loop, but never sets
     * `booking_requirement_id` — callers of this method get exactly the same
     * assignments they always did. Only `assignRoomsWithRequirementLink()`
     * (below) is wired to the demand-first controller endpoint; every other
     * existing call site of this method (tests, seeders, other services) is
     * untouched and keeps working identically.
     */
    public function assignRooms(Booking $booking, array $assignments): array
    {
        // Sort by room_id ascending to acquire locks in deterministic order and avoid deadlocks.
        usort($assignments, fn (array $a, array $b): int => $a['room_id'] <=> $b['room_id']);

        return DB::transaction(function () use ($booking, $assignments): array {
            $created = [];

            foreach ($assignments as $assignment) {
                $created[] = $this->lockRoomRecheckAndCreateAssignment($booking, $assignment);
            }

            $this->bookings->updateBookingAssignmentStatus($booking);

            return $created;
        });
    }

    /**
     * Room Demand/Room Board Unification M2 — the ONLY place that locks a Room
     * row, rechecks availability/conflict, and creates a RoomAssignment. Shared
     * by assignRooms() (legacy, booking_requirement_id always null) and
     * assignRoomsWithRequirementLink() (below) so the two paths can never drift
     * apart. Does not open its own transaction — the caller's DB::transaction()
     * governs it. booking_requirement_id is written in the SAME create() call,
     * never patched onto the row afterwards.
     */
    private function lockRoomRecheckAndCreateAssignment(
        Booking $booking,
        array $assignment,
        ?int $bookingRequirementId = null,
    ): RoomAssignment {
        // Lock the room row to prevent concurrent double assignment.
        $room = Room::whereKey($assignment['room_id'])->lockForUpdate()->firstOrFail();
        $startAt = $assignment['start_at'] ?? $booking->checkin_at;
        $endAt = $assignment['end_at'] ?? $booking->checkout_at;

        if ($this->rules->hasConflict($room->id, $startAt, $endAt)) {
            throw ValidationException::withMessages([
                'room_id' => 'Phòng đã có booking khác trong khoảng thời gian này.',
            ]);
        }

        return RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $assignment['room_type_id'] ?? $room->room_type_id,
            'booking_requirement_id' => $bookingRequirementId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => Auth::id(),
        ]);
    }

    /**
     * Room Demand/Room Board Unification M2 — atomic demand-first assignment:
     * every RoomAssignment created here gets its booking_requirement_id set in
     * the SAME transaction, never in a second transaction afterwards. Wired to
     * RoomAssignmentController::store() (the existing demand-first endpoint) —
     * no parallel endpoint.
     *
     * Lock order (Architecture Review REVISION 3 Mục 20 / Implementation Plan
     * Mục X): Booking → Room (all, id asc) → BookingRequirement (all, id asc)
     * → create RoomAssignment. Room and BookingRequirement are locked for the
     * WHOLE batch before any resolution decision or write happens, matching the
     * same "lock everything, then validate, then write" shape already used by
     * BookingService::validateTimeChange(). Requirement resolution for every
     * room_type in the batch must succeed before any assignment is created —
     * one failure rolls back the entire request (Architecture Review Mục VII.E).
     *
     * Eligibility rule for a requirement line (Architecture Review Mục VII.D):
     * belongs to this booking, room_type_id matches, quantity > 0 (a line
     * reduced to 0 is "hidden" per Product Owner Decision #18 — M2 never writes
     * that reduction, but must already treat such a line as ineligible).
     * BookingRequirement has no soft-delete/status column, so quantity > 0 is
     * the only eligibility signal that exists in the current schema.
     *
     * @param  array<string,int>  $targetRequirementIdByRoomType  room_type_id => booking_requirement_id, only required for room_types with more than one eligible line.
     */
    public function assignRoomsWithRequirementLink(
        Booking $booking,
        array $assignments,
        array $targetRequirementIdByRoomType = [],
    ): array {
        usort($assignments, fn (array $a, array $b): int => $a['room_id'] <=> $b['room_id']);

        return DB::transaction(function () use ($booking, $assignments, $targetRequirementIdByRoomType): array {
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            $roomIds = collect($assignments)->pluck('room_id')->unique()->sort()->values()->all();
            $lockedRoomsById = Room::whereIn('id', $roomIds)
                ->with('roomType:id,code')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $roomTypeIdsInBatch = collect($assignments)
                ->map(fn (array $assignment) => (int) ($assignment['room_type_id'] ?? $lockedRoomsById->get($assignment['room_id'])?->room_type_id))
                ->filter()
                ->unique()
                ->sort()
                ->values();

            // Lock every eligible line for the room_types in this batch, sorted
            // ascending — resolved BEFORE any assignment is created.
            $eligibleLinesByRoomType = BookingRequirement::where('booking_id', $lockedBooking->id)
                ->whereIn('room_type_id', $roomTypeIdsInBatch)
                ->where('quantity', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('room_type_id');

            $resolvedRequirementIdByRoomType = [];

            foreach ($roomTypeIdsInBatch as $roomTypeId) {
                $eligibleLines = $eligibleLinesByRoomType->get($roomTypeId, collect());
                $roomTypeCode = $lockedRoomsById->firstWhere('room_type_id', $roomTypeId)?->roomType?->code ?? (string) $roomTypeId;

                if ($eligibleLines->isEmpty()) {
                    throw ValidationException::withMessages([
                        "target_requirement_id.$roomTypeId" => "Loại phòng {$roomTypeCode} chưa có dòng nhu cầu phù hợp. Hãy thêm nhu cầu phòng trước khi phân phòng.",
                    ]);
                }

                $target = $targetRequirementIdByRoomType[$roomTypeId] ?? null;

                if ($target === null) {
                    if ($eligibleLines->count() === 1) {
                        $resolvedRequirementIdByRoomType[$roomTypeId] = $eligibleLines->first()->id;

                        continue;
                    }

                    throw ValidationException::withMessages([
                        "target_requirement_id.$roomTypeId" => "Loại phòng {$roomTypeCode} có nhiều dòng nhu cầu. Vui lòng chọn dòng nhu cầu phù hợp trước khi phân phòng.",
                    ]);
                }

                // Ownership + room_type match + eligibility are all encoded in the
                // locked $eligibleLines set above — never trust the frontend-sent ID
                // on its own (Architecture Review Mục VII.D).
                $matched = $eligibleLines->firstWhere('id', (int) $target);

                if ($matched === null) {
                    throw ValidationException::withMessages([
                        "target_requirement_id.$roomTypeId" => "Dòng nhu cầu được chọn không hợp lệ cho loại phòng {$roomTypeCode}.",
                    ]);
                }

                $resolvedRequirementIdByRoomType[$roomTypeId] = $matched->id;
            }

            $created = [];

            foreach ($assignments as $assignment) {
                $roomTypeId = (int) ($assignment['room_type_id'] ?? $lockedRoomsById->get($assignment['room_id'])?->room_type_id);

                $created[] = $this->lockRoomRecheckAndCreateAssignment(
                    $lockedBooking,
                    $assignment,
                    $resolvedRequirementIdByRoomType[$roomTypeId] ?? null,
                );
            }

            $this->bookings->updateBookingAssignmentStatus($lockedBooking);

            return $created;
        });
    }

    /**
     * Room Demand/Room Board Unification M3 — Room-Board-first reverse
     * synchronization. Selecting rooms directly on the Room Board creates or
     * increases the matching BookingRequirement line(s) AND the
     * RoomAssignment rows AND the Stay rows, all inside ONE transaction — no
     * second transaction, no partial success (Architecture Review REVISION 3
     * Mục 14b/18/19-21; Implementation Plan Mục XII).
     *
     * Reuses, never duplicates:
     *  - lockRoomRecheckAndCreateAssignment() for every RoomAssignment (same
     *    helper as assignRooms()/assignRoomsWithRequirementLink()).
     *  - RoomRequirementAllocationService::reconcileRoomType() for the
     *    excess_to_add formula (never `quantity += selected_count`).
     *  - RoomRequirementAllocationService::planLineAllocation() for the
     *    "which line" decision (single/ambiguous/none/folio-locked) — the
     *    SAME decision table already used and tested since Milestone 1.
     *  - BookingService::addRequirement()/updateRequirement() for every
     *    BookingRequirement write, so Room-Board-first and demand-first go
     *    through the identical guarded application core (Product Owner
     *    Decision #3) — never a raw $requirement->update()/::create() here.
     *  - StayService::createStayFromAssignment() — called INSIDE this
     *    transaction (unlike the demand-first flow, which intentionally
     *    keeps it outside — Mục XIII). Verified DB-only, no dispatched
     *    event/job, safe to include.
     *
     * Lock order (Implementation Plan Mục XII): Booking → Room (all, id asc)
     * → BookingRequirement (all eligible, id asc) → create/increase
     * BookingRequirement → create RoomAssignment → create Stay.
     *
     * @param  array<int, array{room_type_id:int, room_ids:int[], target_requirement_id:?int, room_price:?float, price_source:?string, note:?string, adults:?int, children_under_6:?int, children_over_6:?int}>  $groups
     * @return array{assignments: RoomAssignment[], stays: Stay[]}
     */
    public function assignRoomsFromRoomBoard(
        Booking $booking,
        array $groups,
        CarbonInterface|string $startAt,
        CarbonInterface|string $endAt,
    ): array {
        return DB::transaction(function () use ($booking, $groups, $startAt, $endAt): array {
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            $this->assertBookingAcceptsRoomBoardAssignment($lockedBooking);

            $allRoomIds = collect($groups)->flatMap(fn (array $group): array => $group['room_ids'])
                ->unique()->sort()->values()->all();

            $lockedRoomsById = Room::whereIn('id', $allRoomIds)
                ->with('roomType:id,code')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($allRoomIds as $roomId) {
                $room = $lockedRoomsById->get($roomId);

                if ($room === null) {
                    throw ValidationException::withMessages([
                        'room_ids' => 'Một phòng đã chọn không còn tồn tại.',
                    ]);
                }

                if ($this->rules->isRoomUnavailable($room)) {
                    throw ValidationException::withMessages([
                        'room_ids' => "Phòng {$room->room_number} không khả dụng (bảo trì/ngừng phục vụ).",
                    ]);
                }
            }

            // Room-type in every group is re-derived from the LOCKED Room rows,
            // never trusted from the frontend payload (Implementation Plan Mục XI).
            foreach ($groups as $group) {
                foreach ($group['room_ids'] as $roomId) {
                    $actualRoomTypeId = $lockedRoomsById->get($roomId)?->room_type_id;

                    if ($actualRoomTypeId !== $group['room_type_id']) {
                        throw ValidationException::withMessages([
                            'groups' => "Phòng đã chọn không thuộc đúng loại phòng của nhóm (room_type_id={$group['room_type_id']}).",
                        ]);
                    }
                }
            }

            $bookingWideFolioLocked = $this->bookings->hasActiveRoomCharge($lockedBooking);

            $roomTypeIds = collect($groups)->pluck('room_type_id')->unique()->sort()->values();

            // Lock every eligible (quantity > 0) line for every room_type in this
            // request, sorted ascending — resolved BEFORE any requirement or
            // assignment write happens (same "lock everything, then decide, then
            // write" shape as assignRoomsWithRequirementLink()).
            $eligibleLinesByRoomType = BookingRequirement::where('booking_id', $lockedBooking->id)
                ->whereIn('room_type_id', $roomTypeIds)
                ->where('quantity', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('room_type_id');

            $createdAssignments = [];
            $createdStays = [];

            foreach ($groups as $group) {
                [$groupAssignments, $groupStays] = $this->resolveAndApplyRoomBoardGroup(
                    $lockedBooking,
                    $group,
                    $eligibleLinesByRoomType->get($group['room_type_id'], collect()),
                    $bookingWideFolioLocked,
                    $lockedRoomsById,
                    $startAt,
                    $endAt,
                );

                array_push($createdAssignments, ...$groupAssignments);
                array_push($createdStays, ...$groupStays);
            }

            $this->bookings->updateBookingAssignmentStatus($lockedBooking);

            return ['assignments' => $createdAssignments, 'stays' => $createdStays];
        });
    }

    /**
     * Room Demand/Room Board Unification M3: resolves the target requirement
     * line(s) for ONE room_type group, creates/increases BookingRequirement
     * as needed, then creates the RoomAssignment + Stay rows for every room
     * in the group. Never called outside the transaction opened by
     * assignRoomsFromRoomBoard().
     *
     * @return array{0: RoomAssignment[], 1: Stay[]}
     */
    private function resolveAndApplyRoomBoardGroup(
        Booking $lockedBooking,
        array $group,
        \Illuminate\Support\Collection $eligibleLines,
        bool $bookingWideFolioLocked,
        \Illuminate\Support\Collection $lockedRoomsById,
        CarbonInterface|string $startAt,
        CarbonInterface|string $endAt,
    ): array {
        $roomTypeId = $group['room_type_id'];
        $roomTypeCode = $lockedRoomsById->firstWhere('room_type_id', $roomTypeId)?->roomType?->code ?? (string) $roomTypeId;
        $selectedRoomIds = collect($group['room_ids'])->sort()->values();
        $selectedCount = $selectedRoomIds->count();

        $activeLinesInput = $eligibleLines->map(fn (BookingRequirement $line): array => [
            'id' => $line->id,
            'is_folio_locked' => $bookingWideFolioLocked,
        ])->all();

        $plan = $this->allocation->planLineAllocation($activeLinesInput, $group['target_requirement_id']);

        if ($plan['status'] === RoomRequirementAllocationService::STATUS_NEEDS_TARGET_SELECTION) {
            throw ValidationException::withMessages([
                "groups.{$roomTypeId}.target_requirement_id" => "Loại phòng {$roomTypeCode} có nhiều dòng nhu cầu. Vui lòng chọn dòng nhu cầu phù hợp.",
            ]);
        }

        if ($plan['status'] === RoomRequirementAllocationService::STATUS_INVALID_TARGET) {
            throw ValidationException::withMessages([
                "groups.{$roomTypeId}.target_requirement_id" => "Dòng nhu cầu được chọn không hợp lệ cho loại phòng {$roomTypeCode}.",
            ]);
        }

        // Resolve the line new assignments link to for the "within remaining
        // capacity" portion (never null when the plan found or targeted a line).
        $linkLineId = match ($plan['status']) {
            RoomRequirementAllocationService::STATUS_USE_EXISTING_LINE => $plan['requirement_id'],
            RoomRequirementAllocationService::STATUS_CREATE_NEW_LINE_FOLIO_LOCKED => $plan['reference_requirement_id'],
            default => null, // STATUS_CREATE_NEW_LINE — no existing line at all.
        };

        $targetLine = $linkLineId !== null ? $eligibleLines->firstWhere('id', $linkLineId) : null;

        // "Active/consumed" here MUST match getAssignmentSummary()'s existing
        // convention (Assigned + CheckedIn + CheckedOut), not the narrower
        // AssignmentStatus::activeValues() (Assigned + CheckedIn only) — a
        // checked-out room still consumed a unit of demand and must not be
        // double-counted as remaining capacity, matching Section VIII's
        // reconciliation formula and the room-type totals already shown in
        // props.assignmentSummary on the frontend.
        $lineRequired = $targetLine?->quantity ?? 0;
        $lineAssignedActive = $targetLine !== null
            ? RoomAssignment::where('booking_requirement_id', $targetLine->id)
                ->whereIn('status', [
                    AssignmentStatus::Assigned,
                    AssignmentStatus::CheckedIn,
                    AssignmentStatus::CheckedOut,
                ])
                ->count()
            : 0;

        $reconciliation = $this->allocation->reconcileRoomType($lineRequired, $lineAssignedActive, $selectedCount);
        $remaining = $reconciliation['remaining'];
        $excessToAdd = $reconciliation['excess_to_add'];

        $excessLineId = null;

        if ($excessToAdd > 0) {
            $canIncreaseTarget = $targetLine !== null
                && $plan['status'] === RoomRequirementAllocationService::STATUS_USE_EXISTING_LINE
                && $group['room_price'] !== null
                && $group['price_source'] !== null
                && $this->matchesRequirementMergeKey($targetLine, $group['room_price'], $group['price_source']);

            if ($canIncreaseTarget) {
                // Reuses BookingService::updateRequirement() — the SAME guarded
                // path demand-first uses, so RequirementLockedAfterRoomChargeException
                // can never be bypassed here (Product Owner Decision #13).
                $increased = $this->bookings->updateRequirement($targetLine, [
                    'quantity' => $targetLine->quantity + $excessToAdd,
                ]);
                $excessLineId = $increased->id;
            } else {
                $this->assertNewRequirementPayloadPresent($group, $roomTypeCode, $excessToAdd);

                // Reuses BookingService::addRequirement() — the SAME application
                // core demand-first uses to create a line (Product Owner Decision #3).
                $newLine = $this->bookings->addRequirement($lockedBooking, [
                    'room_type_id' => $roomTypeId,
                    'quantity' => $excessToAdd,
                    'adults' => $group['adults'],
                    'children_under_6' => $group['children_under_6'],
                    'children_over_6' => $group['children_over_6'],
                    'room_price' => $group['room_price'],
                    'price_source' => $group['price_source'],
                    'note' => $group['note'],
                ]);
                $excessLineId = $newLine->id;
            }
        }

        $withinCapacityCount = min($remaining, $selectedCount);
        $withinCapacityRoomIds = $selectedRoomIds->take($withinCapacityCount);
        $excessRoomIds = $selectedRoomIds->slice($withinCapacityCount);

        $groupAssignments = [];
        $groupStays = [];

        foreach ($withinCapacityRoomIds as $roomId) {
            $assignment = $this->lockRoomRecheckAndCreateAssignment(
                $lockedBooking,
                ['room_id' => $roomId, 'room_type_id' => $roomTypeId, 'start_at' => $startAt, 'end_at' => $endAt],
                $targetLine?->id,
            );
            $groupAssignments[] = $assignment;
            $groupStays[] = $this->stays->createStayFromAssignment($assignment);
        }

        foreach ($excessRoomIds as $roomId) {
            $assignment = $this->lockRoomRecheckAndCreateAssignment(
                $lockedBooking,
                ['room_id' => $roomId, 'room_type_id' => $roomTypeId, 'start_at' => $startAt, 'end_at' => $endAt],
                $excessLineId,
            );
            $groupAssignments[] = $assignment;
            $groupStays[] = $this->stays->createStayFromAssignment($assignment);
        }

        return [$groupAssignments, $groupStays];
    }

    /**
     * Merge-key check for reusing an existing line's quantity (Architecture
     * Review Mục 14c / Implementation Plan Mục X.A): room_type_id is already
     * fixed by construction (the line came from this room_type's eligible
     * set); room_price and price_source must match EXACTLY. `note` is
     * deliberately excluded from the merge key (free-text field, must not
     * fragment demand lines) and is never rewritten onto the existing line.
     */
    private function matchesRequirementMergeKey(BookingRequirement $line, float $roomPrice, string $priceSource): bool
    {
        return bccomp((string) $line->room_price, number_format($roomPrice, 2, '.', ''), 2) === 0
            && $line->price_source?->value === $priceSource;
    }

    /**
     * Room Demand/Room Board Unification M3: when excess demand must become a
     * NEW requirement line, the price/source/guest fields are mandatory —
     * this covers the race-condition edge case where the frontend optimistically
     * predicted no excess (so it did not ask the user for pricing) but the
     * freshly-locked data under transaction says otherwise. Fails clearly
     * instead of writing a requirement with a 0/blank price.
     */
    private function assertNewRequirementPayloadPresent(array $group, string $roomTypeCode, int $excessToAdd): void
    {
        $roomTypeId = $group['room_type_id'];
        $missing = $group['room_price'] === null || $group['price_source'] === null
            || $group['adults'] === null || $group['children_under_6'] === null || $group['children_over_6'] === null;

        if ($missing) {
            throw ValidationException::withMessages([
                "groups.{$roomTypeId}.room_price" => "Loại phòng {$roomTypeCode} cần thêm {$excessToAdd} phòng vào nhu cầu — vui lòng nhập giá/nguồn giá/số khách trước khi xác nhận.",
            ]);
        }
    }

    /**
     * Room Demand/Room Board Unification M3 — State Matrix (Implementation
     * Plan Mục XIV): blocks Room-Board-first the same way the legacy
     * assignRooms()/assignRoomsWithRequirementLink() never had to, because
     * this is the first flow that can also mutate demand. isTerminal()
     * (Cancelled/NoShow/CheckedOut) is the existing BookingStatus helper;
     * PartiallyCheckedOut is added explicitly since it is not terminal but
     * must still block new assignments (booking is already mid-checkout).
     */
    private function assertBookingAcceptsRoomBoardAssignment(Booking $booking): void
    {
        if ($booking->status->isTerminal() || $booking->status === BookingStatus::PartiallyCheckedOut) {
            throw ValidationException::withMessages([
                'booking' => match ($booking->status) {
                    BookingStatus::Cancelled => 'Booking đã hủy. Không thể phân phòng.',
                    BookingStatus::NoShow => 'Booking không đến (No-Show). Không thể phân phòng.',
                    BookingStatus::CheckedOut => 'Booking đã trả phòng. Không thể phân thêm phòng.',
                    BookingStatus::PartiallyCheckedOut => 'Booking đang trong quá trình trả phòng. Không thể phân thêm phòng từ Sơ đồ phòng.',
                    default => 'Booking hiện không cho phép phân phòng.',
                },
            ]);
        }
    }

    public function releaseAssignment(RoomAssignment $assignment, ?string $reason = null): RoomAssignment
    {
        return DB::transaction(function () use ($assignment, $reason): RoomAssignment {
            // Match StayService and BookingService lock direction on shared rows.
            $stay = Stay::where('room_assignment_id', $assignment->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $locked = RoomAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AssignmentStatus::Assigned) {
                throw ValidationException::withMessages([
                    'assignment' => match ($locked->status) {
                        AssignmentStatus::Released => 'Phòng đã được giải phóng. Không thể giải phóng lại.',
                        AssignmentStatus::CheckedIn => 'Phòng đã nhận phòng thực tế. Vui lòng trả phòng trước khi giải phóng.',
                        AssignmentStatus::CheckedOut => 'Phòng đã hoàn thành lưu trú. Không thể giải phóng phòng lịch sử.',
                        default => 'Chỉ có thể giải phóng phòng đang ở trạng thái đã phân.',
                    },
                ]);
            }

            if ($stay === null) {
                $stay = Stay::where('room_assignment_id', $locked->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
            }

            if ($stay !== null && $stay->actual_checkin_at !== null) {
                throw ValidationException::withMessages([
                    'assignment' => 'Phòng đã nhận phòng thực tế. Vui lòng trả phòng trước khi giải phóng.',
                ]);
            }

            $locked->update([
                'status' => AssignmentStatus::Released,
                'released_by' => Auth::id(),
                'released_at' => now(),
                'release_reason' => $reason,
            ]);

            if ($stay !== null && $stay->status === StayStatus::Reserved) {
                $stay->update(['status' => StayStatus::Cancelled]);
            }

            $this->bookings->updateBookingAssignmentStatus($locked->booking);
            $this->bookings->updateBookingStayStatus($locked->booking);

            return $locked->refresh();
        });
    }

    public function checkRoomConflict(Room|int $room, CarbonInterface|string $startAt, CarbonInterface|string $endAt, ?int $ignoreAssignmentId = null): bool
    {
        $roomId = $room instanceof Room ? $room->id : $room;

        return $this->rules->hasConflict($roomId, $startAt, $endAt, $ignoreAssignmentId);
    }

    public function getAssignmentSummary(Booking $booking): array
    {
        $booking->loadMissing(['bookingRequirements.roomType', 'roomAssignments.roomType']);

        return $booking->bookingRequirements
            ->groupBy('room_type_id')
            ->map(function ($requirements, int $roomTypeId) use ($booking): array {
                $required = (int) $requirements->sum('quantity');
                $assigned = $booking->roomAssignments
                    ->where('room_type_id', $roomTypeId)
                    ->whereIn('status', [
                        AssignmentStatus::Assigned,
                        AssignmentStatus::CheckedIn,
                        AssignmentStatus::CheckedOut,
                    ])
                    ->count();

                $roomType = $requirements->first()->roomType;

                return [
                    'room_type_id' => $roomTypeId,
                    'room_type_code' => $roomType?->code,
                    'required' => $required,
                    'assigned' => $assigned,
                    'remaining' => max($required - $assigned, 0),
                ];
            })
            ->values()
            ->all();
    }

    public function getRoomBoard(Booking $booking): array
    {
        $requiredRoomTypeIds = $booking->bookingRequirements()
            ->pluck('room_type_id')
            ->unique()
            ->values();

        $blocking = $this->rules->getBlockingAssignments(
            $booking->checkin_at,
            $booking->checkout_at,
            $booking->id,
            filterCheckedInByDate: true,
        );

        // For the room board, we need one conflict per room (keyBy).
        // CheckedIn takes precedence over Assigned when both exist for the same room.
        $checkedInConflicts = $blocking['checked_in']->map->first()->keyBy('room_id');
        $reservedConflicts = $blocking['reserved']->map->first()->keyBy('room_id');
        $conflicts = $checkedInConflicts->union($reservedConflicts);

        $infoCheckedIn = RoomAssignment::query()
            ->with('booking:id,booking_code,customer_name,booking_color,status')
            ->where('booking_id', '!=', $booking->id)
            ->where('status', AssignmentStatus::CheckedIn)
            ->where(function ($q) use ($booking): void {
                $q->where('end_at', '<=', $booking->checkin_at)
                    ->orWhere('start_at', '>=', $booking->checkout_at);
            })
            ->get()
            ->groupBy('room_id')
            ->map->first();

        $currentAssignments = $booking->roomAssignments()
            ->with('booking:id,booking_code,customer_name')
            ->where(function ($query) use ($booking): void {
                // CheckedIn: always show — guest is physically present regardless of date drift.
                $query->where('status', AssignmentStatus::CheckedIn)
                    // Assigned: show only when planned range overlaps booking dates.
                    ->orWhere(function ($q) use ($booking): void {
                        $q->where('status', AssignmentStatus::Assigned)
                            ->where('start_at', '<', $booking->checkout_at)
                            ->where('end_at', '>', $booking->checkin_at);
                    });
            })
            ->get()
            ->keyBy('room_id');

        $floorsData = Floor::query()
            ->whereHas('rooms')
            ->with(['rooms' => fn ($query) => $query->with('roomType')->orderBy('room_number')])
            ->orderBy('sort_order')
            ->get()
            ->map(function (Floor $floor) use ($conflicts, $currentAssignments, $requiredRoomTypeIds, $infoCheckedIn): array {
                return [
                    'id' => $floor->id,
                    'code' => $floor->code,
                    'name' => $floor->name,
                    'rooms' => $floor->rooms->map(function (Room $room) use ($conflicts, $currentAssignments, $requiredRoomTypeIds, $infoCheckedIn): array {
                        $conflict = $conflicts->get($room->id);
                        $currentAssignment = $currentAssignments->get($room->id);
                        $infoAssignment = $infoCheckedIn->get($room->id);
                        $isUnavailable = $this->rules->isRoomUnavailable($room);
                        $isCurrentBookingAssigned = $currentAssignment !== null;
                        $matchesRequirement = $requiredRoomTypeIds->isEmpty()
                            || $requiredRoomTypeIds->contains($room->room_type_id);
                        $displayAssignment = $conflict ?? $currentAssignment;

                        $availabilityStatus = 'available';
                        $disabledReason = null;

                        if ($isUnavailable) {
                            $availabilityStatus = 'unavailable';
                            $disabledReason = 'Không khả dụng';
                        } elseif ($conflict !== null) {
                            $availabilityStatus = 'conflict';
                            $disabledReason = 'Đã có booking khác';
                        } elseif ($isCurrentBookingAssigned) {
                            $availabilityStatus = 'current_booking';
                            $disabledReason = 'Đã phân booking này';
                        }

                        return [
                            'id' => $room->id,
                            'room_number' => $room->room_number,
                            'room_type_id' => $room->room_type_id,
                            'room_type' => $room->roomType?->code,
                            'room_type_name' => $room->roomType?->name,
                            'status' => $room->status?->value,
                            'status_label' => $room->status?->label(),
                            'availability_status' => $availabilityStatus,
                            'disabled_reason' => $disabledReason,
                            'conflict_booking' => $conflict ? [
                                'id' => $conflict->booking?->id,
                                'code' => $conflict->booking?->booking_code,
                                'customer_name' => $conflict->booking?->customer_name,
                                'booking_color' => $this->bookingColor($conflict->booking),
                                'status' => $conflict->booking?->status?->value,
                                'assignment_id' => $conflict->id,
                                'is_assignment_locked' => $conflict->status === AssignmentStatus::CheckedIn,
                                'lock_reason' => $conflict->status === AssignmentStatus::CheckedIn
                                    ? 'Phòng đã nhận phòng thực tế. Vui lòng trả phòng trước khi giải phóng.'
                                    : null,
                                'can_view' => Auth::user()?->can('viewAny', Booking::class) ?? false,
                                'can_unassign_room' => $conflict->status !== AssignmentStatus::CheckedIn
                                    && (Auth::user()?->can('room.unassign') ?? false),
                            ] : null,
                            'current_assignment' => $currentAssignment ? [
                                'assigned_to_current_booking' => true,
                                'assignment_id' => $currentAssignment->id,
                                'assignment_status' => $currentAssignment->status?->value,
                                'is_assignment_locked' => $currentAssignment->status === AssignmentStatus::CheckedIn,
                                'lock_reason' => $currentAssignment->status === AssignmentStatus::CheckedIn
                                    ? 'Phòng đã nhận phòng thực tế. Vui lòng trả phòng trước khi giải phóng.'
                                    : null,
                                'can_release_assignment' => $currentAssignment->status !== AssignmentStatus::CheckedIn
                                    && (Auth::user()?->can('room.unassign') ?? false),
                            ] : null,
                            'assignment_detail' => $displayAssignment ? $this->roomBoardAssignmentPayload($displayAssignment) : null,
                            'matches_requirement' => $matchesRequirement,
                            'info_booking' => $infoAssignment && ! $conflict ? [
                                'booking_code' => $infoAssignment->booking?->booking_code,
                                'customer_name' => $infoAssignment->booking?->customer_name,
                                'booking_color' => $this->bookingColor($infoAssignment->booking),
                                'checkin_at' => $infoAssignment->start_at?->format('Y-m-d H:i'),
                                'checkout_at' => $infoAssignment->end_at?->format('Y-m-d H:i'),
                            ] : null,
                        ];
                    })->values(),
                ];
            })
            ->values();

        $allRooms = $floorsData->flatMap(fn ($floor) => $floor['rooms']);

        $roomTypeSummary = $requiredRoomTypeIds->map(function (int $roomTypeId) use ($allRooms): array {
            $roomsOfType = $allRooms->where('room_type_id', $roomTypeId);

            return [
                'room_type_id' => $roomTypeId,
                'room_type_code' => $roomsOfType->first()['room_type'] ?? null,
                'room_type_name' => $roomsOfType->first()['room_type_name'] ?? null,
                'total' => $roomsOfType->count(),
                'occupied' => $roomsOfType->whereIn('availability_status', ['conflict', 'unavailable'])->count(),
                'current_booking' => $roomsOfType->where('availability_status', 'current_booking')->count(),
                'remaining' => $roomsOfType->where('availability_status', 'available')->count(),
            ];
        })->values()->all();

        $allRoomTypeSummary = $allRooms
            ->filter(fn ($room) => $room['room_type_id'] !== null)
            ->groupBy('room_type_id')
            ->map(function ($roomsOfType): array {
                return [
                    'room_type_id' => $roomsOfType->first()['room_type_id'],
                    'room_type_code' => $roomsOfType->first()['room_type'] ?? null,
                    'room_type_name' => $roomsOfType->first()['room_type_name'] ?? null,
                    'total' => $roomsOfType->count(),
                    'occupied' => $roomsOfType->whereIn('availability_status', ['conflict', 'unavailable'])->count(),
                    'current_booking' => $roomsOfType->where('availability_status', 'current_booking')->count(),
                    'remaining' => $roomsOfType->where('availability_status', 'available')->count(),
                ];
            })
            ->sortBy('room_type_code')
            ->values()
            ->all();

        return [
            'floors' => $floorsData,
            'room_type_summary' => $roomTypeSummary,
            'all_room_type_summary' => $allRoomTypeSummary,
        ];
    }

    private function bookingColor(?Booking $booking): string
    {
        if ($booking?->booking_color) {
            return $booking->booking_color;
        }

        $palette = [
            '#8B5CF6', '#10B981', '#F59E0B', '#EF4444',
            '#3B82F6', '#EC4899', '#14B8A6', '#6366F1',
        ];

        return $palette[($booking?->id ?? 0) % count($palette)];
    }

    private function roomBoardAssignmentPayload(RoomAssignment $assignment): array
    {
        return [
            'booking_code' => $assignment->booking?->booking_code,
            'customer_name' => $assignment->booking?->customer_name,
            'checkin_at' => $assignment->start_at?->format('Y-m-d H:i'),
            'checkout_at' => $assignment->end_at?->format('Y-m-d H:i'),
            'status' => $assignment->status?->value,
        ];
    }
}
