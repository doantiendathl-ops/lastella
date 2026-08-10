<?php

declare(strict_types=1);

namespace App\Http\Requests\RoomOperations;

use Illuminate\Foundation\Http\FormRequest;

class SwapExecuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('room.assign') ?? false;
    }

    public function rules(): array
    {
        return [
            'pairs' => ['required', 'array', 'min:1'],
            'pairs.*.source_assignment_id' => ['required', 'integer', 'distinct', 'exists:room_assignments,id'],
            'pairs.*.target_room_id' => ['required', 'integer', 'exists:rooms,id'],
            'warnings_acknowledged' => ['sometimes', 'boolean'],
        ];
    }
}
