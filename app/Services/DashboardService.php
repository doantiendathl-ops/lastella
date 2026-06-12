<?php

namespace App\Services;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;

class DashboardService
{
    public function metrics(): array
    {
        return [
            'total_rooms' => Room::count(),
            'available_rooms' => Room::whereIn('status', RoomStatus::availableValues())->count(),
            'occupied_rooms' => Room::where('status', RoomStatus::Occupied)->count(),
            'out_of_order_rooms' => Room::where('status', RoomStatus::OutOfOrder)->count(),
            'users' => User::count(),
            'room_types' => RoomType::count(),
        ];
    }
}
