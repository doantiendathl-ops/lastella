<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TriggerNightAuditRequest;
use App\Models\NightAuditBookingLog;
use App\Models\NightAuditRun;
use App\Services\BusinessDateService;
use App\Services\NightAuditOperationsService;
use App\Services\NightAuditService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NightAuditController extends Controller
{
    public function index(BusinessDateService $businessDate): Response
    {
        $this->authorize('viewAny', NightAuditRun::class);

        $runs = NightAuditRun::with('runBy')
            ->orderByDesc('business_date')
            ->limit(30)
            ->get()
            ->map(fn (NightAuditRun $run): array => [
                'id'               => $run->id,
                'business_date'    => $run->business_date->toDateString(),
                'status'           => $run->status,
                'stays_processed'  => $run->stays_processed,
                'entries_posted'   => $run->entries_posted,
                'entries_skipped'  => $run->entries_skipped,
                'run_by_name'      => $run->runBy?->name,
                'started_at'       => $run->started_at?->format('Y-m-d H:i'),
                'completed_at'     => $run->completed_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Admin/NightAudit/Index', [
            'runs'         => $runs,
            'businessDate' => $businessDate->currentBusinessDate()->toDateString(),
        ]);
    }

    public function show(NightAuditRun $nightAuditRun, NightAuditOperationsService $ops): Response
    {
        $this->authorize('view', $nightAuditRun);

        $summary = $ops->getRunSummary($nightAuditRun);
        $logs    = $ops->getBookingLogs($nightAuditRun);

        $jobSummary = NightAuditBookingLog::where('run_id', $nightAuditRun->id)
            ->selectRaw('job_class, result, COUNT(*) as count')
            ->groupBy('job_class', 'result')
            ->get()
            ->groupBy('job_class')
            ->mapWithKeys(fn ($rows, string $jobClass): array => [
                class_basename($jobClass) => [
                    'posted'         => (int) ($rows->firstWhere('result', 'POSTED')?->count ?? 0),
                    'already_posted' => (int) ($rows->firstWhere('result', 'ALREADY_POSTED')?->count ?? 0),
                    'skipped'        => (int) ($rows->firstWhere('result', 'SKIPPED')?->count ?? 0),
                    'failed'         => (int) ($rows->firstWhere('result', 'FAILED')?->count ?? 0),
                ],
            ]);

        return Inertia::render('Admin/NightAudit/Show', [
            'run' => [
                'id'               => $nightAuditRun->id,
                'business_date'    => $nightAuditRun->business_date->toDateString(),
                'status'           => $nightAuditRun->status,
                'stays_processed'  => $nightAuditRun->stays_processed,
                'entries_posted'   => $nightAuditRun->entries_posted,
                'entries_skipped'  => $nightAuditRun->entries_skipped,
                'run_by_name'      => $nightAuditRun->runBy?->name,
                'started_at'       => $nightAuditRun->started_at?->format('Y-m-d H:i'),
                'completed_at'     => $nightAuditRun->completed_at?->format('Y-m-d H:i'),
                'error_message'    => $nightAuditRun->error_message,
                'can_retry'        => $nightAuditRun->isFailed() && request()->user()?->can('night_audit.run'),
            ],
            'summary'     => $summary,
            'job_summary' => $jobSummary,
            'logs'        => $logs->map(fn ($log): array => [
                'id'          => $log->id,
                'booking_id'  => $log->booking_id,
                'booking_ref' => $log->booking?->booking_code ?? "#{$log->booking_id}",
                'stay_id'     => $log->stay_id,
                'room_number' => $log->stay?->room?->room_number,
                'job_class'   => class_basename($log->job_class),
                'result'      => $log->result,
                'posting_key' => $log->posting_key,
                'message'     => $log->message,
            ])->values(),
        ]);
    }

    public function run(NightAuditService $nightAudit, BusinessDateService $businessDate): RedirectResponse
    {
        $this->authorize('run', NightAuditRun::class);

        $nightAudit->runForCurrentBusinessDate();

        return redirect()
            ->route('admin.night-audit.index')
            ->with('success', 'Night audit hoàn tất.');
    }

    public function trigger(TriggerNightAuditRequest $request, NightAuditOperationsService $ops): RedirectResponse
    {
        $this->authorize('trigger', NightAuditRun::class);

        $date = Carbon::parse($request->validated('date'));
        $run  = $ops->triggerManualRun($date);

        return redirect()
            ->route('admin.night-audit.show', $run->id)
            ->with('success', "Night Audit ngày {$date->toDateString()} đã hoàn tất.");
    }

    public function retry(NightAuditRun $nightAuditRun, NightAuditOperationsService $ops): RedirectResponse
    {
        $this->authorize('retry', $nightAuditRun);

        $run = $ops->retryFailedRun($nightAuditRun);

        return redirect()
            ->route('admin.night-audit.show', $run->id)
            ->with('success', "Night Audit ngày {$run->business_date->toDateString()} đã được thử lại.");
    }
}
