<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\StayEventType;
use App\Enums\StayStatus;
use App\Exceptions\FinalCheckoutConfirmationRequiredException;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Stay;
use App\Models\User;
use App\Services\Posting\EarlyCheckinFeePostingJob;
use App\Services\Posting\LateCheckoutFeePostingJob;
use App\Services\Posting\PostingContext;
use App\Services\Posting\RoomChargePostingJob;
use Carbon\CarbonInterface;
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

            if ($lockedStay->planned_checkin_at !== null && now()->lt($lockedStay->planned_checkin_at)) {
                throw ValidationException::withMessages([
                    'stay' => 'Chưa đến thời gian nhận phòng dự kiến. Thời gian nhận phòng dự kiến: '
                        . $lockedStay->planned_checkin_at->format('d/m/Y H:i') . '.',
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
            }

            // ADR-49: Active stay = Reserved OR CheckedIn (own DML visible within transaction).
            $remainingActive = Stay::where('booking_id', $lockedBooking->id)
                ->whereIn('status', [StayStatus::Reserved, StayStatus::CheckedIn])
                ->count();

            if ($remainingActive === 0) {
                // ADR-48: finalise runs inside caller's transaction; Booking lock already held.
                $this->bookings->finaliseBookingCheckout($lockedBooking);
                $this->stayEvents->record($lockedStay, StayEventType::Checkout, Auth::user(), [
                    'version' => 1,
                    'booking_id' => $lockedBooking->id,
                    'room_assignment_id' => $assignment->id,
                    'remaining_active_stays' => $remainingActive,
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
     * Room Move — an Operational Event, not a Commercial Event. The Booking is
     * never touched, the Stay row is never duplicated: the existing Stay and its
     * existing RoomAssignment row are simply re-pointed to the new Room. History
     * lives in the StayEvent audit log (old/new room, actor, reason, time), not
     * in a second RoomAssignment row — creating one would double-count against
     * the Booking's per-room-type assignment requirement (BookingService::
     * updateBookingAssignmentStatus() / RoomAssignmentService::getAssignmentSummary()
     * both count Assigned+CheckedIn+CheckedOut rows), which this design avoids.
     */
    public function moveRoom(Stay $stay, Room $newRoom, User $actor, ?string $reason = null): Stay
    {
        return DB::transaction(function () use ($stay, $newRoom, $actor, $reason): Stay {
            // Room-first lock order — same reasoning as extendStay(): this method
            // never writes to Booking, and its conflict check is Room-scoped.
            $lockedNewRoom = Room::whereKey($newRoom->id)->lockForUpdate()->firstOrFail();
            $lockedStay    = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $assignment    = RoomAssignment::whereKey($lockedStay->room_assignment_id)->lockForUpdate()->firstOrFail();
            $lockedOldRoom = Room::whereKey($lockedStay->room_id)->lockForUpdate()->firstOrFail();

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

            // Room Move is a lateral, same-room-type operational move only.
            // Changing room type is an Upgrade/Downgrade — explicitly out of
            // scope for this capability (a separate future product sprint).
            if ($lockedNewRoom->room_type_id !== $lockedOldRoom->room_type_id) {
                throw ValidationException::withMessages([
                    'room_id' => 'Chỉ hỗ trợ đổi sang phòng cùng loại. Nâng/hạ hạng phòng chưa được hỗ trợ.',
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

            $assignment->update(['room_id' => $lockedNewRoom->id]);
            $lockedStay->update(['room_id' => $lockedNewRoom->id]);

            // Now $stay->room_id resolves to the NEW room — mark it occupied.
            $this->housekeeping->autoMarkOccupied($lockedStay);

            $this->stayEvents->record($lockedStay, StayEventType::RoomMove, $actor, [
                'version' => 1,
                'old_room_id' => $oldRoomId,
                'old_room_number' => $oldRoomNumber,
                'new_room_id' => $lockedNewRoom->id,
                'new_room_number' => $lockedNewRoom->room_number,
                'reason' => $reason,
            ]);

            return $lockedStay->refresh();
        });
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
