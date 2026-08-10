<?php

declare(strict_types=1);

namespace App\Http\Requests\RoomOperations;

use Illuminate\Foundation\Http\FormRequest;

class BulkCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('stay.checkin') ?? false;
    }

    public function rules(): array
    {
        return [
            'stay_ids' => ['required', 'array', 'min:1'],
            'stay_ids.*' => ['integer', 'distinct', 'exists:stays,id'],
        ];
    }
}
