<?php

namespace App\Http\Requests\Booking;

use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;

class RestoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('booking');

        return $booking instanceof Booking && ($this->user()?->can('restore', $booking) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
