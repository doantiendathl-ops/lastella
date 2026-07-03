<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FolioEntry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RevenueReportService
{
    public const KNOWN_SOURCES = ['MANUAL', 'NIGHT_AUDIT', 'SYSTEM_AUTO'];

    private const SOURCE_LABELS = [
        'MANUAL'      => 'Thủ công',
        'NIGHT_AUDIT' => 'Night Audit',
        'SYSTEM_AUTO' => 'Tự động',
    ];

    public function dailySummary(Carbon $date): array
    {
        $rows = FolioEntry::active()
            ->whereDate('entry_date', $date)
            ->select('charge_type', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('charge_type')
            ->get();

        return [
            'date'           => $date->toDateString(),
            'total'          => (float) $rows->sum('total'),
            'by_charge_type' => $this->mapChargeTypeRows($rows),
        ];
    }

    public function periodSummary(Carbon $from, Carbon $to): array
    {
        $rows = FolioEntry::active()
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->select('charge_type', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('charge_type')
            ->get();

        $dailyRows = FolioEntry::active()
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->select(DB::raw('DATE(entry_date) as date'), DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy(DB::raw('DATE(entry_date)'))
            ->orderByRaw('DATE(entry_date)')
            ->get();

        return [
            'from'           => $from->toDateString(),
            'to'             => $to->toDateString(),
            'total'          => (float) $rows->sum('total'),
            'by_charge_type' => $this->mapChargeTypeRows($rows),
            'by_date'        => $dailyRows->map(fn ($row): array => [
                'date'  => $row->date,
                'total' => (float) $row->total,
                'count' => (int) $row->count,
            ])->values()->all(),
        ];
    }

    public function revenueBySource(Carbon $from, Carbon $to): array
    {
        $rows = FolioEntry::active()
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->select(
                DB::raw("COALESCE(posting_source, 'MANUAL') as source"),
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as count'),
            )
            ->groupBy(DB::raw("COALESCE(posting_source, 'MANUAL')"))
            ->get();

        $bySource = $rows->map(fn ($row): array => [
            'source' => $row->source,
            'label'  => self::SOURCE_LABELS[$row->source] ?? $row->source,
            'amount' => (float) $row->total,
            'count'  => (int) $row->count,
        ])->values()->all();

        return [
            'from'      => $from->toDateString(),
            'to'        => $to->toDateString(),
            'total'     => (float) $rows->sum('total'),
            'by_source' => $bySource,
        ];
    }

    public function exportRows(Carbon $from, Carbon $to): iterable
    {
        return FolioEntry::active()
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->select([
                'entry_date',
                'charge_type',
                'posting_source',
                'description',
                'quantity',
                'unit_price',
                'amount',
            ])
            ->orderByRaw('DATE(entry_date)')
            ->orderBy('id')
            ->cursor();
    }

    private function mapChargeTypeRows(Collection $rows): array
    {
        return $rows->map(fn ($row): array => [
            'charge_type' => $row->charge_type->value,
            'label'       => $row->charge_type->label(),
            'amount'      => (float) $row->total,
            'count'       => (int) $row->count,
        ])->values()->all();
    }
}
