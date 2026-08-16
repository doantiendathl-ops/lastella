<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\RequestStatus;
use App\Enums\StayEventType;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomSwapBatch;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily Room Operations Board — ĐỔI PHÒNG (Room Swap) engine.
 *
 * ARCHITECTURE DECISION (spec Mục XIV — CRITICAL DATA-MODEL CHECK):
 * RoomAssignment/Stay do NOT support in-place partial-interval segmentation
 * — one row is one continuous room+date range, and stays.room_assignment_id
 * is UNIQUE (strict 1:1). CONCLUSION: DATA MODEL SUPPORTS SAFE
 * IMPLEMENTATION = YES, via "release the old assignment(s) + create new
 * assignment(s)/stay(s) for every resulting date sub-segment", built
 * entirely from the existing RoomAssignmentService::releaseAssignment() and
 * StayService::createStayFromAssignment() primitives. This is safe
 * specifically because:
 *   - Mục IX restricts swap SOURCES to assignments not yet checked in
 *     (status must be ASSIGNED).
 *   - Mục XVI hard-blocks any TARGET-room assignment that IS checked in.
 * Together these guarantee every RoomAssignment/Stay row this engine ever
 * releases-and-recreates is still Reserved/Assigned with no irreversible
 * history (no actual_checkin_at, no posted FolioEntry attribution, no
 * CheckoutInspection) — releasing and recreating it loses nothing.
 *
 * Never touches: booking identity, customer identity, folio, payments,
 * room_price / booking_requirement_id (Commercial Source Principle — the
 * same invariant StayService::moveRoom() already enforces), or housekeeping
 * cleanliness (physical-room state, untouched by a booking-side swap).
 */
class RoomSwapService
{
    public function __construct(
        private readonly RoomAssignmentService $assignments,
        private readonly StayService $stays,
        private readonly BookingService $bookings,
        private readonly RoomAvailabilityRuleService $rules,
        private readonly StayEventService $stayEvents,
        private readonly SpecialRequestService $specialRequests,
    ) {
    }

    /**
     * Read-only conflict analyzer (Mục XV). Never writes to the database.
     *
     * @param  array<int, array{source_assignment_id:int, target_room_id:int}>  $pairs
     */
    public function preview(array $pairs): array
    {
        $results = [];
        $hasBlockers = false;
        $hasWarnings = false;

        foreach ($pairs as $pair) {
            $result = $this->analyzePair($pair, lock: false);
            $results[] = $result;
            $hasBlockers = $hasBlockers || $result['blockers'] !== [];
            $hasWarnings = $hasWarnings || $result['warnings'] !== [];
        }

        return [
            'pairs' => $results,
            'has_blockers' => $hasBlockers,
            'has_warnings' => $hasWarnings,
        ];
    }

