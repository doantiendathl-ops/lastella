<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveCheckoutInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('checkout_inspection.perform') ?? false;
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['sometimes', 'array'],
            'items.*.product_service_id' => ['required', 'integer', 'exists:product_services,id'],
            'items.*.actual_quantity' => ['required', 'integer', 'min:0'],
            'items.*.chargeable_quantity_override' => ['nullable', 'integer', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
