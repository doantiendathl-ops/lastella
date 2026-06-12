<?php

namespace Database\Factories;

use App\Enums\StayStatus;
use App\Models\RoomAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Stay>
 */
class StayFactory extends Factory
{
    public function definition(): array
    {
        $assignment = RoomAssignment::factory()->create();

        return [
            'booking_id' => $assignment->booking_id,
            'room_assignment_id' => $assignment->id,
            'room_id' => $assignment->room_id,
            'planned_checkin_at' => $assignment->start_at,
            'planned_checkout_at' => $assignment->end_at,
            'actual_checkin_at' => null,
            'actual_checkout_at' => null,
            'status' => StayStatus::Reserved,
            'checked_in_by' => null,
            'checked_out_by' => null,
            'note' => null,
        ];
    }
}
