<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Booking>
 */
class BookingFactory extends Factory
{
    public function definition(): array
    {
        $checkin = now()->addDays(fake()->numberBetween(1, 30))->setTime(14, 0);

        return [
            'booking_code' => fake()->unique()->bothify('BK-########'),
            'booking_color' => '#196251',
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->phoneNumber(),
            'customer_email' => fake()->safeEmail(),
            'customer_type' => CustomerType::Individual,
            'booking_type' => BookingType::Overnight,
            'checkin_at' => $checkin,
            'checkout_at' => $checkin->copy()->addDay()->setTime(12, 0),
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'status' => BookingStatus::PendingAssignment,
            'sales_user_id' => null,
            'created_by' => null,
            'updated_by' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_reason' => null,
            'note' => null,
            'internal_note' => null,
        ];
    }
}
