<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('room.assign') ?? false;
    }

    public function rules(): array
    {
        return [
            'room_id' => ['required_without:room_ids', 'integer', 'exists:rooms,id'],
            'room_ids' => ['sometimes', 'array', 'min:1'],
            'room_ids.*' => ['integer', 'distinct', 'exists:rooms,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
        ];
    }
}
