<?php

declare(strict_types=1);

namespace App\Http\Requests\RoomOperations;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class BulkCheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('stay.checkout') ?? false;
    }

    public function rules(): array
    {
        return [
            'stay_ids' => ['required', 'array', 'min:1'],
            'stay_ids.*' => ['integer', 'distinct', 'exists:stays,id'],
            'confirmed' => ['sometimes', 'boolean'],
            // Same field/rule as CheckOutStayRequest — one shared actual time
            // applied to every stay in this batch.
            'actual_checkout_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /** Only ADMIN may supply actual_checkout_at — see CheckOutStayRequest for the identical rationale. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->filled('actual_checkout_at') && ! ($this->user()?->can('stay.actual_time.manage') ?? false)) {
                $v->errors()->add('actual_checkout_at', 'Chỉ Quản trị viên được phép chỉnh thời gian trả phòng thực tế.');
            }
        });
    }
}
