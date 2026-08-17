<?php

namespace App\Services\Posting;

use App\Enums\ChargeType;
use App\Models\FolioEntry;
use App\Services\FolioService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RoomChargePostingJob implements PostingJob
{
    public function __construct(
        private readonly FolioService $folioService,
    ) {}

    public function execute(PostingContext $context): PostingResult
    {
        if ($context->stay === null) {
            return PostingResult::skipped('No stay in context');
        }

        if ($this->isAlreadyPosted($context)) {
            return PostingResult::alreadyPosted();
        }

        $unitPrice = $this->resolveUnitPrice($context);

        if (bccomp($unitPrice, '0.00', 2) <= 0) {
            return PostingResult::skipped('Zero unit price for stay');
        }

        $postingKey  = $this->buildPostingKey($context);
        $dateLabel   = $context->businessDate->format('d/m/Y');
        $stay        = $context->stay;

        return DB::transaction(function () use ($context, $postingKey, $unitPrice, $dateLabel, $stay): PostingResult {
            // Lock folio inside the job's transaction
            $folio = \App\Models\Folio::lockForUpdate()->findOrFail($context->folio->id);

            if ($folio->status->value !== 'OPEN') {
                return PostingResult::skipped('Folio not open');
            }

            // Idempotency inside lock
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
                'charge_type'    => ChargeType::Room,
                'description'    => "Tiền phòng đêm {$dateLabel} - P.{$stay->room_id}",
                'quantity'       => '1.00',
                'unit_price'     => $unitPrice,
                'amount'         => $unitPrice,
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

        $postingKey = $this->buildPostingKey($context);

        FolioEntry::where('folio_id', $context->folio->id)
            ->where('posting_key', $postingKey)
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
        return $context->stay !== null
            && $context->folio->status->value === 'OPEN';
    }

    public function dependsOn(): array
    {
        return [];
    }

    private function buildPostingKey(PostingContext $context): string
    {
        return sprintf(
            'ROOM_NIGHT_%d_%s',
            $context->stay->id,
            $context->businessDate->toDateString(),
        );
    }

    /**
     * Commercial Source Principle (Product Sprint 03): rate must resolve from
     * the commercially-sold room type, not the Stay's live physical room. The
     * physical room can diverge from the sold type after a Change Room
     * operational move (same- or different-room-type). RoomAssignment.room_type_id
     * is the commercial requirement slot the assignment fulfills and is never
     * updated by a room move, so it — not $stay->room->room_type_id — is the
     * correct key. Falls back to the physical room's type only when no
     * RoomAssignment link exists, preserving prior behavior for that case.
     *
     * docs/yeucaumoi.txt mục 20-22 — Multiple Requirement Groups / Room Rate
     * Snapshot: when the assignment is linked to a SPECIFIC requirement line
     * (RoomAssignment.booking_requirement_id — the exact commercial group it
     * was allocated against), that line's own rate is authoritative. Without
     * this, two BookingRequirement rows sharing one room_type_id (e.g. 10
     * Twin @ 650,000 "Normal" + 1 Twin @ 300,000 "Internal Driver") would all
     * resolve to whichever row bookingRequirements->firstWhere() happens to
     * return first — silently overcharging or undercharging every assignment
     * that isn't that first row. Falls back to the room-type match only when
     * the assignment predates this link (legacy rows — same fallback the
     * migration itself documents).
     */
    private function resolveUnitPrice(PostingContext $context): string
    {
        $stay = $context->stay;
        $stay->loadMissing(['room', 'roomAssignment.bookingRequirement']);

        if ($stay->room === null) {
            return '0.00';
        }

        $assignment = $stay->roomAssignment;

        if ($assignment?->bookingRequirement !== null) {
            return (string) $assignment->bookingRequirement->room_price;
        }

        $roomTypeId = $assignment?->room_type_id ?? $stay->room->room_type_id;

        $context->booking->loadMissing('bookingRequirements');

        $requirement = $context->booking->bookingRequirements
            ->firstWhere('room_type_id', $roomTypeId);

        return $requirement ? (string) $requirement->room_price : '0.00';
    }
}
