<?php

namespace App\Http\Requests\Folio;

use App\Enums\ChargeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFolioEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('charge.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'charge_type' => ['required', Rule::in(array_map(fn (ChargeType $t): string => $t->value, ChargeType::cases()))],
            'description' => ['required', 'string', 'max:255'],
            'quantity'    => ['required', 'numeric', 'min:0.01'],
            'unit_price'  => ['required', 'numeric', 'min:0'],
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'entry_date'  => ['required', 'date'],
            'note'        => ['nullable', 'string'],
        ];
    }
}
