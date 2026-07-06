<?php

declare(strict_types=1);

namespace App\Http\Requests\Housekeeping;

use App\Enums\RoomStatus;
use App\Models\HousekeepingAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Not one of the 5 named requests in the Milestone 4 instructions, but required to keep
 * releaseFromOutOfOrder() validation out of the controller (validation-only-in-FormRequest rule).
 */
class ReleaseFromOutOfOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('maintenance', HousekeepingAssignment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'target_status' => [
                'nullable',
                'string',
                Rule::in([RoomStatus::VacantDirty->value, RoomStatus::VacantClean->value]),
            ],
        ];
    }
}
