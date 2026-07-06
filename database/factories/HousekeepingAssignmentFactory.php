<?php

namespace Database\Factories;

use App\Enums\CleaningPriority;
use App\Enums\CleaningReason;
use App\Enums\HousekeepingAssignmentStatus;
use App\Models\HousekeepingAssignment;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HousekeepingAssignment>
 */
class HousekeepingAssignmentFactory extends Factory
{
    protected $model = HousekeepingAssignment::class;

    public function definition(): array
    {
        return [
            'room_id'      => Room::factory(),
            'assigned_to'  => null,
            'assigned_by'  => null,
            'priority'     => CleaningPriority::Normal,
            'reason'       => CleaningReason::Checkout,
            'notes'        => null,
            'started_at'   => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'status'       => HousekeepingAssignmentStatus::Pending,
        ];
    }

    public function pending(): static
    {
        return $this->state([
            'status'      => HousekeepingAssignmentStatus::Pending,
            'started_at'  => null,
            'completed_at' => null,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status'     => HousekeepingAssignmentStatus::InProgress,
            'started_at' => now()->subMinutes(15),
        ]);
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status'       => HousekeepingAssignmentStatus::Done,
            'started_at'   => now()->subMinutes(30),
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status'       => HousekeepingAssignmentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => User::factory(),
        ]);
    }

    public function emergency(): static
    {
        return $this->state([
            'priority' => CleaningPriority::Emergency,
        ]);
    }

    public function forCheckout(): static
    {
        return $this->state([
            'reason' => CleaningReason::Checkout,
        ]);
    }
}
