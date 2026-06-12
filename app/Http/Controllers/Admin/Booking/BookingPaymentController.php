<?php

namespace App\Http\Controllers\Admin\Booking;

use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StoreBookingPaymentRequest;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Services\BookingPaymentService;
use Illuminate\Http\RedirectResponse;

class BookingPaymentController extends Controller
{
    public function __construct(private readonly BookingPaymentService $payments)
    {
    }

    public function store(StoreBookingPaymentRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('create', BookingPayment::class);

        $data = $request->validated();
        $paymentType = PaymentType::from($data['payment_type']);

        match ($paymentType) {
            PaymentType::Deposit, PaymentType::AdditionalDeposit => $this->payments->addDeposit($booking, $data),
            PaymentType::Refund => $this->payments->addRefund($booking, $data),
            default => $this->payments->addPayment($booking, $data),
        };

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'payments'])->with('success', 'Đã ghi nhận thanh toán.');
    }
}
