<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'unique:room_types,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'standard_adults' => ['required', 'integer', 'min:1', 'max:20'],
            'max_adults' => ['required', 'integer', 'gte:standard_adults', 'max:20'],
            'free_children' => ['required', 'integer', 'min:0', 'max:20'],
        ];
    }
}
