<?php

namespace App\Services\Posting;

use App\Enums\ChargeType;
use App\Enums\RateStatus;
use App\Models\FolioEntry;
use App\Models\RoomRate;
use App\Services\FolioService;
use App\Services\HotelSettingsService;

class EarlyCheckinFeePostingJob implements PostingJob
{
    public function __construct(
        private readonly FolioService $folioService,
        private readonly HotelSettingsService $hotelSettings,
    ) {}

    public function execute(PostingContext $context): PostingResult
    {
        if (! $this->shouldProcess($context)) {
            return PostingResult::skipped('Early checkin condition not met.');
        }

        if ($this->isAlreadyPosted($context)) {
            return PostingResult::alreadyPosted();
        }

        $rate = $this->resolveRate($context);
        if ($rate === null || $rate->early_checkin_price <= 0) {
            return PostingResult::skipped('No applicable early checkin rate found.');
        }

        $postingKey = $this->buildPostingKey($context);

        $entry = $this->folioService->addCharge($context->folio, [
            'charge_type'    => ChargeType::EarlyCheckin->value,
            'unit_price'     => $rate->early_checkin_price,
            'description'    => 'Phí nhận phòng sớm',
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
        if ($stay->actual_checkin_at === null || $stay->planned_checkin_at === null) {
            return false;
        }

        $graceMinutes = $this->hotelSettings->getInt('early_checkin_grace_minutes', 30);
        $threshold    = $stay->planned_checkin_at->copy()->subMinutes($graceMinutes);

        return $stay->actual_checkin_at->lt($threshold);
    }

    public function dependsOn(): array
    {
        return [];
    }

    private function buildPostingKey(PostingContext $context): string
    {
        return "EARLY_CHECKIN_{$context->stay->id}";
    }

    private function resolveRate(PostingContext $context): ?RoomRate
    {
        $stay = $context->stay;
        $stay->loadMissing('room');

        if ($stay->room === null) {
            return null;
        }

        $checkinDate = $stay->actual_checkin_at ?? $context->businessDate;

        return RoomRate::where('room_type_id', $stay->room->room_type_id)
            ->where('status', RateStatus::Active)
            ->whereDate('valid_from', '<=', $checkinDate)
            ->whereDate('valid_to', '>=', $checkinDate)
            ->orderByDesc('valid_from')
            ->first();
    }
}
