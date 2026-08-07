<?php

namespace Tests\Feature\Concurrency;

use App\Enums\PriceSource;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use Tests\Support\Concurrency\ConcurrencyTestCase;

/**
 * Room Demand/Room Board Unification M5 — real two-process, real-MySQL
 * concurrency races where an assign-side operation and a release-side
 * operation contend on the same booking/requirement at the same instant.
 * Race case numbers reference the Final Gap Closure Milestone 5 prompt
 * Mục XI.
 */
class AssignReleaseInteractionConcurrencyTest extends ConcurrencyTestCase
{
    /**
     * Race case 6 — demand-first assignment races Room-Board-first
     * assignment, both targeting the same booking/requirement line with
     * enough remaining capacity for both selections (no excess triggered
     * either way, regardless of interleave order).
     */
    public function test_case_6_demand_first_races_room_board_first_on_the_same_requirement(): void
    {
        $roomType = RoomType::factory()->create();
        $roomX = $this->makeRoom($roomType);
        $roomY = $this->makeRoom($roomType);
        $booking = $this->makeBooking(['checkin_at' => '2028-03-05 14:00:00', 'checkout_at' => '2028-03-07 12:00:00']);
        $requirement = $this->makeRequirement($booking, $roomType, 2, [
            'room_price' => 500000, 'price_source' => PriceSource::RateTable->value,
        ]);

        $results = $this->runWorkers('case6_demand_vs_roomboard', [
            [
                'name' => 'worker_a_demand_first',
                'action' => 'assign_rooms_with_requirement_link',
                'params' => [
                    'actor_id' => $this->actor->id,
                    'booking_id' => $booking->id,
                    'assignments' => [[
                        'room_id' => $roomX->id,
                        'room_type_id' => $roomType->id,
                        'start_at' => $booking->checkin_at->toDateTimeString(),
                        'end_at' => $booking->checkout_at->toDateTimeString(),
                    ]],
                    'target_requirement_id_by_room_type' => [(string) $roomType->id => $requirement->id],
                ],
            ],
            [
                'name' => 'worker_b_room_board',
                'action' => 'assign_rooms_from_room_board',
                'params' => [
                    'actor_id' => $this->actor->id,
                    'booking_id' => $booking->id,
                    'groups' => [[
                        'room_type_id' => $roomType->id,
                        'room_ids' => [$roomY->id],
                        'target_requirement_id' => $requirement->id,
                        'room_price' => 500000.0,
                        'price_source' => PriceSource::RateTable->value,
                        'note' => null,
                        'adults' => 2,
                        'children_under_6' => 0,
                        'children_over_6' => 0,
                    ]],
                    'start_at' => $booking->checkin_at->toDateTimeString(),
                    'end_at' => $booking->checkout_at->toDateTimeString(),
                ],
            ],
        ]);

        $this->assertTrue(collect($results)->every(fn ($r) => $r['success'] === true), 'both must succeed, different rooms, capacity for both: ' . json_encode($results));

        $requirement->refresh();
        $activeCount = RoomAssignment::where('booking_requirement_id', $requirement->id)
            ->whereIn('status', ['ASSIGNED', 'CHECKED_IN'])
            ->count();

        $this->assertSame(2, $activeCount);
        $this->assertSame(2, $requirement->quantity, 'no excess should ever be triggered — 2 rooms selected against a starting quantity of 2, regardless of interleave order');
    }

