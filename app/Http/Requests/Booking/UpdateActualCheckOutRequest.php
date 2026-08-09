<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActualCheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('ADMIN') ?? false;
    }

    public function rules(): array
    {
        return [
            'actual_checkout_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }
}
