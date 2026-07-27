<?php

namespace App\Http\Requests\Admin;

use App\Enums\RoomStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkRoomReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('room.maintenance') ?? false;
    }

    public function rules(): array
    {
        return [
            'room_ids' => ['required', 'array', 'min:1', 'max:200'],
            'room_ids.*' => ['integer', 'distinct', 'exists:rooms,id'],
            'target_status' => ['nullable', Rule::in([RoomStatus::VacantDirty->value, RoomStatus::VacantClean->value])],
        ];
    }
}
