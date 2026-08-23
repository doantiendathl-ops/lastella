<?php

declare(strict_types=1);

namespace App\Services\Posting;

use App\Enums\ChargeType;
use App\Models\FolioEntry;
use App\Services\ServiceRateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Room-Scoped Bed Operations Correction.
 *
 * ROOT CAUSE (fixed here): this job used to read
 * BookingPackageFlag(EXTRA_BED_PER_NIGHT), which is BOOKING-level (one row
 * per booking, no room dimension). NightAuditPipeline::run() builds one
 * PostingContext PER STAY and runs this job against every one of them — so
 * for a 3-room booking with a single "Giường phụ x1" enrollment, the OLD
 * code matched the SAME booking-level flag for all 3 stays and posted 3
 * separate FolioEntry rows (one per room), each charging the full quantity —
 * a 3× overcharge. Now reads room_assignments.extra_bed_quantity via
 * $context->stay->roomAssignment — the canonical, room-scoped source
 * (Mục III/V) — so each stay posts only its OWN room's quantity, and a room
 * with 0 never posts at all.
 *
 * ServicePackage/ServiceRate remain the sole source for price — this job
 * still resolves the effective EXTRA_BED unit rate the same way it always
 * did; only the QUANTITY source changed.
 */
class ExtraBedPostingJob implements PostingJob
{
    public function __construct(
        private readonly ServiceRateService $rateService,
    ) {}

    public function execute(PostingContext $context): PostingResult
    {
        if ($context->stay === null) {
            return PostingResult::skipped('No stay in context');
        }

        if ($this->isAlreadyPosted($context)) {
            return PostingResult::alreadyPosted();
        }

        $quantity = $this->roomExtraBedQuantity($context);

        if ($quantity <= 0) {
            return PostingResult::skipped('Room has no extra beds enrolled (extra_bed_quantity = 0)');
        }

        $rate = $this->rateService->resolveFor(ChargeType::ExtraBed, $context->businessDate);

        if ($rate === null) {
            return PostingResult::skipped('No active EXTRA_BED rate for business date');
        }

        $unitPrice  = (string) $rate->unit_price;
        $amount     = bcmul($unitPrice, (string) $quantity, 2);
        $postingKey = $this->buildPostingKey($context);
        $dateLabel  = $context->businessDate->format('d/m/Y');
        $stay       = $context->stay;

        return DB::transaction(function () use ($context, $postingKey, $unitPrice, $amount, $quantity, $dateLabel, $stay): PostingResult {
            $folio = \App\Models\Folio::lockForUpdate()->findOrFail($context->folio->id);

            if ($folio->status->value !== 'OPEN') {
                return PostingResult::skipped('Folio not open');
            }

            // whereNull('voided_at') — see RoomChargePostingJob's identical fix
            // for the reasoning (posting_key is no longer DB-unique).
            if (FolioEntry::where('folio_id', $folio->id)
                ->where('posting_key', $postingKey)
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->exists()) {
                return PostingResult::alreadyPosted();
            }

            $entry = FolioEntry::create([
                'folio_id'           => $folio->id,
                'night_audit_run_id' => $context->nightAuditRun?->id,
                'stay_id'            => $stay->id,
                'posting_key'        => $postingKey,
                'posting_source'     => 'NIGHT_AUDIT',
                'charge_type'        => ChargeType::ExtraBed,
                'description'        => "Giường phụ ({$quantity} giường) đêm {$dateLabel}",
                'quantity'           => number_format($quantity, 2, '.', ''),
                'unit_price'         => $unitPrice,
                'amount'             => $amount,
                'entry_date'         => $context->businessDate->toDateString(),
                'posted_by'          => $context->postedBy?->id ?? Auth::id(),
            ]);

            return PostingResult::posted($entry);
        });
    }

    public function rollback(PostingContext $context): void
    {
        if ($context->stay === null) {
            return;
        }

        FolioEntry::where('folio_id', $context->folio->id)
            ->where('posting_key', $this->buildPostingKey($context))
            ->delete();
    }

    public function isAlreadyPosted(PostingContext $context): bool
    {
        if ($context->stay === null) {
            return false;
        }

        return FolioEntry::where('folio_id', $context->folio->id)
            ->where('posting_key', $this->buildPostingKey($context))
            ->whereNull('voided_at')
            ->exists();
    }

    public function shouldProcess(PostingContext $context): bool
    {
        if ($context->stay === null || $context->folio->status->value !== 'OPEN') {
            return false;
        }

        return $this->roomExtraBedQuantity($context) > 0;
    }

    public function dependsOn(): array
    {
        return [RoomChargePostingJob::class];
    }

    /**
     * Canonical room-scoped quantity source (Mục V/VI): the RoomAssignment
     * behind THIS stay — never the booking as a whole. A stay whose
     * assignment was released/has no link (should not normally happen for
     * an active stay) has 0 extra beds, never a fallback guess.
     */
    private function roomExtraBedQuantity(PostingContext $context): int
    {
        return $context->stay?->roomAssignment?->extra_bed_quantity ?? 0;
    }

    private function buildPostingKey(PostingContext $context): string
    {
        return sprintf(
            'EXTRA_BED_%d_%s',
            $context->stay->id,
            $context->businessDate->toDateString(),
        );
    }
}
