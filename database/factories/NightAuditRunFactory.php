<?php

namespace Database\Factories;

use App\Models\NightAuditRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NightAuditRun>
 */
class NightAuditRunFactory extends Factory
{
    protected $model = NightAuditRun::class;

    public function definition(): array
    {
        return [
            'business_date'   => fake()->unique()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'status'          => 'COMPLETED',
            'stays_processed' => fake()->numberBetween(0, 20),
            'entries_posted'  => fake()->numberBetween(0, 20),
            'entries_skipped' => fake()->numberBetween(0, 5),
            'run_by'          => null,
            'started_at'      => now()->subHour(),
            'completed_at'    => now()->subMinutes(55),
            'error_message'   => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status'        => 'FAILED',
            'error_message' => 'Pipeline error during run.',
            'completed_at'  => now()->subMinutes(50),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status'       => 'PENDING',
            'started_at'   => null,
            'completed_at' => null,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status'       => 'RUNNING',
            'started_at'   => now()->subMinutes(2),
            'completed_at' => null,
        ]);
    }
}
