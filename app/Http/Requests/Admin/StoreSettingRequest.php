<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:settings,key'],
            'value' => ['nullable'],
            'type' => ['required', Rule::in(['string', 'integer', 'boolean', 'time', 'json'])],
            'group' => ['required', 'string', 'max:60'],
            'is_public' => ['boolean'],
        ];
    }
}
