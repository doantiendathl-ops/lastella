<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Folio;
use App\Models\FolioEntry;

class PostingTimelineService
{
    private const MAX_ENTRIES = 1000;

    public function forFolio(Folio $folio, bool $includeVoided = true): array
    {
        $query = FolioEntry::with(['stay.room', 'postedBy', 'voidedBy'])
            ->where('folio_id', $folio->id)
            ->orderBy('entry_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->limit(self::MAX_ENTRIES);

        if (! $includeVoided) {
            $query->whereNull('voided_at');
        }

        $runningTotal = '0.00';

        return $query->get()->map(function (FolioEntry $entry) use (&$runningTotal): array {
            $isVoided = $entry->voided_at !== null;

            if (! $isVoided) {
                $runningTotal = bcadd($runningTotal, (string) $entry->amount, 2);
            }

            return [
                'id'             => $entry->id,
                'entry_date'     => $entry->entry_date->toDateString(),
                'created_at'     => $entry->created_at->format('Y-m-d H:i'),
                'charge_type'    => $entry->charge_type?->value,
                'charge_label'   => $entry->charge_type?->label(),
                'description'    => $entry->description,
                'quantity'       => (float) $entry->quantity,
                'unit_price'     => (float) $entry->unit_price,
                'amount'         => (float) $entry->amount,
                'posting_source' => $entry->posting_source ?? 'MANUAL',
                'posting_key'    => $entry->posting_key,
                'stay_id'        => $entry->stay_id,
                'room_number'    => $entry->stay?->room?->room_number,
                'posted_by'      => $entry->postedBy?->name ?? '—',
                'is_voided'      => $isVoided,
                'voided_at'      => $entry->voided_at?->format('Y-m-d H:i'),
                'voided_by'      => $entry->voidedBy?->name,
                'void_reason'    => $entry->void_reason,
                'running_total'  => $runningTotal,
            ];
        })->all();
    }
}
