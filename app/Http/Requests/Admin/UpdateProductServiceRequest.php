<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('product_services.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer', 'exists:product_service_categories,id'],
            'code' => ['required', 'string', 'max:40', Rule::unique('product_services', 'code')->ignore($this->route('productService'))],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_map(fn (ProductServiceType $t): string => $t->value, ProductServiceType::cases()))],
            'unit' => ['required', 'string', 'max:30'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'free_quantity_default' => ['nullable', 'integer', 'min:0'],
            'use_in_checkout_inspection' => ['sometimes', 'boolean'],
            'can_add_to_booking' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
