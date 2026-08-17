<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Exceptions\BookingTerminalException;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Folio;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingPaymentService
{
    public function __construct(private readonly FolioService $folios)
    {
    }

    /**
     * docs/Prompt_2.txt mục XI/XII — a CheckedOut booking may still be
     * collecting on an outstanding balance, so it must NOT be treated as
     * terminal for a general payment the way Cancelled/NoShow are.
     * addDeposit()/addRefund()/deletePayment() are unaffected — they keep
     * the original all-terminal-statuses guard; only the general
     * "settle the balance" payment path is relaxed.
     */
    private const NON_PAYABLE_STATUSES = [
        BookingStatus::Cancelled,
        BookingStatus::NoShow,
    ];

    private const DEPOSITABLE_STATUSES = [
        BookingStatus::Draft,
        BookingStatus::PendingAssignment,
        BookingStatus::PartiallyAssigned,
        BookingStatus::FullyAssigned,
        BookingStatus::Held,
    ];

    /**
     * ADR-46: all payment mutations acquire Booking lock first (canonical order: Booking → BookingPayment).
     * ADR-44: terminal guard applied before any write.
     */
    public function addDeposit(Booking $booking, array $data): BookingPayment
    {
        return DB::transaction(function () use ($booking, $data): BookingPayment {
            $locked = Booking::lockForUpdate()->findOrFail($booking->id);
            $this->assertBookingNotTerminal($locked);

            $payment = $this->insertPayment($locked, [
                ...$data,
                'payment_type' => $data['payment_type'] ?? PaymentType::Deposit,
            ]);

            if (in_array($locked->status, self::DEPOSITABLE_STATUSES, true)) {
                $locked->update([
                    'status'     => BookingStatus::Deposited,
                    'updated_by' => Auth::id(),
                ]);
            }

            return $payment;
        });
    }

    public function addPayment(Booking $booking, array $data): BookingPayment
    {
        return DB::transaction(function () use ($booking, $data): BookingPayment {
            $locked = Booking::lockForUpdate()->findOrFail($booking->id);
            $this->assertBookingAcceptsPayment($locked);

            $payment = $this->insertPayment($locked, $data);

            // docs/Prompt_2.txt mục XI — paying off a CheckedOut booking's
            // debt is what finally settles its Folio; auto-close mirrors
            // exactly what finaliseBookingCheckout() already does when the
            // balance is zero AT checkout time (FolioService::autoCloseFolio
            // is idempotent — a no-op if already Closed/Voided). Scoped to
            // CheckedOut only: a mid-stay balance hitting zero must NOT
            // close the Folio, more charges are still expected before the
            // guest actually checks out.
            if ($locked->status === BookingStatus::CheckedOut) {
                $this->autoCloseFolioIfSettled($locked);
            }

            return $payment;
        });
    }

    public function addRefund(Booking $booking, array $data): BookingPayment
    {
        return DB::transaction(function () use ($booking, $data): BookingPayment {
            $locked = Booking::lockForUpdate()->findOrFail($booking->id);
            $this->assertBookingNotTerminal($locked);

            // ADR-46: lockForUpdate on payments ensures a current read (avoids MVCC snapshot).
            $currentPaidTotal = $this->calculatePaidTotal($locked);

            if ((float) $data['amount'] > $currentPaidTotal) {
                throw ValidationException::withMessages([
                    'amount' => 'Số tiền hoàn vượt quá tổng số đã thu ('.number_format($currentPaidTotal, 0, ',', '.').' đ).',
                ]);
            }

            return $this->insertPayment($locked, [
                ...$data,
                'payment_type' => PaymentType::Refund,
            ]);
        });
    }

    public function deletePayment(BookingPayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $locked = Booking::lockForUpdate()->findOrFail($payment->booking_id);
            $this->assertBookingNotTerminal($locked);
            // Canonical order: Booking → BookingPayment.
            BookingPayment::lockForUpdate()->findOrFail($payment->id)->delete();
        });
    }

    private function assertBookingNotTerminal(Booking $booking): void
    {
        if ($booking->status->isTerminal()) {
            throw new BookingTerminalException();
        }
    }

    private function assertBookingAcceptsPayment(Booking $booking): void
    {
        if (in_array($booking->status, self::NON_PAYABLE_STATUSES, true)) {
            throw new BookingTerminalException();
        }
    }

    /**
     * Same formula as BookingService::finaliseBookingCheckout()/paymentSummary()
     * and ReconciliationService::computeBalance() (pre-existing duplication,
     * not introduced here) — recomputed under lock so the auto-close decision
     * reflects the payment just inserted, not a stale read.
     */
    private function autoCloseFolioIfSettled(Booking $booking): void
    {
        $folio = $booking->folio()->first();
        if ($folio === null) {
            return;
        }

        $lockedFolio = Folio::lockForUpdate()->findOrFail($folio->id);
        $totalCharges = $this->folios->getFolioTotal($booking);
        $paidTotal = $this->calculatePaidTotal($booking);
        $balanceDue = (float) bcsub((string) $totalCharges, (string) $paidTotal, 2);

        if ($balanceDue <= 0) {
            $this->folios->autoCloseFolio($lockedFolio, Auth::user());
        }
    }

    private function insertPayment(Booking $booking, array $data): BookingPayment
    {
        $data['payment_type'] = $data['payment_type'] ?? PaymentType::RoomPayment;
        $data['payment_at']   = $data['payment_at'] ?? now();
        $data['confirmed_by'] = $data['confirmed_by'] ?? Auth::id();

        /** @var BookingPayment $payment */
        $payment = $booking->bookingPayments()->create($data);

        return $payment;
    }

    private function calculatePaidTotal(Booking $booking): float
    {
        $total = 0.0;

        foreach ($booking->bookingPayments()->lockForUpdate()->get(['payment_type', 'amount']) as $payment) {
            $total += match ($payment->payment_type) {
                PaymentType::Deposit,
                PaymentType::AdditionalDeposit,
                PaymentType::RoomPayment,
                PaymentType::ServicePayment,
                PaymentType::Adjustment => (float) $payment->amount,
                PaymentType::Refund => -1 * (float) $payment->amount,
            };
        }

        return $total;
    }
}
