<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RoomAvailabilityRuleService
{
    public function hasConflict(
        int $roomId,
        CarbonInterface|string $startAt,
        CarbonInterface|string $endAt,
        ?int $ignoreAssignmentId = null,
    ): bool {
        $startAt = $startAt instanceof CarbonInterface ? $startAt : Carbon::parse($startAt);
        $endAt = $endAt instanceof CarbonInterface ? $endAt : Carbon::parse($endAt);

        return $this->applyOverlap(
            $this->blockingQuery(ignoreAssignmentId: $ignoreAssignmentId)
                ->where('room_id', $roomId)
                ->whereIn('status', [AssignmentStatus::CheckedIn, AssignmentStatus::Assigned]),
            $startAt,
            $endAt,
        )->exists();
    }

    /**
     * Find the first assignment that conflicts with the proposed time range for a booking time change.
     * Excludes ALL assignments belonging to the booking being changed so a multi-room booking
     * cannot conflict with itself.
     */
    public function findConflictForTimeChange(
        int $roomId,
        CarbonInterface|string $startAt,
        CarbonInterface|string $endAt,
        int $excludeBookingId,
    ): ?RoomAssignment {
        $startAt = $startAt instanceof CarbonInterface ? $startAt : Carbon::parse($startAt);
        $endAt = $endAt instanceof CarbonInterface ? $endAt : Carbon::parse($endAt);

        return $this->applyOverlap(
            $this->blockingQuery(excludeBookingId: $excludeBookingId)
                ->where('room_id', $roomId)
                ->whereIn('status', [AssignmentStatus::CheckedIn, AssignmentStatus::Assigned]),
            $startAt,
            $endAt,
        )->with([
            'booking:id,booking_code,customer_name,checkin_at,checkout_at',
            'room:id,room_number,room_type_id',
            'room.roomType:id,code',
        ])->first();
    }

    /**
     * @param  bool  $filterCheckedInByDate  When true, CHECKED_IN assignments are
     *     date-filtered like ASSIGNED ones (for the Availability Checker).  When false
     *     (default), ALL CHECKED_IN assignments are returned — a physically present
     *     guest blocks regardless of planned dates (for the Room Board and conflict
     *     checking).
     * @return array{checked_in: Collection, reserved: Collection}
     */
    public function getBlockingAssignments(
        CarbonInterface|string $startAt,
        CarbonInterface|string $endAt,
        ?int $excludeBookingId = null,
        bool $filterCheckedInByDate = false,
    ): array {
        $startAt = $startAt instanceof CarbonInterface ? $startAt : Carbon::parse($startAt);
        $endAt = $endAt instanceof CarbonInterface ? $endAt : Carbon::parse($endAt);

        $baseQuery = fn (): Builder => $this->blockingQuery(excludeBookingId: $excludeBookingId)
            ->with('booking:id,booking_code,customer_name,booking_color,status');

        $checkedInQuery = $baseQuery()->where('status', AssignmentStatus::CheckedIn);
        if ($filterCheckedInByDate) {
            $this->applyOverlap($checkedInQuery, $startAt, $endAt);
        }
        $checkedIn = $checkedInQuery->get()->groupBy('room_id');

        $reserved = $this->applyOverlap(
            $baseQuery()->where('status', AssignmentStatus::Assigned),
            $startAt,
            $endAt,
        )->get()->groupBy('room_id');

        return [
            'checked_in' => $checkedIn,
            'reserved' => $reserved,
        ];
    }

    public function resolveAvailability(
        Collection $checkedIn,
        Collection $reserved,
        bool $isUnavailable,
        Carbon $now,
    ): string {
        if ($isUnavailable) {
            return 'out_of_order';
        }

        if ($checkedIn->isNotEmpty()) {
            return $checkedIn->contains(fn ($a) => $a->end_at->lessThan($now))
                ? 'overstay'
                : 'occupied';
        }

        if ($reserved->isEmpty()) {
            return 'available';
        }

        if ($reserved->count() === 1) {
            return 'reserved';
        }

        return $this->hasTimeOverlap($reserved->all()) ? 'overlap' : 'multi_booking';
    }

    public function isRoomUnavailable(Room $room): bool
    {
        return in_array($room->status, [
            RoomStatus::OutOfOrder,
            RoomStatus::OutOfService,
            RoomStatus::Cleaning, // Phase 4.2 ADR-88: a room being cleaned cannot be assigned
        ], true);
    }

    public function hasTimeOverlap(array $assignments): bool
    {
        usort($assignments, fn ($a, $b) => $a->start_at->timestamp <=> $b->start_at->timestamp);

        for ($i = 0; $i < count($assignments) - 1; $i++) {
            if ($assignments[$i]->end_at->greaterThan($assignments[$i + 1]->start_at)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a specific assignment is currently blocking a booking's room availability.
     *
     * Rules:
     *  - Released / CheckedOut → never blocks
     *  - CheckedIn / Assigned → blocks only when its planned range overlaps the booking's range
     */
    public function isAssignmentBlockingBooking(RoomAssignment $assignment, Booking $booking): bool
    {
        if (in_array($assignment->status, [AssignmentStatus::Released, AssignmentStatus::CheckedOut], true)) {
            return false;
        }

        if (in_array($assignment->status, [AssignmentStatus::CheckedIn, AssignmentStatus::Assigned], true)) {
            return $assignment->start_at->lessThan($booking->checkout_at)
                && $assignment->end_at->greaterThan($booking->checkin_at);
        }

        return false;
    }

    /**
     * Base query builder with optional exclusion filters.
     * Used by all conflict-detection methods as the starting point.
     */
    private function blockingQuery(
        ?int $ignoreAssignmentId = null,
        ?int $excludeBookingId = null,
    ): Builder {
        return RoomAssignment::query()
            ->when($ignoreAssignmentId, fn (Builder $q): Builder => $q->whereKeyNot($ignoreAssignmentId))
            ->when($excludeBookingId, fn (Builder $q): Builder => $q->where('booking_id', '!=', $excludeBookingId));
    }

    /**
     * Applies the time-overlap predicate shared by all conflict queries.
     */
    private function applyOverlap(Builder $query, Carbon $startAt, Carbon $endAt): Builder
    {
        return $query
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt);
    }
}
