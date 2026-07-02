<?php

namespace App\Services\Posting;

use App\Enums\ChargeType;
use App\Enums\RateStatus;
use App\Models\FolioEntry;
use App\Models\RoomRate;
use App\Services\FolioService;
use App\Services\HotelSettingsService;

class LateCheckoutFeePostingJob implements PostingJob
{
    public function __construct(
        private readonly FolioService $folioService,
        private readonly HotelSettingsService $hotelSettings,
    ) {}

    public function execute(PostingContext $context): PostingResult
    {
        if (! $this->shouldProcess($context)) {
            return PostingResult::skipped('Late checkout condition not met.');
        }

        if ($this->isAlreadyPosted($context)) {
            return PostingResult::alreadyPosted();
        }

        $rate = $this->resolveRate($context);
        if ($rate === null || $rate->late_checkout_price <= 0) {
            return PostingResult::skipped('No applicable late checkout rate found.');
        }

        $postingKey = $this->buildPostingKey($context);

        $entry = $this->folioService->addCharge($context->folio, [
            'charge_type'    => ChargeType::LateCheckout->value,
            'unit_price'     => $rate->late_checkout_price,
            'description'    => 'Phí trả phòng muộn',
            'quantity'       => 1,
            'stay_id'        => $context->stay->id,
            'posting_source' => 'SYSTEM_AUTO',
            'posting_key'    => $postingKey,
            'posted_by'      => $context->postedBy?->id,
        ]);

        return PostingResult::posted($entry);
    }

    public function rollback(PostingContext $context): void
    {
        $postingKey = $this->buildPostingKey($context);

        FolioEntry::where('folio_id', $context->folio->id)
            ->where('posting_key', $postingKey)
            ->whereNull('voided_at')
            ->update(['voided_at' => now()]);
    }

    public function isAlreadyPosted(PostingContext $context): bool
    {
        return FolioEntry::where('folio_id', $context->folio->id)
            ->where('posting_key', $this->buildPostingKey($context))
            ->whereNull('voided_at')
            ->exists();
    }

    public function shouldProcess(PostingContext $context): bool
    {
        if ($context->stay === null) {
            return false;
        }

        if ($context->folio->status->value !== 'OPEN') {
            return false;
        }

        $stay = $context->stay;
        if ($stay->actual_checkout_at === null || $stay->planned_checkout_at === null) {
            return false;
        }

        $graceMinutes = $this->hotelSettings->getInt('late_checkout_grace_minutes', 30);
        $threshold    = $stay->planned_checkout_at->copy()->addMinutes($graceMinutes);

        return $stay->actual_checkout_at->gt($threshold);
    }

    public function dependsOn(): array
    {
        return [];
    }

    private function buildPostingKey(PostingContext $context): string
    {
        return "LATE_CHECKOUT_{$context->stay->id}";
    }

    private function resolveRate(PostingContext $context): ?RoomRate
    {
        $stay = $context->stay;
        $stay->loadMissing('room');

        if ($stay->room === null) {
            return null;
        }

        $checkoutDate = $stay->actual_checkout_at ?? $context->businessDate;

        return RoomRate::where('room_type_id', $stay->room->room_type_id)
            ->where('status', RateStatus::Active)
            ->whereDate('valid_from', '<=', $checkoutDate)
            ->whereDate('valid_to', '>=', $checkoutDate)
            ->orderByDesc('valid_from')
            ->first();
    }
}
