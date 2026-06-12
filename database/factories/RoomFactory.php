<?php

namespace Database\Factories;

use App\Enums\RoomStatus;
use App\Models\Floor;
use App\Models\Resource;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Room>
 */
class RoomFactory extends Factory
{
    public function definition(): array
    {
        $roomNumber = (string) fake()->unique()->numberBetween(100, 999);

        return [
            'resource_id' => Resource::factory()->room($roomNumber),
            'floor_id' => Floor::factory(),
            'room_type_id' => RoomType::factory(),
            'room_number' => $roomNumber,
            'status' => RoomStatus::VacantClean,
            'bed_configuration' => [
                'label' => 'Twin',
                'beds' => [
                    ['quantity' => 2, 'size_meters' => 1.2],
                ],
            ],
            'notes' => null,
        ];
    }
}
