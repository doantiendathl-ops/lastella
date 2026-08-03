<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreServicePackageRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service_packages.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'unit_price'      => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'effective_from'  => ['required', 'date'],
            'tax_rate'        => ['nullable', 'numeric', 'min:0', 'max:1'],
            'gl_account_code' => ['nullable', 'string', 'max:50'],
            'is_active'       => ['boolean'],
        ];
    }
}
