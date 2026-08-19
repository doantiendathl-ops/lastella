<?php

namespace App\Http\Requests\Folio;

use Illuminate\Foundation\Http\FormRequest;

class VoidFolioEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // User request (2026-08-19 chat) — the `|| hasRole('ADMIN')` bypass
        // here was always redundant: ADMIN already holds charge.void via
        // the full RolePermissionSeeder::PERMISSIONS sync, so can('charge.void')
        // alone already covers it. The real "void ANY date" bypass is a
        // separate, finer-grained check in FolioEntryPolicy::void().
        return $this->user()?->can('charge.void') ?? false;
    }

    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
