<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class MoveRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('stay.room_move') ?? false;
    }

    public function rules(): array
    {
        return [
            'new_room_id' => ['required', 'integer', 'exists:rooms,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
