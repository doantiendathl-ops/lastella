<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\RoomStatus;
use App\Enums\StayStatus;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;

class DashboardService
{
    public function metrics(): array
    {
        $now = now();
        $outOfInventoryStatuses = [
            RoomStatus::OutOfOrder->value,
            RoomStatus::OutOfService->value,
        ];

        $occupiedRoomIds = Stay::query()
            ->where('status', StayStatus::CheckedIn->value)
            ->whereNull('actual_checkout_at')
            ->pluck('room_id')
            ->merge(
                RoomAssignment::query()
                    ->whereIn('status', [
                        AssignmentStatus::Assigned->value,
                        AssignmentStatus::CheckedIn->value,
                    ])
                    ->where('start_at', '<=', $now)
                    ->where('end_at', '>', $now)
                    ->pluck('room_id'),
            )
            ->unique()
            ->values();

        $outOfInventoryRoomIds = Room::query()
            ->whereIn('status', $outOfInventoryStatuses)
            ->pluck('id')
            ->unique()
            ->values();

        $totalRooms = Room::count();
        $occupiedRooms = $occupiedRoomIds->count();
        $outOfOrderRooms = $outOfInventoryRoomIds->count();
        $occupiedSellableRooms = $occupiedRoomIds->diff($outOfInventoryRoomIds)->count();

        return [
            'total_rooms' => $totalRooms,
            'available_rooms' => max($totalRooms - $occupiedSellableRooms - $outOfOrderRooms, 0),
            'occupied_rooms' => $occupiedRooms,
            'out_of_order_rooms' => $outOfOrderRooms,
            'users' => User::count(),
            'room_types' => RoomType::count(),
        ];
    }
}
