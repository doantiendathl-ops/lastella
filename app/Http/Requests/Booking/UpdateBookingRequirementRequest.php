<?php

namespace App\Http\Requests\Booking;

use App\Enums\PriceSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('booking.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'adults' => ['required', 'integer', 'min:0'],
            'children_under_6' => ['required', 'integer', 'min:0'],
            'children_over_6' => ['required', 'integer', 'min:0'],
            'room_price' => ['required', 'numeric', 'min:0'],
            'price_source' => ['required', Rule::in(array_map(fn (PriceSource $source): string => $source->value, PriceSource::cases()))],
            'note' => ['nullable', 'string'],
        ];
    }
}
