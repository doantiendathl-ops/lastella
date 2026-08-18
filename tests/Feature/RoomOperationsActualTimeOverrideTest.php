<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\FolioStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * User request (2026-08-18 chat): extend Early Check-in/Admin Actual Time
 * Override (already on the booking-detail page — see
 * EarlyCheckInAdminActualTimeOverrideTest) to Sơ đồ thao tác (Room
 * Operations Board): bulk check-in/check-out can carry an ADMIN-supplied
 * actual time, and an already-recorded time can be corrected directly from
 * the board via new stay-scoped routes. Reuses the exact same
 * StayService/StayPolicy/UpdateActualCheck{In,Out}Request the booking-detail
 * page already relies on — no parallel validation/authorization logic.
 */
class RoomOperationsActualTimeOverrideTest extends TestCase
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

    /** @return array{0: Booking, 1: Stay, 2: RoomAssignment} */
    private function makeReservedStay(): array
    {
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();
        Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id, 'room_price' => 500000]);

        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'status' => AssignmentStatus::Assigned,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
        ]);
        $stay = Stay::factory()->for($booking)->for($room)->create([
            'room_assignment_id' => $assignment->id,
            'status' => StayStatus::Reserved,
            'planned_checkin_at' => now()->subHour(),
            'planned_checkout_at' => now()->addDay(),
        ]);

        return [$booking, $stay, $assignment];
    }

    /** @return array{0: Booking, 1: Stay, 2: RoomAssignment} */
    private function makeCheckedInStay(): array
    {
        [$booking, $stay, $assignment] = $this->makeReservedStay();
        $stay->update(['status' => StayStatus::CheckedIn, 'actual_checkin_at' => now()->subMinutes(30)]);
        $assignment->update(['status' => AssignmentStatus::CheckedIn]);

        return [$booking, $stay, $assignment];
    }

    public function test_index_exposes_adjust_actual_time_true_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.room-operations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.adjustActualTime', true));
    }

    public function test_index_exposes_adjust_actual_time_false_for_reception(): void
    {
        $this->actingAs($this->reception)
            ->get(route('admin.room-operations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.adjustActualTime', false));
    }

    public function test_reception_bulk_check_in_uses_now_with_no_time_field(): void
    {
        [, $stay] = $this->makeReservedStay();

        $this->actingAs($this->reception)
            ->post(route('admin.room-operations.check-in'), ['stay_ids' => [$stay->id]])
            ->assertRedirect();

        $stay->refresh();
        $this->assertNotNull($stay->actual_checkin_at);
        $this->assertTrue($stay->actual_checkin_at->diffInSeconds(now()) < 5);
    }

    public function test_reception_forging_actual_checkin_at_is_rejected(): void
    {
        [, $stay] = $this->makeReservedStay();

        $this->actingAs($this->reception)
            ->post(route('admin.room-operations.check-in'), [
                'stay_ids' => [$stay->id],
                'actual_checkin_at' => now()->subHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasErrors('actual_checkin_at');

        $this->assertNull($stay->fresh()->actual_checkin_at);
    }

    public function test_admin_bulk_check_in_applies_the_same_actual_time_to_every_selected_stay(): void
    {
        [, $stayA] = $this->makeReservedStay();
        [, $stayB] = $this->makeReservedStay();
        $chosenTime = now()->subHours(2);

        $this->actingAs($this->admin)
            ->post(route('admin.room-operations.check-in'), [
                'stay_ids' => [$stayA->id, $stayB->id],
                'actual_checkin_at' => $chosenTime->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $this->assertSame($chosenTime->format('Y-m-d H:i'), $stayA->fresh()->actual_checkin_at->format('Y-m-d H:i'));
        $this->assertSame($chosenTime->format('Y-m-d H:i'), $stayB->fresh()->actual_checkin_at->format('Y-m-d H:i'));
    }

    public function test_admin_bulk_check_out_applies_actual_time(): void
    {
        [, $stay] = $this->makeCheckedInStay();
        $chosenTime = now()->subMinutes(10);

        $this->actingAs($this->admin)
            ->post(route('admin.room-operations.check-out'), [
                'stay_ids' => [$stay->id],
                'confirmed' => true,
                'actual_checkout_at' => $chosenTime->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $this->assertSame($chosenTime->format('Y-m-d H:i'), $stay->fresh()->actual_checkout_at->format('Y-m-d H:i'));
    }

    public function test_board_payload_carries_raw_actual_times(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $this->actingAs($this->admin)
            ->get(route('admin.room-operations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('board.floors', function ($floors) use ($stay): bool {
                foreach ($floors as $floor) {
                    foreach ($floor['rooms'] as $room) {
                        if (($room['occupant']['stay_id'] ?? null) === $stay->id) {
                            return $room['occupant']['actual_checkin_at'] === $stay->actual_checkin_at->format('Y-m-d H:i');
                        }
                    }
                }

                return false;
            }));
    }

    public function test_admin_can_correct_actual_check_in_from_the_board(): void
    {
        [, $stay] = $this->makeCheckedInStay();
        $newTime = now()->subMinutes(5);

        $this->actingAs($this->admin)
            ->patch(route('admin.room-operations.stays.actual-check-in', $stay), [
                'actual_checkin_at' => $newTime->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $this->assertSame($newTime->format('Y-m-d H:i'), $stay->fresh()->actual_checkin_at->format('Y-m-d H:i'));
    }

    public function test_reception_cannot_correct_actual_check_in_from_the_board(): void
    {
        [, $stay] = $this->makeCheckedInStay();

        $this->actingAs($this->reception)
            ->patch(route('admin.room-operations.stays.actual-check-in', $stay), [
                'actual_checkin_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertForbidden();
    }

    public function test_admin_can_correct_actual_check_out_from_the_board(): void
    {
        [, $stay, $assignment] = $this->makeCheckedInStay();
        $stay->update(['status' => StayStatus::CheckedOut, 'actual_checkout_at' => now()->subMinutes(20)]);
        $assignment->update(['status' => AssignmentStatus::CheckedOut]);
        $newTime = now()->subMinutes(2);

        $this->actingAs($this->admin)
            ->patch(route('admin.room-operations.stays.actual-check-out', $stay), [
                'actual_checkout_at' => $newTime->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $this->assertSame($newTime->format('Y-m-d H:i'), $stay->fresh()->actual_checkout_at->format('Y-m-d H:i'));
    }

    /** Editing an already-recorded time never re-runs check-in/check-out or touches Folio — same guarantee as the booking-detail page's equivalent (see EarlyCheckInAdminActualTimeOverrideTest). */
    public function test_editing_actual_time_from_the_board_does_not_change_stay_status(): void
    {
        [, $stay] = $this->makeCheckedInStay();
        $statusBefore = $stay->status;

        $this->actingAs($this->admin)->patch(route('admin.room-operations.stays.actual-check-in', $stay), [
            'actual_checkin_at' => now()->subMinutes(1)->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame($statusBefore, $stay->fresh()->status);
    }
}
