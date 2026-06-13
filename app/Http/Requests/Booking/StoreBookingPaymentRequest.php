<?php

namespace App\Http\Requests\Booking;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payment.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'payment_type' => ['required', Rule::in(array_map(fn (PaymentType $type): string => $type->value, PaymentType::cases()))],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in(array_map(fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases()))],
            'payment_at' => ['required', 'date'],
            'note' => ['nullable', 'string'],
        ];
    }
}
