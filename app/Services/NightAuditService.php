<?php

namespace App\Services;

use App\Exceptions\RunNotRecalculableException;
use App\Models\FolioEntry;
use App\Models\NightAuditRun;
use App\Models\User;
use App\Services\Posting\BreakfastPostingJob;
use App\Services\Posting\CityTaxPostingJob;
use App\Services\Posting\ExtraBedPostingJob;
use App\Services\Posting\ExtraPersonPostingJob;
use App\Services\Posting\RoomChargePostingJob;
use App\Services\Posting\ServicePackagePostingJob;
use App\Services\Posting\UnifiedServicePostingJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
        private readonly UnifiedServicePostingJob $unifiedServiceJob,
        private readonly BusinessDateService $businessDate,
        private readonly FolioService $folios,
    ) {}

    public function runForDate(Carbon $businessDate): NightAuditRun
    {
        // Night Audit pending-confirmation window (Phần 2): every run still
        // awaiting confirmation gets auto-confirmed the moment the NEXT run
        // starts — "đến 12h đêm hôm sau ... hệ thống sẽ mặc định việc chạy
        // của đêm trước là đã được xác nhận" (user's own design). Must run
        // BEFORE the existing-run/idempotency check below so a same-date
        // re-run of an already-completed date is unaffected either way.
        $this->confirmPendingRuns();

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

        $this->registerJobs()->run($auditRun, $businessDate);

        return $auditRun->refresh();
    }

    public function runForCurrentBusinessDate(): NightAuditRun
    {
        return $this->runForDate($this->businessDate->currentBusinessDate());
    }

    /**
     * Auto-confirms every run that finished a prior pass but is still inside
     * its correction window (COMPLETED, confirmed_at still null). Confirming
     * a run closes its window for good: every not-yet-finalized FolioEntry it
     * produced (i.e. every stay that has NOT already been finalized early at
     * checkout — see StayService::finalizeStayNightAuditEntries()) is
     * finalized here, which is what makes FolioService::voidEntry() start
     * refusing to void them (ADR-50 restored for this run from this point on).
     *
     * Idempotent and cheap to call unconditionally: a fully-confirmed system
     * has zero isAwaitingConfirmation() rows, so this is a no-op query on the
     * common path.
     */
    public function confirmPendingRuns(): void
    {
        $pending = NightAuditRun::where('status', 'COMPLETED')
            ->whereNull('confirmed_at')
            ->get();

        foreach ($pending as $run) {
            DB::transaction(function () use ($run): void {
                $lockedRun = NightAuditRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

                if ($lockedRun->confirmed_at !== null) {
                    return;
                }

                FolioEntry::where('night_audit_run_id', $lockedRun->id)
                    ->whereNull('voided_at')
                    ->whereNull('finalized_at')
                    ->update(['finalized_at' => now()]);

                $lockedRun->update(['confirmed_at' => now()]);
            });
        }
    }

    /**
     * "Tính lại" (Phần 2, design Q2 — manual button, not automatic): voids
     * every still-correctable FolioEntry this run produced (night_audit_run_id
     * matches, not voided, not finalized — i.e. NOT a stay that already
     * checked out and got finalized early) and re-runs the same 7 posting
     * jobs for the same business date, so any input that changed since the
     * original run (extra bed count, a corrected room rate, a newly enrolled
     * package, ...) is reflected without waiting for the next midnight sweep.
     *
     * Guarded to COMPLETED-and-not-yet-confirmed runs only: a run that never
     * finished has nothing to recalculate, and a confirmed run's entries are
     * no longer voidable at all (by design — that is what "confirmed" means).
     */
    public function recalculate(NightAuditRun $run, User $actor): NightAuditRun
    {
        return DB::transaction(function () use ($run, $actor): NightAuditRun {
            $lockedRun = NightAuditRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

            if (! $lockedRun->isCompleted()) {
                throw new RunNotRecalculableException(
                    "Night Audit run #{$lockedRun->id} chưa hoàn tất. Chỉ có thể tính lại một lượt đã hoàn tất."
                );
            }

            if ($lockedRun->confirmed_at !== null) {
                throw new RunNotRecalculableException(
                    "Night Audit run #{$lockedRun->id} đã được xác nhận. Cửa sổ chỉnh sửa đã đóng, không thể tính lại."
                );
            }

            $voidableEntries = FolioEntry::where('night_audit_run_id', $lockedRun->id)
                ->whereNull('voided_at')
                ->whereNull('finalized_at')
                ->get();

            foreach ($voidableEntries as $entry) {
                $this->folios->voidEntry($entry, 'Night Audit — tính lại', $actor);
            }

            $this->registerJobs()->run($lockedRun, Carbon::parse($lockedRun->business_date->toDateString()));

            return $lockedRun->refresh();
        });
    }

    private function registerJobs(): NightAuditPipeline
    {
        return $this->pipeline
            ->register($this->roomChargeJob)
            ->register($this->breakfastJob)
            ->register($this->extraPersonJob)
            ->register($this->extraBedJob)
            ->register($this->servicePackageJob)
            ->register($this->cityTaxJob)
            ->register($this->unifiedServiceJob);
    }
}
