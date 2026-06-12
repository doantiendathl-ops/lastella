<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'max:60'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'floor_id' => ['nullable', 'integer', 'exists:floors,id'],
            'room_type_id' => ['nullable', 'integer', 'exists:room_types,id'],
            'status' => ['nullable', 'string', 'max:60'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
            'group' => ['nullable', 'string', 'max:60'],
            'action' => ['nullable', 'string', 'max:30'],
            'entity_type' => ['nullable', 'string', 'max:255'],
        ];
    }
}
