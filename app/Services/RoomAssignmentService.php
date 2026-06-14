<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Floor;
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
                        'room_id' => 'Phòng đã có booking khác trong khoảng thời gian này.',
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

    public function getRoomBoard(Booking $booking): array
    {
        $requiredRoomTypeIds = $booking->bookingRequirements()
            ->pluck('room_type_id')
            ->unique()
            ->values();

        $conflicts = RoomAssignment::query()
            ->with('booking:id,booking_code,customer_name')
            ->where('booking_id', '!=', $booking->id)
            ->whereIn('status', AssignmentStatus::activeValues())
            ->where('start_at', '<', $booking->checkout_at)
            ->where('end_at', '>', $booking->checkin_at)
            ->get()
            ->keyBy('room_id');

        $currentAssignments = $booking->roomAssignments()
            ->with('booking:id,booking_code,customer_name')
            ->whereIn('status', AssignmentStatus::activeValues())
            ->where('start_at', '<', $booking->checkout_at)
            ->where('end_at', '>', $booking->checkin_at)
            ->get()
            ->keyBy('room_id');

        $unavailableStatuses = [
            RoomStatus::OutOfOrder,
            RoomStatus::OutOfService,
        ];

        return [
            'floors' => Floor::query()
                ->whereHas('rooms')
                ->with(['rooms' => fn ($query) => $query->with('roomType')->orderBy('room_number')])
                ->orderBy('sort_order')
                ->get()
                ->map(function (Floor $floor) use ($conflicts, $currentAssignments, $requiredRoomTypeIds, $unavailableStatuses): array {
                    return [
                        'id' => $floor->id,
                        'code' => $floor->code,
                        'name' => $floor->name,
                        'rooms' => $floor->rooms->map(function (Room $room) use ($conflicts, $currentAssignments, $requiredRoomTypeIds, $unavailableStatuses): array {
                            $conflict = $conflicts->get($room->id);
                            $currentAssignment = $currentAssignments->get($room->id);
                            $isUnavailable = in_array($room->status, $unavailableStatuses, true);
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
                                    'code' => $conflict->booking?->booking_code,
                                    'customer_name' => $conflict->booking?->customer_name,
                                ] : null,
                                'assignment_detail' => $displayAssignment ? $this->roomBoardAssignmentPayload($displayAssignment) : null,
                                'matches_requirement' => $matchesRequirement,
                            ];
                        })->values(),
                    ];
                })
                ->values(),
        ];
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
