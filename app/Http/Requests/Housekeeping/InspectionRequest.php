<?php

declare(strict_types=1);

namespace App\Http\Requests\Housekeeping;

use App\Models\HousekeepingAssignment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared across passInspection(), failInspection(), and skipInspection().
 * `notes` is required only on the skip route — ADR-90: the skip reason must be documented.
 */
class InspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inspect', HousekeepingAssignment::class) ?? false;
    }

    public function rules(): array
    {
        $isSkip = $this->route()?->getActionMethod() === 'skipInspection';

        return [
            'notes' => [$isSkip ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }
}
