<?php

declare(strict_types=1);

namespace App\Http\Requests\RoomOperations;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class BulkCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('stay.checkin') ?? false;
    }

    public function rules(): array
    {
        return [
            'stay_ids' => ['required', 'array', 'min:1'],
            'stay_ids.*' => ['integer', 'distinct', 'exists:stays,id'],
            // Same field/rule as CheckInStayRequest (the booking-detail-page
            // equivalent) — one shared actual time applied to every stay in
            // this batch, so a bulk check-in still lets ADMIN correct/record
            // the real time instead of always defaulting to now().
            'actual_checkin_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /** Only ADMIN may supply actual_checkin_at — see CheckInStayRequest for the identical rationale. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->filled('actual_checkin_at') && ! ($this->user()?->can('stay.actual_time.manage') ?? false)) {
                $v->errors()->add('actual_checkin_at', 'Chỉ Quản trị viên được phép chỉnh thời gian nhận phòng thực tế.');
            }
        });
    }
}
