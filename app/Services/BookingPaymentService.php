<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\BookingPayment;
use Illuminate\Support\Facades\Auth;

class BookingPaymentService
{
    public function addDeposit(Booking $booking, array $data): BookingPayment
    {
        $payment = $this->addPayment($booking, [
            ...$data,
            'payment_type' => $data['payment_type'] ?? PaymentType::Deposit,
        ]);

        if ($booking->status !== BookingStatus::Cancelled) {
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
        return $this->addPayment($booking, [
            ...$data,
            'payment_type' => PaymentType::Refund,
        ]);
    }
}
