<?php

namespace App\Http\Requests\Admin;

use App\Enums\ServiceBillingMode;
use App\Enums\ServiceScope;
use App\Models\Service;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code', '')))]);
        }
    }

    public function rules(): array
    {
        /** @var Service $service */
        $service = $this->route('service');

        return [
            'category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/', Rule::unique('services', 'code')->ignore($service->id)],
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

    /**
     * Same locked-field discipline as UpdateServicePackageRequest: fields
     * the posting/enrollment engine relies on to interpret already-existing
     * booking_services history become immutable once the Service has real
     * usage. This request-level check is the first of two layers — the
     * Service model's own saving() hook enforces it again (defense-in-depth).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var Service $service */
            $service = $this->route('service');

            if ($service->hasBeenUsed()) {
                if ($this->input('scope') !== $service->scope->value) {
                    $v->errors()->add('scope', 'Không thể thay đổi phạm vi áp dụng sau khi dịch vụ đã được sử dụng.');
                }
                if ($this->input('billing_mode') !== $service->billing_mode->value) {
                    $v->errors()->add('billing_mode', 'Không thể thay đổi cách tính phí sau khi dịch vụ đã được sử dụng.');
                }
                if ($this->boolean('quantity_enabled') !== $service->quantity_enabled) {
                    $v->errors()->add('quantity_enabled', 'Không thể thay đổi cấu hình số lượng sau khi dịch vụ đã được sử dụng.');
                }
                if ($this->boolean('is_chargeable') !== $service->is_chargeable) {
                    $v->errors()->add('is_chargeable', 'Không thể thay đổi "Có thu phí" sau khi dịch vụ đã được sử dụng.');
                }
            }
        });
    }
}
