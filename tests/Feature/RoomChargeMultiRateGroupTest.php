<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use App\Services\BusinessDateService;
use App\Services\PaymentProjectionService;
use App\Services\Posting\PostingContext;
use App\Services\Posting\RoomChargePostingJob;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/yeucaumoi.txt mục 20-22/37 — Multiple Requirement Groups for the same
 * room_type with different commercial terms (the exact scenario: Twin Group
 * A 10 rooms @ 650,000 "Normal" + Twin Group B 1 room @ 300,000 "Internal
 * Driver"). RoomAssignment.booking_requirement_id + the frontend's
 * target_requirement_id selector already resolve/link each assignment to
 * its own group (Room Demand/Room Board Unification, pre-existing) — this
 * covers the one gap found: RoomChargePostingJob and PaymentProjectionService
 * were not reading that link, so every assignment for a shared room_type
 * silently collapsed onto whichever requirement row came first.
 */
class RoomChargeMultiRateGroupTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->businessDate = Carbon::parse('2026-07-04');
        $this->instance(BusinessDateService::class, $this->mockBusinessDate($this->businessDate));
    }

    private function mockBusinessDate(Carbon $date): BusinessDateService
    {
        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn($date);
        $mock->method('businessDateFor')->willReturn($date);

        return $mock;
    }

    /** @return array{0: Booking, 1: BookingRequirement, 2: BookingRequirement} */
    private function bookingWithTwoRateGroups(): array
    {
        $booking = Booking::factory()->create();

        $groupA = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'quantity' => 10,
            'room_price' => 650000,
            'note' => 'Normal',
        ]);

        $groupB = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $groupA->room_type_id,
            'quantity' => 1,
            'room_price' => 300000,
            'note' => 'Internal driver',
        ]);

        return [$booking, $groupA, $groupB];
    }

    private function checkedInStayForGroup(Booking $booking, BookingRequirement $group): Stay
    {
        $room = Room::factory()->create(['room_type_id' => $group->room_type_id]);

        $assignment = RoomAssignment::factory()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $group->room_type_id,
            'booking_requirement_id' => $group->id,
        ]);

        return Stay::factory()->create([
            'booking_id' => $booking->id,
            'room_assignment_id' => $assignment->id,
            'room_id' => $room->id,
            'status' => StayStatus::CheckedIn,
            'actual_checkin_at' => $this->businessDate,
        ]);
    }

    public function test_room_charge_posting_uses_each_assignments_own_requirement_group_rate(): void
    {
        [$booking, $groupA, $groupB] = $this->bookingWithTwoRateGroups();
        $folio = Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);

        $stayA = $this->checkedInStayForGroup($booking, $groupA);
        $stayB = $this->checkedInStayForGroup($booking, $groupB);

        $job = app(RoomChargePostingJob::class);

        $resultA = $job->execute(new PostingContext(
            booking: $booking,
            folio: $folio->fresh(),
            businessDate: $this->businessDate,
            stay: $stayA,
        ));
        $resultB = $job->execute(new PostingContext(
            booking: $booking,
            folio: $folio->fresh(),
            businessDate: $this->businessDate,
            stay: $stayB,
        ));

        $this->assertTrue($resultA->success);
        $this->assertTrue($resultB->success);
        $this->assertEquals('650000.00', $resultA->entry->unit_price);
        $this->assertEquals('300000.00', $resultB->entry->unit_price, 'Group B must be charged at its OWN rate, not silently inherit Group A\'s.');
    }

    public function test_editing_group_b_price_does_not_change_already_posted_group_a_charge(): void
    {
        [$booking, $groupA, $groupB] = $this->bookingWithTwoRateGroups();
        $folio = Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);

        $stayA = $this->checkedInStayForGroup($booking, $groupA);
        $job = app(RoomChargePostingJob::class);

        $job->execute(new PostingContext(
            booking: $booking,
            folio: $folio->fresh(),
            businessDate: $this->businessDate,
            stay: $stayA,
        ));

        // mục 23 — editing Group B's price/note must not overwrite Group A's line.
        $groupB->update(['room_price' => 350000, 'note' => 'Internal driver v2']);

        $this->assertEquals(650000.0, (float) $groupA->fresh()->room_price, 'Group A must be untouched by an edit to Group B.');

        // Posting the NEXT night for stay A must still use Group A's rate.
        $resultNextNight = $job->execute(new PostingContext(
            booking: $booking,
            folio: $folio->fresh(),
            businessDate: $this->businessDate->copy()->addDay(),
            stay: $stayA->fresh(),
        ));

        $this->assertEquals('650000.00', $resultNextNight->entry->unit_price);
    }

    public function test_payment_projection_matches_the_posting_jobs_per_group_rate(): void
    {
        [$booking, $groupA, $groupB] = $this->bookingWithTwoRateGroups();

        $stayA = $this->checkedInStayForGroup($booking, $groupA);
        $stayB = $this->checkedInStayForGroup($booking, $groupB);
        $stayA->update(['planned_checkin_at' => '2026-07-04 14:00:00', 'planned_checkout_at' => '2026-07-05 12:00:00']);
        $stayB->update(['planned_checkin_at' => '2026-07-04 14:00:00', 'planned_checkout_at' => '2026-07-05 12:00:00']);

        $projection = app(PaymentProjectionService::class)->project($booking->fresh());

        $rateByStay = collect($projection['calculation_context']['stays'])->keyBy('stay_id');

        $this->assertEquals(650000.0, $rateByStay[$stayA->id]['unit_price']);
        $this->assertEquals(300000.0, $rateByStay[$stayB->id]['unit_price'], 'Projection must mirror the posting job\'s per-group rate, never diverge from what Night Audit will actually charge.');
    }
}
