<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NightAuditBookingLog;
use App\Models\NightAuditRun;
use App\Models\User;
use App\Services\BusinessDateService;
use App\Services\Posting\BreakfastPostingJob;
use App\Services\Posting\ExtraPersonPostingJob;
use App\Services\Posting\RoomChargePostingJob;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NightAuditOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private User $accountant;
    private User $reception;
    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('MANAGER');

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('ACCOUNTANT');

        $this->reception = User::factory()->create();
        $this->reception->assignRole('RECEPTION');

        $this->businessDate = Carbon::parse('2026-07-02');

        $this->instance(BusinessDateService::class, $this->mockBusinessDate($this->businessDate));
    }

    private function mockBusinessDate(Carbon $date): BusinessDateService
    {
        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn($date);
        $mock->method('businessDateFor')->willReturn($date);

        return $mock;
    }

    // -------------------------------------------------------------------------
    // Run Detail (show)
    // -------------------------------------------------------------------------

    public function test_admin_can_view_run_detail(): void
    {
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'COMPLETED',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.night-audit.show', $run))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/NightAudit/Show', false)
                ->has('run')
                ->has('summary')
                ->has('logs')
            );
    }

    public function test_manager_can_view_run_detail(): void
    {
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'COMPLETED',
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.night-audit.show', $run))
            ->assertOk();
    }

    public function test_accountant_can_view_run_detail(): void
    {
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'COMPLETED',
        ]);

        $this->actingAs($this->accountant)
            ->get(route('admin.night-audit.show', $run))
            ->assertOk();
    }

    public function test_receptionist_cannot_view_run_detail(): void
    {
        $run = NightAuditRun::factory()->create(['status' => 'COMPLETED']);

        $this->actingAs($this->reception)
            ->get(route('admin.night-audit.show', $run))
            ->assertForbidden();
    }

    public function test_run_detail_includes_booking_logs(): void
    {
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'COMPLETED',
        ]);

        NightAuditBookingLog::factory()->count(3)->create(['run_id' => $run->id, 'result' => 'POSTED']);
        NightAuditBookingLog::factory()->count(1)->create(['run_id' => $run->id, 'result' => 'SKIPPED']);

        $this->actingAs($this->admin)
            ->get(route('admin.night-audit.show', $run))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs', 4)
                ->where('summary.posted', 3)
                ->where('summary.skipped', 1)
                ->where('summary.total', 4)
            );
    }

    public function test_run_detail_can_retry_flag_is_true_for_failed_run(): void
    {
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'FAILED',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.night-audit.show', $run))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('run.can_retry', true)
            );
    }

    public function test_run_detail_can_retry_flag_is_false_for_completed_run(): void
    {
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'COMPLETED',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.night-audit.show', $run))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('run.can_retry', false)
            );
    }

    public function test_show_page_includes_job_summary_with_new_job_types(): void
    {
        // Arrange
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'COMPLETED',
        ]);

        NightAuditBookingLog::factory()->create([
            'run_id'    => $run->id,
            'job_class' => RoomChargePostingJob::class,
            'result'    => 'POSTED',
        ]);
        NightAuditBookingLog::factory()->create([
            'run_id'    => $run->id,
            'job_class' => BreakfastPostingJob::class,
            'result'    => 'SKIPPED',
        ]);
        NightAuditBookingLog::factory()->create([
            'run_id'    => $run->id,
            'job_class' => ExtraPersonPostingJob::class,
            'result'    => 'POSTED',
        ]);

        // Act & Assert
        $this->actingAs($this->admin)
            ->get(route('admin.night-audit.show', $run))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('job_summary')
                ->has('job_summary.RoomChargePostingJob')
                ->has('job_summary.BreakfastPostingJob')
                ->has('job_summary.ExtraPersonPostingJob')
            );
    }

    public function test_job_summary_correctly_counts_posted_and_skipped_per_job(): void
    {
        // Arrange
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'COMPLETED',
        ]);

        NightAuditBookingLog::factory()->count(2)->create([
            'run_id'    => $run->id,
            'job_class' => BreakfastPostingJob::class,
            'result'    => 'POSTED',
        ]);
        NightAuditBookingLog::factory()->count(3)->create([
            'run_id'    => $run->id,
            'job_class' => BreakfastPostingJob::class,
            'result'    => 'SKIPPED',
        ]);
        NightAuditBookingLog::factory()->count(1)->create([
            'run_id'    => $run->id,
            'job_class' => ExtraPersonPostingJob::class,
            'result'    => 'ALREADY_POSTED',
        ]);

        // Act & Assert
        $this->actingAs($this->admin)
            ->get(route('admin.night-audit.show', $run))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('job_summary.BreakfastPostingJob.posted', 2)
                ->where('job_summary.BreakfastPostingJob.skipped', 3)
                ->where('job_summary.BreakfastPostingJob.already_posted', 0)
                ->where('job_summary.BreakfastPostingJob.failed', 0)
                ->where('job_summary.ExtraPersonPostingJob.already_posted', 1)
                ->where('job_summary.ExtraPersonPostingJob.posted', 0)
            );
    }

    // -------------------------------------------------------------------------
    // Manual Trigger
    // -------------------------------------------------------------------------

    public function test_admin_can_trigger_manual_run(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.trigger'), ['date' => '2026-07-02'])
            ->assertRedirect();

        $this->assertDatabaseHas('night_audit_runs', [
            'business_date' => '2026-07-02',
        ]);
    }

    public function test_manager_can_trigger_manual_run(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.night-audit.trigger'), ['date' => '2026-07-02'])
            ->assertRedirect();

        $this->assertDatabaseHas('night_audit_runs', [
            'business_date' => '2026-07-02',
        ]);
    }

    public function test_receptionist_cannot_trigger_manual_run(): void
    {
        $this->actingAs($this->reception)
            ->post(route('admin.night-audit.trigger'), ['date' => '2026-07-02'])
            ->assertForbidden();
    }

    public function test_accountant_cannot_trigger_manual_run(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('admin.night-audit.trigger'), ['date' => '2026-07-02'])
            ->assertForbidden();
    }

    public function test_trigger_redirects_to_run_detail_on_success(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.trigger'), ['date' => '2026-07-02'])
            ->assertRedirect();

        $run = NightAuditRun::where('business_date', '2026-07-02')->firstOrFail();

        // Verify we can navigate to the created run
        $this->actingAs($this->admin)
            ->get(route('admin.night-audit.show', $run))
            ->assertOk();
    }

    public function test_trigger_blocked_for_future_date(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.trigger'), ['date' => '2026-07-03'])
            ->assertSessionHasErrors('date');

        $this->assertDatabaseEmpty('night_audit_runs');
    }

    public function test_trigger_blocked_for_completed_run(): void
    {
        NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'COMPLETED',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.trigger'), ['date' => '2026-07-01'])
            ->assertSessionHasErrors('date');

        $this->assertDatabaseCount('night_audit_runs', 1);
    }

    public function test_trigger_blocked_for_running_run(): void
    {
        NightAuditRun::factory()->create([
            'business_date' => '2026-07-02',
            'status'        => 'RUNNING',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.trigger'), ['date' => '2026-07-02'])
            ->assertSessionHasErrors('date');
    }

    public function test_trigger_blocked_outside_audit_window(): void
    {
        // Business date is 2026-07-02 (mocked); default window is 7 days
        // 2026-06-24 is 8 days ago — outside the 7-day window
        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.trigger'), ['date' => '2026-06-24'])
            ->assertSessionHasErrors('date');
    }

    public function test_trigger_requires_valid_date_format(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.trigger'), ['date' => 'not-a-date'])
            ->assertSessionHasErrors('date');
    }

    public function test_trigger_requires_date_field(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.trigger'), [])
            ->assertSessionHasErrors('date');
    }

    public function test_trigger_allows_failed_run_to_be_re_triggered(): void
    {
        NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'FAILED',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.trigger'), ['date' => '2026-07-01'])
            ->assertRedirect();

        // Run should now be COMPLETED (re-ran with no stays to process)
        $this->assertDatabaseHas('night_audit_runs', [
            'business_date' => '2026-07-01',
            'status'        => 'COMPLETED',
        ]);
    }

    // -------------------------------------------------------------------------
    // Retry
    // -------------------------------------------------------------------------

    public function test_admin_can_retry_failed_run(): void
    {
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'FAILED',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.retry', $run))
            ->assertRedirect(route('admin.night-audit.show', $run));

        $this->assertDatabaseHas('night_audit_runs', [
            'id'     => $run->id,
            'status' => 'COMPLETED',
        ]);
    }

    public function test_manager_can_retry_failed_run(): void
    {
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'FAILED',
        ]);

        $this->actingAs($this->manager)
            ->post(route('admin.night-audit.retry', $run))
            ->assertRedirect();
    }

    public function test_receptionist_cannot_retry_run(): void
    {
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'FAILED',
        ]);

        $this->actingAs($this->reception)
            ->post(route('admin.night-audit.retry', $run))
            ->assertForbidden();
    }

    public function test_retry_blocked_for_completed_run(): void
    {
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'COMPLETED',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.retry', $run))
            ->assertSessionHasErrors('run');
    }

    public function test_retry_blocked_for_pending_run(): void
    {
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'PENDING',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.retry', $run))
            ->assertSessionHasErrors('run');
    }

    public function test_retry_updates_run_to_completed_when_no_errors(): void
    {
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'FAILED',
            'error_message' => 'Previous failure reason.',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.retry', $run));

        $this->assertDatabaseHas('night_audit_runs', [
            'id'     => $run->id,
            'status' => 'COMPLETED',
        ]);
    }
}
