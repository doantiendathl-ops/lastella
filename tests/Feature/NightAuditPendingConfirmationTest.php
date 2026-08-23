<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\FolioStatus;
use App\Enums\StayStatus;
use App\Exceptions\RunNotRecalculableException;
use App\Exceptions\SystemEntryVoidException;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\NightAuditRun;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\FolioService;
use App\Services\NightAuditService;
use App\Services\Posting\RoomChargePostingJob;
use App\Services\StayService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Night Audit pending-confirmation window (Phần 2, user request 2026-08-22/23
 * chat): "cửa sổ chờ xác nhận 24h" — a COMPLETED run stays correctable
 * (voidable + "Tính lại") until the NEXT run auto-confirms it, EXCEPT for
 * individual stays that already checked out, which finalize immediately.
 */
class NightAuditPendingConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->reception = User::factory()->create();
        $this->reception->assignRole('RECEPTION');
    }

    /** @return array{0: Booking, 1: Stay, 2: RoomAssignment, 3: BookingRequirement} */
    private function makeCheckedInStay(int $roomPrice = 500000): array
    {
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();
        Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);
        $requirement = BookingRequirement::factory()->create([
            'booking_id'   => $booking->id,
            'room_type_id' => $roomType->id,
            'room_price'   => $roomPrice,
        ]);

        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'status'       => AssignmentStatus::CheckedIn,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addDay(),
        ]);
        $stay = Stay::factory()->for($booking)->for($room)->create([
            'room_assignment_id' => $assignment->id,
            'status'             => StayStatus::CheckedIn,
            'actual_checkin_at'  => now()->subDay(),
            'planned_checkin_at' => now()->subDay(),
            'planned_checkout_at' => now()->addDay(),
        ]);

        return [$booking, $stay, $assignment, $requirement];
    }

    private function roomChargeEntryFor(Stay $stay, NightAuditRun $run): FolioEntry
    {
        return FolioEntry::where('stay_id', $stay->id)
            ->where('night_audit_run_id', $run->id)
            ->where('posting_key', 'like', 'ROOM_NIGHT_%')
            ->firstOrFail();
    }

    // -------------------------------------------------------------------------
    // Confirmation lifecycle
    // -------------------------------------------------------------------------

    public function test_completed_run_is_awaiting_confirmation_until_the_next_run(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $service = app(NightAuditService::class);
        $run1 = $service->runForDate(Carbon::parse('2026-08-01'));

        $this->assertTrue($run1->isCompleted());
        $this->assertNull($run1->confirmed_at);
        $this->assertTrue($run1->isAwaitingConfirmation());

        $entry1 = $this->roomChargeEntryFor($stay, $run1);
        $this->assertNull($entry1->finalized_at);

        // Starting the NEXT run auto-confirms run1 and finalizes its entries.
        $service->runForDate(Carbon::parse('2026-08-02'));

        $run1->refresh();
        $entry1->refresh();
        $this->assertNotNull($run1->confirmed_at);
        $this->assertNotNull($entry1->finalized_at);
    }

    public function test_confirming_a_run_does_not_finalize_the_next_runs_entries(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $service = app(NightAuditService::class);
        $run1 = $service->runForDate(Carbon::parse('2026-08-01'));
        $run2 = $service->runForDate(Carbon::parse('2026-08-02'));

        $entry2 = $this->roomChargeEntryFor($stay, $run2);

        $run1->refresh();
        $this->assertNotNull($run1->confirmed_at);
        $this->assertNull($run2->confirmed_at);
        $this->assertNull($entry2->finalized_at);
    }

    // -------------------------------------------------------------------------
    // Void guard (FolioService::voidEntry)
    // -------------------------------------------------------------------------

    public function test_night_audit_entry_is_voidable_while_awaiting_confirmation(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $service = app(NightAuditService::class);
        $run1 = $service->runForDate(Carbon::parse('2026-08-01'));
        $entry1 = $this->roomChargeEntryFor($stay, $run1);

        app(FolioService::class)->voidEntry($entry1, 'Sửa lại giá phòng', $this->admin);

        $entry1->refresh();
        $this->assertNotNull($entry1->voided_at);
    }

    public function test_night_audit_entry_is_not_voidable_once_confirmed(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $service = app(NightAuditService::class);
        $run1 = $service->runForDate(Carbon::parse('2026-08-01'));
        $entry1 = $this->roomChargeEntryFor($stay, $run1);

        // Confirms run1.
        $service->runForDate(Carbon::parse('2026-08-02'));
        $entry1->refresh();

        $this->expectException(SystemEntryVoidException::class);
        app(FolioService::class)->voidEntry($entry1, 'Quá hạn sửa', $this->admin);
    }

    public function test_manual_charge_untouched_by_pending_confirmation_window(): void
    {
        // posting_key === null (manual charge) was always voidable and stays
        // that way regardless of night_audit_run_id/finalized_at.
        $folio = Folio::factory()->create(['status' => FolioStatus::Open]);
        $entry = FolioEntry::factory()->for($folio)->create(['posting_key' => null]);

        app(FolioService::class)->voidEntry($entry, 'Sai sót', $this->admin);

        $entry->refresh();
        $this->assertNotNull($entry->voided_at);
    }

    public function test_non_sweep_system_entry_stays_unconditionally_immutable(): void
    {
        // posting_key set but night_audit_run_id null (e.g. an early-check-in
        // fee, or a ONE_TIME unified-service posting) — old ADR-50 behavior,
        // never voidable, regardless of finalized_at.
        $folio = Folio::factory()->create(['status' => FolioStatus::Open]);
        $entry = FolioEntry::factory()->for($folio)->create([
            'posting_key'        => 'EARLY_CHECKIN_FEE_1',
            'night_audit_run_id' => null,
        ]);

        $this->expectException(SystemEntryVoidException::class);
        app(FolioService::class)->voidEntry($entry, 'Không được phép', $this->admin);
    }

    // -------------------------------------------------------------------------
    // Checkout-time early finalization
    // -------------------------------------------------------------------------

    public function test_checkout_finalizes_the_stays_night_audit_entries_immediately(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $service = app(NightAuditService::class);
        $run1 = $service->runForDate(Carbon::parse('2026-08-01'));
        $entry1 = $this->roomChargeEntryFor($stay, $run1);
        $this->assertNull($entry1->finalized_at);

        $this->actingAs($this->admin);
        app(StayService::class)->checkOut($stay, now(), confirmed: true);

        $entry1->refresh();
        $run1->refresh();
        $this->assertNotNull($entry1->finalized_at);
        // The whole run is not confirmed yet — only this stay's own entries were.
        $this->assertNull($run1->confirmed_at);

        $this->expectException(SystemEntryVoidException::class);
        app(FolioService::class)->voidEntry($entry1, 'Quá hạn', $this->admin);
    }

    // -------------------------------------------------------------------------
    // Recalculate ("Tính lại")
    // -------------------------------------------------------------------------

    public function test_recalculate_voids_and_reposts_with_updated_price(): void
    {
        [$booking, $stay, , $requirement] = $this->makeCheckedInStay(500000);

        $service = app(NightAuditService::class);
        $run1 = $service->runForDate(Carbon::parse('2026-08-01'));
        $oldEntry = $this->roomChargeEntryFor($stay, $run1);
        $this->assertSame('500000.00', number_format((float) $oldEntry->amount, 2, '.', ''));

        $requirement->update(['room_price' => 650000]);

        $recalculated = $service->recalculate($run1, $this->admin);

        $oldEntry->refresh();
        $this->assertNotNull($oldEntry->voided_at);

        $newEntry = FolioEntry::where('stay_id', $stay->id)
            ->where('night_audit_run_id', $recalculated->id)
            ->where('posting_key', 'like', 'ROOM_NIGHT_%')
            ->whereNull('voided_at')
            ->firstOrFail();

        $this->assertSame('650000.00', number_format((float) $newEntry->amount, 2, '.', ''));
        $this->assertTrue($recalculated->isCompleted());
    }

    public function test_recalculate_skips_entries_already_finalized_by_early_checkout(): void
    {
        [$booking, $stayA] = $this->makeCheckedInStay(500000);

        // A second stay on the SAME booking, in a different room, so the
        // first checkout is a partial checkout (no confirmation required).
        $roomTypeB = RoomType::factory()->create();
        $roomB = Room::factory()->for($roomTypeB)->create();
        BookingRequirement::factory()->create([
            'booking_id'   => $booking->id,
            'room_type_id' => $roomTypeB->id,
            'room_price'   => 400000,
        ]);
        $assignmentB = RoomAssignment::factory()->for($booking)->for($roomB)->create([
            'room_type_id' => $roomTypeB->id,
            'status'       => AssignmentStatus::CheckedIn,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addDay(),
        ]);
        $stayB = Stay::factory()->for($booking)->for($roomB)->create([
            'room_assignment_id'  => $assignmentB->id,
            'status'              => StayStatus::CheckedIn,
            'actual_checkin_at'   => now()->subDay(),
            'planned_checkin_at'  => now()->subDay(),
            'planned_checkout_at' => now()->addDay(),
        ]);

        $service = app(NightAuditService::class);
        $run1 = $service->runForDate(Carbon::parse('2026-08-01'));

        $entryA = $this->roomChargeEntryFor($stayA, $run1);
        $entryB = $this->roomChargeEntryFor($stayB, $run1);

        $this->actingAs($this->admin);
        app(StayService::class)->checkOut($stayA); // partial — stayB still active

        $entryA->refresh();
        $this->assertNotNull($entryA->finalized_at);

        $service->recalculate($run1, $this->admin);

        $entryA->refresh();
        $entryB->refresh();
        $this->assertNull($entryA->voided_at, 'Finalized entry must never be touched by recalculate.');
        $this->assertNotNull($entryB->voided_at, 'Still-open stay entry must be recalculated.');
    }

    public function test_recalculate_rejected_for_a_run_that_never_completed(): void
    {
        $run = NightAuditRun::factory()->create(['status' => 'PENDING']);

        $this->expectException(RunNotRecalculableException::class);
        app(NightAuditService::class)->recalculate($run, $this->admin);
    }

    public function test_recalculate_rejected_once_the_run_is_confirmed(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $service = app(NightAuditService::class);
        $run1 = $service->runForDate(Carbon::parse('2026-08-01'));
        $service->runForDate(Carbon::parse('2026-08-02')); // confirms run1
        $run1->refresh();

        $this->expectException(RunNotRecalculableException::class);
        $service->recalculate($run1, $this->admin);
    }

    // -------------------------------------------------------------------------
    // Controller endpoint
    // -------------------------------------------------------------------------

    public function test_admin_can_recalculate_via_endpoint(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $run1 = app(NightAuditService::class)->runForDate(Carbon::parse('2026-08-01'));

        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.recalculate', $run1))
            ->assertRedirect(route('admin.night-audit.show', $run1));
    }

    public function test_reception_cannot_recalculate(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $run1 = app(NightAuditService::class)->runForDate(Carbon::parse('2026-08-01'));

        $this->actingAs($this->reception)
            ->post(route('admin.night-audit.recalculate', $run1))
            ->assertForbidden();
    }

    public function test_recalculate_endpoint_surfaces_guard_error_for_confirmed_run(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $service = app(NightAuditService::class);
        $run1 = $service->runForDate(Carbon::parse('2026-08-01'));
        $service->runForDate(Carbon::parse('2026-08-02')); // confirms run1
        $run1->refresh();

        $this->actingAs($this->admin)
            ->post(route('admin.night-audit.recalculate', $run1))
            ->assertSessionHasErrors('run');
    }

    public function test_show_page_exposes_can_recalculate_only_while_awaiting_confirmation(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $service = app(NightAuditService::class);
        $run1 = $service->runForDate(Carbon::parse('2026-08-01'));

        $this->actingAs($this->admin)
            ->get(route('admin.night-audit.show', $run1))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('run.can_recalculate', true)
                ->where('run.is_awaiting_confirmation', true)
            );

        $service->runForDate(Carbon::parse('2026-08-02')); // confirms run1

        $this->actingAs($this->admin)
            ->get(route('admin.night-audit.show', $run1))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('run.can_recalculate', false)
                ->where('run.is_awaiting_confirmation', false)
            );
    }
}
