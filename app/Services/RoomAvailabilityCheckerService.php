<?php

namespace App\Services;

use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomAssignment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;

class RoomAvailabilityCheckerService
{
    public function __construct(
        private readonly RoomAvailabilityRuleService $rules,
        private readonly BusinessDateService $businessDate,
    ) {
    }

    /**
     * docs/yeucaumoi.txt mục 11 — a query range is "historical" only when it is
     * ENTIRELY before today's business date (same BusinessDateService Night Audit
     * uses, mục 27). A range that touches today/future stays on the live-only filter
     * — this screen's core purpose is "can I book this room", and a CheckedOut guest
     * never blocks a new booking, so mixing historical info into a live/future query
     * would be misleading, not helpful.
     */
    private function isHistoricalRange(CarbonInterface $endAt): bool
    {
        return $endAt->lte($this->businessDate->currentBusinessDate());
    }

    public function check(string $startAt, string $endAt): array
    {
        $now = now();

        $blocking = $this->rules->getBlockingAssignments($startAt, $endAt, filterCheckedInByDate: true);
        $checkedInByRoom = $blocking['checked_in'];
        $reservedByRoom = $blocking['reserved'];

        $checkedOutByRoom = $this->isHistoricalRange(Carbon::parse($endAt))
            ? $this->rules->getHistoricalCheckedOutAssignments($startAt, $endAt)
            : collect();

        $canViewBooking = Auth::user()?->can('viewAny', Booking::class) ?? false;

        $floors = Floor::query()
            ->whereHas('rooms')
            ->with(['rooms' => fn ($q) => $q->with('roomType')->orderBy('room_number')])
            ->orderBy('sort_order')
            ->get();

        $floorsData = $floors->map(function (Floor $floor) use ($checkedInByRoom, $reservedByRoom, $checkedOutByRoom, $canViewBooking, $now): array {
            return [
                'floor_id' => $floor->id,
                'floor_code' => $floor->code,
                'floor_name' => $floor->name,
                'rooms' => $floor->rooms->map(function (Room $room) use ($checkedInByRoom, $reservedByRoom, $checkedOutByRoom, $canViewBooking, $now): array {
                    $checkedIn = $checkedInByRoom->get($room->id, collect());
                    $reserved = $reservedByRoom->get($room->id, collect());
                    $checkedOut = $checkedOutByRoom->get($room->id, collect());

                    // Phase 4.2 ADR-88: CLEANING gets its own distinct availability label,
                    // checked before the generic out_of_order bucket.
                    if ($room->status === RoomStatus::Cleaning) {
                        $availability = 'cleaning';
                    } else {
                        $isUnavailable = $this->rules->isRoomUnavailable($room);
                        $availability = $this->rules->resolveAvailability($checkedIn, $reserved, $isUnavailable, $now, $checkedOut);
                    }

                    $allAssignments = $checkedIn->merge($reserved)->merge($checkedOut)->values();
                    $primaryAssignment = $checkedIn->first() ?? $reserved->first() ?? $checkedOut->first();

                    return [
                        'room_id' => $room->id,
                        'room_number' => $room->room_number,
                        'room_type_id' => $room->room_type_id,
                        'room_type_code' => $room->roomType?->code,
                        'room_type_name' => $room->roomType?->name,
                        'room_status' => $room->status?->value,
                        'room_status_label' => $room->status?->label(),
                        'availability' => $availability,
                        'availability_label' => $this->availabilityLabel($availability),
                        'booking_count' => $allAssignments->count(),
                        'has_overlap' => $availability === 'overlap',
                        'primary_color' => $primaryAssignment !== null
                            ? $this->bookingColor($primaryAssignment->booking)
                            : null,
                        'bookings' => $allAssignments->map(fn (RoomAssignment $a) => [
                            'booking_id' => $a->booking_id,
                            'booking_code' => $a->booking?->booking_code,
                            'customer_name' => $a->booking?->customer_name,
                            'booking_color' => $this->bookingColor($a->booking),
                            'booking_status' => $a->booking?->status?->value,
                            'assignment_status' => $a->status?->value,
                            'checkin_at' => $a->start_at?->format('Y-m-d H:i'),
                            'checkout_at' => $a->end_at?->format('Y-m-d H:i'),
                            'can_view' => $canViewBooking,
                        ])->values()->all(),
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        $allRooms = collect($floorsData)->flatMap(fn ($floor) => $floor['rooms']);

        $roomTypeSummary = $allRooms
            ->filter(fn ($room) => $room['room_type_id'] !== null)
            ->groupBy('room_type_id')
            ->map(function ($roomsOfType): array {
                $total = $roomsOfType->count();
                $outOfOrder = $roomsOfType->where('availability', 'out_of_order')->count();
                $unavailable = $roomsOfType->whereIn('availability', ['occupied', 'overstay', 'reserved', 'overlap', 'multi_booking', 'cleaning', 'checked_out'])->count();
                $remaining = $total - $outOfOrder - $unavailable;
                $sellable = $total - $outOfOrder;

                $badge = match (true) {
                    $sellable === 0 || $remaining === 0 => 'none',
                    ($remaining / $sellable) > 0.3 => 'many',
                    default => 'low',
                };

                return [
                    'room_type_id' => $roomsOfType->first()['room_type_id'],
                    'room_type_code' => $roomsOfType->first()['room_type_code'],
                    'room_type_name' => $roomsOfType->first()['room_type_name'],
                    'total' => $total,
                    'out_of_order' => $outOfOrder,
                    'occupied' => $unavailable,
                    'remaining' => $remaining,
                    'badge' => $badge,
                    'badge_label' => match ($badge) {
                        'many' => 'Còn nhiều',
                        'low' => 'Sắp hết',
                        default => 'Hết phòng',
                    },
                ];
            })
            ->values()
            ->all();

        return [
            'range' => [
                'start' => Carbon::parse($startAt)->format('Y-m-d H:i'),
                'end' => Carbon::parse($endAt)->format('Y-m-d H:i'),
            ],
            'room_type_summary' => $roomTypeSummary,
            'floors' => $floorsData,
        ];
    }

    private function availabilityLabel(string $availability): string
    {
        return match ($availability) {
            'available' => 'Trống',
            'reserved' => 'Đã giữ phòng',
            'occupied' => 'Đang ở',
            'overstay' => 'Quá hạn lưu trú',
            'overlap' => 'Xung đột',
            'multi_booking' => 'Nhiều booking',
            'out_of_order' => 'Không khả dụng',
            'cleaning' => 'Đang dọn',
            'checked_out' => 'Đã trả phòng',
            default => $availability,
        };
    }

    private function bookingColor(?Booking $booking): string
    {
        if ($booking?->booking_color) {
            return $booking->booking_color;
        }

        $palette = ['#8B5CF6', '#10B981', '#F59E0B', '#EF4444', '#3B82F6', '#EC4899', '#14B8A6', '#6366F1'];

        return $palette[($booking?->id ?? 0) % count($palette)];
    }
}
