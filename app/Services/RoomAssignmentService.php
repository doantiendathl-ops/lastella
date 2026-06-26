<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
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
    ) {
    }

    public function assignRooms(Booking $booking, array $assignments): array
    {
        // Sort by room_id ascending to acquire locks in deterministic order and avoid deadlocks.
        usort($assignments, fn (array $a, array $b): int => $a['room_id'] <=> $b['room_id']);

        return DB::transaction(function () use ($booking, $assignments): array {
            $created = [];

            foreach ($assignments as $assignment) {
                // Lock the room row to prevent concurrent double assignment.
                $room = Room::whereKey($assignment['room_id'])->lockForUpdate()->firstOrFail();
                $startAt = $assignment['start_at'] ?? $booking->checkin_at;
                $endAt = $assignment['end_at'] ?? $booking->checkout_at;

                if ($this->rules->hasConflict($room->id, $startAt, $endAt)) {
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
