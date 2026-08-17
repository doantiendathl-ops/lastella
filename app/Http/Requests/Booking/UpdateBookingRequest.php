<?php

namespace App\Http\Requests\Booking;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PriceSource;
use App\Http\Requests\Booking\Concerns\ValidatesBookingColorOverlap;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingRequest extends FormRequest
{
    use ValidatesBookingColorOverlap;

    public function authorize(): bool
    {
        return $this->user()?->can('booking.update') ?? false;
    }

    public function withValidator(Validator $validator): void
    {
        $booking = $this->route('booking');
        $this->addBookingColorConflictRule(
            $validator,
            $booking?->getKey(),
            $booking?->booking_color,
            $booking?->checkin_at,
            $booking?->checkout_at,
        );
    }

    public function rules(): array
    {
        return [
            'booking_color' => ['required', 'string', 'max:20', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_type' => ['required', Rule::in($this->enumValues(CustomerType::cases()))],
            'booking_type' => ['required', Rule::in($this->enumValues(BookingType::cases()))],
            'checkin_at' => ['required', 'date'],
            'checkout_at' => ['required', 'date', 'after:checkin_at'],
            'adults' => ['required', 'integer', 'min:1'],
            'children_under_6' => ['required', 'integer', 'min:0'],
            'children_over_6' => ['required', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in($this->enumValues(BookingStatus::cases()))],
            'sales_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string'],
            'internal_note' => ['nullable', 'string'],
            'quick_note' => ['nullable', 'string', 'max:100'],
            'requirements' => ['sometimes', 'array', 'min:1'],
            'requirements.*.room_type_id' => ['required_with:requirements', 'integer', 'exists:room_types,id'],
            'requirements.*.quantity' => ['required_with:requirements', 'integer', 'min:1'],
            'requirements.*.adults' => ['required_with:requirements', 'integer', 'min:0'],
            'requirements.*.children_under_6' => ['required_with:requirements', 'integer', 'min:0'],
            'requirements.*.children_over_6' => ['required_with:requirements', 'integer', 'min:0'],
            'requirements.*.room_price' => ['required_with:requirements', 'numeric', 'min:0'],
            'requirements.*.price_source' => ['required_with:requirements', Rule::in($this->enumValues(PriceSource::cases()))],
            'requirements.*.note' => ['nullable', 'string'],
        ];
    }

    private function enumValues(array $cases): array
    {
        return array_map(fn ($case): string => $case->value, $cases);
    }
}
