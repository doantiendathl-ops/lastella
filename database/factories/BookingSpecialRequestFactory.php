<?php

namespace Database\Factories;

use App\Enums\RequestCategory;
use App\Enums\RequestStatus;
use App\Models\Booking;
use App\Models\BookingSpecialRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingSpecialRequest>
 */
class BookingSpecialRequestFactory extends Factory
{
    protected $model = BookingSpecialRequest::class;

    public function definition(): array
    {
        return [
            'booking_id'      => Booking::factory(),
            'stay_id'         => null,
            'category'        => RequestCategory::General,
            'request_type'    => 'general_request',
            'quantity'        => 1,
            'note'            => null,
            'status'          => RequestStatus::Pending,
            'requested_by'    => User::factory(),
            'acknowledged_by' => null,
            'acknowledged_at' => null,
            'fulfilled_by'    => null,
            'fulfilled_at'    => null,
            'cancelled_by'    => null,
            'cancelled_at'    => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => RequestStatus::Pending]);
    }

    public function acknowledged(): static
    {
        return $this->state(fn () => [
            'status'          => RequestStatus::Acknowledged,
            'acknowledged_by' => User::factory(),
            'acknowledged_at' => now(),
        ]);
    }

    public function fulfilled(): static
    {
        return $this->state(fn () => [
            'status'          => RequestStatus::Fulfilled,
            'acknowledged_by' => User::factory(),
            'acknowledged_at' => now()->subMinute(),
            'fulfilled_by'    => User::factory(),
            'fulfilled_at'    => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status'       => RequestStatus::Cancelled,
            'cancelled_by' => User::factory(),
            'cancelled_at' => now(),
        ]);
    }
}
