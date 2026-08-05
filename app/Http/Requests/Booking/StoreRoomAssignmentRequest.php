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
            // Room Demand/Room Board Unification M2: room_type_id => booking_requirement_id,
            // only required when a room_type in the batch has more than one eligible
            // requirement line. This is structural validation only (integer, row exists) —
            // ownership, room_type match and eligibility are re-checked inside the locked
            // transaction in RoomAssignmentService::assignRoomsWithRequirementLink()
            // (Architecture Review Mục VII.D: never trust the frontend-sent ID alone).
            'target_requirement_id' => ['sometimes', 'array'],
            'target_requirement_id.*' => ['integer', 'exists:booking_requirements,id'],
        ];
    }
}
