<?php

namespace Tests\Feature;

use App\Enums\FolioStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\NightAuditRun;
use App\Models\Stay;
use App\Models\User;
use App\Services\BusinessDateService;
use App\Services\HotelSettingsService;
use App\Services\NightAuditService;
use App\Services\Posting\RoomChargePostingJob;
use App\Services\NightAuditPipeline;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NightAuditPipelineFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private User $reception;
    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin     = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->manager   = User::factory()->create();
        $this->manager->assignRole('MANAGER');

        $this->reception = User::factory()->create();
        $this->reception->assignRole('RECEPTION');

        $this->businessDate = Carbon::parse('2026-07-01');
    }

    private function makeCheckedInStayWithFolio(): Stay
    {
        $stay = Stay::factory()->create(['status' => StayStatus::CheckedIn]);
        $booking = Booking::find($stay->booking_id);
        Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);

        $stay->loadMissing('room');
        if ($stay->room !== null) {
            BookingRequirement::factory()->create([
                'booking_id'   => $booking->id,
                'room_type_id' => $stay->room->room_type_id,
                'room_price'   => 500000,
            ]);
        }

        return $stay;
    }

    public function test_pipeline_posts_room_charge_for_checked_in_stays(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();

        $auditRun = NightAuditRun::create([
            'business_date' => $this->businessDate->toDateString(),
            'status'        => 'PENDING',
            'run_by'        => $this->admin->id,
        ]);

        $pipeline = app(NightAuditPipeline::class);
        $pipeline->register(app(RoomChargePostingJob::class));
        $pipeline->run($auditRun, $this->businessDate);

        $auditRun->refresh();
        $this->assertEquals('COMPLETED', $auditRun->status);
        $this->assertGreaterThanOrEqual(1, $auditRun->stays_processed);
        $this->assertGreaterThanOrEqual(1, $auditRun->entries_posted);

        $this->assertDatabaseHas('folio_entries', [
            'stay_id'        => $stay->id,
            'posting_source' => 'NIGHT_AUDIT',
        ]);
    }

    public function test_pipeline_does_not_re_post_existing_entry(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();

        $auditRun1 = NightAuditRun::create([
            'business_date' => $this->businessDate->toDateString(),
            'status'        => 'PENDING',
        ]);

        $pipeline = app(NightAuditPipeline::class);
        $pipeline->register(app(RoomChargePostingJob::class));
        $pipeline->run($auditRun1, $this->businessDate);

        // Second run — should log ALREADY_POSTED, not duplicate entries
        $auditRun2 = NightAuditRun::create([
            'business_date' => $this->businessDate->addDay()->toDateString(),
            'status'        => 'PENDING',
        ]);

        $pipeline2 = app(NightAuditPipeline::class);
        $pipeline2->register(app(RoomChargePostingJob::class));
        $pipeline2->run($auditRun2, $this->businessDate->subDay()); // same business date

        $this->assertDatabaseCount('folio_entries', 1);
        $this->assertDatabaseHas('night_audit_booking_logs', [
            'result' => 'ALREADY_POSTED',
        ]);
    }

    public function test_audit_run_status_is_completed_on_success(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();

        $service = app(NightAuditService::class);
        $run     = $service->runForDate($this->businessDate);

        $this->assertEquals('COMPLETED', $run->status);
        $this->assertNotNull($run->completed_at);
    }

    public function test_night_audit_logs_entry_per_stay_per_job(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();

        $service = app(NightAuditService::class);
        $service->runForDate($this->businessDate);

        $this->assertDatabaseHas('night_audit_booking_logs', [
            'stay_id'   => $stay->id,
            'job_class' => RoomChargePostingJob::class,
        ]);
    }

    public function test_admin_can_access_night_audit_index(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.night-audit.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/NightAudit/Index')
                ->has('runs')
                ->has('businessDate')
            );
    }

    public function test_reception_cannot_access_night_audit_index(): void
    {
        $this->actingAs($this->reception)
            ->get(route('admin.night-audit.index'))
            ->assertForbidden();
    }

    public function test_admin_can_trigger_night_audit_run(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.run'))
            ->assertRedirect(route('admin.night-audit.index'));

        $this->assertDatabaseHas('night_audit_runs', [
            'status' => 'COMPLETED',
        ]);
    }

    public function test_reception_cannot_trigger_night_audit(): void
    {
        $this->actingAs($this->reception)
            ->post(route('admin.night-audit.run'))
            ->assertForbidden();
    }

    public function test_artisan_command_runs_successfully(): void
    {
        $this->makeCheckedInStayWithFolio();

        $this->artisan('audit:night-audit', ['--date' => '2026-07-01'])
            ->assertSuccessful();

        $this->assertDatabaseHas('night_audit_runs', [
            'business_date' => '2026-07-01',
            'status'        => 'COMPLETED',
        ]);
    }

    public function test_artisan_dry_run_makes_no_database_changes(): void
    {
        $this->makeCheckedInStayWithFolio();

        $this->artisan('audit:night-audit', ['--date' => '2026-07-01', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('night_audit_runs', ['business_date' => '2026-07-01']);
    }

    public function test_running_same_date_twice_returns_existing_completed_run(): void
    {
        $this->makeCheckedInStayWithFolio();

        $service = app(NightAuditService::class);
        $run1    = $service->runForDate($this->businessDate);
        $run2    = $service->runForDate($this->businessDate);

        $this->assertEquals($run1->id, $run2->id);
        $this->assertDatabaseCount('night_audit_runs', 1);
    }
}
