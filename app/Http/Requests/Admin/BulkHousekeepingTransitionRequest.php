<?php

namespace App\Http\Requests\Admin;

use App\Models\HousekeepingAssignment;
use Illuminate\Foundation\Http\FormRequest;

class BulkHousekeepingTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateStatus', HousekeepingAssignment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'room_ids' => ['required', 'array', 'min:1', 'max:200'],
            'room_ids.*' => ['integer', 'distinct', 'exists:rooms,id'],
        ];
    }
}
