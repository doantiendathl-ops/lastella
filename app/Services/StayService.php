<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\StayEventType;
use App\Enums\StayStatus;
use App\Exceptions\FinalCheckoutConfirmationRequiredException;
use App\Models\Booking;
use App\Models\FolioEntry;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Stay;
use App\Models\User;
use App\Services\Posting\EarlyCheckinFeePostingJob;
use App\Services\Posting\LateCheckoutFeePostingJob;
use App\Services\Posting\PostingContext;
use App\Services\Posting\RoomChargePostingJob;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StayService
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly FolioService $folios,
        private readonly BusinessDateService $businessDate,
        private readonly RoomChargePostingJob $roomChargeJob,
        private readonly LateCheckoutFeePostingJob $lateCheckoutJob,
        private readonly EarlyCheckinFeePostingJob $earlyCheckinJob,
        private readonly SpecialRequestService $specialRequests,
        private readonly HousekeepingService $housekeeping,
        private readonly StayEventService $stayEvents,
        private readonly RoomAvailabilityRuleService $rules,
        private readonly CheckoutInspectionService $checkoutInspections,
    ) {
    }

    public function createStayFromAssignment(RoomAssignment $assignment): Stay
    {
        /** @var Stay $stay */
        $stay = Stay::firstOrCreate(
            ['room_assignment_id' => $assignment->id],
            [
                'booking_id' => $assignment->booking_id,
                'room_id' => $assignment->room_id,
                'planned_checkin_at' => $assignment->start_at,
                'planned_checkout_at' => $assignment->end_at,
                'status' => StayStatus::Reserved,
            ],
        );

        // Phase 4.1: auto-link unlinked requests when booking has exactly one active stay.
        // autoLinkSingleStayRequests never throws; any failure is logged and silently skipped.
        $this->specialRequests->autoLinkSingleStayRequests($assignment->booking, $stay);

        return $stay->load(['booking', 'roomAssignment', 'room']);
    }

    public function checkIn(Stay $stay, CarbonInterface|string|null $actualCheckinAt = null): Stay
    {
        return DB::transaction(function () use ($stay, $actualCheckinAt): Stay {
            // ADR-38: Booking lock FIRST — canonical order: Booking → Stay → RoomAssignment.
            $lockedBooking = Booking::whereKey($stay->booking_id)->lockForUpdate()->firstOrFail();
            $lockedStay    = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $assignment    = RoomAssignment::whereKey($lockedStay->room_assignment_id)->lockForUpdate()->firstOrFail();

            if ($assignment->status !== AssignmentStatus::Assigned) {
                throw ValidationException::withMessages([
                    'stay' => 'Không thể nhận phòng. Trạng thái phân phòng không hợp lệ.',
                ]);
            }

            if ($lockedStay->actual_checkin_at !== null) {
                throw ValidationException::withMessages([
                    'stay' => 'Phòng đã được nhận phòng rồi.',
                ]);
            }

            // Early check-in is allowed (Active Pilot decision) — the guest may
            // arrive and be checked in before planned_checkin_at as long as the
            // room/stay/assignment guards above and below all still pass. Only
            // the "not yet time" gate was ever removed; every other guard
            // (assignment status, already-checked-in, room conflict via the
            // Assigned-status invariant, etc.) is unchanged.
            //
            // A resulting early-checkin fee (if the actual time is early enough
            // to cross the configured grace window) is still handled exactly as
            // before by EarlyCheckinFeePostingJob below — that job already keys
            // off actual_checkin_at vs planned_checkin_at, so removing this gate
            // does not change its logic, only how often it now actually fires.
            if ($actualCheckinAt !== null && Carbon::parse($actualCheckinAt)->gt(now())) {
                throw ValidationException::withMessages([
                    'actual_checkin_at' => 'Thời gian nhận phòng thực tế không được ở tương lai.',
                ]);
            }

            $lockedStay->update([
                'actual_checkin_at' => $actualCheckinAt ?? now(),
                'status'            => StayStatus::CheckedIn,
                'checked_in_by'     => Auth::id(),
            ]);

            $assignment->update([
                'status' => AssignmentStatus::CheckedIn,
            ]);

            $folio = $lockedBooking->folio;
            if ($folio !== null) {
                $context = new PostingContext(
                    booking:      $lockedBooking,
                    folio:        $folio,
                    businessDate: $this->businessDate->currentBusinessDate(),
                    stay:         $lockedStay,
                    postedBy:     Auth::user(),
                );
                $this->roomChargeJob->execute($context);
                $this->earlyCheckinJob->execute($context);
            }

            $this->bookings->updateBookingStayStatus($lockedBooking);

            // Phase 4.2: auto-mark room OCCUPIED on check-in.
            // autoMarkOccupied never throws — see HousekeepingService (ADR-84).
            $this->housekeeping->autoMarkOccupied($lockedStay);

            $this->stayEvents->record($lockedStay, StayEventType::CheckIn, Auth::user());

            return $lockedStay->refresh();
        });
    }

    public function checkOut(Stay $stay, CarbonInterface|string|null $actualCheckoutAt = null, bool $confirmed = false): Stay
    {
        return DB::transaction(function () use ($stay, $actualCheckoutAt, $confirmed): Stay {
            // ADR-38: Booking lock FIRST — canonical order: Booking → Stay → RoomAssignment.
            $lockedBooking = Booking::whereKey($stay->booking_id)->lockForUpdate()->firstOrFail();
            $lockedStay    = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $assignment    = RoomAssignment::whereKey($lockedStay->room_assignment_id)->lockForUpdate()->firstOrFail();

            if ($assignment->status !== AssignmentStatus::CheckedIn) {
                throw ValidationException::withMessages([
                    'stay' => 'Không thể trả phòng. Trạng thái phân phòng không hợp lệ.',
                ]);
            }

            if ($lockedStay->actual_checkin_at === null) {
                throw ValidationException::withMessages([
                    'stay' => 'Chưa nhận phòng nên không thể trả phòng.',
                ]);
            }

            if ($lockedStay->actual_checkout_at !== null) {
                throw ValidationException::withMessages([
                    'stay' => 'Phòng đã được trả phòng rồi.',
                ]);
            }

            if ($actualCheckoutAt !== null) {
                $parsedCheckoutAt = Carbon::parse($actualCheckoutAt);

                if ($parsedCheckoutAt->gt(now())) {
                    throw ValidationException::withMessages([
                        'actual_checkout_at' => 'Thời gian trả phòng thực tế không được ở tương lai.',
                    ]);
                }

                if ($parsedCheckoutAt->lt($lockedStay->actual_checkin_at)) {
                    throw ValidationException::withMessages([
                        'actual_checkout_at' => 'Thời gian trả phòng thực tế không được trước thời gian nhận phòng thực tế.',
                    ]);
                }
            }

            // ADR-55: Final checkout gate — determined pre-DML under the booking lock.
            // Count stays that will still be active after this one checks out.
            // Reserved stays count as active (ADR-49) so a Reserved sibling keeps this non-final.
            $remainingOtherActive = Stay::where('booking_id', $lockedBooking->id)
                ->whereIn('status', [StayStatus::Reserved, StayStatus::CheckedIn])
                ->where('id', '!=', $lockedStay->id)
                ->count();

            if ($remainingOtherActive === 0 && !$confirmed) {
                throw new FinalCheckoutConfirmationRequiredException();
            }

            $lockedStay->update([
                'actual_checkout_at' => $actualCheckoutAt ?? now(),
                'status'             => StayStatus::CheckedOut,
                'checked_out_by'     => Auth::id(),
            ]);

            $assignment->update([
                'status' => AssignmentStatus::CheckedOut,
            ]);

            $folio = $lockedBooking->folio;
            if ($folio !== null) {
                $lockedStay->refresh();
                $checkoutContext = new PostingContext(
                    booking:      $lockedBooking,
                    folio:        $folio,
                    businessDate: $this->businessDate->currentBusinessDate(),
                    stay:         $lockedStay,
                    postedBy:     Auth::user(),
                );
                $this->lateCheckoutJob->execute($checkoutContext);

                // Pre-Commit Critical Safety Closure (Blocker #1): this is the
                // "existing final posting point" a Completed checkout inspection's
                // projected charge waits for — same event-triggered, checkout-time
                // pattern as the late-checkout-fee job right above. Posts nothing
                // if the inspection is still Draft, was never started, or was
                // already posted (idempotent).
                $this->checkoutInspections->postCompletedChargesAtCheckout($lockedStay, Auth::user());

                // Night Audit pending-confirmation window (Phần 2): a checked-out
                // stay will never again pass through NightAuditPipeline::run() (it
                // only iterates CheckedIn stays), so there is no future "Tính lại"
                // opportunity for its postings — finalize them immediately rather
                // than waiting for the whole run to be confirmed at the next
                // midnight sweep. Entries outside the sweep (this stay's own
                // immediate room-charge post, ONE_TIME unified-service postings)
                // have night_audit_run_id === null and were already
                // immediate-immutable before this feature existed — untouched here.
                $this->finalizeStayNightAuditEntries($lockedStay);
            }

            // ADR-49: Active stay = Reserved OR CheckedIn (own DML visible within transaction).
            $remainingActive = Stay::where('booking_id', $lockedBooking->id)
                ->whereIn('status', [StayStatus::Reserved, StayStatus::CheckedIn])
                ->count();

            if ($remainingActive === 0) {
                // ADR-48: finalise runs inside caller's transaction; Booking lock already held.
                // docs/Prompt_2.txt mục X — audit trail: who/when already come from the
                // StayEvent row itself (actor + created_at); the outstanding balance at the
                // moment of checkout is the one additional fact worth capturing, so it rides
                // along in this same event instead of a second ledger/table.
                $outstandingBalanceAtCheckout = $this->bookings->finaliseBookingCheckout($lockedBooking);
                $this->stayEvents->record($lockedStay, StayEventType::Checkout, Auth::user(), [
                    'version' => 1,
                    'booking_id' => $lockedBooking->id,
                    'room_assignment_id' => $assignment->id,
                    'remaining_active_stays' => $remainingActive,
                    'outstanding_balance_at_checkout' => $outstandingBalanceAtCheckout,
                ]);
            } else {
                $this->bookings->updateBookingStayStatus($lockedBooking);
                $this->stayEvents->record($lockedStay, StayEventType::PartialCheckout, Auth::user(), [
                    'version' => 1,
                    'booking_id' => $lockedBooking->id,
                    'room_assignment_id' => $assignment->id,
                    'remaining_active_stays' => $remainingActive,
                ]);
            }

            // Phase 4.2: auto-mark room VACANT_DIRTY and create cleaning assignment on checkout.
            // autoMarkDirtyOnCheckout never throws — see HousekeepingService (ADR-84).
            $this->housekeeping->autoMarkDirtyOnCheckout($lockedStay);

            return $lockedStay->refresh();
        });
    }

    /**
     * Night Audit pending-confirmation window (Phần 2): marks every not-yet-void,
     * not-yet-finalized Night-Audit-sweep FolioEntry belonging to this stay as
     * finalized. Called at checkout time — see checkOut() call site for why a
     * checked-out stay must be finalized immediately instead of waiting for its
     * run's normal confirmation. Deliberately scoped to night_audit_run_id IS NOT
     * NULL: entries outside the sweep are unaffected (they never became
     * conditionally voidable in the first place — see FolioService::voidEntry()).
     */
    private function finalizeStayNightAuditEntries(Stay $stay): void
    {
        FolioEntry::where('stay_id', $stay->id)
            ->whereNotNull('night_audit_run_id')
            ->whereNull('finalized_at')
            ->whereNull('voided_at')
            ->update(['finalized_at' => now()]);
    }

    /**
     * Records an authorized, reasoned skip of the checkout inspection for a single stay.
     * Deliberately kept as a standalone action — never called from inside checkOut() —
     * so the core checkout transaction/guards remain completely unchanged and this
     * cannot regress check-in/checkout/partial-checkout/folio behavior. The frontend
     * calls this (when applicable) before submitting the normal, unmodified checkout
     * request; checkOut() itself is not aware this ever happened except by reading the
     * recorded columns for display purposes.
     */
    public function skipCheckoutInspection(Stay $stay, User $actor, string $reason): Stay
    {
        if (! $actor->can('checkout_inspection.override')) {
            throw new AuthorizationException('Bạn không có quyền bỏ qua kiểm đồ khi trả phòng.');
        }

        return DB::transaction(function () use ($stay, $actor, $reason): Stay {
            $lockedStay = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();

            if ($lockedStay->status !== StayStatus::CheckedIn) {
                throw ValidationException::withMessages([
                    'stay' => 'Chỉ có thể bỏ qua kiểm đồ cho phòng đang lưu trú.',
                ]);
            }

            $lockedStay->update([
                'inspection_skipped_at' => now(),
                'inspection_skipped_by' => $actor->id,
                'inspection_skip_reason' => $reason,
            ]);

            $this->stayEvents->record($lockedStay, StayEventType::InspectionSkipped, $actor, [
                'version' => 1,
                'reason' => $reason,
            ]);

            return $lockedStay->refresh();
        });
    }

    public function extendStay(Stay $stay, CarbonInterface|string $newPlannedCheckoutAt, User $actor): Stay
    {
        return DB::transaction(function () use ($stay, $newPlannedCheckoutAt, $actor): Stay {
            $newPlannedCheckoutAt = $newPlannedCheckoutAt instanceof CarbonInterface
                ? $newPlannedCheckoutAt
                : Carbon::parse($newPlannedCheckoutAt);

            // Room locked first (not Booking): this method never writes to Booking, and its
            // conflict check is scoped to a single Room, so Room is the relevant first lock —
            // unlike checkIn()/checkOut()'s Booking-first order (ADR-38), which applies to
            // methods that do write Booking-linked aggregate state.
            $lockedStay    = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $lockedRoom    = Room::whereKey($lockedStay->room_id)->lockForUpdate()->firstOrFail();
            $assignment    = RoomAssignment::whereKey($lockedStay->room_assignment_id)->lockForUpdate()->firstOrFail();

            if ($lockedStay->status !== StayStatus::CheckedIn) {
                throw ValidationException::withMessages([
                    'stay' => 'Chỉ có thể gia hạn lưu trú đang nhận phòng.',
                ]);
            }

            if (! $newPlannedCheckoutAt->gt($lockedStay->planned_checkout_at)) {
                throw ValidationException::withMessages([
                    'new_planned_checkout_at' => 'Ngày trả phòng mới phải sau ngày trả phòng hiện tại.',
                ]);
            }

            $conflictAssignment = $this->rules->findConflictForTimeChange(
                $lockedRoom->id,
                $assignment->start_at,
                $newPlannedCheckoutAt,
                $lockedStay->booking_id,
            );

            if ($conflictAssignment !== null) {
                $roomNumber  = $lockedRoom->room_number;
                $bookingCode = $conflictAssignment->booking->booking_code;
                throw ValidationException::withMessages([
                    'new_planned_checkout_at' => "Không thể gia hạn. Phòng {$roomNumber} đang được booking {$bookingCode} sử dụng trong khoảng thời gian này.",
                ]);
            }

            $oldPlannedCheckoutAt = $lockedStay->planned_checkout_at;

            $lockedStay->update([
                'planned_checkout_at' => $newPlannedCheckoutAt,
            ]);

            $assignment->update([
                'end_at' => $newPlannedCheckoutAt,
            ]);

            $this->stayEvents->record($lockedStay, StayEventType::ExtendStay, $actor, [
                'version' => 1,
                'old_planned_checkout_at' => $oldPlannedCheckoutAt?->toIso8601String(),
                'new_planned_checkout_at' => $newPlannedCheckoutAt->toIso8601String(),
            ]);

            return $lockedStay->refresh();
        });
    }

    /**
     * Change Room — an Operational Event, not a Commercial Event. The Booking is
     * never touched, the Stay row is never duplicated: the existing Stay and its
     * existing RoomAssignment row are simply re-pointed to the new Room. History
     * lives in the StayEvent audit log (old/new room, actor, reason, time), not
     * in a second RoomAssignment row — creating one would double-count against
     * the Booking's per-room-type assignment requirement (BookingService::
     * updateBookingAssignmentStatus() / RoomAssignmentService::getAssignmentSummary()
     * both count Assigned+CheckedIn+CheckedOut rows), which this design avoids.
     *
     * Covers both variants of the single Change Room capability: same-room-type
     * (Room Move) and different-room-type (Product Sprint 03 — Operational
     * Cross-Type Move). Only the physical room changes. `RoomAssignment.room_type_id`
     * is deliberately left untouched on any move — it represents the commercial
     * requirement slot this assignment fulfills (see the RoomAssignment Semantic
     * Review in docs/reports/product-sprint-03-change-room-capability-phase-2-report.md),
     * not the physical room's live type, and updating it would create a permanent
     * false mismatch against BookingRequirement. `BookingRequirement` itself is
     * never written by this method — commercial rate is intentionally out of scope
     * (Commercial Source Principle / Pricing Independence Principle).
     */
    public function moveRoom(Stay $stay, Room $newRoom, User $actor, ?string $reason = null): Stay
    {
        return DB::transaction(function () use ($stay, $newRoom, $actor, $reason): Stay {
            // Room-first lock order — same reasoning as extendStay(): this method
            // never writes to Booking, and its conflict check is Room-scoped.
            $lockedNewRoom = Room::with('roomType')->whereKey($newRoom->id)->lockForUpdate()->firstOrFail();
            $lockedStay    = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $assignment    = RoomAssignment::whereKey($lockedStay->room_assignment_id)->lockForUpdate()->firstOrFail();
            $lockedOldRoom = Room::with('roomType')->whereKey($lockedStay->room_id)->lockForUpdate()->firstOrFail();

            if ($lockedStay->status !== StayStatus::CheckedIn) {
                throw ValidationException::withMessages([
                    'stay' => 'Chỉ có thể đổi phòng cho lưu trú đang nhận phòng.',
                ]);
            }

            if ($lockedNewRoom->id === $lockedOldRoom->id) {
                throw ValidationException::withMessages([
                    'room_id' => 'Phòng mới phải khác phòng hiện tại.',
                ]);
            }

            if ($this->rules->isRoomUnavailable($lockedNewRoom)) {
                throw ValidationException::withMessages([
                    'room_id' => 'Phòng mới đang bảo trì hoặc không sẵn sàng.',
                ]);
            }

            $conflict = $this->rules->findConflictForTimeChange(
                $lockedNewRoom->id,
                now(),
                $lockedStay->planned_checkout_at,
                $lockedStay->booking_id,
            );

            if ($conflict !== null) {
                $bookingCode = $conflict->booking->booking_code;
                throw ValidationException::withMessages([
                    'room_id' => "Phòng {$lockedNewRoom->room_number} đang được booking {$bookingCode} sử dụng trong khoảng thời gian này.",
                ]);
            }

            $oldRoomId = $lockedOldRoom->id;
            $oldRoomNumber = $lockedOldRoom->room_number;

            // Mark the vacated room dirty BEFORE repointing the Stay — this hook
            // reads $stay->room_id directly, so it must run while that still
            // resolves to the OLD room. Reuses the exact same hook checkOut()
            // already calls (ADR-84); no Housekeeping file is touched.
            $this->housekeeping->autoMarkDirtyOnCheckout($lockedStay);

            // Only the physical room changes. RoomAssignment.room_type_id (the
            // commercial requirement slot) is intentionally left untouched, even
            // when the new room's type differs — see the class docblock above.
            $assignment->update(['room_id' => $lockedNewRoom->id]);
            $lockedStay->update(['room_id' => $lockedNewRoom->id]);

            // Now $stay->room_id resolves to the NEW room — mark it occupied.
            $this->housekeeping->autoMarkOccupied($lockedStay);

            $this->stayEvents->record($lockedStay, StayEventType::RoomMove, $actor, [
                'version' => 2,
                'old_room_id' => $oldRoomId,
                'old_room_number' => $oldRoomNumber,
                'old_room_type_id' => $lockedOldRoom->room_type_id,
                'old_room_type_name' => $lockedOldRoom->roomType?->name,
                'new_room_id' => $lockedNewRoom->id,
                'new_room_number' => $lockedNewRoom->room_number,
                'new_room_type_id' => $lockedNewRoom->room_type_id,
                'new_room_type_name' => $lockedNewRoom->roomType?->name,
                'reason' => $reason,
            ]);

            return $lockedStay->refresh();
        });
    }

    /**
     * ADMIN-only correction of an already-recorded actual_checkin_at — e.g. the
     * guest was let into the room before reception pressed the button, or the
     * original timestamp was mistyped. Authorization is enforced by the caller
     * (StayPolicy::updateActualCheckIn() / UpdateActualCheckInRequest), and
     * re-checked here as defense-in-depth.
     *
     * Deliberately narrow: only the actual_checkin_at column changes. Status,
     * room assignment, folio postings (room charge / early check-in fee),
     * housekeeping state, and StayEvent(CheckIn) are all left exactly as they
     * were — this is a timestamp correction, not a re-run of check-in. Any
     * FolioEntry already posted at the original check-in stays untouched;
     * this task does not retroactively rewrite financial history.
     */
    public function updateActualCheckIn(Stay $stay, CarbonInterface $newActualCheckinAt, User $actor): Stay
    {
        if (! $actor->can('stay.actual_time.manage')) {
            throw new AuthorizationException('Bạn không có quyền sửa thời gian nhận phòng thực tế.');
        }

        return DB::transaction(function () use ($stay, $newActualCheckinAt, $actor): Stay {
            $lockedStay = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();

            if ($lockedStay->actual_checkin_at === null) {
                throw ValidationException::withMessages([
                    'stay' => 'Chưa nhận phòng nên không thể sửa thời gian nhận phòng thực tế.',
                ]);
            }

            if ($newActualCheckinAt->gt(now())) {
                throw ValidationException::withMessages([
                    'actual_checkin_at' => 'Thời gian nhận phòng thực tế không được ở tương lai.',
                ]);
            }

            if ($lockedStay->actual_checkout_at !== null && $newActualCheckinAt->gt($lockedStay->actual_checkout_at)) {
                throw ValidationException::withMessages([
                    'actual_checkin_at' => 'Thời gian nhận phòng thực tế không được sau thời gian trả phòng thực tế.',
                ]);
            }

            $this->guardNotFullyPaid($lockedStay, 'actual_checkin_at');

            $oldActualCheckinAt = $lockedStay->actual_checkin_at;

            $lockedStay->update(['actual_checkin_at' => $newActualCheckinAt]);

            $this->stayEvents->record($lockedStay, StayEventType::CheckInTimeAdjusted, $actor, [
                'version' => 1,
                'old_actual_checkin_at' => $oldActualCheckinAt->toIso8601String(),
                'new_actual_checkin_at' => $newActualCheckinAt->toIso8601String(),
            ]);

            return $lockedStay->refresh();
        });
    }

    /**
     * ADMIN-only correction of an already-recorded actual_checkout_at. Same
     * narrow scope as updateActualCheckIn(): only the timestamp column
     * changes — no re-checkout, no duplicate state transition, no duplicate
     * folio posting, no retroactive rewrite of already-posted FolioEntry rows
     * (e.g. the late-checkout fee, if one was posted at the original
     * checkout, is left exactly as posted).
     */
    public function updateActualCheckOut(Stay $stay, CarbonInterface $newActualCheckoutAt, User $actor): Stay
    {
        if (! $actor->can('stay.actual_time.manage')) {
            throw new AuthorizationException('Bạn không có quyền sửa thời gian trả phòng thực tế.');
        }

        return DB::transaction(function () use ($stay, $newActualCheckoutAt, $actor): Stay {
            $lockedStay = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();

            if ($lockedStay->actual_checkout_at === null) {
                throw ValidationException::withMessages([
                    'stay' => 'Chưa trả phòng nên không thể sửa thời gian trả phòng thực tế.',
                ]);
            }

            if ($newActualCheckoutAt->gt(now())) {
                throw ValidationException::withMessages([
                    'actual_checkout_at' => 'Thời gian trả phòng thực tế không được ở tương lai.',
                ]);
            }

            if ($lockedStay->actual_checkin_at !== null && $newActualCheckoutAt->lt($lockedStay->actual_checkin_at)) {
                throw ValidationException::withMessages([
                    'actual_checkout_at' => 'Thời gian trả phòng thực tế không được trước thời gian nhận phòng thực tế.',
                ]);
            }

            $this->guardNotFullyPaid($lockedStay, 'actual_checkout_at');

            $oldActualCheckoutAt = $lockedStay->actual_checkout_at;

            $lockedStay->update(['actual_checkout_at' => $newActualCheckoutAt]);

            $this->stayEvents->record($lockedStay, StayEventType::CheckOutTimeAdjusted, $actor, [
                'version' => 1,
                'old_actual_checkout_at' => $oldActualCheckoutAt->toIso8601String(),
                'new_actual_checkout_at' => $newActualCheckoutAt->toIso8601String(),
            ]);

            return $lockedStay->refresh();
        });
    }

    /**
     * User request (2026-08-22 chat) — "nếu đã hoàn thành thanh toán rồi
     * không được phép sửa thời gian nhận, trả phòng". "Đã hoàn thành thanh
     * toán" = balance_due <= 0 (đủ hoặc thừa), tính từ
     * BookingService::paymentSummary() — CÙNG công thức đang dùng ở Đối
     * soát và cảnh báo trả phòng cuối cùng (tổng phí ĐÃ GHI SỔ trừ đã thu),
     * không dùng số "Dự kiến" để tránh vòng phụ thuộc vào chính
     * actual_checkin_at/actual_checkout_at đang được sửa. Áp dụng cho cả
     * updateActualCheckIn() và updateActualCheckOut().
     *
     * Epsilon 0.005 khớp với ngưỡng ReconciliationService::outstandingBalances()
     * đang dùng, tránh false-positive do sai số làm tròn số thực.
     */
    private function guardNotFullyPaid(Stay $stay, string $field): void
    {
        $booking = $stay->booking;

        if ($booking === null) {
            return;
        }

        $balanceDue = $this->bookings->paymentSummary($booking)['balance_due'];

        if ($balanceDue <= 0.005) {
            throw ValidationException::withMessages([
                $field => 'Booking đã hoàn thành thanh toán — không thể sửa thời gian nhận/trả phòng thực tế.',
            ]);
        }
    }

    public function checkInMany(iterable $stays): array
    {
        $checkedIn = [];

        foreach ($stays as $stay) {
            $checkedIn[] = $this->checkIn($stay);
        }

        return $checkedIn;
    }

    public function checkOutMany(iterable $stays, bool $confirmed = false): array
    {
        $checkedOut = [];

        foreach ($stays as $stay) {
            $checkedOut[] = $this->checkOut($stay, null, $confirmed);
        }

        return $checkedOut;
    }
}
