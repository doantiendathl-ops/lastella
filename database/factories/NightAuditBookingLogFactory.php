<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\NightAuditBookingLog;
use App\Models\NightAuditRun;
use App\Services\Posting\RoomChargePostingJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NightAuditBookingLog>
 */
class NightAuditBookingLogFactory extends Factory
{
    protected $model = NightAuditBookingLog::class;

    public function definition(): array
    {
        return [
            'run_id'      => NightAuditRun::factory(),
            'booking_id'  => Booking::factory(),
            'stay_id'     => null,
            'job_class'   => RoomChargePostingJob::class,
            'result'      => 'POSTED',
            'posting_key' => 'ROOM_NIGHT_1_2026-07-01',
            'message'     => 'Room charge posted successfully.',
        ];
    }

    public function skipped(string $reason = 'shouldProcess returned false'): static
    {
        return $this->state(fn (): array => [
            'result'      => 'SKIPPED',
            'posting_key' => null,
            'message'     => $reason,
        ]);
    }

    public function failed(string $error = 'Unexpected error.'): static
    {
        return $this->state(fn (): array => [
            'result'      => 'FAILED',
            'posting_key' => null,
            'message'     => $error,
        ]);
    }

    public function alreadyPosted(): static
    {
        return $this->state(fn (): array => [
            'result'  => 'ALREADY_POSTED',
            'message' => 'Entry already posted for this date.',
        ]);
    }
}
