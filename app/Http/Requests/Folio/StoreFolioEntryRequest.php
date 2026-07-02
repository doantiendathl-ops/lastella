<?php

namespace App\Http\Requests\Folio;

use App\Enums\ChargeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreFolioEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('charge.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'posting_key' => ['prohibited'],
            'amount'      => ['prohibited'],
            'charge_type' => [
                'required',
                Rule::in(array_map(fn (ChargeType $t): string => $t->value, ChargeType::cases())),
                Rule::notIn([ChargeType::Room->value]),
            ],
            'description' => ['required', 'string', 'max:255'],
            'quantity'    => ['required', 'numeric', 'min:0.01'],
            'unit_price'  => ['required', 'numeric', 'min:0'],
            'entry_date'  => ['required', 'date'],
            'note'        => ['nullable', 'string'],
            'stay_id'     => ['nullable', 'integer', Rule::exists('stays', 'id')],
        ];
    }
}
