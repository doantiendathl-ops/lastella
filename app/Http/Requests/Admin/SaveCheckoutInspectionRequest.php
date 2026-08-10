<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Inspection Financial Correction: `chargeable_quantity` is now a DIRECT
 * input — the old `actual_quantity` + `chargeable_quantity_override` pair
 * (which computed chargeable = actual - free_quantity_default) is removed
 * entirely. `complimentary_quantity` is optional and purely a display
 * snapshot (never used in the charge formula).
 */
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
            'items.*.chargeable_quantity' => ['required', 'integer', 'min:0'],
            'items.*.complimentary_quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
