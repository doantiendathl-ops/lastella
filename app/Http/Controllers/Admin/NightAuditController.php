<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NightAuditRun;
use App\Services\BusinessDateService;
use App\Services\NightAuditService;
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

    public function run(NightAuditService $nightAudit, BusinessDateService $businessDate): RedirectResponse
    {
        $this->authorize('run', NightAuditRun::class);

        $nightAudit->runForCurrentBusinessDate();

        return redirect()
            ->route('admin.night-audit.index')
            ->with('success', 'Night audit hoàn tất.');
    }
}
