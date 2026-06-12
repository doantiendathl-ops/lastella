<?php

namespace App\Http\Requests\Admin;

use App\Enums\RoomStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'floor_id' => ['required', 'integer', 'exists:floors,id'],
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'room_number' => ['required', 'string', 'max:20', Rule::unique('rooms', 'room_number')->ignore($this->route('room'))],
            'status' => ['required', Rule::in(array_map(fn (RoomStatus $status): string => $status->value, RoomStatus::cases()))],
            'bed_configuration' => ['nullable', 'array'],
            'bed_configuration.label' => ['nullable', 'string', 'max:100'],
            'bed_configuration.beds' => ['nullable', 'array'],
            'bed_configuration.beds.*.quantity' => ['required_with:bed_configuration.beds', 'integer', 'min:1', 'max:10'],
            'bed_configuration.beds.*.size_meters' => ['required_with:bed_configuration.beds', 'numeric', 'min:0.5', 'max:3'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
