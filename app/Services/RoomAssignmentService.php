<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoomAssignmentService
{
    public function __construct(private readonly BookingService $bookings)
    {
    }

    public function assignRooms(Booking $booking, array $assignments): array
    {
        return DB::transaction(function () use ($booking, $assignments): array {
            $created = [];

            foreach ($assignments as $assignment) {
                $room = Room::findOrFail($assignment['room_id']);
                $startAt = $assignment['start_at'] ?? $booking->checkin_at;
                $endAt = $assignment['end_at'] ?? $booking->checkout_at;

                if ($this->checkRoomConflict($room, $startAt, $endAt)) {
                    throw ValidationException::withMessages([
                        'room_id' => "Room {$room->room_number} already has an active assignment for the selected time.",
                    ]);
                }

                $created[] = RoomAssignment::create([
                    'booking_id' => $booking->id,
                    'room_id' => $room->id,
                    'room_type_id' => $assignment['room_type_id'] ?? $room->room_type_id,
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'status' => AssignmentStatus::Assigned,
                    'assigned_by' => Auth::id(),
                ]);
            }

            $this->bookings->updateBookingAssignmentStatus($booking);

            return $created;
        });
    }

    public function releaseAssignment(RoomAssignment $assignment, ?string $reason = null): RoomAssignment
    {
        return DB::transaction(function () use ($assignment, $reason): RoomAssignment {
            $assignment->update([
                'status' => AssignmentStatus::Released,
                'released_by' => Auth::id(),
                'released_at' => now(),
                'release_reason' => $reason,
            ]);

            $this->bookings->updateBookingAssignmentStatus($assignment->booking);

            return $assignment->refresh();
        });
    }

    public function checkRoomConflict(Room|int $room, CarbonInterface|string $startAt, CarbonInterface|string $endAt, ?int $ignoreAssignmentId = null): bool
    {
        $roomId = $room instanceof Room ? $room->id : $room;

        return RoomAssignment::query()
            ->when($ignoreAssignmentId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreAssignmentId))
            ->where('room_id', $roomId)
            ->whereIn('status', AssignmentStatus::activeValues())
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->exists();
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
}
