<?php

declare(strict_types=1);

namespace App\Services\Posting;

use App\Enums\ChargeType;
use App\Models\FolioEntry;
use App\Services\HotelSettingsService;
use App\Services\ServiceRateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CityTaxPostingJob implements PostingJob
{
    public function __construct(
        private readonly ServiceRateService $rateService,
        private readonly HotelSettingsService $settingsService,
    ) {}

    public function execute(PostingContext $context): PostingResult
    {
        if ($context->stay === null) {
            return PostingResult::skipped('No stay in context');
        }

        if ($this->isAlreadyPosted($context)) {
            return PostingResult::alreadyPosted();
        }

        $rate = $this->rateService->resolveFor(ChargeType::CityTax, $context->businessDate);

        if ($rate === null) {
            return PostingResult::skipped('No active CITY_TAX rate for business date');
        }

        $quantity   = max(1, $this->settingsService->getInt('city_tax_quantity', 1));
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

            if (FolioEntry::where('folio_id', $folio->id)
                ->where('posting_key', $postingKey)
                ->lockForUpdate()
                ->exists()) {
                return PostingResult::alreadyPosted();
            }

            $entry = FolioEntry::create([
                'folio_id'       => $folio->id,
                'stay_id'        => $stay->id,
                'posting_key'    => $postingKey,
                'posting_source' => 'NIGHT_AUDIT',
                'charge_type'    => ChargeType::CityTax,
                'description'    => "Thuế du lịch đêm {$dateLabel}",
                'quantity'       => number_format($quantity, 2, '.', ''),
                'unit_price'     => $unitPrice,
                'amount'         => $amount,
                'entry_date'     => $context->businessDate->toDateString(),
                'posted_by'      => $context->postedBy?->id ?? Auth::id(),
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

        return $this->settingsService->getBool('city_tax_enabled', false);
    }

    public function dependsOn(): array
    {
        return [RoomChargePostingJob::class];
    }

    private function buildPostingKey(PostingContext $context): string
    {
        return sprintf(
            'CITY_TAX_%d_%s',
            $context->stay->id,
            $context->businessDate->toDateString(),
        );
    }
}
