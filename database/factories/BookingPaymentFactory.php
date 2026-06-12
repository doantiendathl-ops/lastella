<?php

namespace Database\Factories;

use App\Enums\PaymentType;
use App\Models\Booking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\BookingPayment>
 */
class BookingPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'payment_type' => PaymentType::Deposit,
            'amount' => fake()->numberBetween(500, 3000),
            'payment_method' => 'cash',
            'payment_at' => now(),
            'confirmed_by' => null,
            'note' => null,
        ];
    }
}
