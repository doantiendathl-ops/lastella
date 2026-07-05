<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use App\Enums\RequestCategory;
use App\Models\BookingSpecialRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingSpecialRequestRequest extends FormRequest
{
    /**
     * Valid request_type codes per category.
     * New codes: add here + update frontend catalog — no migration needed (VARCHAR).
     */
    public const ALLOWED_REQUEST_TYPES = [
        // bed_config
        'twin_keep', 'twin_to_double', 'separate_beds', 'extra_bed',
        // extra_item
        'baby_cot', 'extra_pillow', 'non_feather_pillow', 'extra_blanket',
        'extra_towel', 'welcome_fruit', 'welcome_amenity',
        // decoration
        'anniversary', 'honeymoon', 'birthday', 'vip_setup', 'flower_arrangement',
        // accessibility
        'wheelchair', 'non_smoking_prep', 'ground_floor', 'near_elevator',
        // general
        'late_arrival', 'airport_pickup', 'connecting_room', 'other',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('special_request.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'category'     => [
                'required',
                'string',
                Rule::in(array_column(RequestCategory::cases(), 'value')),
            ],
            'request_type' => [
                'required',
                'string',
                Rule::in(self::ALLOWED_REQUEST_TYPES),
            ],
            'quantity'     => ['required', 'integer', 'min:1', 'max:99'],
            'note'         => ['nullable', 'string', 'max:1000'],
            'stay_id'      => [
                'nullable',
                'integer',
                Rule::exists('stays', 'id')->where('booking_id', $this->route('booking')->id),
            ],
        ];
    }
}
