<?php

declare(strict_types=1);

namespace App\Services\Posting;

use App\Enums\ChargeType;
use App\Models\BookingPackageFlag;
use App\Models\FolioEntry;
use App\Services\PackageEnrollmentService;
use App\Services\ServiceRateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ExtraPersonPostingJob implements PostingJob
{
    public function __construct(
        private readonly ServiceRateService $rateService,
        private readonly PackageEnrollmentService $enrollmentService,
    ) {}

    public function execute(PostingContext $context): PostingResult
    {
        if ($context->stay === null) {
            return PostingResult::skipped('No stay in context');
        }

        if ($this->isAlreadyPosted($context)) {
            return PostingResult::alreadyPosted();
        }

        $flag = BookingPackageFlag::where('booking_id', $context->booking->id)
            ->where('package_key', PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT)
            ->first();

        if ($flag === null) {
            return PostingResult::skipped('Not enrolled in EXTRA_PERSON_PER_NIGHT');
        }

        $rate = $this->rateService->resolveFor(ChargeType::ExtraPerson, $context->businessDate);

        if ($rate === null) {
            return PostingResult::skipped('No active EXTRA_PERSON rate for business date');
        }

        $quantity   = max(1, intval($flag->value ?? '1'));
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
                'charge_type'        => ChargeType::ExtraPerson,
                'description'        => "Người thêm ({$quantity} người) đêm {$dateLabel}",
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

        return $this->enrollmentService->isEnrolled(
            $context->booking,
            PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
        );
    }

    public function dependsOn(): array
    {
        return [RoomChargePostingJob::class];
    }

    private function buildPostingKey(PostingContext $context): string
    {
        return sprintf(
            'EXTRA_PERSON_%d_%s',
            $context->stay->id,
            $context->businessDate->toDateString(),
        );
    }
}