    /**
     * Race case 7 — a NEW assignment (demand-first) races a bulk release of
     * a DIFFERENT, pre-existing assignment on the SAME booking (keep
     * demand). Independent rows, same Booking — must fully serialize via
     * the shared Booking lock, both must succeed.
     */
    public function test_case_7_new_assignment_races_bulk_release_of_a_different_assignment_same_booking(): void
    {
        $roomType = RoomType::factory()->create();
        $existingRoom = $this->makeRoom($roomType);
        $newRoom = $this->makeRoom($roomType);
        $booking = $this->makeBooking(['checkin_at' => '2028-03-10 14:00:00', 'checkout_at' => '2028-03-12 12:00:00']);
        $requirement = $this->makeRequirement($booking, $roomType, 2);

        $existingAssignment = RoomAssignment::factory()->create([
            'booking_id' => $booking->id,
            'room_id' => $existingRoom->id,
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => $requirement->id,
            'status' => 'ASSIGNED',
            'start_at' => $booking->checkin_at,
            'end_at' => $booking->checkout_at,
        ]);
        \App\Models\Stay::factory()->create([
            'booking_id' => $booking->id,
            'room_assignment_id' => $existingAssignment->id,
            'room_id' => $existingRoom->id,
            'planned_checkin_at' => $booking->checkin_at,
            'planned_checkout_at' => $booking->checkout_at,
        ]);

        $results = $this->runWorkers('case7_assign_vs_release_same_booking', [
            [
                'name' => 'worker_a_assign',
                'action' => 'assign_rooms_with_requirement_link',
                'params' => [
                    'actor_id' => $this->actor->id,
                    'booking_id' => $booking->id,
                    'assignments' => [[
                        'room_id' => $newRoom->id,
                        'room_type_id' => $roomType->id,
                        'start_at' => $booking->checkin_at->toDateTimeString(),
                        'end_at' => $booking->checkout_at->toDateTimeString(),
                    ]],
                ],
            ],
            [
                'name' => 'worker_b_release',
                'action' => 'bulk_release_assignments',
                'params' => [
                    'actor_id' => $this->actor->id,
                    'booking_id' => $booking->id,
                    'assignment_ids' => [$existingAssignment->id],
                    'reason' => 'QA-M5 case7',
                    'note' => null,
                    'reduce_demand' => false,
                ],
            ],
        ]);

        $this->assertTrue(collect($results)->every(fn ($r) => $r['success'] === true), 'both are independent rows under the same booking — both must succeed: ' . json_encode($results));

        $booking->refresh();
        $this->assertNotNull($booking->status, 'booking status must have been recomputed consistently, not left corrupted');
    }

    /**
     * Race case 8 — a NEW assignment (demand-first) targeting a requirement
     * line races a bulk release with reduce_demand=true releasing a
     * DIFFERENT, pre-existing assignment on that SAME line.
     *
     * Invariant asserted regardless of interleave order: after the race,
     * the requirement's quantity always equals its own active-assignment
     * count (the two operations must never leave it inconsistent).
     */
    public function test_case_8_new_assignment_races_reduce_demand_release_on_the_same_requirement(): void
    {
        $roomType = RoomType::factory()->create();
        $roomA = $this->makeRoom($roomType);
        $roomB = $this->makeRoom($roomType);
        $newRoom = $this->makeRoom($roomType);
        $booking = $this->makeBooking(['checkin_at' => '2028-03-15 14:00:00', 'checkout_at' => '2028-03-17 12:00:00']);
        $requirement = $this->makeRequirement($booking, $roomType, 3);

        $existing = [];
        foreach ([$roomA, $roomB] as $room) {
            $assignment = RoomAssignment::factory()->create([
                'booking_id' => $booking->id,
                'room_id' => $room->id,
                'room_type_id' => $roomType->id,
                'booking_requirement_id' => $requirement->id,
                'status' => 'ASSIGNED',
                'start_at' => $booking->checkin_at,
                'end_at' => $booking->checkout_at,
            ]);
            \App\Models\Stay::factory()->create([
                'booking_id' => $booking->id,
                'room_assignment_id' => $assignment->id,
                'room_id' => $room->id,
                'planned_checkin_at' => $booking->checkin_at,
                'planned_checkout_at' => $booking->checkout_at,
            ]);
            $existing[] = $assignment;
        }

        $results = $this->runWorkers('case8_assign_vs_reduce_demand_release', [
            [
                'name' => 'worker_a_assign',
                'action' => 'assign_rooms_with_requirement_link',
                'params' => [
                    'actor_id' => $this->actor->id,
                    'booking_id' => $booking->id,
                    'assignments' => [[
                        'room_id' => $newRoom->id,
                        'room_type_id' => $roomType->id,
                        'start_at' => $booking->checkin_at->toDateTimeString(),
                        'end_at' => $booking->checkout_at->toDateTimeString(),
                    ]],
                    'target_requirement_id_by_room_type' => [(string) $roomType->id => $requirement->id],
                ],
            ],
            [
                'name' => 'worker_b_release',
                'action' => 'bulk_release_assignments',
                'params' => [
                    'actor_id' => $this->actor->id,
                    'booking_id' => $booking->id,
                    'assignment_ids' => [$existing[0]->id],
                    'reason' => 'QA-M5 case8',
                    'note' => null,
                    'reduce_demand' => true,
                ],
            ],
        ]);

        $this->assertTrue(collect($results)->every(fn ($r) => $r['success'] === true), 'both must succeed under either interleave order (see class docblock math): ' . json_encode($results));

        $requirement->refresh();
        $activeCount = RoomAssignment::where('booking_requirement_id', $requirement->id)
            ->whereIn('status', ['ASSIGNED', 'CHECKED_IN'])
            ->count();

        $this->assertSame($requirement->quantity, $activeCount, 'quantity must always equal active count after the race — the requirement lock must fully serialize the two writers');
    }

