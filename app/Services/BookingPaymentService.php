<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\BookingPayment;
use Illuminate\Support\Facades\Auth;
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

    public function addDeposit(Booking $booking, array $data): BookingPayment
    {
        $payment = $this->addPayment($booking, [
            ...$data,
            'payment_type' => $data['payment_type'] ?? PaymentType::Deposit,
        ]);

        if (in_array($booking->status, self::DEPOSITABLE_STATUSES, true)) {
            $booking->update([
                'status' => BookingStatus::Deposited,
                'updated_by' => Auth::id(),
            ]);
        }

        return $payment;
    }

    public function addPayment(Booking $booking, array $data): BookingPayment
    {
        $data['payment_type'] = $data['payment_type'] ?? PaymentType::RoomPayment;
        $data['payment_at'] = $data['payment_at'] ?? now();
        $data['confirmed_by'] = $data['confirmed_by'] ?? Auth::id();

        /** @var BookingPayment $payment */
        $payment = $booking->bookingPayments()->create($data);

        return $payment;
    }

    public function addRefund(Booking $booking, array $data): BookingPayment
    {
        $currentPaidTotal = $this->calculatePaidTotal($booking);

        if ((float) $data['amount'] > $currentPaidTotal) {
            throw ValidationException::withMessages([
                'amount' => 'Số tiền hoàn vượt quá tổng số đã thu ('.number_format($currentPaidTotal, 0, ',', '.').' đ).',
            ]);
        }

        return $this->addPayment($booking, [
            ...$data,
            'payment_type' => PaymentType::Refund,
        ]);
    }

    public function deletePayment(BookingPayment $payment): void
    {
        $payment->delete();
    }

    private function calculatePaidTotal(Booking $booking): float
    {
        $total = 0.0;

        foreach ($booking->bookingPayments()->get(['payment_type', 'amount']) as $payment) {
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
