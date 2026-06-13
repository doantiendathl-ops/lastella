<?php

namespace App\Http\Requests\Booking;

use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CancelBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('booking.cancel') ?? false;
    }

    public function rules(): array
    {
        return [
            'booking_code_confirmation' => ['required', 'string'],
            'cancellation_reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $booking = $this->route('booking');

            if ($booking instanceof Booking && $this->input('booking_code_confirmation') !== $booking->booking_code) {
                $validator->errors()->add('booking_code_confirmation', 'Mã booking xác nhận không chính xác.');
            }
        });
    }
}