    /**
     * Transactional, all-or-nothing execute (Mục XIX/XXI). Re-locks and
     * re-evaluates every pair from scratch inside the transaction — the
     * preview above is never trusted as a guarantee (Mục XLIII "stale state").
     *
     * @param  array<int, array{source_assignment_id:int, target_room_id:int}>  $pairs
     */
    public function execute(array $pairs, User $actor, bool $warningsAcknowledged): array
    {
        return DB::transaction(function () use ($pairs, $actor, $warningsAcknowledged): array {
            // Deterministic lock order (Mục XIX): every Room referenced by the
            // batch (source rooms ∪ target rooms), id ascending, locked for the
            // WHOLE transaction. Every writer in this codebase that creates a
            // RoomAssignment for a room locks that Room row first
            // (RoomAssignmentService::lockRoomRecheckAndCreateAssignment) — so
            // holding every involved Room's lock here serializes concurrent
            // assign/release/swap attempts on the same rooms, matching the
            // established convention rather than inventing a new one.
            $roomIds = $this->involvedRoomIds($pairs);
            Room::whereIn('id', $roomIds)->orderBy('id')->lockForUpdate()->get();

            $batch = null;
            $results = [];
            $hasWarnings = false;
            $touchedBookingIds = [];

            foreach ($pairs as $pair) {
                $result = $this->analyzePair($pair, lock: true);

                if ($result['blockers'] !== []) {
                    // Whole batch rolls back — no partial commit (Mục XXI).
                    throw ValidationException::withMessages(['pairs' => $result['blockers']]);
                }

                $hasWarnings = $hasWarnings || $result['warnings'] !== [];
                $results[] = $result;
            }

            if ($hasWarnings && ! $warningsAcknowledged) {
                throw ValidationException::withMessages([
                    'warnings_acknowledged' => ['Vui lòng xác nhận các cảnh báo trước khi thực hiện đổi phòng.'],
                ]);
            }

            $batch = RoomSwapBatch::create([
                'executed_by' => $actor->id,
                'pairs_summary' => $results,
                'warnings_acknowledged' => $warningsAcknowledged,
                'executed_at' => now(),
            ]);

            // Two-phase per pair (release everything vacating a room, THEN
            // create everything occupying a room) — a displaced assignment on
            // the target room must be released BEFORE the source's new
            // assignment is created there, or the source's own conflict
            // check would see the still-Assigned displaced row as a false
            // conflict on the very room it is about to vacate.
            foreach ($results as $result) {
                $sourceAssignment = RoomAssignment::whereKey($result['source']['assignment_id'])->lockForUpdate()->firstOrFail();
                $touchedBookingIds[$sourceAssignment->booking_id] = true;

                $releasedSource = $this->releaseForSwap($sourceAssignment, $batch, 'Đổi phòng (Sơ đồ thao tác)');

                $releasedDisplaced = [];
                foreach ($result['displaced'] as $displaced) {
                    $touchedBookingIds[$displaced['booking_id']] = true;
                    $displacedAssignment = RoomAssignment::whereKey($displaced['assignment_id'])->lockForUpdate()->firstOrFail();
                    $this->releaseForSwap($displacedAssignment, $batch, 'Đổi phòng (Sơ đồ thao tác) — ảnh hưởng bởi thao tác đổi phòng khác');
                    $releasedDisplaced[] = ['result' => $displaced, 'original' => $displacedAssignment];
                }

                $this->createSourceMoveAssignment($sourceAssignment, $result['target']['room_id'], $batch, $actor);

                foreach ($releasedDisplaced as $entry) {
                    $this->createDisplacementSegments($entry['result'], $entry['original'], $sourceAssignment, $batch, $actor);
                }
            }

            foreach (array_keys($touchedBookingIds) as $bookingId) {
                $booking = Booking::find($bookingId);
                if ($booking !== null) {
                    $this->bookings->updateBookingAssignmentStatus($booking);
                }
            }

            return ['batch_id' => $batch->id, 'pairs' => $results];
        });
    }

