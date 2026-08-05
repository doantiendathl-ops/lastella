<?php

namespace Tests\Unit\Services;

use App\Services\RoomRequirementAllocationService;
use Tests\TestCase;

/**
 * Room Demand/Room Board Unification M1 — pure unit tests for
 * RoomRequirementAllocationService. No database, no framework bootstrapping
 * beyond the base TestCase, matching the fact that the service under test
 * performs no I/O at all.
 */
class RoomRequirementAllocationServiceTest extends TestCase
{
    private RoomRequirementAllocationService $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new RoomRequirementAllocationService();
    }

    // ── 26: required=5, assigned_active=2, selected_count=2 — must NOT inflate demand to 7 ──

    public function test_reconcile_room_type_does_not_inflate_demand_when_selection_fits_within_remaining(): void
    {
        $plan = $this->planner->reconcileRoomType(required: 5, assignedActive: 2, selectedCount: 2);

        $this->assertSame(5, $plan['required']);
        $this->assertSame(2, $plan['assigned_active']);
        $this->assertSame(2, $plan['selected_count']);
        $this->assertSame(3, $plan['remaining']);
        $this->assertSame(0, $plan['excess_to_add']);
    }

    // ── 27: selection exceeds remaining — only the true excess is reported ──

    public function test_reconcile_room_type_reports_only_the_true_excess_when_selection_exceeds_remaining(): void
    {
        $plan = $this->planner->reconcileRoomType(required: 5, assignedActive: 2, selectedCount: 4);

        $this->assertSame(3, $plan['remaining']);
        $this->assertSame(1, $plan['excess_to_add']);
    }

    // ── 28: required smaller than assigned_active — remaining never negative ──

    public function test_reconcile_room_type_never_returns_negative_remaining(): void
    {
        $plan = $this->planner->reconcileRoomType(required: 2, assignedActive: 5, selectedCount: 3);

        $this->assertSame(0, $plan['remaining']);
        $this->assertSame(3, $plan['excess_to_add']);
    }

    // ── 29: multiple active lines, no target given — must ask, never guess ──

    public function test_plan_line_allocation_requests_target_selection_when_multiple_lines_are_ambiguous(): void
    {
        $plan = $this->planner->planLineAllocation([
            ['id' => 11, 'is_folio_locked' => false],
            ['id' => 12, 'is_folio_locked' => false],
        ]);

        $this->assertSame(RoomRequirementAllocationService::STATUS_NEEDS_TARGET_SELECTION, $plan['status']);
        $this->assertNull($plan['requirement_id']);
        $this->assertSame([11, 12], $plan['candidate_requirement_ids']);
    }

    public function test_plan_line_allocation_resolves_when_a_valid_target_is_supplied_for_multiple_lines(): void
    {
        $plan = $this->planner->planLineAllocation([
            ['id' => 11, 'is_folio_locked' => false],
            ['id' => 12, 'is_folio_locked' => false],
        ], targetRequirementId: 12);

        $this->assertSame(RoomRequirementAllocationService::STATUS_USE_EXISTING_LINE, $plan['status']);
        $this->assertSame(12, $plan['requirement_id']);
    }

    public function test_plan_line_allocation_rejects_a_target_that_is_not_among_the_active_lines(): void
    {
        $plan = $this->planner->planLineAllocation([
            ['id' => 11, 'is_folio_locked' => false],
        ], targetRequirementId: 999);

        $this->assertSame(RoomRequirementAllocationService::STATUS_INVALID_TARGET, $plan['status']);
        $this->assertNull($plan['requirement_id']);
    }

    // ── 30: single-line match that is folio-locked — must create a new line, never modify the locked one ──

    public function test_plan_line_allocation_creates_a_new_line_when_the_only_matching_line_is_folio_locked(): void
    {
        $plan = $this->planner->planLineAllocation([
            ['id' => 21, 'is_folio_locked' => true],
        ]);

        $this->assertSame(RoomRequirementAllocationService::STATUS_CREATE_NEW_LINE_FOLIO_LOCKED, $plan['status']);
        $this->assertNull($plan['requirement_id']);
        $this->assertSame(21, $plan['reference_requirement_id']);
    }

    public function test_plan_line_allocation_uses_the_single_unlocked_line_without_asking(): void
    {
        $plan = $this->planner->planLineAllocation([
            ['id' => 31, 'is_folio_locked' => false],
        ]);

        $this->assertSame(RoomRequirementAllocationService::STATUS_USE_EXISTING_LINE, $plan['status']);
        $this->assertSame(31, $plan['requirement_id']);
    }

    public function test_plan_line_allocation_reports_create_new_line_when_no_active_line_exists(): void
    {
        $plan = $this->planner->planLineAllocation([]);

        $this->assertSame(RoomRequirementAllocationService::STATUS_CREATE_NEW_LINE, $plan['status']);
        $this->assertNull($plan['requirement_id']);
        $this->assertSame([], $plan['candidate_requirement_ids']);
    }
}
