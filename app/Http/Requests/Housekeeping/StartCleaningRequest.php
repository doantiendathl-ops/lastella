<?php

declare(strict_types=1);

namespace App\Http\Requests\Housekeeping;

use App\Models\HousekeepingAssignment;
use Illuminate\Foundation\Http\FormRequest;

class StartCleaningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateStatus', HousekeepingAssignment::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
