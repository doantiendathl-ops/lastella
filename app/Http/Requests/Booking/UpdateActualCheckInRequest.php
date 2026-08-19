<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActualCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('stay.actual_time.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'actual_checkin_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }
}
