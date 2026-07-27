<?php

namespace Tests\Feature;

use App\Enums\CleaningPriority;
use App\Enums\HousekeepingAssignmentStatus;
use App\Enums\RoomStatus;
use App\Models\CleaningRecord;
use App\Models\HousekeepingAssignment;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 4.2 Milestone 4/5: HousekeepingController — authorization, validation, and
 * success-path coverage for all 9 routes. index() renders Inertia (Milestone 5);
 * the 8 mutation actions still return JSON, called from the frontend via axios.
 */
class HousekeepingControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private User $housekeeping;
    private User $reception;
    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin        = tap(User::factory()->create())->assignRole('ADMIN');
        $this->manager       = tap(User::factory()->create())->assignRole('MANAGER');
        $this->housekeeping  = tap(User::factory()->create())->assignRole('HOUSEKEEPING');
        $this->reception     = tap(User::factory()->create())->assignRole('RECEPTION');
        $this->accountant    = tap(User::factory()->create())->assignRole('ACCOUNTANT');
    }

    // -------------------------------------------------------------------------
    // index — housekeeping.view
    // -------------------------------------------------------------------------

    public function test_index_success_for_authorized_role(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/housekeeping')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Housekeeping/Index')
                ->has('floors')
                ->has('can.assign')
                ->has('can.updateStatus')
                ->has('can.inspect')
                ->has('can.maintenance')
            );
    }

    public function test_index_forbidden_for_accountant(): void
    {
        $this->actingAs($this->accountant)
            ->get('/admin/housekeeping')
            ->assertForbidden();
    }

    public function test_index_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/housekeeping')->assertRedirect('/login');
    }

    /**
     * Phase 4.2 Milestone 5: the `can` flags drive which action buttons the frontend
     * renders — must reflect the actual role grant, not just be present.
     */
    public function test_index_can_flags_match_housekeeping_role_grant(): void
    {
        $this->actingAs($this->housekeeping)
            ->get('/admin/housekeeping')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.markCleaning', true)
                ->where('can.assign', true)
                ->where('can.updateStatus', true)
                ->where('can.inspect', false)
                ->where('can.maintenance', false)
            );
    }

    /**
     * Room Operations Simplification: RECEPTION gains one-tap mark clean/dirty
     * (room.cleaning.update) without gaining the legacy assign/updateStatus abilities.
     */
    public function test_index_can_flags_match_reception_role_grant(): void
    {
        $this->actingAs($this->reception)
            ->get('/admin/housekeeping')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.markCleaning', true)
                ->where('can.assign', false)
                ->where('can.updateStatus', false)
                ->where('can.inspect', false)
                ->where('can.maintenance', false)
            );
    }

    public function test_index_includes_room_status_and_assignment_fields(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $assignment = HousekeepingAssignment::factory()->pending()->create([
            'room_id'     => $room->id,
            'assigned_to' => $this->housekeeping->id,
            'priority'    => CleaningPriority::High,
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/housekeeping')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('floors', fn ($floors): bool => collect($floors)
                    ->pluck('rooms')
                    ->flatten(1)
                    ->contains(fn (array $r): bool =>
                        $r['id'] === $room->id
                        && $r['status'] === RoomStatus::VacantDirty->value
                        && $r['active_assignment']['id'] === $assignment->id
                        && $r['active_assignment']['assigned_to'] === $this->housekeeping->name
                        && $r['active_assignment']['priority'] === CleaningPriority::High->value
                    ))
            );
    }

    /**
     * Final Consistency Review (section IX.10): the Vue card must read cleaning_status
     * directly from this prop, never re-derive SẠCH/BẨN from `status`/`status_label`.
     * INSPECTED is the clearest proof case — its status_label ("Đã kiểm tra") shares no
     * text with "Sạch", yet cleaning_status_label must independently say "Sạch".
     */
    public function test_index_cleaning_status_is_decoupled_from_operational_status_label(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Inspected, 'cleaning_status' => 'CLEAN']);

        $this->actingAs($this->admin)
            ->get('/admin/housekeeping')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('floors', fn ($floors): bool => collect($floors)
                    ->pluck('rooms')
                    ->flatten(1)
                    ->contains(fn (array $r): bool =>
                        $r['id'] === $room->id
                        && $r['status_label'] === 'Đã kiểm tra'
                        && $r['cleaning_status'] === 'CLEAN'
                        && $r['cleaning_status_label'] === 'Sạch'
                        && $r['operational_status_label'] === 'Trống'
                    ))
            );
    }

    /**
     * Nav gating (AppLayout.vue) reads auth.user.permissions from the shared Inertia
     * prop — verify housekeeping.view is present for a HOUSEKEEPING user and absent
     * for a role without it, on any Inertia page (not just the Housekeeping board).
     */
    public function test_shared_permissions_prop_includes_housekeeping_view_for_housekeeping_role(): void
    {
        $this->actingAs($this->housekeeping)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.permissions', fn ($permissions): bool => collect($permissions)->contains('housekeeping.view'))
            );
    }

    public function test_shared_permissions_prop_excludes_housekeeping_view_for_accountant_role(): void
    {
        $this->actingAs($this->accountant)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.permissions', fn ($permissions): bool => !collect($permissions)->contains('housekeeping.view'))
            );
    }

    // -------------------------------------------------------------------------
    // markClean / markDirty — room.cleaning.update (Room Operations Simplification)
    // -------------------------------------------------------------------------

    public function test_mark_clean_success(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->housekeeping)
            ->patchJson("/admin/housekeeping/{$room->id}/mark-clean", ['notes' => 'Đã dọn xong'])
            ->assertOk()
            ->assertJsonFragment(['status' => RoomStatus::VacantClean->value]);

        $this->assertEquals('CLEAN', $room->refresh()->cleaning_status->value);
    }

    public function test_mark_dirty_success(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantClean]);

        $this->actingAs($this->reception)
            ->patchJson("/admin/housekeeping/{$room->id}/mark-dirty")
            ->assertOk()
            ->assertJsonFragment(['status' => RoomStatus::VacantDirty->value]);

        $this->assertEquals('DIRTY', $room->refresh()->cleaning_status->value);
    }

    public function test_mark_clean_forbidden_for_accountant(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->accountant)
            ->patchJson("/admin/housekeeping/{$room->id}/mark-clean")
            ->assertForbidden();
    }

    public function test_mark_dirty_rejected_for_out_of_service_room(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::OutOfService]);

        $this->actingAs($this->admin)
            ->patchJson("/admin/housekeeping/{$room->id}/mark-dirty")
            ->assertUnprocessable();
    }

    public function test_mark_clean_does_not_change_occupied_room_status(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Occupied, 'cleaning_status' => 'DIRTY']);

        $this->actingAs($this->housekeeping)
            ->patchJson("/admin/housekeeping/{$room->id}/mark-clean")
            ->assertOk()
            ->assertJsonFragment(['status' => RoomStatus::Occupied->value]);

        $this->assertEquals('CLEAN', $room->refresh()->cleaning_status->value);
    }

    // -------------------------------------------------------------------------
    // assign — housekeeping.assign
    // -------------------------------------------------------------------------

    public function test_assign_success_creates_pending_assignment(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->manager)
            ->postJson("/admin/housekeeping/{$room->id}/assign", [
                'assigned_to' => $this->housekeeping->id,
                'priority'    => CleaningPriority::High->value,
            ])
            ->assertCreated()
            ->assertJsonPath('status', HousekeepingAssignmentStatus::Pending->value)
            ->assertJsonPath('assigned_to', $this->housekeeping->id);

        $this->assertDatabaseHas('housekeeping_assignments', [
            'room_id'     => $room->id,
            'assigned_to' => $this->housekeeping->id,
            'assigned_by' => $this->manager->id,
        ]);
    }

    public function test_housekeeping_can_self_assign(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->housekeeping)
            ->postJson("/admin/housekeeping/{$room->id}/assign", [])
            ->assertCreated()
            ->assertJsonPath('assigned_to', $this->housekeeping->id);
    }

    public function test_housekeeping_cannot_assign_to_another_housekeeper(): void
    {
        $otherHk = tap(User::factory()->create())->assignRole('HOUSEKEEPING');
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->housekeeping)
            ->postJson("/admin/housekeeping/{$room->id}/assign", ['assigned_to' => $otherHk->id])
            ->assertForbidden();
    }

    public function test_assign_forbidden_for_reception(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->reception)
            ->postJson("/admin/housekeeping/{$room->id}/assign", [])
            ->assertForbidden();
    }

    public function test_assign_validation_fails_for_invalid_priority(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->admin)
            ->postJson("/admin/housekeeping/{$room->id}/assign", ['priority' => 'NOT_A_PRIORITY'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['priority']);
    }

    public function test_assign_blocked_when_room_not_dirty(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantClean]);

        $this->actingAs($this->admin)
            ->postJson("/admin/housekeeping/{$room->id}/assign", [])
            ->assertUnprocessable();
    }

    public function test_assign_blocked_duplicate_active_assignment(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->admin)
            ->postJson("/admin/housekeeping/{$room->id}/assign", [])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson("/admin/housekeeping/{$room->id}/assign", [])
            ->assertUnprocessable();
    }

    // -------------------------------------------------------------------------
    // startCleaning / completeCleaning — room.status.update
    // -------------------------------------------------------------------------

    public function test_start_cleaning_success(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $assignment = HousekeepingAssignment::factory()->pending()->create([
            'room_id'     => $room->id,
            'assigned_to' => $this->housekeeping->id,
        ]);

        $this->actingAs($this->housekeeping)
            ->patchJson("/admin/housekeeping/assignments/{$assignment->id}/start")
            ->assertOk()
            ->assertJsonPath('status', HousekeepingAssignmentStatus::InProgress->value);

        $this->assertEquals(RoomStatus::Cleaning, $room->refresh()->status);
    }

    public function test_start_cleaning_forbidden_for_reception(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $assignment = HousekeepingAssignment::factory()->pending()->create(['room_id' => $room->id]);

        $this->actingAs($this->reception)
            ->patchJson("/admin/housekeeping/assignments/{$assignment->id}/start")
            ->assertForbidden();
    }

    /**
     * Milestone 5.1 (ADR-90 auto-claim): an unassigned assignment (assigned_to = null,
     * e.g. auto-created by autoMarkDirtyOnCheckout()) can now be started directly by any
     * HOUSEKEEPING user — startCleaning() claims it for them in the same request.
     */
    public function test_start_cleaning_auto_claims_unassigned_assignment_for_housekeeping(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $assignment = HousekeepingAssignment::factory()->pending()->create([
            'room_id'     => $room->id,
            'assigned_to' => null,
        ]);

        $this->actingAs($this->housekeeping)
            ->patchJson("/admin/housekeeping/assignments/{$assignment->id}/start")
            ->assertOk()
            ->assertJsonPath('status', HousekeepingAssignmentStatus::InProgress->value)
            ->assertJsonPath('assigned_to', $this->housekeeping->id);

        $this->assertEquals(RoomStatus::Cleaning, $room->refresh()->status);
        $this->assertDatabaseHas('housekeeping_assignments', [
            'id'          => $assignment->id,
            'assigned_to' => $this->housekeeping->id,
        ]);
    }

    /**
     * A second HOUSEKEEPING user cannot start an assignment already claimed/assigned to
     * someone else — auto-claim must never steal an existing assignment.
     */
    public function test_start_cleaning_forbidden_for_housekeeping_when_already_assigned_to_another_user(): void
    {
        $otherHousekeeping = tap(User::factory()->create())->assignRole('HOUSEKEEPING');
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);
        $assignment = HousekeepingAssignment::factory()->pending()->create([
            'room_id'     => $room->id,
            'assigned_to' => $otherHousekeeping->id,
        ]);

        $this->actingAs($this->housekeeping)
            ->patchJson("/admin/housekeeping/assignments/{$assignment->id}/start")
            ->assertForbidden();

        $this->assertEquals($otherHousekeeping->id, $assignment->refresh()->assigned_to);
        $this->assertEquals(HousekeepingAssignmentStatus::Pending, $assignment->refresh()->status);
    }

    public function test_complete_cleaning_success(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Cleaning]);
        $assignment = HousekeepingAssignment::factory()->inProgress()->create([
            'room_id'     => $room->id,
            'assigned_to' => $this->housekeeping->id,
        ]);
        CleaningRecord::factory()->create([
            'room_id'       => $room->id,
            'assignment_id' => $assignment->id,
            'cleaned_by'    => $this->housekeeping->id,
            'started_at'    => now()->subMinutes(20),
            'completed_at'  => null,
        ]);

        $this->actingAs($this->housekeeping)
            ->patchJson("/admin/housekeeping/assignments/{$assignment->id}/complete", ['notes' => 'Xong'])
            ->assertOk()
            ->assertJsonPath('cleaning_notes', 'Xong');

        $this->assertEquals(RoomStatus::Inspected, $room->refresh()->status);
    }

    // -------------------------------------------------------------------------
    // passInspection / failInspection / skipInspection — room.inspect
    // -------------------------------------------------------------------------

    public function test_pass_inspection_success(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Inspected]);
        CleaningRecord::factory()->awaitingInspection()->create(['room_id' => $room->id]);

        $this->actingAs($this->manager)
            ->patchJson("/admin/housekeeping/{$room->id}/pass-inspection", ['notes' => 'Đạt'])
            ->assertOk()
            ->assertJsonPath('inspection_result', 'pass');

        $this->assertEquals(RoomStatus::VacantClean, $room->refresh()->status);
    }

    public function test_fail_inspection_success(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Inspected]);
        CleaningRecord::factory()->awaitingInspection()->create(['room_id' => $room->id]);

        $this->actingAs($this->manager)
            ->patchJson("/admin/housekeeping/{$room->id}/fail-inspection", ['notes' => 'Chưa sạch'])
            ->assertOk()
            ->assertJsonPath('inspection_result', 'fail');

        $this->assertEquals(RoomStatus::VacantDirty, $room->refresh()->status);
    }

    public function test_skip_inspection_requires_notes(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Inspected]);
        CleaningRecord::factory()->awaitingInspection()->create(['room_id' => $room->id]);

        $this->actingAs($this->manager)
            ->patchJson("/admin/housekeeping/{$room->id}/skip-inspection", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notes']);
    }

    public function test_skip_inspection_success_with_notes(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Inspected]);
        CleaningRecord::factory()->awaitingInspection()->create(['room_id' => $room->id]);

        $this->actingAs($this->manager)
            ->patchJson("/admin/housekeeping/{$room->id}/skip-inspection", ['notes' => 'Khách VIP quay lại gấp'])
            ->assertOk()
            ->assertJsonPath('inspection_result', 'skip');

        $this->assertEquals(RoomStatus::VacantClean, $room->refresh()->status);
    }

    public function test_inspect_forbidden_for_housekeeping(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Inspected]);
        CleaningRecord::factory()->awaitingInspection()->create(['room_id' => $room->id]);

        $this->actingAs($this->housekeeping)
            ->patchJson("/admin/housekeeping/{$room->id}/pass-inspection", [])
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // markOutOfOrder / releaseFromOutOfOrder — room.maintenance
    // -------------------------------------------------------------------------

    public function test_mark_out_of_order_success(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->manager)
            ->patchJson("/admin/housekeeping/{$room->id}/out-of-order", ['reason' => 'Hỏng điều hòa'])
            ->assertOk()
            ->assertJsonPath('status', RoomStatus::OutOfOrder->value);
    }

    public function test_mark_out_of_order_forbidden_for_housekeeping(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->housekeeping)
            ->patchJson("/admin/housekeeping/{$room->id}/out-of-order", ['reason' => 'Hỏng điều hòa'])
            ->assertForbidden();
    }

    public function test_mark_out_of_order_validation_requires_reason(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->manager)
            ->patchJson("/admin/housekeeping/{$room->id}/out-of-order", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_release_from_out_of_order_success(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::OutOfOrder]);

        $this->actingAs($this->manager)
            ->patchJson("/admin/housekeeping/{$room->id}/release", [])
            ->assertOk()
            ->assertJsonPath('status', RoomStatus::VacantDirty->value);
    }

    public function test_release_from_out_of_order_validation_rejects_invalid_target_status(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::OutOfOrder]);

        $this->actingAs($this->manager)
            ->patchJson("/admin/housekeeping/{$room->id}/release", ['target_status' => 'OCCUPIED'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target_status']);
    }

    public function test_release_forbidden_for_housekeeping(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::OutOfOrder]);

        $this->actingAs($this->housekeeping)
            ->patchJson("/admin/housekeeping/{$room->id}/release", [])
            ->assertForbidden();
    }
}
