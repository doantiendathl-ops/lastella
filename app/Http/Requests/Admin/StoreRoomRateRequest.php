<?php

namespace App\Http\Requests\Admin;

use App\Enums\RateStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'overnight_price' => ['required', 'numeric', 'min:0'],
            'hourly_price' => ['required', 'numeric', 'min:0'],
            'extra_adult_price' => ['required', 'numeric', 'min:0'],
            'extra_child_price' => ['required', 'numeric', 'min:0'],
            'early_checkin_price' => ['required', 'numeric', 'min:0'],
            'late_checkout_price' => ['required', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(array_map(fn (RateStatus $status): string => $status->value, RateStatus::cases()))],
        ];
    }
}