    /**
     * Race case 12 — a bulk release with reduce_demand=true races a direct
     * `updateRequirement()` call (e.g. a manual price/quantity edit) on the
     * SAME requirement line.
     *
     * Both lock the requirement row, so they fully serialize; the specific
     * final quantity depends on which ran last (last-write-wins on the
     * unconditional update, or the release's own delta math if it ran
     * last) — this is legitimate, not a bug, and the test asserts the
     * result lands in the two mathematically valid outcomes, never a third
     * corrupted value.
     */
    public function test_case_12_reduce_demand_release_races_a_direct_requirement_update(): void
    {
        $roomType = RoomType::factory()->create();
        $room = $this->makeRoom($roomType);
        $booking = $this->makeBooking(['checkin_at' => '2028-03-20 14:00:00', 'checkout_at' => '2028-03-22 12:00:00']);
        $requirement = $this->makeRequirement($booking, $roomType, 3);

        $assignment = RoomAssignment::factory()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => $requirement->id,
            'status' => 'ASSIGNED',
            'start_at' => $booking->checkin_at,
            'end_at' => $booking->checkout_at,
        ]);
        \App\Models\Stay::factory()->create([
            'booking_id' => $booking->id,
            'room_assignment_id' => $assignment->id,
            'room_id' => $room->id,
            'planned_checkin_at' => $booking->checkin_at,
            'planned_checkout_at' => $booking->checkout_at,
        ]);

        $results = $this->runWorkers('case12_release_vs_direct_update', [
            [
                'name' => 'worker_a_release',
                'action' => 'bulk_release_assignments',
                'params' => [
                    'actor_id' => $this->actor->id,
                    'booking_id' => $booking->id,
                    'assignment_ids' => [$assignment->id],
                    'reason' => 'QA-M5 case12',
                    'note' => null,
                    'reduce_demand' => true,
                ],
            ],
            [
                'name' => 'worker_b_direct_update',
                'action' => 'update_requirement',
                'params' => [
                    'actor_id' => $this->actor->id,
                    'requirement_id' => $requirement->id,
                    'data' => ['quantity' => 5],
                ],
            ],
        ]);

        $this->assertTrue(collect($results)->every(fn ($r) => $r['success'] === true), 'both are legitimate, independently-valid operations: ' . json_encode($results));

        $requirement->refresh();
        fwrite(STDERR, "\n[Case 12 evidence] final quantity={$requirement->quantity} (valid set: {4, 5} depending on interleave order)\n");
        $this->assertContains($requirement->quantity, [4, 5], 'final quantity must be one of the two mathematically valid outcomes, never a third corrupted value: ' . json_encode($results));
    }
}
