<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class SkipCheckoutInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('checkout_inspection.override') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
