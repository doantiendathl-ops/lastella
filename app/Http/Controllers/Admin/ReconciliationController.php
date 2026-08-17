<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BusinessDateService;
use App\Services\ReconciliationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReconciliationController extends Controller
{
    private const MAX_RANGE_DAYS = 365;

    public function index(
        Request $request,
        ReconciliationService $service,
        BusinessDateService $businessDate,
    ): Response {
        abort_unless($request->user()?->can('reconciliation.view'), 403);

        $filters = ['status' => $request->input('status')];

        return Inertia::render('Admin/Reconciliation/Index', [
            'outstanding'  => $service->outstandingBalances($filters),
            'discrepancies'=> $service->discrepancies(),
            'filters'      => $filters,
            'businessDate' => $businessDate->currentBusinessDate()->toDateString(),
        ]);
    }

    public function voids(
        Request $request,
        ReconciliationService $service,
        BusinessDateService $businessDate,
    ): Response {
        abort_unless($request->user()?->can('reconciliation.view'), 403);

        [$from, $to] = $this->resolveRange($request, $businessDate->currentBusinessDate());

        return Inertia::render('Admin/Reconciliation/Voids', [
            'voids'        => $service->voidedEntries($from, $to),
            'filters'      => [
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
            ],
            'businessDate' => $businessDate->currentBusinessDate()->toDateString(),
        ]);
    }

    public function exportOutstanding(
        Request $request,
        ReconciliationService $service,
    ): StreamedResponse {
        abort_unless($request->user()?->can('reconciliation.view'), 403);

        $filters  = ['status' => $request->input('status')];
        $filename = sprintf('outstanding-%s.csv', now()->format('Ymd'));

        return response()->streamDownload(function () use ($service, $filters): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, [
                'Mã đặt phòng',
                'Khách hàng',
                'Trạng thái đặt phòng',
                'Trạng thái folio',
                'Ngày trả phòng',
                'Tổng phí',
                'Đã thanh toán',
                'Còn nợ',
            ]);

            foreach ($service->exportOutstandingRows($filters) as $row) {
                fputcsv($output, [
                    $row['booking_code'],
                    $row['customer_name'],
                    $row['status'],
                    $row['folio_status'] ?? '—',
                    $row['checkout_at'] ?? '—',
                    $row['total_charges'],
                    $row['paid_total'],
                    $row['balance_due'],
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    public function exportVoids(
        Request $request,
        ReconciliationService $service,
        BusinessDateService $businessDate,
    ): StreamedResponse {
        abort_unless($request->user()?->can('reconciliation.view'), 403);

        [$from, $to] = $this->resolveRange($request, $businessDate->currentBusinessDate());

        $filename = sprintf('voided-entries-%s-%s.csv', $from->format('Ymd'), $to->format('Ymd'));

        return response()->streamDownload(function () use ($service, $from, $to): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, [
                'Mã đặt phòng',
                'Khách hàng',
                'Loại phí',
                'Mô tả',
                'Thành tiền',
                'Ngày phát sinh',
                'Thời gian hủy',
                'Người hủy',
                'Lý do hủy',
            ]);

            foreach ($service->exportVoidedRows($from, $to) as $row) {
                fputcsv($output, $row);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveRange(Request $request, Carbon $today): array
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

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
