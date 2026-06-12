<?php

namespace App\Http\Requests\Booking;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('report.view')
            || $this->user()?->can('booking.create')
            || $this->user()?->can('booking.update')
            || $this->user()?->can('booking.cancel');
    }

    public function rules(): array
    {
        return [
            'booking_code' => ['nullable', 'string', 'max:100'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_map(fn (BookingStatus $status): string => $status->value, BookingStatus::cases()))],
            'booking_type' => ['nullable', Rule::in(array_map(fn (BookingType $type): string => $type->value, BookingType::cases()))],
            'checkin_from' => ['nullable', 'date'],
            'checkin_to' => ['nullable', 'date'],
            'checkout_from' => ['nullable', 'date'],
            'checkout_to' => ['nullable', 'date'],
            'sales_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'sort' => ['nullable', 'string', 'max:60'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
