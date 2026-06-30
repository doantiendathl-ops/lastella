<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Exceptions\BookingTerminalException;
use App\Models\Booking;
use App\Models\BookingPayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingPaymentService
{
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
            $this->assertBookingNotTerminal($locked);
            return $this->insertPayment($locked, $data);
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
