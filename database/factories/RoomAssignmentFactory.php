<?php

namespace Database\Factories;

use App\Enums\AssignmentStatus;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\RoomAssignment>
 */
class RoomAssignmentFactory extends Factory
{
    public function definition(): array
    {
        $booking = Booking::factory()->create();
        $room = Room::factory()->create();

        return [
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => $booking->checkin_at,
            'end_at' => $booking->checkout_at,
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => null,
            'released_by' => null,
            'released_at' => null,
            'release_reason' => null,
        ];
    }
}
