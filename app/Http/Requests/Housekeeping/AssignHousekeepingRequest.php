<?php

declare(strict_types=1);

namespace App\Http\Requests\Housekeeping;

use App\Enums\CleaningPriority;
use App\Enums\CleaningReason;
use App\Models\HousekeepingAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignHousekeepingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assign', HousekeepingAssignment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'priority'    => ['nullable', 'string', Rule::in(array_column(CleaningPriority::cases(), 'value'))],
            'reason'      => ['nullable', 'string', Rule::in(array_column(CleaningReason::cases(), 'value'))],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ];
    }
}
