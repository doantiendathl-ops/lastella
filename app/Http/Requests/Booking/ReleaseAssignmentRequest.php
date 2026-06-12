<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('room.unassign') ?? false;
    }

    public function rules(): array
    {
        return [
            'release_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
