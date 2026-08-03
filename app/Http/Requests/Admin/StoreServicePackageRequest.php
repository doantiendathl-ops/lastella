<?php

namespace App\Http\Requests\Admin;

use App\Enums\ChargeType;
use App\Enums\PackageCalculationStrategy;
use App\Enums\PackagePostingFrequency;
use App\Enums\PackageQuantityMode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServicePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service_packages.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code', '')))]);
    }

    public function rules(): array
    {
        return [
            'code'                 => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/', 'unique:service_packages,code'],
            'name'                 => ['required', 'string', 'max:150'],
            'description'          => ['nullable', 'string'],
            'charge_type'          => ['required', Rule::in(array_map(fn (ChargeType $c): string => $c->value, ChargeType::cases()))],
            'calculation_strategy' => ['required', Rule::in(array_map(fn (PackageCalculationStrategy $c): string => $c->value, PackageCalculationStrategy::implemented()))],
            'quantity_mode'        => ['required', Rule::in(array_map(fn (PackageQuantityMode $c): string => $c->value, PackageQuantityMode::implemented()))],
            'posting_frequency'    => ['required', Rule::in(array_map(fn (PackagePostingFrequency $c): string => $c->value, PackagePostingFrequency::implemented()))],
            'default_quantity'     => ['required', 'integer', 'min:1'],
            'unit_label'           => ['required', 'string', 'max:30'],
            'display_order'        => ['nullable', 'integer', 'min:0'],
            'is_active'            => ['boolean'],
            'is_bookable'          => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $strategy = PackageCalculationStrategy::tryFrom((string) $this->input('calculation_strategy'));
            $quantityMode = PackageQuantityMode::tryFrom((string) $this->input('quantity_mode'));

            if ($strategy !== null && $quantityMode !== null && ! $strategy->isCompatibleWith($quantityMode)) {
                $v->errors()->add('quantity_mode', 'Cách nhập số lượng không phù hợp với cách tính đã chọn.');
            }
        });
    }
}
