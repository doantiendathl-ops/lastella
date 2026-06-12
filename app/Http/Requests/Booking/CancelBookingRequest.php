<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class CancelBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('booking.cancel') ?? false;
    }

    public function rules(): array
    {
        return [
            'cancellation_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
