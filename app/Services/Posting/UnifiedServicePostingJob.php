<?php

declare(strict_types=1);

namespace App\Services\Posting;

use App\Enums\ChargeType;
use App\Enums\ServiceBillingMode;
use App\Models\BookingService;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Sections 15/16) — the
 * one posting path for every Service enrolled through the new catalog.
 *
 * PER_NIGHT rows are posted here, registered ADDITIONALLY into
 * NightAuditService alongside the 4 legacy jobs (never replacing them in
 * this slice — legacy Breakfast/ExtraPerson/ExtraBed/CityTax/ServicePackage
 * postings are untouched).
 *
 * ONE_TIME rows are NOT posted through Night Audit at all (Section 15:
 * "Không buộc ONE_TIME phải chờ Night Audit nếu không có lý do nghiệp vụ")
 * — postOneTime() is called synchronously by
 * BookingServiceEnrollmentService::enroll() the moment the row is created.
 *
 * Idempotency (Section 16): posting_key = booking_service_id + scope
 * (room_assignment_id or "BOOKING") + business date — a booking with
 * multiple rooms can never have one room's enrollment double-charge
 * another room's stay, and a Night Audit retry can never create a second
 * charge for a date already posted.
 */
class UnifiedServicePostingJob implements PostingJob
{
    private const POSTING_KEY_PREFIX = 'UNIFIED_SVC';

    public function execute(PostingContext $context): PostingResult
    {
        if ($context->stay === null) {
            return PostingResult::skipped('No stay in context');
        }

        $rows = $this->eligiblePerNightRowsFor($context);

        if ($rows->isEmpty()) {
            return PostingResult::skipped('No unified PER_NIGHT service enrollments for this stay');
        }

        $lastEntry = null;
        $anyNewlyPosted = false;
        $anyAlreadyPosted = false;
        $lastMessage = 'No unified service produced a charge';

        foreach ($rows as $bookingService) {
            $result = $this->postPerNight($bookingService, $context);

            if ($result->entry !== null) {
                $lastEntry = $result->entry;
            }
            if ($result->success && ! $result->alreadyPosted && $result->entry !== null) {
                $anyNewlyPosted = true;
            }
            if ($result->alreadyPosted) {
                $anyAlreadyPosted = true;
            }
            if ($result->message !== '') {
                $lastMessage = $result->message;
            }
        }

        if ($anyNewlyPosted && $lastEntry !== null) {
            return PostingResult::posted($lastEntry);
        }

        if ($anyAlreadyPosted) {
            return PostingResult::alreadyPosted();
        }

        return PostingResult::skipped($lastMessage);
    }

    public function rollback(PostingContext $context): void
    {
        if ($context->stay === null) {
            return;
        }

        // Scoped to THIS stay only (mirrors ServicePackagePostingJob::rollback()) — without
        // this, a rollback triggered for one room's PostingContext would delete every unified
        // service entry on the folio for the date, including other rooms' legitimate charges.
        // whereDate() (not where()) on entry_date: the column may carry a time component
        // depending on driver, and a plain string equality check can silently match nothing.
        // No backslash-escaping on the LIKE prefix: MySQL defaults to '\' as the LIKE escape
        // character but SQLite (used in tests) does not unless an ESCAPE clause is given, so
        // an escaped pattern silently matches nothing on SQLite. Unescaped is still correct
        // here — POSTING_KEY_PREFIX's only special char is '_', and SQL's '_' wildcard matches
        // a literal underscore too, so it can never cause a false negative on either driver.
        FolioEntry::where('folio_id', $context->folio->id)
            ->where('stay_id', $context->stay->id)
            ->whereDate('entry_date', $context->businessDate->toDateString())
            ->where('posting_key', 'like', self::POSTING_KEY_PREFIX.'_%')
            ->delete();
    }

    public function isAlreadyPosted(PostingContext $context): bool
    {
        if ($context->stay === null) {
            return false;
        }

        $rows = $this->eligiblePerNightRowsFor($context);

        if ($rows->isEmpty()) {
            return false;
        }

        foreach ($rows as $bookingService) {
            $posted = FolioEntry::where('folio_id', $context->folio->id)
                ->where('posting_key', $this->buildPostingKey($bookingService, $context))
                ->whereNull('voided_at')
                ->exists();

            if (! $posted) {
                return false;
            }
        }

        return true;
    }

    public function shouldProcess(PostingContext $context): bool
    {
        if ($context->stay === null || $context->folio->status->value !== 'OPEN') {
            return false;
        }

        return $this->eligiblePerNightRowsFor($context)->isNotEmpty();
    }

    public function dependsOn(): array
    {
        return [RoomChargePostingJob::class];
    }

