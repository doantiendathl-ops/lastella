<?php

namespace App\Http\Requests\Booking;

use App\Enums\PriceSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Room Demand/Room Board Unification M3 — Room-Board-first request contract.
 * A SEPARATE FormRequest from StoreRoomAssignmentRequest (Implementation Plan
 * Mục XI): the demand-first payload shape (flat room_ids + optional
 * target_requirement_id map) and this one (room_ids grouped by room_type,
 * each group carrying its own price/source/note/guest fields for the excess
 * portion) are different enough that sharing a request class would either
 * complicate M2's validated() shape or force awkward nullable fields onto it.
 * Both requests authorize via the SAME `room.assign` permission and both feed
 * the SAME service core (RoomAssignmentService) — no duplicated business
 * logic, only the transport-layer shape differs.
 *
 * authorize() requires BOTH `room.assign` AND `booking.update` (Implementation
 * Plan Mục XV) — unlike the demand-first endpoint, this flow can also CREATE
 * or INCREASE commercial demand (BookingRequirement), which is squarely
 * booking.update's domain, not just room.assign's.
 *
 * This is structural validation only (types, existence, cross-group
 * consistency). Room-type truth, target-requirement ownership/eligibility,
 * folio-lock state, and the reconciliation/allocation decision are all
 * re-derived from freshly LOCKED rows inside
 * RoomAssignmentService::assignRoomsFromRoomBoard() — never trusted from this
 * payload alone (Architecture Review Mục VII.D).
 *
 * Milestone 5 hardening: `room_ids`/`groups` gained a `max:100` cap,
 * matching the exact precedent already set by `BulkReleaseAssignmentRequest`
 * (M4) and the hotel's stated operating scale (10–100 rooms, Milestone 5
 * Phase A Mục XIII) — never smaller than a legitimate full-hotel batch,
 * only closing the previously-uncapped payload-size gap this endpoint alone
 * had relative to every other bulk endpoint in this feature.
 */
class StoreRoomBoardAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can('room.assign') && $user->can('update', $this->route('booking'));
    }

    public function rules(): array
    {
        return [
            'room_ids' => ['required', 'array', 'min:1', 'max:100'],
            'room_ids.*' => ['integer', 'distinct', 'exists:rooms,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],

            'groups' => ['required', 'array', 'min:1', 'max:100'],
            'groups.*.room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'groups.*.room_ids' => ['required', 'array', 'min:1'],
            'groups.*.room_ids.*' => ['integer', 'distinct', 'exists:rooms,id'],
            'groups.*.target_requirement_id' => ['nullable', 'integer', 'exists:booking_requirements,id'],
            'groups.*.room_price' => ['nullable', 'numeric', 'min:0'],
            'groups.*.price_source' => ['nullable', Rule::in(array_column(PriceSource::cases(), 'value'))],
            'groups.*.note' => ['nullable', 'string', 'max:1000'],
            'groups.*.adults' => ['nullable', 'integer', 'min:0'],
            'groups.*.children_under_6' => ['nullable', 'integer', 'min:0'],
            'groups.*.children_over_6' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();
            $roomIds = collect($data['room_ids'] ?? [])->map(fn ($id) => (int) $id);
            $groups = collect($data['groups'] ?? []);

            $groupRoomIds = $groups->flatMap(fn (array $group): array => collect($group['room_ids'] ?? [])
                ->map(fn ($id) => (int) $id)->all());

            // Every room_id in a group must also be in the top-level room_ids list,
            // and vice versa — the two must describe exactly the same batch, just
            // grouped differently (Implementation Plan Mục XI).
            if ($groupRoomIds->unique()->sort()->values()->all() !== $roomIds->unique()->sort()->values()->all()) {
                $validator->errors()->add('groups', 'Danh sách phòng trong các nhóm phải khớp chính xác với danh sách phòng đã chọn.');
            }

            // No room may appear in more than one group.
            if ($groupRoomIds->count() !== $groupRoomIds->unique()->count()) {
                $validator->errors()->add('groups', 'Một phòng không thể thuộc nhiều hơn một nhóm loại phòng.');
            }
        });
    }
}
