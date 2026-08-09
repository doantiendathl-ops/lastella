<?php

namespace App\Http\Requests\Booking;

use Illuminate\Contracts\Validation\Validator;
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
            'actual_checkin_at' => ['nullable', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string'],
        ];
    }

    /**
     * Only ADMIN may supply actual_checkin_at at all — a normal employee's
     * check-in always uses server now() (StayService::checkIn() defaults to
     * now() when no override is given). This is enforced here, not just
     * hidden in the Vue UI, so a forged payload from a non-admin is rejected
     * with a 422 rather than silently accepted.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->filled('actual_checkin_at') && ! ($this->user()?->hasRole('ADMIN') ?? false)) {
                $v->errors()->add('actual_checkin_at', 'Chỉ Quản trị viên được phép chỉnh thời gian nhận phòng thực tế.');
            }
        });
    }
}
