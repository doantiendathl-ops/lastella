<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ManualRunBlockedException;
use App\Exceptions\RunNotRetryableException;
use App\Models\NightAuditBookingLog;
use App\Models\NightAuditRun;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class NightAuditOperationsService
{
    public function __construct(
        private readonly NightAuditService $nightAuditService,
        private readonly BusinessDateService $businessDateService,
        private readonly HotelSettingsService $settings,
    ) {}

    public function triggerManualRun(Carbon $date): NightAuditRun
    {
        $this->guardFutureDate($date);
        $this->guardWindowConstraint($date);
        $this->guardExistingRun($date);

        return $this->nightAuditService->runForDate($date);
    }

    public function retryFailedRun(NightAuditRun $run): NightAuditRun
    {
        if (! $run->isFailed()) {
            throw new RunNotRetryableException(
                "Night Audit run #{$run->id} không ở trạng thái FAILED. Chỉ có thể retry run đã thất bại."
            );
        }

        return $this->nightAuditService->runForDate($run->business_date);
    }

    public function getRunSummary(NightAuditRun $run): array
    {
        $logs = $run->bookingLogs;

        return [
            'posted'        => $logs->where('result', 'POSTED')->count(),
            'already_posted' => $logs->where('result', 'ALREADY_POSTED')->count(),
            'skipped'       => $logs->where('result', 'SKIPPED')->count(),
            'failed'        => $logs->where('result', 'FAILED')->count(),
            'total'         => $logs->count(),
        ];
    }

    public function getBookingLogs(NightAuditRun $run, ?string $result = null): Collection
    {
        $query = NightAuditBookingLog::where('run_id', $run->id)
            ->with(['booking', 'stay.room']);

        if ($result !== null) {
            $query->where('result', $result);
        }

        return $query->orderBy('id')->get();
    }

    private function guardFutureDate(Carbon $date): void
    {
        $currentBusinessDate = $this->businessDateService->currentBusinessDate();

        if ($date->greaterThan($currentBusinessDate)) {
            throw new ManualRunBlockedException(
                "Không thể kích hoạt Night Audit cho ngày {$date->toDateString()} vì ngày này ở trong tương lai (ngày kế toán hiện tại: {$currentBusinessDate->toDateString()})."
            );
        }
    }

    private function guardWindowConstraint(Carbon $date): void
    {
        $windowDays = $this->settings->getInt('audit_window_days', 7);
        $currentBusinessDate = $this->businessDateService->currentBusinessDate();
        $diffDays = (int) abs($currentBusinessDate->diffInDays($date));

        if ($diffDays > $windowDays) {
            throw new ManualRunBlockedException(
                "Ngày {$date->toDateString()} nằm ngoài phạm vi cho phép. Chỉ được kích hoạt Night Audit trong vòng {$windowDays} ngày tính từ ngày kế toán hiện tại ({$currentBusinessDate->toDateString()})."
            );
        }
    }

    private function guardExistingRun(Carbon $date): void
    {
        $existing = NightAuditRun::where('business_date', $date->toDateString())->first();

        if ($existing === null) {
            return;
        }

        if ($existing->isCompleted()) {
            throw new ManualRunBlockedException(
                "Night Audit cho ngày {$date->toDateString()} đã hoàn tất (run #{$existing->id}). Không thể chạy lại."
            );
        }

        if ($existing->isRunning()) {
            throw new ManualRunBlockedException(
                "Night Audit cho ngày {$date->toDateString()} đang chạy (run #{$existing->id}). Vui lòng chờ hoàn tất."
            );
        }
    }
}
