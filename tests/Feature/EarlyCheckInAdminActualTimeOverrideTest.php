<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\FolioStatus;
use App\Enums\StayStatus;
use App\Exceptions\FinalCheckoutConfirmationRequiredException;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StayEvent;
use App\Models\User;
use App\Services\StayService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Early Check-in (removes the "not yet time" gate) + ADMIN actual
 * check-in/check-out time override — Active Pilot.
 *
 * See docs/reports/early-checkin-admin-actual-time-override-implementation-report.md.
 */
class EarlyCheckInAdminActualTimeOverrideTest extends TestCase
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

    /**
     * @return array{0: Booking, 1: Stay, 2: RoomAssignment}
     */
    private function makeReservedStay(?Carbon $plannedCheckin = null): array
    {
        $roomType = RoomType::factory()->create();
        $room     = Room::factory()->for($roomType)->create();
        $booking  = Booking::factory()->create();
        Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);

        BookingRequirement::factory()->create([
            'booking_id'   => $booking->id,
            'room_type_id' => $roomType->id,
            'room_price'   => 500000,
        ]);

        $plannedCheckin ??= now()->addHours(3);

        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'status'   => AssignmentStatus::Assigned,
            'start_at' => $plannedCheckin,
            'end_at'   => $plannedCheckin->copy()->addDay(),
        ]);

        $stay = Stay::factory()->for($booking)->for($room)->create([
            'room_assignment_id' => $assignment->id,
            'status'             => StayStatus::Reserved,
            'planned_checkin_at' => $plannedCheckin,
            'planned_checkout_at' => $plannedCheckin->copy()->addDay(),
        ]);

        return [$booking, $stay, $assignment];
    }

    private function makeCheckedInStay(?Carbon $actualCheckin = null): array
    {
        [$booking, $stay, $assignment] = $this->makeReservedStay(now()->subHours(3));

        $actualCheckin ??= now()->subHour();

        app(StayService::class)->checkIn($stay, $actualCheckin);

        return [$booking, $stay->refresh(), $assignment->refresh()];
    }

    // -------------------------------------------------------------------------
    // 1-3. Early check-in — service level
    // -------------------------------------------------------------------------

    public function test_early_check_in_before_scheduled_time_succeeds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 11:00:00'));
        [, $stay] = $this->makeReservedStay(Carbon::parse('2026-08-09 14:00:00'));

        $result = app(StayService::class)->checkIn($stay);

        $this->assertSame(StayStatus::CheckedIn, $result->status);
        $this->assertNotNull($result->actual_checkin_at);
        Carbon::setTestNow();
    }

    public function test_employee_early_check_in_uses_server_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 11:00:00'));
        [, $stay] = $this->makeReservedStay(Carbon::parse('2026-08-09 14:00:00'));

        $result = app(StayService::class)->checkIn($stay);

        $this->assertSame('2026-08-09 11:00:00', $result->actual_checkin_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    public function test_scheduled_check_in_unchanged_after_early_check_in(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 11:00:00'));
        [, $stay] = $this->makeReservedStay(Carbon::parse('2026-08-09 14:00:00'));

        $result = app(StayService::class)->checkIn($stay);

        $this->assertSame('2026-08-09 14:00:00', $result->planned_checkin_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    // -------------------------------------------------------------------------
    // 4. Employee cannot override check-in timestamp (server-side authorization)
    // -------------------------------------------------------------------------

    public function test_employee_forged_check_in_timestamp_is_rejected(): void
    {
        [$booking, $stay] = $this->makeReservedStay(now()->subHour());

        $this->actingAs($this->reception)
            ->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in", [
                'actual_checkin_at' => now()->subDay()->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasErrors('actual_checkin_at');

        $this->assertNull($stay->refresh()->actual_checkin_at);
    }

    public function test_employee_normal_check_in_without_custom_time_succeeds(): void
    {
        [$booking, $stay] = $this->makeReservedStay(now()->subHour());

        $this->actingAs($this->reception)
            ->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(StayStatus::CheckedIn, $stay->refresh()->status);
    }

    // -------------------------------------------------------------------------
    // 5-6. Admin check-in override
    // -------------------------------------------------------------------------

    public function test_admin_can_override_check_in_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00'));
        [$booking, $stay] = $this->makeReservedStay(Carbon::parse('2026-08-09 14:00:00'));

        $this->actingAs($this->admin)
            ->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in", [
                'actual_checkin_at' => '2026-08-09 10:35:00',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $stay->refresh();
        $this->assertSame('2026-08-09 10:35:00', $stay->actual_checkin_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-09 14:00:00', $stay->planned_checkin_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    public function test_admin_future_check_in_override_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00'));
        [$booking, $stay] = $this->makeReservedStay(Carbon::parse('2026-08-09 14:00:00'));

        $this->actingAs($this->admin)
            ->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in", [
                'actual_checkin_at' => '2026-08-09 13:00:00',
            ])
            ->assertSessionHasErrors('actual_checkin_at');

        $this->assertNull($stay->refresh()->actual_checkin_at);
        Carbon::setTestNow();
    }

    // -------------------------------------------------------------------------
    // 7-8. Admin edit-after-check-in
    // -------------------------------------------------------------------------

    public function test_admin_can_edit_actual_check_in_after_check_in(): void
    {
        [$booking, $stay] = $this->makeCheckedInStay(now()->subHours(2));
        $oldActual = $stay->actual_checkin_at;

        $newTime = now()->subMinutes(30);

        $this->actingAs($this->admin)
            ->patch("/admin/bookings/{$booking->id}/stays/{$stay->id}/actual-check-in", [
                'actual_checkin_at' => $newTime->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $stay->refresh();
        $this->assertSame($newTime->format('Y-m-d H:i'), $stay->actual_checkin_at->format('Y-m-d H:i'));
        $this->assertNotEquals($oldActual->format('Y-m-d H:i'), $stay->actual_checkin_at->format('Y-m-d H:i'));
        $this->assertSame(StayStatus::CheckedIn, $stay->status);

        $this->assertDatabaseHas('stay_events', [
            'stay_id'    => $stay->id,
            'event_type' => 'CHECK_IN_TIME_ADJUSTED',
            'actor_id'   => $this->admin->id,
        ]);
    }

    public function test_non_admin_cannot_edit_actual_check_in(): void
    {
        [$booking, $stay] = $this->makeCheckedInStay();
        $oldActual = $stay->actual_checkin_at->format('Y-m-d H:i');

        $this->actingAs($this->reception)
            ->patch("/admin/bookings/{$booking->id}/stays/{$stay->id}/actual-check-in", [
                'actual_checkin_at' => now()->subMinutes(10)->format('Y-m-d H:i:s'),
            ])
            ->assertForbidden();

        $this->assertSame($oldActual, $stay->refresh()->actual_checkin_at->format('Y-m-d H:i'));
    }

    public function test_admin_edit_check_in_service_rejects_when_not_yet_checked_in(): void
    {
        [, $stay] = $this->makeReservedStay(now()->subHour());

        $this->expectException(ValidationException::class);

        app(StayService::class)->updateActualCheckIn($stay, now(), $this->admin);
    }

    public function test_admin_edit_check_in_service_throws_for_non_admin_actor(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $this->expectException(AuthorizationException::class);

        app(StayService::class)->updateActualCheckIn($stay, now(), $this->reception);
    }

    /**
     * User request (2026-08-19 chat) — "khóa điều kiện an toàn" check:
     * confirms updateActualCheckIn() rejects a correction that would push
     * the check-in time PAST an already-recorded checkout time, same
     * cross-field guard checkOut() enforces on the initial action, just
     * exercised from the edit-after-fact side.
     */
    public function test_admin_edit_check_in_service_rejects_when_after_actual_checkout(): void
    {
        [, $stay] = $this->makeCheckedInStay(now()->subHours(3));
        app(StayService::class)->checkOut($stay, now()->subHour(), true);
        $stay->refresh();
        $originalCheckin = $stay->actual_checkin_at;

        try {
            app(StayService::class)->updateActualCheckIn(
                $stay,
                $stay->actual_checkout_at->copy()->addMinutes(10),
                $this->admin,
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('actual_checkin_at', $e->errors());
        }

        $this->assertSame(
            $originalCheckin->format('Y-m-d H:i'),
            $stay->refresh()->actual_checkin_at->format('Y-m-d H:i'),
        );
    }

    // -------------------------------------------------------------------------
    // 9-10. Employee checkout
    // -------------------------------------------------------------------------

    public function test_employee_checkout_uses_server_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 18:30:00'));
        [$booking, $stay] = $this->makeCheckedInStay(Carbon::parse('2026-08-09 10:50:00'));

        $this->actingAs($this->reception)
            ->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", ['confirmed' => true])
            ->assertRedirect()
            ->assertSessionHas('success');

        $stay->refresh();
        $this->assertSame('2026-08-09 18:30:00', $stay->actual_checkout_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    public function test_employee_forged_checkout_timestamp_is_rejected(): void
    {
        [$booking, $stay] = $this->makeCheckedInStay();

        $this->actingAs($this->reception)
            ->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", [
                'actual_checkout_at' => now()->subDay()->format('Y-m-d H:i:s'),
                'confirmed'          => true,
            ])
            ->assertSessionHasErrors('actual_checkout_at');

        $this->assertNull($stay->refresh()->actual_checkout_at);
    }

    // -------------------------------------------------------------------------
    // 11, 13. Admin checkout override + future rejection
    // -------------------------------------------------------------------------

    public function test_admin_can_override_checkout_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 18:30:00'));
        [$booking, $stay] = $this->makeCheckedInStay(Carbon::parse('2026-08-09 10:50:00'));

        $this->actingAs($this->admin)
            ->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", [
                'actual_checkout_at' => '2026-08-09 17:45:00',
                'confirmed'          => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $stay->refresh();
        $this->assertSame('2026-08-09 17:45:00', $stay->actual_checkout_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    public function test_admin_future_checkout_override_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 18:30:00'));
        [$booking, $stay] = $this->makeCheckedInStay(Carbon::parse('2026-08-09 10:50:00'));

        $this->actingAs($this->admin)
            ->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", [
                'actual_checkout_at' => '2026-08-09 19:00:00',
                'confirmed'          => true,
            ])
            ->assertSessionHasErrors('actual_checkout_at');

        $this->assertNull($stay->refresh()->actual_checkout_at);
        Carbon::setTestNow();
    }

    // -------------------------------------------------------------------------
    // 12. Checkout before check-in rejected
    // -------------------------------------------------------------------------

    public function test_checkout_before_check_in_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 18:30:00'));
        [$booking, $stay] = $this->makeCheckedInStay(Carbon::parse('2026-08-09 10:50:00'));

        $this->actingAs($this->admin)
            ->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", [
                'actual_checkout_at' => '2026-08-09 09:00:00',
                'confirmed'          => true,
            ])
            ->assertRedirect();

        // Rejected inside the service (cross-field check against the locked
        // stay row), so it surfaces as a ValidationException -> redirect back
        // with errors under the booking key used by StayService, not a field
        // error on the request itself.
        $this->assertNull($stay->refresh()->actual_checkout_at);
        Carbon::setTestNow();
    }

    // -------------------------------------------------------------------------
    // 14-15. Admin edit-after-checkout
    // -------------------------------------------------------------------------

    public function test_admin_can_edit_actual_checkout_after_checkout(): void
    {
        [$booking, $stay] = $this->makeCheckedInStay(now()->subHours(3));
        app(StayService::class)->checkOut($stay, now()->subHour(), true);
        $stay->refresh();
        $oldActual = $stay->actual_checkout_at;

        $newTime = $oldActual->copy()->subMinutes(25);

        $this->actingAs($this->admin)
            ->patch("/admin/bookings/{$booking->id}/stays/{$stay->id}/actual-check-out", [
                'actual_checkout_at' => $newTime->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $stay->refresh();
        $this->assertSame($newTime->format('Y-m-d H:i'), $stay->actual_checkout_at->format('Y-m-d H:i'));
        $this->assertNotEquals($oldActual->format('Y-m-d H:i'), $stay->actual_checkout_at->format('Y-m-d H:i'));
        $this->assertSame(StayStatus::CheckedOut, $stay->status);

        $this->assertDatabaseHas('stay_events', [
            'stay_id'    => $stay->id,
            'event_type' => 'CHECK_OUT_TIME_ADJUSTED',
            'actor_id'   => $this->admin->id,
        ]);
    }

    public function test_non_admin_cannot_edit_actual_checkout(): void
    {
        [$booking, $stay] = $this->makeCheckedInStay(now()->subHours(3));
        app(StayService::class)->checkOut($stay, now()->subHour(), true);
        $stay->refresh();
        $oldActual = $stay->actual_checkout_at->format('Y-m-d H:i');

        $this->actingAs($this->reception)
            ->patch("/admin/bookings/{$booking->id}/stays/{$stay->id}/actual-check-out", [
                'actual_checkout_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertForbidden();

        $this->assertSame($oldActual, $stay->refresh()->actual_checkout_at->format('Y-m-d H:i'));
    }

    /**
     * User request (2026-08-19 chat) — "khóa điều kiện an toàn" check, other
     * direction: updateActualCheckOut() rejects a correction that would pull
     * the checkout time BEFORE the recorded check-in time.
     */
    public function test_admin_edit_check_out_service_rejects_when_before_actual_checkin(): void
    {
        [, $stay] = $this->makeCheckedInStay(now()->subHours(3));
        app(StayService::class)->checkOut($stay, now()->subHour(), true);
        $stay->refresh();
        $originalCheckout = $stay->actual_checkout_at;

        try {
            app(StayService::class)->updateActualCheckOut(
                $stay,
                $stay->actual_checkin_at->copy()->subMinutes(10),
                $this->admin,
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('actual_checkout_at', $e->errors());
        }

        $this->assertSame(
            $originalCheckout->format('Y-m-d H:i'),
            $stay->refresh()->actual_checkout_at->format('Y-m-d H:i'),
        );
    }

    // -------------------------------------------------------------------------
    // 16. Scheduled checkout unchanged
    // -------------------------------------------------------------------------

    public function test_scheduled_checkout_unchanged_by_actual_checkout_edit(): void
    {
        [$booking, $stay] = $this->makeCheckedInStay(now()->subHours(3));
        $plannedCheckout = $stay->planned_checkout_at;
        app(StayService::class)->checkOut($stay, now()->subHour(), true);
        $stay->refresh();

        $this->actingAs($this->admin)
            ->patch("/admin/bookings/{$booking->id}/stays/{$stay->id}/actual-check-out", [
                'actual_checkout_at' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $this->assertSame($plannedCheckout->format('Y-m-d H:i'), $stay->refresh()->planned_checkout_at->format('Y-m-d H:i'));
    }

    // -------------------------------------------------------------------------
    // 17-19. Existing guards preserved
    // -------------------------------------------------------------------------

    public function test_room_conflict_guard_still_preserved_for_early_check_in(): void
    {
        // Assignment not in Assigned status (e.g. Released) must still block
        // check-in regardless of how early/late "now" is relative to planned time.
        [$booking, $stay, $assignment] = $this->makeReservedStay(now()->subHour());
        $assignment->update(['status' => AssignmentStatus::Released]);

        $this->expectException(ValidationException::class);
        app(StayService::class)->checkIn($stay);
    }

    public function test_cancelled_booking_stay_cannot_early_check_in(): void
    {
        // A stay whose booking is cancelled still fails via the assignment/
        // stay-state guards — early check-in only removed the time gate, not
        // any of these.
        [$booking, $stay, $assignment] = $this->makeReservedStay(now()->subHour());
        $assignment->update(['status' => AssignmentStatus::Released]);
        $booking->update(['status' => \App\Enums\BookingStatus::Cancelled]);

        $this->expectException(ValidationException::class);
        app(StayService::class)->checkIn($stay);
    }

    public function test_already_checked_in_stay_cannot_check_in_again(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $this->expectException(ValidationException::class);
        app(StayService::class)->checkIn($stay);
    }

    public function test_already_checked_out_stay_cannot_check_out_again(): void
    {
        [$booking, $stay] = $this->makeCheckedInStay(now()->subHours(3));
        app(StayService::class)->checkOut($stay, now()->subHour(), true);
        $stay->refresh();

        $this->expectException(ValidationException::class);
        app(StayService::class)->checkOut($stay);
    }

    // -------------------------------------------------------------------------
    // 20. No duplicate FolioEntry from a timestamp edit
    // -------------------------------------------------------------------------

    public function test_editing_actual_time_does_not_create_duplicate_folio_entries(): void
    {
        [$booking, $stay] = $this->makeCheckedInStay(now()->subHours(2));
        $countBefore = \App\Models\FolioEntry::where('folio_id', $booking->folio->id)->count();

        app(StayService::class)->updateActualCheckIn($stay, now()->subMinutes(90), $this->admin);

        $countAfter = \App\Models\FolioEntry::where('folio_id', $booking->folio->id)->count();
        $this->assertSame($countBefore, $countAfter);
    }

    // -------------------------------------------------------------------------
    // 21. Audit log
    // -------------------------------------------------------------------------

    public function test_audit_event_records_old_and_new_timestamp_and_actor(): void
    {
        [, $stay] = $this->makeCheckedInStay(now()->subHours(2));
        $oldActual = $stay->actual_checkin_at;
        $newActual = now()->subMinutes(100);

        app(StayService::class)->updateActualCheckIn($stay, $newActual, $this->admin);

        $event = StayEvent::where('stay_id', $stay->id)
            ->where('event_type', 'CHECK_IN_TIME_ADJUSTED')
            ->firstOrFail();

        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertSame($oldActual->toIso8601String(), $event->metadata['old_actual_checkin_at']);
        $this->assertSame($newActual->toIso8601String(), $event->metadata['new_actual_checkin_at']);
    }

    // -------------------------------------------------------------------------
    // Final-checkout gate still works with an admin actual-time override
    // -------------------------------------------------------------------------

    public function test_final_checkout_confirmation_gate_still_fires_with_admin_override(): void
    {
        [$booking, $stay] = $this->makeCheckedInStay(now()->subHours(2));

        $this->expectException(FinalCheckoutConfirmationRequiredException::class);
        app(StayService::class)->checkOut($stay, now()->subMinutes(10), false);
    }
}
