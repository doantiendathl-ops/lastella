<?php

namespace App\Http\Requests\Folio;

use Illuminate\Foundation\Http\FormRequest;

class VoidFolioEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('charge.void') || $this->user()?->hasRole('ADMIN') ?? false;
    }

    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
