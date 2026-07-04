<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\FolioStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\FolioEntry;
use Carbon\Carbon;

class ReconciliationService
{
    /**
     * Returns bookings with a positive balance_due (charges exceed payments).
     *
     * Formula matches BookingService::paymentSummary() exactly.
     * Eager-loads folio entries and payments to avoid N+1 queries.
     */
    public function outstandingBalances(array $filters = []): array
    {
        $query = Booking::query()
            ->with([
                'folio',
                'folio.folioEntries' => fn ($q) => $q->whereNull('voided_at'),
                'bookingPayments',
            ])
            ->whereHas('folio', fn ($q) => $q->where('status', '!=', FolioStatus::Voided->value));

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $rows = [];

        foreach ($query->get() as $booking) {
            $balance = $this->computeBalance($booking);

            if ($balance['balance_due'] > 0.005) {
                $rows[] = [
                    'booking_id'    => $booking->id,
                    'booking_code'  => $booking->booking_code,
                    'customer_name' => $booking->customer_name,
                    'status'        => $booking->status->value,
                    'folio_status'  => $booking->folio?->status->value,
                    'total_charges' => $balance['total_charges'],
                    'paid_total'    => $balance['paid_total'],
                    'balance_due'   => $balance['balance_due'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Returns bookings with detected financial discrepancies:
     * - Checked-out booking still carrying a positive balance_due
     * - Closed folio with a non-zero balance
     */
    public function discrepancies(): array
    {
        $bookings = Booking::query()
            ->with([
                'folio',
                'folio.folioEntries' => fn ($q) => $q->whereNull('voided_at'),
                'bookingPayments',
            ])
            ->where(function ($q): void {
                $q->where('status', BookingStatus::CheckedOut->value)
                  ->orWhereHas('folio', fn ($q2) => $q2->where('status', FolioStatus::Closed->value));
            })
            ->get();

        $rows = [];
        $seen = [];

        foreach ($bookings as $booking) {
            if (isset($seen[$booking->id])) {
                continue;
            }

            $balance = $this->computeBalance($booking);

            if (abs($balance['balance_due']) < 0.005) {
                continue;
            }

            $seen[$booking->id] = true;

            $type = match (true) {
                $booking->status === BookingStatus::CheckedOut
                    => 'CHECKED_OUT_OUTSTANDING_BALANCE',
                $booking->folio?->status === FolioStatus::Closed
                    => 'CLOSED_FOLIO_NON_ZERO_BALANCE',
                default => 'NON_ZERO_BALANCE',
            };

            $rows[] = [
                'type'           => $type,
                'booking_id'     => $booking->id,
                'booking_code'   => $booking->booking_code,
                'customer_name'  => $booking->customer_name,
                'booking_status' => $booking->status->value,
                'folio_status'   => $booking->folio?->status->value,
                'balance_due'    => $balance['balance_due'],
            ];
        }

        return $rows;
    }

    /**
     * Returns voided folio entries whose voided_at falls within the given date range.
     * Includes booking reference, charge detail, and void metadata.
     */
    public function voidedEntries(Carbon $from, Carbon $to): array
    {
        return FolioEntry::with(['folio.booking', 'voidedBy'])
            ->whereNotNull('voided_at')
            ->whereDate('voided_at', '>=', $from->toDateString())
            ->whereDate('voided_at', '<=', $to->toDateString())
            ->orderBy('voided_at', 'desc')
            ->get()
            ->map(fn (FolioEntry $entry): array => [
                'id'            => $entry->id,
                'booking_code'  => $entry->folio?->booking?->booking_code ?? '—',
                'customer_name' => $entry->folio?->booking?->customer_name ?? '—',
                'charge_type'   => $entry->charge_type->value,
                'charge_label'  => $entry->charge_type->label(),
                'description'   => $entry->description,
                'amount'        => (float) $entry->amount,
                'entry_date'    => $entry->entry_date->toDateString(),
                'voided_at'     => $entry->voided_at?->format('Y-m-d H:i'),
                'voided_by'     => $entry->voidedBy?->name ?? '—',
                'void_reason'   => $entry->void_reason ?? '—',
            ])
            ->all();
    }

    /**
     * Lazy iterable of outstanding balance rows for streaming CSV export.
     */
    public function exportOutstandingRows(array $filters = []): iterable
    {
        return $this->outstandingBalances($filters);
    }

    /**
     * Lazy iterable of voided entry rows for streaming CSV export.
     */
    public function exportVoidedRows(Carbon $from, Carbon $to): iterable
    {
        // lazy() uses chunkById() internally, which correctly processes with() eager loading
        // cursor() bypasses the eager loading pipeline and would trigger N+1 per row
        return FolioEntry::with(['folio.booking', 'voidedBy'])
            ->whereNotNull('voided_at')
            ->whereDate('voided_at', '>=', $from->toDateString())
            ->whereDate('voided_at', '<=', $to->toDateString())
            ->orderBy('voided_at', 'desc')
            ->lazy()
            ->map(fn (FolioEntry $entry): array => [
                $entry->folio?->booking?->booking_code ?? '—',
                $entry->folio?->booking?->customer_name ?? '—',
                $entry->charge_type->label(),
                $entry->description,
                (float) $entry->amount,
                $entry->entry_date->toDateString(),
                $entry->voided_at?->format('Y-m-d H:i') ?? '—',
                $entry->voidedBy?->name ?? '—',
                $entry->void_reason ?? '—',
            ]);
    }

    /**
     * Computes folio_total, paid_total, and balance_due for a booking.
     *
     * Uses the same formula as BookingService::paymentSummary().
     * Requires folio.folioEntries (filtered to non-voided) and bookingPayments
     * to be eager-loaded on the booking model.
     */
    private function computeBalance(Booking $booking): array
    {
        $folioTotal = (float) ($booking->folio?->folioEntries->sum('amount') ?? 0);

        $paidTotal = 0.0;
        foreach ($booking->bookingPayments as $payment) {
            $paidTotal += match ($payment->payment_type) {
                PaymentType::Deposit,
                PaymentType::AdditionalDeposit,
                PaymentType::RoomPayment,
                PaymentType::ServicePayment,
                PaymentType::Adjustment => (float) $payment->amount,
                PaymentType::Refund => -1.0 * (float) $payment->amount,
            };
        }

        return [
            'total_charges' => $folioTotal,
            'paid_total'    => $paidTotal,
            'balance_due'   => (float) bcsub((string) $folioTotal, (string) $paidTotal, 2),
        ];
    }
}
