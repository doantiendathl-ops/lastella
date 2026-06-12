<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class CheckOutStayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('stay.checkout') ?? false;
    }

    public function rules(): array
    {
        return [
            'actual_checkout_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ];
    }
}
