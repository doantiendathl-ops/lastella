<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TriggerNightAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('night_audit.run') ?? false;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required'    => 'Vui lòng chọn ngày cần chạy Night Audit.',
            'date.date_format' => 'Ngày không hợp lệ. Định dạng yêu cầu: YYYY-MM-DD.',
        ];
    }
}
