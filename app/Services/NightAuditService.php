<?php

namespace App\Services;

use App\Models\NightAuditRun;
use App\Services\Posting\BreakfastPostingJob;
use App\Services\Posting\RoomChargePostingJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class NightAuditService
{
    public function __construct(
        private readonly NightAuditPipeline $pipeline,
        private readonly RoomChargePostingJob $roomChargeJob,
        private readonly BreakfastPostingJob $breakfastJob,
        private readonly BusinessDateService $businessDate,
    ) {}

    public function runForDate(Carbon $businessDate): NightAuditRun
    {
        $existing = NightAuditRun::where('business_date', $businessDate->toDateString())->first();

        if ($existing !== null && $existing->isCompleted()) {
            return $existing;
        }

        $auditRun = NightAuditRun::firstOrCreate(
            ['business_date' => $businessDate->toDateString()],
            [
                'status' => 'PENDING',
                'run_by' => Auth::id(),
            ]
        );

        $this->pipeline
            ->register($this->roomChargeJob)
            ->register($this->breakfastJob)
            ->run($auditRun, $businessDate);

        return $auditRun->refresh();
    }

    public function runForCurrentBusinessDate(): NightAuditRun
    {
        return $this->runForDate($this->businessDate->currentBusinessDate());
    }
}
