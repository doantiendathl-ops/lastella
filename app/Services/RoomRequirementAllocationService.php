<?php

namespace App\Services;

/**
 * Room Demand/Room Board Unification M1 — pure reconciliation/allocation
 * planner (Architecture Review REVISION 3, Mục 14; Implementation Plan
 * Mục 5 Milestone 1 item 8).
 *
 * This service performs NO database queries and has NO side effects — every
 * method takes plain values/arrays already read by the caller and returns a
 * plan/decision array. It does not create, update, or delete any model, does
 * not call any assignment/Stay orchestration, and is not wired into any
 * route or controller in this milestone. Milestone 2/3 wire its output into
 * the actual assign/demand-sync orchestration.
 *
 * Two responsibilities, kept separate on purpose:
 *  - reconcileRoomType(): the room-type level total (required/assigned/
 *    remaining/excess) — Architecture Review Mục 14a.
 *  - planLineAllocation(): which specific BookingRequirement line (if any)
 *    should receive the newly assigned rooms — Architecture Review Mục 14b.
 *    This only resolves the TARGET line (or "needs selection" / "create
 *    new"); it does not split a batch across multiple partially-filled
 *    lines — that finer-grained spillover logic is explicitly deferred to
 *    Milestone 2/3, where it has real orchestration context to work with.
 */
class RoomRequirementAllocationService
{
    /** Exactly one active requirement line matched (or the caller-supplied target was valid and unlocked) — use it as-is. */
    public const STATUS_USE_EXISTING_LINE = 'use_existing_line';

    /** No active requirement line exists for this room_type — the excess must become a brand new line. */
    public const STATUS_CREATE_NEW_LINE = 'create_new_line';

    /** The resolved line (single match, or explicit target) is folio-locked — must not be modified; excess goes to a new line instead. */
    public const STATUS_CREATE_NEW_LINE_FOLIO_LOCKED = 'create_new_line_folio_locked';

    /** More than one active requirement line exists and no target was supplied — caller must ask the user to choose. */
    public const STATUS_NEEDS_TARGET_SELECTION = 'needs_target_selection';

    /** A target_requirement_id was supplied but does not match any of the active lines given — never guessed, always rejected. */
    public const STATUS_INVALID_TARGET = 'invalid_target';

    /**
     * Room-type total reconciliation (Architecture Review Mục 14a).
     *
     * remaining      = max(required - assigned_active, 0)
     * excess_to_add  = max(selected_count - remaining, 0)
     *
     * Only excess_to_add may ever create or increase demand — selected_count
     * itself must never be added directly to quantity (this is the double-
     * count defect fixed between REVISION 1 and REVISION 2 of the
     * Architecture Review).
     *
     * @param  int  $required  Sum of quantity across all requirement lines of this room_type.
     * @param  int  $assignedActive  Count of active-status assignments (Assigned/CheckedIn/CheckedOut) of this room_type, before this batch.
     * @param  int  $selectedCount  Number of rooms of this room_type being selected in the current batch.
     * @return array{required: int, assigned_active: int, selected_count: int, remaining: int, excess_to_add: int}
     */
    public function reconcileRoomType(int $required, int $assignedActive, int $selectedCount): array
    {
        $remaining = max($required - $assignedActive, 0);
        $excessToAdd = max($selectedCount - $remaining, 0);

        return [
            'required' => $required,
            'assigned_active' => $assignedActive,
            'selected_count' => $selectedCount,
            'remaining' => $remaining,
            'excess_to_add' => $excessToAdd,
        ];
    }

    /**
     * Requirement-line allocation decision (Architecture Review Mục 14b).
     *
     * Decides which single BookingRequirement line new assignments of this
     * room_type should reference, without ever guessing when more than one
     * line is a candidate (Product Owner Decision #10/#15 — Mục I item 10):
     * ambiguity is always surfaced as STATUS_NEEDS_TARGET_SELECTION, never
     * resolved automatically.
     *
     * @param  array<int, array{id: int, is_folio_locked: bool}>  $activeLines  Active requirement lines of this room_type, already read by the caller.
     * @param  int|null  $targetRequirementId  The line the caller/user explicitly chose, if any. Ignored when there is exactly one active line (unambiguous).
     * @return array{status: string, requirement_id: int|null, reference_requirement_id: int|null, candidate_requirement_ids: int[]}
     */
    public function planLineAllocation(array $activeLines, ?int $targetRequirementId = null): array
    {
        $candidateIds = array_values(array_map(
            static fn (array $line): int => $line['id'],
            $activeLines,
        ));

        if ($activeLines === []) {
            return [
                'status' => self::STATUS_CREATE_NEW_LINE,
                'requirement_id' => null,
                'reference_requirement_id' => null,
                'candidate_requirement_ids' => [],
            ];
        }

        // An explicit target always wins and is always validated — even when
        // there is only one active line, a caller-supplied target that does
        // not match it is rejected rather than silently overridden. Only
        // fall back to "auto-resolve the single line" when no target was
        // supplied at all.
        if ($targetRequirementId !== null) {
            $resolvedLine = null;

            foreach ($activeLines as $line) {
                if ($line['id'] === $targetRequirementId) {
                    $resolvedLine = $line;

                    break;
                }
            }

            if ($resolvedLine === null) {
                return [
                    'status' => self::STATUS_INVALID_TARGET,
                    'requirement_id' => null,
                    'reference_requirement_id' => null,
                    'candidate_requirement_ids' => $candidateIds,
                ];
            }
        } elseif (count($activeLines) === 1) {
            $resolvedLine = $activeLines[array_key_first($activeLines)];
        } else {
            return [
                'status' => self::STATUS_NEEDS_TARGET_SELECTION,
                'requirement_id' => null,
                'reference_requirement_id' => null,
                'candidate_requirement_ids' => $candidateIds,
            ];
        }

        if ($resolvedLine['is_folio_locked']) {
            return [
                'status' => self::STATUS_CREATE_NEW_LINE_FOLIO_LOCKED,
                'requirement_id' => null,
                'reference_requirement_id' => $resolvedLine['id'],
                'candidate_requirement_ids' => [$resolvedLine['id']],
            ];
        }

        return [
            'status' => self::STATUS_USE_EXISTING_LINE,
            'requirement_id' => $resolvedLine['id'],
            'reference_requirement_id' => null,
            'candidate_requirement_ids' => [$resolvedLine['id']],
        ];
    }
}
