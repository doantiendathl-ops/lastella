<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BusinessDateService;
use App\Services\RevenueReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RevenueReportController extends Controller
{
    private const MAX_RANGE_DAYS = 365;

    public function index(
        Request $request,
        RevenueReportService $service,
        BusinessDateService $businessDate,
    ): Response {
        abort_unless($request->user()?->can('revenue.view'), 403);

        [$from, $to] = $this->resolveRange($request, $businessDate->currentBusinessDate());

        return Inertia::render('Admin/Revenue/Index', [
            'daily'        => $service->dailySummary($businessDate->currentBusinessDate()),
            'period'       => $service->periodSummary($from, $to),
            'bySource'     => $service->revenueBySource($from, $to),
            'filters'      => [
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
            ],
            'businessDate' => $businessDate->currentBusinessDate()->toDateString(),
        ]);
    }

    public function export(
        Request $request,
        RevenueReportService $service,
        BusinessDateService $businessDate,
    ): StreamedResponse {
        abort_unless($request->user()?->can('revenue.view'), 403);

        [$from, $to] = $this->resolveRange($request, $businessDate->currentBusinessDate());

        $filename = sprintf('revenue-%s-%s.csv', $from->format('Ymd'), $to->format('Ymd'));

        return response()->streamDownload(function () use ($service, $from, $to): void {
            $output = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, ['Ngày', 'Loại phí', 'Nguồn', 'Mô tả', 'Số lượng', 'Đơn giá', 'Thành tiền']);

            foreach ($service->exportRows($from, $to) as $entry) {
                fputcsv($output, [
                    $entry->entry_date->format('Y-m-d'),
                    $entry->charge_type->label(),
                    $entry->posting_source ?? 'MANUAL',
                    $entry->description,
                    $entry->quantity,
                    $entry->unit_price,
                    $entry->amount,
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveRange(Request $request, Carbon $today): array
    {
        $from = $request->date('from') ?? $today->copy()->startOfMonth();
        $to   = $request->date('to') ?? $today->copy();

        if ($from->gt($to)) {
            $from = $to->copy()->startOfMonth();
        }

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $to->copy()->subDays(self::MAX_RANGE_DAYS);
        }

        return [$from, $to];
    }
}
