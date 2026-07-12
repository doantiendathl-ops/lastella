<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class ExtendStayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('stay.extend') ?? false;
    }

    public function rules(): array
    {
        return [
            'new_planned_checkout_at' => ['required', 'date'],
        ];
    }
}
