<?php

namespace App\Services;

use App\Models\NightAuditRun;
use App\Services\Posting\BreakfastPostingJob;
use App\Services\Posting\CityTaxPostingJob;
use App\Services\Posting\ExtraBedPostingJob;
use App\Services\Posting\ExtraPersonPostingJob;
use App\Services\Posting\RoomChargePostingJob;
use App\Services\Posting\ServicePackagePostingJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class NightAuditService
{
    public function __construct(
        private readonly NightAuditPipeline $pipeline,
        private readonly RoomChargePostingJob $roomChargeJob,
        private readonly BreakfastPostingJob $breakfastJob,
        private readonly ExtraPersonPostingJob $extraPersonJob,
        private readonly ExtraBedPostingJob $extraBedJob,
        private readonly ServicePackagePostingJob $servicePackageJob,
        private readonly CityTaxPostingJob $cityTaxJob,
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
            ->register($this->extraPersonJob)
            ->register($this->extraBedJob)
            ->register($this->servicePackageJob)
            ->register($this->cityTaxJob)
            ->run($auditRun, $businessDate);

        return $auditRun->refresh();
    }

    public function runForCurrentBusinessDate(): NightAuditRun
    {
        return $this->runForDate($this->businessDate->currentBusinessDate());
    }
}