    /**
     * Analyzes ONE source→target pair. Shared by preview() (lock:false, plain
     * reads) and execute() (lock:true, SELECT ... FOR UPDATE on every row
     * touched) so the two paths can never drift apart (Mục XLIII).
     */
    private function analyzePair(array $pair, bool $lock): array
    {
        $blockers = [];
        $warnings = [];

        $sourceQuery = RoomAssignment::query()->with(['booking:id,booking_code,customer_name,status', 'room.roomType']);
        $source = $lock
            ? $sourceQuery->whereKey($pair['source_assignment_id'])->lockForUpdate()->first()
            : $sourceQuery->whereKey($pair['source_assignment_id'])->first();

        if ($source === null) {
            return $this->blockedResult($pair, ['Không tìm thấy phòng nguồn đã chọn.']);
        }

        if ($source->status !== AssignmentStatus::Assigned) {
            $blockers[] = match ($source->status) {
                AssignmentStatus::CheckedIn => "Phòng {$source->room->room_number}: khách đã nhận phòng — không thể đổi phòng từ Sơ đồ thao tác (dùng chức năng Chuyển phòng hiện có).",
                default => "Phòng {$source->room->room_number}: trạng thái phân phòng không hợp lệ để đổi phòng.",
            };
        }

        if (in_array($source->booking->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
            $blockers[] = "Booking {$source->booking->booking_code} đã hủy/không đến — không thể đổi phòng.";
        }

        $targetRoom = Room::with('roomType')->find($pair['target_room_id']);

        if ($targetRoom === null) {
            return $this->blockedResult($pair, ['Không tìm thấy phòng đích.']);
        }

        if ($targetRoom->id === $source->room_id) {
            $blockers[] = 'Phòng đích trùng phòng nguồn.';
        }

        if ($this->rules->isRoomUnavailable($targetRoom)) {
            $blockers[] = "Phòng {$targetRoom->room_number}: không khả dụng (bảo trì/ngừng phục vụ).";
        }

        $displacedQuery = RoomAssignment::query()
            ->where('room_id', $targetRoom->id)
            ->whereIn('status', [AssignmentStatus::Assigned, AssignmentStatus::CheckedIn])
            ->where('id', '!=', $source->id)
            ->where('start_at', '<', $source->end_at)
            ->where('end_at', '>', $source->start_at)
            ->with('booking:id,booking_code,customer_name,status')
            ->orderBy('start_at');

        $displacedAssignments = $lock ? $displacedQuery->lockForUpdate()->get() : $displacedQuery->get();

        $displacedResults = [];

        foreach ($displacedAssignments as $displaced) {
            if ($displaced->status === AssignmentStatus::CheckedIn) {
                $blockers[] = "Booking {$displaced->booking->booking_code} đã nhận phòng {$targetRoom->room_number} — không thể đổi phòng đè lên.";

                continue;
            }

            $overlapStart = $displaced->start_at->max($source->start_at);
            $overlapEnd = $displaced->end_at->min($source->end_at);

            $displacedResults[] = [
                'assignment_id' => $displaced->id,
                'booking_id' => $displaced->booking_id,
                'booking_code' => $displaced->booking->booking_code,
                'customer_name' => $displaced->booking->customer_name,
                'original_start_at' => $displaced->start_at->format('Y-m-d H:i'),
                'original_end_at' => $displaced->end_at->format('Y-m-d H:i'),
                'moved_start_at' => $overlapStart->format('Y-m-d H:i'),
                'moved_end_at' => $overlapEnd->format('Y-m-d H:i'),
            ];
        }

        if ($displacedResults !== []) {
            $bookingCodes = collect($displacedResults)->pluck('booking_code')->unique()->implode(', ');
            $warnings[] = "Phòng đích {$targetRoom->room_number} đang được sử dụng bởi booking khác trong thời gian lưu trú. Thao tác này sẽ ảnh hưởng: {$bookingCodes}.";
        }

        if ($targetRoom->room_type_id !== $source->room->room_type_id) {
            $warnings[] = "Phòng đích {$targetRoom->room_number} khác loại phòng vật lý so với phòng nguồn {$source->room->room_number}. Giá phòng của booking không thay đổi.";
        }

        if ($source->quick_note !== null || $source->extra_bed_quantity > 0) {
            $warnings[] = "Ghi chú nhanh/giường phụ của phòng {$source->room->room_number} sẽ được chuyển theo booking sang phòng {$targetRoom->room_number}.";
        }

        return [
            'source' => [
                'assignment_id' => $source->id,
                'room_id' => $source->room_id,
                'room_number' => $source->room->room_number,
                'booking_id' => $source->booking_id,
                'booking_code' => $source->booking->booking_code,
                'customer_name' => $source->booking->customer_name,
                'start_at' => $source->start_at->format('Y-m-d H:i'),
                'end_at' => $source->end_at->format('Y-m-d H:i'),
            ],
            'target' => [
                'room_id' => $targetRoom->id,
                'room_number' => $targetRoom->room_number,
                'room_type' => $targetRoom->roomType?->code,
            ],
            'is_move' => $displacedResults === [],
            'displaced' => $displacedResults,
            'warnings' => $warnings,
            'blockers' => $blockers,
        ];
    }

    private function blockedResult(array $pair, array $blockers): array
    {
        return [
            'source' => ['assignment_id' => $pair['source_assignment_id'] ?? null],
            'target' => ['room_id' => $pair['target_room_id'] ?? null],
            'is_move' => false,
            'displaced' => [],
            'warnings' => [],
            'blockers' => $blockers,
        ];
    }

    /**
     * Phase 1 (release) — shared by the source assignment and every displaced
     * assignment. Kept as its own step, run for the WHOLE pair before any
     * phase-2 create, so a room a displaced booking is about to vacate is
     * never mistaken for a live conflict when the source's new assignment is
     * created there (and vice versa for the source's own vacated room).
     */
    private function releaseForSwap(RoomAssignment $assignment, RoomSwapBatch $batch, string $reason): RoomAssignment
    {
        $released = $this->assignments->releaseAssignment($assignment, $reason);
        $released->update(['swap_batch_id' => $batch->id]);

        return $released;
    }

    /**
     * Phase 2 (create) — moves the source booking's assignment onto the
     * target room for its ENTIRE swapped range (Mục XII — whole-stay rule).
     * room_type_id and booking_requirement_id are copied verbatim from the
     * released assignment — never re-derived from the new physical room —
     * matching the Commercial Source Principle StayService::moveRoom()
     * already enforces (Mục XVIII: never re-price on swap).
     */
    private function createSourceMoveAssignment(RoomAssignment $source, int $targetRoomId, RoomSwapBatch $batch, User $actor): void
    {
        $newStay = $this->createSplitSegment($source, $targetRoomId, $source->start_at, $source->end_at, $batch, $actor, 'board_swap_source');

        // Mục XIV/XXI/XXVI: Ghép giường/Special Requests are booking/guest
        // operational data, not physical-room state — they must follow the
        // booking to its new room, never stay attached to the vacated one.
        // The source assignment covers its WHOLE stay in one new segment
        // (Mục XII), so relinking is unambiguous here.
        $this->relinkSpecialRequests($source, $newStay);
        $this->relinkUnifiedServices($source, $newStay);
    }

    /**
     * Phase 2 (create) — splits a displaced third-party assignment (Mục XIII)
     * into up to three segments: the leading remainder (still in the target
     * room, if any), the overlapping segment (moved onto the source's
     * vacated room), and the trailing remainder (still in the target room,
     * if any). Only the overlapping sub-range ever moves — never the whole
     * displaced stay.
     */
    private function createDisplacementSegments(array $displacedResult, RoomAssignment $displacedOriginal, RoomAssignment $source, RoomSwapBatch $batch, User $actor): void
    {
        $targetRoomId = $displacedOriginal->room_id;
        $overlapStart = Carbon::parse($displacedResult['moved_start_at']);
        $overlapEnd = Carbon::parse($displacedResult['moved_end_at']);

        if ($displacedOriginal->start_at->lt($overlapStart)) {
            $this->createSplitSegment($displacedOriginal, $targetRoomId, $displacedOriginal->start_at, $overlapStart, $batch, $actor, 'displaced_remain_before');
        }

        // The "swap" segment is the one that actually changes room — it is
        // the segment the displaced booking's special requests (Ghép giường
        // etc.) follow to, mirroring Mục XXVI for the third party as well as
        // the source booking. The remain-before/after segments stay in the
        // SAME physical room the guest was already in, so nothing to relink
        // there.
        $swapStay = $this->createSplitSegment($displacedOriginal, $source->room_id, $overlapStart, $overlapEnd, $batch, $actor, 'displaced_swap');
        $this->relinkSpecialRequests($displacedOriginal, $swapStay);
        $this->relinkUnifiedServices($displacedOriginal, $swapStay);

        if ($overlapEnd->lt($displacedOriginal->end_at)) {
            $this->createSplitSegment($displacedOriginal, $targetRoomId, $overlapEnd, $displacedOriginal->end_at, $batch, $actor, 'displaced_remain_after');
        }
    }

    /**
     * Mục XXVI: reuses the EXISTING SpecialRequestService::linkToStay() —
     * never a new relinking mechanism — to rebind every non-terminal
     * special request from the OLD stay (about to be orphaned by the
     * release) onto the NEW stay the swap just created. Cancelled requests
     * are left alone (they are workflow-terminal, not "still relevant").
     * MUST NOT throw — a relink failure must never abort an otherwise valid
     * swap; logged by the service it delegates to, same discipline as
     * autoLinkSingleStayRequests().
     */
    private function relinkSpecialRequests(RoomAssignment $originalAssignment, Stay $newStay): void
    {
        $oldStay = $originalAssignment->stay;

        if ($oldStay === null || $oldStay->id === $newStay->id) {
            return;
        }

        $requests = \App\Models\BookingSpecialRequest::where('stay_id', $oldStay->id)
            ->where('status', '!=', RequestStatus::Cancelled->value)
            ->get();

        foreach ($requests as $request) {
            try {
                $this->specialRequests->linkToStay($request, $newStay);
            } catch (\Throwable) {
                // Never abort the swap over a relink failure.
            }
        }
    }

    /**
     * Unified Services & Requests (docs/yeucaumoi.txt, Slice 3) — the exact
     * same "must follow the booking to its new room" principle as
     * relinkSpecialRequests() above, applied to the new catalog. Unlike
     * BookingSpecialRequest (linked by stay_id, which survives untouched
     * here — only the Stay's OWN room_assignment_id FK moves), BookingService
     * rows are linked by room_assignment_id directly (required for Slice 1's
     * per-room billing correctness), so a swap that creates a brand-new
     * RoomAssignment+Stay pair (see class docblock's ARCHITECTURE DECISION)
     * leaves them silently pointing at the now-released old assignment
     * unless explicitly rebound here. Safe to do unconditionally: swap
     * sources are always pre-check-in (status ASSIGNED — enforced by Mục IX
     * below), so there is never a posted FolioEntry attributed to the old
     * assignment_id that this could disturb.
     */
    private function relinkUnifiedServices(RoomAssignment $originalAssignment, Stay $newStay): void
    {
        if ($newStay->room_assignment_id === null || $originalAssignment->id === $newStay->room_assignment_id) {
            return;
        }

        \App\Models\BookingService::where('room_assignment_id', $originalAssignment->id)
            ->where('fulfillment_status', '!=', \App\Enums\ServiceFulfillmentStatus::Cancelled->value)
            ->update(['room_assignment_id' => $newStay->room_assignment_id]);
    }

    private function createSplitSegment(RoomAssignment $original, int $roomId, Carbon $startAt, Carbon $endAt, RoomSwapBatch $batch, User $actor, string $reason): Stay
    {
        if ($this->rules->hasConflict($roomId, $startAt, $endAt)) {
            throw ValidationException::withMessages([
                'pairs' => ["Phòng #{$roomId} vừa có xung đột lịch mới trong lúc xử lý. Không có thay đổi nào được lưu."],
            ]);
        }

        $segment = RoomAssignment::create([
            'booking_id' => $original->booking_id,
            'room_id' => $roomId,
            'room_type_id' => $original->room_type_id,
            'booking_requirement_id' => $original->booking_requirement_id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $actor->id,
            'quick_note' => $original->quick_note,
            'extra_bed_quantity' => $original->extra_bed_quantity,
            'swap_batch_id' => $batch->id,
        ]);

        $stay = $this->stays->createStayFromAssignment($segment);

        $this->stayEvents->record($stay, StayEventType::RoomMove, $actor, [
            'swap_batch_id' => $batch->id,
            'from_room_id' => $original->room_id,
            'to_room_id' => $roomId,
            'reason' => $reason,
        ]);

        return $stay;
    }

    /**
     * @param  array<int, array{source_assignment_id:int, target_room_id:int}>  $pairs
     * @return int[]
     */
    private function involvedRoomIds(array $pairs): array
    {
        $sourceRoomIds = RoomAssignment::whereIn('id', collect($pairs)->pluck('source_assignment_id')->unique())
            ->pluck('room_id')->all();
        $targetRoomIds = collect($pairs)->pluck('target_room_id')->unique()->all();

        return collect($sourceRoomIds)->merge($targetRoomIds)->unique()->sort()->values()->all();
    }
}
