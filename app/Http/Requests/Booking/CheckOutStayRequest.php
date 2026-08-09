<?php

namespace App\Http\Requests\Booking;

use Illuminate\Contracts\Validation\Validator;
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
            'actual_checkout_at' => ['nullable', 'date', 'before_or_equal:now'],
            'note'               => ['nullable', 'string'],
            'confirmed'          => ['nullable', 'boolean'],
        ];
    }

    /**
     * Only ADMIN may supply actual_checkout_at — see CheckInStayRequest for
     * the identical rationale on the check-in side.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->filled('actual_checkout_at') && ! ($this->user()?->hasRole('ADMIN') ?? false)) {
                $v->errors()->add('actual_checkout_at', 'Chỉ Quản trị viên được phép chỉnh thời gian trả phòng thực tế.');
            }
        });
    }
}
