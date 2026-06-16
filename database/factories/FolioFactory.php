<?php

namespace Database\Factories;

use App\Enums\FolioStatus;
use App\Models\Booking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Folio>
 */
class FolioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_id'   => Booking::factory(),
            'folio_number' => 'FLO-' . now()->format('Ymd') . '-' . str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'status'       => FolioStatus::Open,
            'note'         => null,
            'created_by'   => null,
            'closed_at'    => null,
            'closed_by'    => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status'    => FolioStatus::Closed,
            'closed_at' => now(),
        ]);
    }
}
