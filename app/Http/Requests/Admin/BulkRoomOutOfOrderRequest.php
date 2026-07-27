<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BulkRoomOutOfOrderRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
