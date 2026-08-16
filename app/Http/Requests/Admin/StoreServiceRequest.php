<?php

namespace App\Http\Requests\Admin;

use App\Enums\ServiceBillingMode;
use App\Enums\ServiceScope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code', '')))]);
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/', 'unique:services,code'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_chargeable' => ['boolean'],
            'scope' => ['required', Rule::in(array_map(fn (ServiceScope $s): string => $s->value, ServiceScope::cases()))],
            'billing_mode' => ['required', Rule::in(array_map(fn (ServiceBillingMode $m): string => $m->value, ServiceBillingMode::cases()))],
            'quantity_enabled' => ['boolean'],
            'default_quantity' => ['required', 'integer', 'min:1'],
            'unit_label' => ['required', 'string', 'max:30'],
            'fulfillment_required' => ['boolean'],
            'is_active' => ['boolean'],
            'is_bookable' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->boolean('quantity_enabled') && $this->input('default_quantity') !== null && (int) $this->input('default_quantity') < 1) {
                $v->errors()->add('default_quantity', 'Số lượng mặc định phải từ 1 trở lên.');
            }
        });
    }
}
