<?php

namespace App\Services;

use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Exceptions\AlreadyVoidedException;
use App\Exceptions\FolioClosedException;
use App\Exceptions\FolioHasActiveEntriesException;
use App\Exceptions\FolioNumberOverflowException;
use App\Exceptions\FolioVoidedException;
use App\Models\Booking;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FolioService
{
    public function createFolioForBooking(Booking $booking): Folio
    {
        /** @var Folio $folio */
        $folio = Folio::create([
            'booking_id'    => $booking->id,
            'folio_number'  => $this->generateFolioNumber(),
            'currency_code' => 'VND',
            'status'        => FolioStatus::Open,
            'created_by'    => Auth::id(),
        ]);

        return $folio;
    }

    public function addCharge(Folio $folio, array $data): FolioEntry
    {
        return DB::transaction(function () use ($folio, $data): FolioEntry {
            // ADR-12: lock folio row before state check and entry creation
            $locked = Folio::lockForUpdate()->findOrFail($folio->id);

            if ($locked->status === FolioStatus::Voided) {
                throw new FolioVoidedException();
            }

            if ($locked->status !== FolioStatus::Open) {
                throw new FolioClosedException();
            }

            // ADR-16: amount is always computed server-side, never accepted from HTTP
            $data['amount']     = bcmul((string) $data['quantity'], (string) $data['unit_price'], 2);
            $data['posted_by']  = $data['posted_by'] ?? Auth::id();
            $data['entry_date'] = $data['entry_date'] ?? today()->toDateString();

            /** @var FolioEntry $entry */
            $entry = $locked->folioEntries()->create($data);

            return $entry;
        });
    }

    public function voidEntry(FolioEntry $entry, string $reason, User $voidedBy): void
    {
        DB::transaction(function () use ($entry, $reason, $voidedBy): void {
            // ADR-12 canonical lock order: Folio before FolioEntry.
            // Lock Folio first to get a fresh (non-stale) status and to prevent
            // a concurrent closeFolio() from transitioning the folio between
            // this status check and the FolioEntry update below.
            $folio = Folio::lockForUpdate()->find($entry->folio_id);

            // ADR-33: folio must be Open for void operations.
            // Service-layer guard: Policy::void() is not called by Artisan commands,
            // queue jobs, or direct service invocations.
            if ($folio !== null && $folio->status !== FolioStatus::Open) {
                if ($folio->status === FolioStatus::Voided) {
                    throw new FolioVoidedException();
                }
                throw new FolioClosedException();
            }

            // Lock FolioEntry to prevent a concurrent voidEntry call on the same
            // entry from both passing the voided_at check before either commits.
            $locked = FolioEntry::lockForUpdate()->findOrFail($entry->id);

            if ($locked->voided_at !== null) {
                throw new AlreadyVoidedException();
            }

            $locked->update([
                'voided_at'   => now(),
                'voided_by'   => $voidedBy->id,
                'void_reason' => $reason,
            ]);
        });
    }

    /**
     * ADR-27: the only public entry point for system room charge posting.
     * doPostRoomCharge() is private and must never be called directly.
     * Shared foundation for Phase 3.1B checkout flow.
     * Concurrency contract: doPostRoomCharge() holds the folio lock.
     */
    public function autoPostRoomCharge(Booking $booking, ?User $postedBy = null): ?FolioEntry
    {
        $folio = $booking->folio;
        if ($folio === null) {
            return null;
        }

        $booking->loadMissing('bookingRequirements');

        // ADR-16: amount computed server-side using bcmath
        $amount = $booking->bookingRequirements->reduce(
            fn (string $carry, $r): string => bcadd(
                $carry,
                bcmul((string) $r->room_price, (string) $r->quantity, 2),
                2
            ),
            '0.00'
        );

        if (bccomp($amount, '0.00', 2) <= 0) {
            return null;
        }

        return $this->doPostRoomCharge($folio, $booking, $amount, $postedBy);
    }

    public function getFolioTotal(Booking $booking): float
    {
        $folio = $booking->folio;

        if ($folio === null) {
            return 0.0;
        }

        return (float) $folio->folioEntries()->whereNull('voided_at')->sum('amount');
    }

    public function closeFolio(Folio $folio, User $closedBy): void
    {
        DB::transaction(function () use ($folio, $closedBy): void {
            // ADR-12: lock folio row so that concurrent addCharge() or
            // voidFolioOnCancellation() cannot race with the status change.
            // Reads $locked from DB to avoid acting on a stale in-memory status.
            $locked = Folio::lockForUpdate()->findOrFail($folio->id);

            if ($locked->status === FolioStatus::Voided) {
                throw new FolioVoidedException();
            }

            if ($locked->status === FolioStatus::Closed) {
                return; // idempotent — concurrent close already won
            }

            $locked->update([
                'status'    => FolioStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $closedBy->id,
            ]);
        });
    }

    public function reopenFolio(Folio $folio): void
    {
        $folio->update([
            'status'    => FolioStatus::Open,
            'closed_at' => null,
            'closed_by' => null,
        ]);
    }

    /**
     * ADR-6: 6-digit zero-padded sequence per calendar day.
     * Verified under MySQL/InnoDB (production) and SQLite (test database only).
     * Not verified under PostgreSQL — this project does not use PostgreSQL.
     *
     * Mechanism (MySQL/InnoDB):
     *   1. insertOrIgnore — INSERT IGNORE ensures the row exists; no-op on dup key.
     *   2. increment — emits UPDATE col = col + 1; acquires X row lock for the
     *      duration of the transaction; all concurrent callers serialise on this lock.
     *   3. value() read — within the same transaction, InnoDB MVCC guarantees that
     *      reads see the transaction's own uncommitted DML (own-DML visibility rule).
     *
     * Mechanism (SQLite, test database):
     *   SQLite uses a file-level write lock; only one writer runs at a time.
     *   increment() is therefore serialised implicitly; value() sees the updated row.
     *
     * Throws FolioNumberOverflowException if daily sequence exceeds 999 999.
     */
    private function generateFolioNumber(): string
    {
        $date    = now()->toDateString();
        $dateKey = now()->format('Ymd');

        $sequence = DB::transaction(function () use ($date): int {
            // Ensure the row exists; no-op on duplicate (cross-database safe)
            DB::table('folio_number_sequences')->insertOrIgnore([
                'sequence_date' => $date,
                'last_sequence' => 0,
            ]);

            // Atomic increment — row lock in MySQL, serialised by write-lock in SQLite
            DB::table('folio_number_sequences')
                ->where('sequence_date', $date)
                ->increment('last_sequence');

            // Read back within the same transaction — sees our own DML
            return (int) DB::table('folio_number_sequences')
                ->where('sequence_date', $date)
                ->value('last_sequence');
        });

        if ($sequence > 999_999) {
            throw new FolioNumberOverflowException($date);
        }

        return 'FLO-' . $dateKey . '-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /**
     * ADR-32: canonical method for ALL balance decisions. Must be used anywhere
     * balance_due is computed (not getFolioTotal which is display-only).
     * ADR-37: VOIDED folio always returns 0.00 — no balance is owed.
     * ADR-11: transition guard — when the system aggregate room charge has not
     * yet been posted, fall back to requirements_estimate + non_room_active_entries
     * to avoid understating the balance during the check-in window.
     * Shared foundation for Phase 3.1B paymentSummary() refactor.
     * No lock required — reads only, called outside mutating transactions.
     */
    public function calculateGuardedFolioTotal(Booking $booking): float
    {
        $folio = $booking->folio;

        if ($folio === null || $folio->status === FolioStatus::Voided) {
            return 0.0;
        }

        $postingKey = "ROOM_CHARGE_{$booking->id}_AGGREGATE";

        $systemRoomPosted = FolioEntry::where('folio_id', $folio->id)
            ->where('posting_key', $postingKey)
            ->whereNull('voided_at')
            ->exists();

        if ($systemRoomPosted) {
            return (float) FolioEntry::where('folio_id', $folio->id)
                ->whereNull('voided_at')
                ->sum('amount');
        }

        // ADR-11: system room charge absent — use estimate to avoid understatement
        $booking->loadMissing('bookingRequirements');

        $estimate = $booking->bookingRequirements->reduce(
            fn (string $carry, $r): string => bcadd(
                $carry,
                bcmul((string) $r->room_price, (string) $r->quantity, 2),
                2
            ),
            '0.00'
        );

        $nonRoomSum = (string) FolioEntry::where('folio_id', $folio->id)
            ->where('charge_type', '!=', ChargeType::Room->value)
            ->whereNull('voided_at')
            ->sum('amount');

        return (float) bcadd($estimate, $nonRoomSum, 2);
    }

    /**
     * ADR-35: idempotent — calling on an already-CLOSED folio is a no-op.
     * ADR-29: public (not controller-routed) so BookingPaymentService can
     * trigger auto-close when balance reaches zero after a payment.
     * Locking contract: caller must hold the Folio row lock before calling.
     */
    public function autoCloseFolio(Folio $folio, User $closedBy): void
    {
        if ($folio->status === FolioStatus::Closed) {
            return;
        }

        if ($folio->status === FolioStatus::Voided) {
            throw new FolioVoidedException();
        }

        $folio->update([
            'status'    => FolioStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $closedBy->id,
        ]);
    }

    /**
     * ADR-36: public (not controller-routed) so BookingService::cancelBooking
     * can void the folio when a booking is cancelled.
     * If the folio still has active (non-voided) entries, throws
     * FolioHasActiveEntriesException and leaves the folio OPEN — the caller
     * must surface this to staff so they can void entries before cancelling.
     * ADR-12: acquires Folio lockForUpdate within the caller's transaction.
     * Locking contract: caller must hold the Booking row lock before calling;
     * this method then acquires Folio (canonical order: Booking → Folio).
     */
    public function voidFolioOnCancellation(Folio $folio): void
    {
        // ADR-12: lock Folio to get a current-state read — eliminates the
        // stale-object race where $folio was hydrated before a concurrent
        // closeFolio() committed, which would otherwise allow a Closed folio
        // to be silently overwritten to Voided (terminal state corruption).
        $locked = Folio::lockForUpdate()->findOrFail($folio->id);

        if ($locked->status === FolioStatus::Voided) {
            return; // idempotent
        }

        if ($locked->status !== FolioStatus::Open) {
            throw new FolioClosedException();
        }

        $hasActiveEntries = FolioEntry::where('folio_id', $locked->id)
            ->whereNull('voided_at')
            ->exists();

        if ($hasActiveEntries) {
            throw new FolioHasActiveEntriesException();
        }

        $locked->update(['status' => FolioStatus::Voided]);
    }

    /**
     * ADR-27: private — only callable via autoPostRoomCharge().
     * Posts the aggregate system room charge entry with idempotency via posting_key.
     * ADR-12: locks the Folio row before state check and entry creation.
     * ADR-13: posting_key is set internally; never accepted from HTTP.
     */
    private function doPostRoomCharge(Folio $folio, Booking $booking, string $amount, ?User $postedBy): ?FolioEntry
    {
        return DB::transaction(function () use ($folio, $booking, $amount, $postedBy): ?FolioEntry {
            $locked = Folio::lockForUpdate()->findOrFail($folio->id);

            if ($locked->status !== FolioStatus::Open) {
                return null;
            }

            $postingKey = "ROOM_CHARGE_{$booking->id}_AGGREGATE";

            // Idempotency check inside the lock — prevents duplicate on concurrent calls
            if (FolioEntry::where('folio_id', $locked->id)
                ->where('posting_key', $postingKey)
                ->lockForUpdate()
                ->exists()) {
                return null;
            }

            /** @var FolioEntry $entry */
            $entry = FolioEntry::create([
                'folio_id'    => $locked->id,
                'posting_key' => $postingKey,
                'charge_type' => ChargeType::Room,
                'description' => 'Tiền phòng (tổng hợp)',
                'quantity'    => '1.00',
                'unit_price'  => $amount,
                'amount'      => $amount,
                'entry_date'  => today()->toDateString(),
                'posted_by'   => $postedBy?->id ?? Auth::id(),
            ]);

            return $entry;
        });
    }
}
