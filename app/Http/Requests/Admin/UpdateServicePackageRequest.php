<?php

namespace App\Http\Requests\Admin;

use App\Enums\ChargeType;
use App\Enums\PackageCalculationStrategy;
use App\Enums\PackagePostingFrequency;
use App\Enums\PackageQuantityMode;
use App\Models\ServicePackage;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServicePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service_packages.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code', '')))]);
        }
    }

    public function rules(): array
    {
        /** @var ServicePackage $package */
        $package = $this->route('servicePackage');

        return [
            'name'                 => ['required', 'string', 'max:150'],
            'description'          => ['nullable', 'string'],
            'default_quantity'     => ['required', 'integer', 'min:1'],
            'unit_label'           => ['required', 'string', 'max:30'],
            'display_order'        => ['nullable', 'integer', 'min:0'],
            'is_active'            => ['boolean'],
            'is_bookable'          => ['boolean'],
            'code'                 => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/', Rule::unique('service_packages', 'code')->ignore($package->id)],
            'charge_type'          => ['required', Rule::in(array_map(fn (ChargeType $c): string => $c->value, ChargeType::cases()))],
            'calculation_strategy' => ['required', Rule::in(array_map(fn (PackageCalculationStrategy $c): string => $c->value, PackageCalculationStrategy::implemented()))],
            'quantity_mode'        => ['required', Rule::in(array_map(fn (PackageQuantityMode $c): string => $c->value, PackageQuantityMode::implemented()))],
            'posting_frequency'    => ['required', Rule::in(array_map(fn (PackagePostingFrequency $c): string => $c->value, PackagePostingFrequency::implemented()))],
        ];
    }

    /**
     * Locked fields may be present in the request (the Vue form disables
     * their inputs but still submits the package's current values) — they
     * are only rejected when the submitted value actually differs from what
     * is stored, not merely for being present. This mirrors the "form does
     * not show the input, backend still blocks an actual change" guidance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var ServicePackage $package */
            $package = $this->route('servicePackage');

            if ($package->hasBeenUsed()) {
                foreach (['code', 'charge_type', 'calculation_strategy', 'quantity_mode', 'posting_frequency'] as $field) {
                    if ($this->input($field) !== $package->{$field}) {
                        $v->errors()->add($field, 'Không thể thay đổi trường này sau khi gói đã được sử dụng.');
                    }
                }
            }

            $strategy = PackageCalculationStrategy::tryFrom(
                (string) $this->input('calculation_strategy', $package->calculation_strategy)
            );
            $quantityMode = PackageQuantityMode::tryFrom(
                (string) $this->input('quantity_mode', $package->quantity_mode)
            );

            if ($strategy !== null && $quantityMode !== null && ! $strategy->isCompatibleWith($quantityMode)) {
                $v->errors()->add('quantity_mode', 'Cách nhập số lượng không phù hợp với cách tính đã chọn.');
            }
        });
    }
}
