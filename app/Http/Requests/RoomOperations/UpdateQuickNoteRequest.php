<?php

declare(strict_types=1);

namespace App\Http\Requests\RoomOperations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mục VII: "Ghi chú nhanh" — max 100 chars, server-validated regardless of
 * the frontend's maxlength attribute.
 *
 * Room-Scoped Bed Operations Correction: this request previously also
 * carried `bed_joined`. That field was removed (Product Owner traced "Ghép
 * giường" to the existing Special Request module — BookingSpecialRequest,
 * category=bed_config, request_type=twin_to_double — which already has a
 * full create/acknowledge/fulfill lifecycle; room_assignments.bed_joined
 * would have been a second, unsynchronized source of the same fact). The
 * board now only READS that canonical source; it is never edited here.
 */
class UpdateQuickNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('room.assign') ?? false;
    }

    public function rules(): array
    {
        return [
            'quick_note' => ['nullable', 'string', 'max:100'],
        ];
    }
}
