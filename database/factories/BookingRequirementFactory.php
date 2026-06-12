<?php

namespace Database\Factories;

use App\Enums\PriceSource;
use App\Models\Booking;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\BookingRequirement>
 */
class BookingRequirementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'room_type_id' => RoomType::factory(),
            'quantity' => 1,
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'room_price' => fake()->numberBetween(1200, 5000),
            'price_source' => PriceSource::RateTable,
            'note' => null,
        ];
    }
}
