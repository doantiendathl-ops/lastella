<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class AssignRoomsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('room.assign') ?? false;
    }

    public function rules(): array
    {
        return [
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.room_id' => ['required', 'integer', 'exists:rooms,id'],
            'assignments.*.room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'assignments.*.start_at' => ['nullable', 'date'],
            'assignments.*.end_at' => ['nullable', 'date', 'after:assignments.*.start_at'],
        ];
    }
}
