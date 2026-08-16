<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreServicePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'unit_price' => ['required', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
