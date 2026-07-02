<?php

namespace App\Http\Requests\Admin;

use App\Services\HotelSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHotelSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('hotel_settings.manage') ?? false;
    }

    public function rules(): array
    {
        $validKeys = array_keys(HotelSettingsService::$defaults);

        return [
            'settings'          => ['required', 'array'],
            'settings.*.key'    => ['required', 'string', Rule::in($validKeys)],
            'settings.*.value'  => ['required', 'string', 'max:255'],
        ];
    }
}
