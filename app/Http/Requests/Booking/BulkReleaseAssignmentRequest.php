<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Room Demand/Room Board Unification M4 — atomic bulk room release request
 * contract. Structural validation only (types, existence, batch size cap) —
 * ownership, status, check-in fact, NULL mapping, and Folio lock are all
 * re-derived from freshly LOCKED rows inside
 * RoomAssignmentService::bulkReleaseAssignments() — never trusted from this
 * payload alone (Architecture Review Mục VII.D, same discipline as M3's
 * StoreRoomBoardAssignmentRequest).
 *
 * authorize() requires `room.unassign` always, plus `booking.update` when
 * `reduce_demand=true` (Architecture Review REVISION 3 Mục 23 / Product
 * Owner Decision — reducing demand is booking.update's domain, mirroring
 * exactly how M3's Room-Board-first flow requires booking.update to create/
 * increase demand).
 */
class BulkReleaseAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if (! $user->can('room.unassign')) {
            return false;
        }

        if ($this->boolean('reduce_demand') && ! $user->can('update', $this->route('booking'))) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'assignment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'assignment_ids.*' => ['integer', 'distinct', 'exists:room_assignments,id'],
            'reason' => ['required', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'reduce_demand' => ['sometimes', 'boolean'],
        ];
    }
}
