<?php

namespace App\Http\Requests\Admin;

use App\Enums\ChargeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service_rates.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:100'],
            'charge_type'     => [
                'required',
                Rule::in(array_map(fn (ChargeType $t): string => $t->value, ChargeType::cases())),
                Rule::notIn([ChargeType::Room->value]),
            ],
            'unit_price'      => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'effective_from'  => ['required', 'date'],
            'unit_label'      => ['required', 'string', 'max:30'],
            'tax_rate'        => ['nullable', 'numeric', 'min:0', 'max:1'],
            'gl_account_code' => ['nullable', 'string', 'max:50'],
            'display_order'   => ['nullable', 'integer', 'min:0'],
        ];
    }
}
