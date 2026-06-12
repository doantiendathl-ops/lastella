<?php

namespace Database\Factories;

use App\Enums\RateStatus;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\RoomRate>
 */
class RoomRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room_type_id' => RoomType::factory(),
            'valid_from' => now()->startOfYear()->toDateString(),
            'valid_to' => null,
            'overnight_price' => fake()->numberBetween(1200, 4200),
            'hourly_price' => fake()->numberBetween(250, 700),
            'extra_adult_price' => 500,
            'extra_child_price' => 250,
            'early_checkin_price' => 500,
            'late_checkout_price' => 500,
            'status' => RateStatus::Active,
        ];
    }
}