    /**
     * ONE_TIME billing (Section 15): posted synchronously at enrollment,
     * never via Night Audit. Idempotency key is the booking_service id
     * alone — each row is created exactly once, so this can never
     * double-post even if called more than once for the same row.
     */
    public function postOneTime(BookingService $bookingService, ?User $postedBy = null): ?FolioEntry
    {
        if (! $bookingService->service->is_chargeable) {
            return null;
        }

        if (bccomp((string) $bookingService->actual_price, '0.00', 2) <= 0) {
            return null;
        }

        $postingKey = sprintf('%s_ONE_TIME_%d', self::POSTING_KEY_PREFIX, $bookingService->id);

        return DB::transaction(function () use ($bookingService, $postedBy, $postingKey): ?FolioEntry {
            $folio = $bookingService->booking->folio()->first();

            if ($folio === null) {
                return null;
            }

            $lockedFolio = Folio::lockForUpdate()->findOrFail($folio->id);

            if ($lockedFolio->status->value !== 'OPEN') {
                return null;
            }

            if (FolioEntry::where('folio_id', $lockedFolio->id)
                ->where('posting_key', $postingKey)
                ->lockForUpdate()
                ->exists()) {
                return null;
            }

            $amount = bcmul((string) $bookingService->actual_price, (string) $bookingService->quantity, 2);

            return FolioEntry::create([
                'folio_id' => $lockedFolio->id,
                'stay_id' => $bookingService->roomAssignment?->stay?->id,
                'posting_key' => $postingKey,
                'posting_source' => 'BOOKING_SERVICE',
                'charge_type' => ChargeType::Other,
                'description' => "{$bookingService->service->name} ({$bookingService->service->code})",
                'quantity' => number_format($bookingService->quantity, 2, '.', ''),
                'unit_price' => (string) $bookingService->actual_price,
                'amount' => $amount,
                'entry_date' => now()->toDateString(),
                'posted_by' => $postedBy?->id ?? Auth::id(),
            ]);
        });
    }

    private function postPerNight(BookingService $bookingService, PostingContext $context): PostingResult
    {
        if (! $bookingService->service->is_chargeable || bccomp((string) $bookingService->actual_price, '0.00', 2) <= 0) {
            return PostingResult::skipped('Not chargeable or zero price');
        }

        $postingKey = $this->buildPostingKey($bookingService, $context);
        $dateLabel = $context->businessDate->format('d/m/Y');
        $amount = bcmul((string) $bookingService->actual_price, (string) $bookingService->quantity, 2);

        return DB::transaction(function () use ($bookingService, $context, $postingKey, $dateLabel, $amount): PostingResult {
            $folio = Folio::lockForUpdate()->findOrFail($context->folio->id);

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
                'folio_id' => $folio->id,
                'stay_id' => $context->stay->id,
                'posting_key' => $postingKey,
                'posting_source' => 'NIGHT_AUDIT',
                'charge_type' => ChargeType::Other,
                'description' => "{$bookingService->service->name} ({$bookingService->quantity} {$bookingService->service->unit_label}) đêm {$dateLabel}",
                'quantity' => number_format($bookingService->quantity, 2, '.', ''),
                'unit_price' => (string) $bookingService->actual_price,
                'amount' => $amount,
                'entry_date' => $context->businessDate->toDateString(),
                'posted_by' => $context->postedBy?->id ?? Auth::id(),
            ]);

            return PostingResult::posted($entry);
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, BookingService>
     */
    private function eligiblePerNightRowsFor(PostingContext $context)
    {
        $roomAssignmentId = $context->stay?->room_assignment_id;

        return BookingService::where('booking_id', $context->booking->id)
            ->where('billing_mode_selected', ServiceBillingMode::PerNight->value)
            ->whereIn('fulfillment_status', ['CREATED', 'CONFIRMED', 'COMPLETED'])
            ->with(['service', 'roomAssignment'])
            ->get()
            ->filter(function (BookingService $bs) use ($roomAssignmentId) {
                // BOOKING-scoped rows apply to every stay of the booking (room_assignment_id is null).
                // ROOM-scoped rows apply ONLY to the stay whose RoomAssignment matches — this is the
                // exact guard that prevents the multi-room over-posting bug the legacy Extra Bed flow
                // once had (see ExtraBedPostingJob docblock).
                if ($bs->room_assignment_id === null) {
                    return true;
                }

                return $roomAssignmentId !== null && $bs->room_assignment_id === $roomAssignmentId;
            });
    }

    /**
     * CRITICAL fix (security review, 2026-08-16): NightAuditPipeline builds
     * one PostingContext PER ACTIVE STAY and calls execute() once per stay
     * (see NightAuditPipeline::run()). A ROOM-scoped row is only ever
     * eligible for its OWN stay (eligiblePerNightRowsFor() already filters
     * that), so keying on stay->id there is correct and required — it is
     * what makes two different rooms' enrollments never share a key.
     *
     * A BOOKING-scoped row (room_assignment_id === null), however, is
     * eligible for EVERY stay of the booking by design (Section 7 — a
     * BOOKING-scoped service applies booking-wide). If the key still
     * included stay->id, a 3-room booking would produce 3 DIFFERENT keys
     * for the same night — the exact multi-room over-posting bug class
     * this feature exists to prevent, just for BOOKING scope instead of
     * ROOM scope. The key must be stay-INDEPENDENT for booking-scoped rows
     * so that whichever stay in the loop posts first "claims" the night,
     * and every other stay's attempt for the same (row, date) correctly
     * resolves to alreadyPosted via the existing FolioEntry lock-and-check.
     */
    private function buildPostingKey(BookingService $bookingService, PostingContext $context): string
    {
        $scopeSegment = $bookingService->room_assignment_id !== null
            ? (string) $context->stay->id
            : 'BOOKING';

        return sprintf(
            '%s_%d_%s_%s',
            self::POSTING_KEY_PREFIX,
            $bookingService->id,
            $scopeSegment,
            $context->businessDate->toDateString(),
        );
    }
}
