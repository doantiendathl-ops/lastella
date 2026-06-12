<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class CheckInStayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('stay.checkin') ?? false;
    }

    public function rules(): array
    {
        return [
            'actual_checkin_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ];
    }
}
