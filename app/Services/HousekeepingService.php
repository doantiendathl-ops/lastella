<?php

namespace App\Services;

use App\Enums\CleaningPriority;
use App\Enums\CleaningReason;
use App\Enums\CleaningStatus;
use App\Enums\HousekeepingAssignmentStatus;
use App\Enums\InspectionResult;
use App\Enums\RoomStatus;
use App\Models\CleaningRecord;
use App\Models\HousekeepingAssignment;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class HousekeepingService
{
    /**
     * Room Operations Simplification: one-tap "Đánh dấu sạch". Never touches
     * booking/stay, never requires or creates a HousekeepingAssignment. For an
     * Occupied room (guest still in-house), only `cleaning_status` changes —
     * `status` stays Occupied, per business rule V (stayover cleaning must not
     * affect the operational/stay state).
     */
    public function markClean(Room $room, User $actor, ?string $notes = null): Room
    {
        return $this->applyCleaningState($room, $actor, CleaningStatus::Clean, $notes);
    }

    /**
     * Room Operations Simplification: one-tap "Đánh dấu bẩn". Symmetric to
     * markClean() — see its docblock for the Occupied-room rule.
     */
    public function markDirty(Room $room, User $actor, ?string $notes = null): Room
    {
        return $this->applyCleaningState($room, $actor, CleaningStatus::Dirty, $notes);
    }

    /**
     * Final Consistency Review: single shared implementation behind markClean()/
     * markDirty() — locks the room, re-checks maintenance server-side, resolves the
     * matching `status` (collapsing the legacy VacantDirty/Cleaning/Inspected/
     * VacantClean cluster, or leaving Occupied untouched), writes both `status` and
     * `cleaning_status` atomically, and records one CleaningRecord audit row.
     */
    private function applyCleaningState(Room $room, User $actor, CleaningStatus $target, ?string $notes): Room
    {
        return DB::transaction(function () use ($room, $actor, $target, $notes): Room {
            $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            $statusBefore = $lockedRoom->status;

            $this->guardAgainstMaintenance($statusBefore);

            $newStatus = $statusBefore === RoomStatus::Occupied
                ? RoomStatus::Occupied
                : ($target === CleaningStatus::Clean ? RoomStatus::VacantClean : RoomStatus::VacantDirty);

            $attributes = [
                'status'          => $newStatus,
                'cleaning_status' => $target,
            ];

            if ($target === CleaningStatus::Clean) {
                $attributes['last_cleaned_at'] = now();
            }

            $lockedRoom->update($attributes);

            CleaningRecord::create([
                'room_id'            => $lockedRoom->id,
                'cleaned_by'         => $actor->id,
                'room_status_before' => $statusBefore->value,
                'room_status_after'  => $newStatus->value,
                'reason'             => CleaningReason::Manual,
                'started_at'         => now(),
                'completed_at'       => now(),
                'duration_minutes'   => 0,
                'cleaning_notes'     => $notes,
            ]);

            return $lockedRoom->refresh();
        });
    }

    private function guardAgainstMaintenance(RoomStatus $status): void
    {
        if (in_array($status, [RoomStatus::OutOfOrder, RoomStatus::OutOfService], true)) {
            throw ValidationException::withMessages([
                'room' => 'Không thể thao tác sạch/bẩn khi phòng đang bảo trì hoặc ngừng sử dụng.',
            ]);
        }
    }

    /**
     * Final Consistency Review: deliberately never touches cleaning_status — assigning
     * a room for cleaning is a workflow/ownership action, not a cleanliness claim.
     */
    public function assignRoom(
        Room $room,
        ?User $assignee,
        User $actor,
        CleaningPriority $priority = CleaningPriority::Normal,
        CleaningReason $reason = CleaningReason::Checkout,
        ?string $notes = null,
    ): HousekeepingAssignment {
        return DB::transaction(function () use ($room, $assignee, $actor, $priority, $reason, $notes): HousekeepingAssignment {
            $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

            if ($lockedRoom->status !== RoomStatus::VacantDirty) {
                throw ValidationException::withMessages([
                    'room' => 'Chỉ có thể phân công dọn phòng cho phòng đang ở trạng thái trống bẩn.',
                ]);
            }

            $hasActiveAssignment = HousekeepingAssignment::query()
                ->forRoom($lockedRoom->id)
                ->active()
                ->exists();

            if ($hasActiveAssignment) {
                throw ValidationException::withMessages([
                    'room' => 'Phòng này đã có phân công dọn phòng đang hoạt động.',
                ]);
            }

            $resolvedAssignee = $assignee ?? $actor;

            if ($resolvedAssignee->id !== $actor->id && !$actor->can('room.maintenance')) {
                throw new AuthorizationException('Nhân viên buồng phòng chỉ có thể tự nhận phòng, không thể phân công cho người khác.');
            }

            return HousekeepingAssignment::create([
                'room_id'     => $lockedRoom->id,
                'assigned_to' => $resolvedAssignee->id,
                'assigned_by' => $actor->id,
                'priority'    => $priority,
                'reason'      => $reason,
                'notes'       => $notes,
                'status'      => HousekeepingAssignmentStatus::Pending,
            ]);
        });
    }

    public function startCleaning(HousekeepingAssignment $assignment, User $actor): HousekeepingAssignment
    {
        return DB::transaction(function () use ($assignment, $actor): HousekeepingAssignment {
            $lockedRoom = Room::whereKey($assignment->room_id)->lockForUpdate()->firstOrFail();
            $lockedAssignment = HousekeepingAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if ($lockedAssignment->status !== HousekeepingAssignmentStatus::Pending) {
                throw ValidationException::withMessages([
                    'assignment' => 'Chỉ có thể bắt đầu dọn phòng với phân công đang chờ xử lý.',
                ]);
            }

            if ($lockedRoom->status !== RoomStatus::VacantDirty) {
                throw ValidationException::withMessages([
                    'room' => 'Phòng không ở trạng thái trống bẩn nên không thể bắt đầu dọn.',
                ]);
            }

            // ADR-90 auto-claim: an unassigned assignment (e.g. auto-created by
            // autoMarkDirtyOnCheckout()) is claimed by whoever starts it, provided they
            // hold both assignment and status-update permissions. An already-assigned
            // assignment is never reassigned this way — ownership is untouched below.
            if ($lockedAssignment->assigned_to === null
                && $actor->can('housekeeping.assign')
                && $actor->can('room.status.update')
            ) {
                $lockedAssignment->assigned_to = $actor->id;
            }

            if ($lockedAssignment->assigned_to !== $actor->id && !$actor->can('room.maintenance')) {
                throw new AuthorizationException('Bạn không được phân công dọn phòng này.');
            }

            $now = now();

            $lockedAssignment->update([
                'assigned_to' => $lockedAssignment->assigned_to,
                'status'      => HousekeepingAssignmentStatus::InProgress,
                'started_at'  => $now,
            ]);

            // Final Consistency Review: a room mid-clean is still dirty — explicit,
            // not inherited from whatever cleaning_status happened to hold before.
            $lockedRoom->update([
                'status'          => RoomStatus::Cleaning,
                'cleaning_status' => CleaningStatus::Dirty,
            ]);

            CleaningRecord::create([
                'room_id'            => $lockedRoom->id,
                'assignment_id'      => $lockedAssignment->id,
                'cleaned_by'         => $actor->id,
                'room_status_before' => RoomStatus::VacantDirty->value,
                'reason'             => $lockedAssignment->reason,
                'started_at'         => $now,
            ]);

            return $lockedAssignment->refresh();
        });
    }

    public function completeCleaning(HousekeepingAssignment $assignment, User $actor, ?string $notes = null): CleaningRecord
    {
        return DB::transaction(function () use ($assignment, $notes): CleaningRecord {
            $lockedRoom = Room::whereKey($assignment->room_id)->lockForUpdate()->firstOrFail();
            $lockedAssignment = HousekeepingAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if ($lockedAssignment->status !== HousekeepingAssignmentStatus::InProgress) {
                throw ValidationException::withMessages([
                    'assignment' => 'Chỉ có thể hoàn thành phân công đang được thực hiện.',
                ]);
            }

            if ($lockedRoom->status !== RoomStatus::Cleaning) {
                throw ValidationException::withMessages([
                    'room' => 'Phòng không ở trạng thái đang dọn nên không thể hoàn thành.',
                ]);
            }

            // M-01: an open CleaningRecord must already exist from startCleaning() — never auto-create here.
            $record = CleaningRecord::where('assignment_id', $lockedAssignment->id)
                ->whereNull('completed_at')
                ->latest('started_at')
                ->first();

            if ($record === null) {
                throw new LogicException('Không tìm thấy bản ghi dọn phòng đang mở cho phân công này.');
            }

            $now = now();
            $duration = (int) $record->started_at->diffInMinutes($now);

            $record->update([
                'completed_at'     => $now,
                'duration_minutes' => $duration,
                'cleaning_notes'   => $notes,
            ]);

            $lockedAssignment->update([
                'status'       => HousekeepingAssignmentStatus::Done,
                'completed_at' => $now,
            ]);

            // Final Consistency Review decision: "complete" means the room has been
            // physically cleaned and is awaiting inspection — treated as Clean, matching
            // the same INSPECTED → CLEAN mapping used everywhere else (migration
            // backfill, RoomStatus::impliedCleaningStatus()). Inspection can still send
            // it back to Dirty via failInspection() below.
            $lockedRoom->update([
                'status'          => RoomStatus::Inspected,
                'cleaning_status' => CleaningStatus::Clean,
                'last_cleaned_at' => $now,
            ]);

            return $record->refresh();
        });
    }

    /**
     * M2-01: only accepts a room in RoomStatus::Inspected — enforced by lockRoomForInspection(),
     * which throws ValidationException for any other status.
     */
    public function passInspection(Room $room, User $inspector, ?string $notes = null): CleaningRecord
    {
        return DB::transaction(function () use ($room, $inspector, $notes): CleaningRecord {
            $lockedRoom = $this->lockRoomForInspection($room);
            $record = $this->findOpenInspectionRecord($lockedRoom->id);

            $record->update([
                'inspection_result' => InspectionResult::Pass,
                'inspected_by'      => $inspector->id,
                'inspected_at'      => now(),
                'inspection_notes'  => $notes,
                'room_status_after' => RoomStatus::VacantClean->value,
            ]);

            $lockedRoom->update([
                'status'          => RoomStatus::VacantClean,
                'cleaning_status' => CleaningStatus::Clean,
            ]);

            return $record->refresh();
        });
    }

    /**
     * M2-01: only accepts a room in RoomStatus::Inspected — enforced by lockRoomForInspection(),
     * which throws ValidationException for any other status.
     */
    public function failInspection(Room $room, User $inspector, ?string $notes = null): CleaningRecord
    {
        return DB::transaction(function () use ($room, $inspector, $notes): CleaningRecord {
            $lockedRoom = $this->lockRoomForInspection($room);
            $record = $this->findOpenInspectionRecord($lockedRoom->id);

            $record->update([
                'inspection_result' => InspectionResult::Fail,
                'inspected_by'      => $inspector->id,
                'inspected_at'      => now(),
                'inspection_notes'  => $notes,
                'room_status_after' => RoomStatus::VacantDirty->value,
            ]);

            $lockedRoom->update([
                'status'          => RoomStatus::VacantDirty,
                'cleaning_status' => CleaningStatus::Dirty,
            ]);

            // M2-03: auto-escalate only if no active assignment already exists for this room —
            // avoids a duplicate pending assignment stacking on top of one created by another path.
            $hasActiveAssignment = HousekeepingAssignment::query()
                ->forRoom($lockedRoom->id)
                ->active()
                ->exists();

            if (!$hasActiveAssignment) {
                HousekeepingAssignment::create([
                    'room_id'  => $lockedRoom->id,
                    'status'   => HousekeepingAssignmentStatus::Pending,
                    'priority' => CleaningPriority::High,
                    'reason'   => $record->reason,
                ]);
            }

            return $record->refresh();
        });
    }

    /**
     * M2-01: only accepts a room in RoomStatus::Inspected — enforced by lockRoomForInspection(),
     * which throws ValidationException for any other status.
     */
    public function skipInspection(Room $room, User $inspector, string $reasonNotes): CleaningRecord
    {
        if (trim($reasonNotes) === '') {
            throw ValidationException::withMessages([
                'notes' => 'Phải ghi chú lý do khi bỏ qua kiểm tra.',
            ]);
        }

        return DB::transaction(function () use ($room, $inspector, $reasonNotes): CleaningRecord {
            $lockedRoom = $this->lockRoomForInspection($room);
            $record = $this->findOpenInspectionRecord($lockedRoom->id);

            $record->update([
                'inspection_result' => InspectionResult::Skip,
                'inspected_by'      => $inspector->id,
                'inspected_at'      => now(),
                'inspection_notes'  => $reasonNotes,
                'room_status_after' => RoomStatus::VacantClean->value,
            ]);

            $lockedRoom->update([
                'status'          => RoomStatus::VacantClean,
                'cleaning_status' => CleaningStatus::Clean,
            ]);

            return $record->refresh();
        });
    }

    public function markOutOfOrder(Room $room, User $actor, string $reason): Room
    {
        return DB::transaction(function () use ($room, $actor): Room {
            $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

            if ($lockedRoom->status === RoomStatus::Cleaning) {
                throw ValidationException::withMessages([
                    'room' => 'Không thể khóa bảo trì phòng đang được dọn.',
                ]);
            }

            // Final Consistency Review: `cleaning_status` is deliberately left untouched
            // here — locking a room for maintenance must not silently overwrite whatever
            // cleanliness was recorded right before the lock. If the room already had a
            // known cleaning_status, it's preserved unchanged and restored verbatim on
            // release; only a room with no prior signal falls back to
            // RoomStatus::impliedCleaningStatus()'s conservative Dirty default while locked.
            $lockedRoom->update(['status' => RoomStatus::OutOfOrder]);

            HousekeepingAssignment::query()
                ->forRoom($lockedRoom->id)
                ->active()
                ->get()
                ->each(fn (HousekeepingAssignment $active) => $active->update([
                    'status'       => HousekeepingAssignmentStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => $actor->id,
                ]));

            return $lockedRoom->refresh();
        });
    }

    public function releaseFromOutOfOrder(Room $room, User $actor, RoomStatus $targetStatus = RoomStatus::VacantDirty): Room
    {
        return DB::transaction(function () use ($room, $targetStatus): Room {
            $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

            if ($lockedRoom->status !== RoomStatus::OutOfOrder) {
                throw ValidationException::withMessages([
                    'room' => 'Phòng không ở trạng thái hỏng.',
                ]);
            }

            // Final Consistency Review: the caller's explicit $targetStatus choice (made
            // at the moment of release, e.g. "release as VacantDirty — needs cleaning
            // after the repair work") is a fresher, more authoritative signal than
            // whatever cleaning_status happened to be frozen from before the room went
            // into maintenance — so it wins outright rather than being "restored".
            // This also guarantees status/cleaning_status can never disagree on release.
            $lockedRoom->update([
                'status'          => $targetStatus,
                'cleaning_status' => $targetStatus->impliedCleaningStatus(),
            ]);

            return $lockedRoom->refresh();
        });
    }

    /**
     * ADR-84: non-throwing hook called from StayService::checkIn().
     */
    public function autoMarkOccupied(Stay $stay): void
    {
        try {
            Room::whereKey($stay->room_id)->update(['status' => RoomStatus::Occupied]);
        } catch (Throwable $e) {
            Log::error('HousekeepingService::autoMarkOccupied failed', [
                'booking_id' => $stay->booking_id,
                'stay_id'    => $stay->id,
                'room_id'    => $stay->room_id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * ADR-84: non-throwing hook called from StayService::checkOut().
     *
     * Room Operations Simplification: no longer auto-creates a HousekeepingAssignment
     * — the simplified workflow has no mandatory "chờ dọn" step. The room simply
     * becomes BẨN; a single "Đánh dấu sạch" tap (markClean()) is all that's needed
     * to clear it. The old assign/start/complete pipeline still exists and still
     * works for anyone who explicitly opts into it via the Detail popup.
     */
    public function autoMarkDirtyOnCheckout(Stay $stay): void
    {
        try {
            Room::whereKey($stay->room_id)->update([
                'status'          => RoomStatus::VacantDirty,
                'cleaning_status' => CleaningStatus::Dirty,
            ]);
        } catch (Throwable $e) {
            Log::error('HousekeepingService::autoMarkDirtyOnCheckout failed', [
                'stay_id' => $stay->id,
                'room_id' => $stay->room_id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function lockRoomForInspection(Room $room): Room
    {
        $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

        if ($lockedRoom->status !== RoomStatus::Inspected) {
            throw ValidationException::withMessages([
                'room' => 'Phòng không ở trạng thái chờ kiểm tra.',
            ]);
        }

        return $lockedRoom;
    }

    private function findOpenInspectionRecord(int $roomId): CleaningRecord
    {
        $record = CleaningRecord::where('room_id', $roomId)
            ->whereNull('inspection_result')
            ->latest('completed_at')
            ->first();

        if ($record === null) {
            throw new LogicException('Không tìm thấy bản ghi dọn phòng đang chờ kiểm tra cho phòng này.');
        }

        return $record;
    }
}
