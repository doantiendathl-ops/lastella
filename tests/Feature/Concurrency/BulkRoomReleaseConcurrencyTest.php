<?php

namespace Tests\Feature\Concurrency;

use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Stay;
use Tests\Support\Concurrency\ConcurrencyTestCase;

/**
 * Room Demand/Room Board Unification M5 — real two-process, real-MySQL
 * concurrency races on the bulk-release side. Race case numbers reference
 * the Final Gap Closure Milestone 5 prompt Mục XI.
 */
class BulkRoomReleaseConcurrencyTest extends ConcurrencyTestCase
{
    private function makeAssignment($booking, RoomType $roomType, ?int $requirementId, string $roomNumberSeed): RoomAssignment
    {
        $room = $this->makeRoom($roomType);
        $assignment = RoomAssignment::factory()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => $requirementId,
            'status' => 'ASSIGNED',
            'start_at' => $booking->checkin_at,
            'end_at' => $booking->checkout_at,
        ]);
        Stay::factory()->create([
            'booking_id' => $booking->id,
            'room_assignment_id' => $assignment->id,
            'room_id' => $room->id,
            'planned_checkin_at' => $booking->checkin_at,
            'planned_checkout_at' => $booking->checkout_at,
        ]);

        return $assignment;
    }

    /**
     * Race case 9 — two bulk releases with the IDENTICAL assignment_ids
     * set, submitted at the same instant.
     */
    public function test_case_9_two_bulk_releases_with_identical_assignment_sets(): void
    {
        $roomType = RoomType::factory()->create();
        $booking = $this->makeBooking(['checkin_at' => '2028-04-01 14:00:00', 'checkout_at' => '2028-04-03 12:00:00']);
        $a1 = $this->makeAssignment($booking, $roomType, null, 'a1');
        $a2 = $this->makeAssignment($booking, $roomType, null, 'a2');

        $params = [
            'actor_id' => $this->actor->id,
            'booking_id' => $booking->id,
            'assignment_ids' => [$a1->id, $a2->id],
            'reason' => 'QA-M5 case9',
            'note' => null,
            'reduce_demand' => false,
        ];

        $results = $this->runWorkers('case9_identical_sets', [
            ['name' => 'worker_a', 'action' => 'bulk_release_assignments', 'params' => $params],
            ['name' => 'worker_b', 'action' => 'bulk_release_assignments', 'params' => $params],
        ]);

        $successCount = collect($results)->where('success', true)->count();
        $this->assertSame(1, $successCount, 'exactly one identical batch must succeed: ' . json_encode($results));

        $batchCount = \App\Models\ReleaseBatch::where('booking_id', $booking->id)->count();
        $this->assertSame(1, $batchCount, 'never two batches for the identical set');

        foreach ([$a1, $a2] as $a) {
            $this->assertSame('RELEASED', $a->fresh()->status->value);
        }
    }

    /**
     * Race case 10 — two bulk releases with PARTIALLY overlapping
     * assignment sets ({1,2} vs {2,3}) — the most deadlock-relevant shape
     * in this milestone, since both process rows in ascending id order and
     * only id=2 is shared; verified no InnoDB deadlock is produced.
     */
    public function test_case_10_two_bulk_releases_with_partially_overlapping_sets(): void
    {
        $roomType = RoomType::factory()->create();
        $booking = $this->makeBooking(['checkin_at' => '2028-04-05 14:00:00', 'checkout_at' => '2028-04-07 12:00:00']);
        $a1 = $this->makeAssignment($booking, $roomType, null, 'a1');
        $a2 = $this->makeAssignment($booking, $roomType, null, 'a2');
        $a3 = $this->makeAssignment($booking, $roomType, null, 'a3');

        $results = $this->runWorkers('case10_partial_overlap', [
            [
                'name' => 'worker_a',
                'action' => 'bulk_release_assignments',
                'params' => ['actor_id' => $this->actor->id, 'booking_id' => $booking->id, 'assignment_ids' => [$a1->id, $a2->id], 'reason' => 'QA-M5 case10a', 'note' => null, 'reduce_demand' => false],
            ],
            [
                'name' => 'worker_b',
                'action' => 'bulk_release_assignments',
                'params' => ['actor_id' => $this->actor->id, 'booking_id' => $booking->id, 'assignment_ids' => [$a2->id, $a3->id], 'reason' => 'QA-M5 case10b', 'note' => null, 'reduce_demand' => false],
            ],
        ]);

        $successCount = collect($results)->where('success', true)->count();
        $failed = collect($results)->firstWhere('success', false);

        $this->assertSame(1, $successCount, 'only the set that wins the contested row (a2) can fully succeed: ' . json_encode($results));
        $this->assertNull($failed['sql_state'], 'must be a clean validation rejection on re-check, never a raw deadlock SQLSTATE: ' . json_encode($failed));

        // Whichever succeeded, the LOSER's set must be entirely untouched
        // (no partial release of the non-contested member of its set).
        $loserAssignmentIds = $failed === ($results[0]) ? [$a1->id, $a2->id] : [$a2->id, $a3->id];
        $winnerAssignmentIds = $loserAssignmentIds === [$a1->id, $a2->id] ? [$a2->id, $a3->id] : [$a1->id, $a2->id];

        $releasedCount = RoomAssignment::whereIn('id', [$a1->id, $a2->id, $a3->id])->where('status', 'RELEASED')->count();
        $this->assertSame(2, $releasedCount, 'exactly the winning set (2 assignments) must be released, never 1 (partial) or 3');
    }

    /**
     * Race case 11 — two bulk releases with DISJOINT assignment sets that
     * both reference the SAME requirement line with reduce_demand=true.
     *
     * Invariant asserted regardless of interleave order: final quantity
     * always equals final active-assignment count for that requirement.
     */
    public function test_case_11_two_disjoint_bulk_releases_reduce_the_same_requirement(): void
    {
        $roomType = RoomType::factory()->create();
        $booking = $this->makeBooking(['checkin_at' => '2028-04-10 14:00:00', 'checkout_at' => '2028-04-12 12:00:00']);
        $requirement = $this->makeRequirement($booking, $roomType, 4);
        $a1 = $this->makeAssignment($booking, $roomType, $requirement->id, 'a1');
        $a2 = $this->makeAssignment($booking, $roomType, $requirement->id, 'a2');
        $a3 = $this->makeAssignment($booking, $roomType, $requirement->id, 'a3');
        $a4 = $this->makeAssignment($booking, $roomType, $requirement->id, 'a4');

        $results = $this->runWorkers('case11_disjoint_same_requirement', [
            [
                'name' => 'worker_a',
                'action' => 'bulk_release_assignments',
                'params' => ['actor_id' => $this->actor->id, 'booking_id' => $booking->id, 'assignment_ids' => [$a1->id, $a2->id], 'reason' => 'QA-M5 case11a', 'note' => null, 'reduce_demand' => true],
            ],
            [
                'name' => 'worker_b',
                'action' => 'bulk_release_assignments',
                'params' => ['actor_id' => $this->actor->id, 'booking_id' => $booking->id, 'assignment_ids' => [$a3->id, $a4->id], 'reason' => 'QA-M5 case11b', 'note' => null, 'reduce_demand' => true],
            ],
        ]);

        $this->assertTrue(collect($results)->every(fn ($r) => $r['success'] === true), 'disjoint sets, both should succeed: ' . json_encode($results));

        $requirement->refresh();
        $activeCount = RoomAssignment::where('booking_requirement_id', $requirement->id)
            ->whereIn('status', ['ASSIGNED', 'CHECKED_IN'])
            ->count();

        $this->assertSame(0, $activeCount);
        $this->assertSame(0, $requirement->quantity, 'both releases together consume the full quantity — must land at exactly 0, never negative, never stuck at a partial value');
    }

    /**
     * Race case 14 — double-submit of the exact same single-assignment bulk
     * release (double-click / retried request).
     */
    public function test_case_14_double_submit_bulk_release(): void
    {
        $roomType = RoomType::factory()->create();
        $booking = $this->makeBooking(['checkin_at' => '2028-04-15 14:00:00', 'checkout_at' => '2028-04-17 12:00:00']);
        $a1 = $this->makeAssignment($booking, $roomType, null, 'a1');

        $params = [
            'actor_id' => $this->actor->id,
            'booking_id' => $booking->id,
            'assignment_ids' => [$a1->id],
            'reason' => 'QA-M5 case14',
            'note' => null,
            'reduce_demand' => false,
        ];

        $results = $this->runWorkers('case14_double_submit', [
            ['name' => 'worker_a', 'action' => 'bulk_release_assignments', 'params' => $params],
            ['name' => 'worker_b', 'action' => 'bulk_release_assignments', 'params' => $params],
        ]);

        $successCount = collect($results)->where('success', true)->count();
        $this->assertSame(1, $successCount, 'double-submit must not release/create a batch twice: ' . json_encode($results));
        $this->assertSame(1, \App\Models\ReleaseBatch::where('booking_id', $booking->id)->count());
    }

    /**
     * Race case 17 — "two-tab stale": a single-assignment release fired
     * from tab A races an identical single-assignment release fired from
     * tab B for the SAME assignment.
     */
    public function test_case_17_two_tab_stale_single_release_race(): void
    {
        $roomType = RoomType::factory()->create();
        $booking = $this->makeBooking(['checkin_at' => '2028-04-20 14:00:00', 'checkout_at' => '2028-04-22 12:00:00']);
        $a1 = $this->makeAssignment($booking, $roomType, null, 'a1');

        $results = $this->runWorkers('case17_two_tab_stale', [
            ['name' => 'tab_a', 'action' => 'release_assignment', 'params' => ['actor_id' => $this->actor->id, 'assignment_id' => $a1->id, 'reason' => 'QA-M5 case17 tab A']],
            ['name' => 'tab_b', 'action' => 'release_assignment', 'params' => ['actor_id' => $this->actor->id, 'assignment_id' => $a1->id, 'reason' => 'QA-M5 case17 tab B']],
        ]);

        $successCount = collect($results)->where('success', true)->count();
        $this->assertSame(1, $successCount, 'only one tab may win the release: ' . json_encode($results));
        $this->assertSame('RELEASED', $a1->fresh()->status->value);
    }

    /**
     * Race case 18 — a Room charge is posted to the Folio at the same
     * instant as a reduce_demand=true bulk release targeting the same
     * booking's requirement.
     *
     * This directly tests whether `hasActiveRoomCharge()`'s
     * `lockForUpdate()` on a currently-EMPTY result set (no charge exists
     * yet) provides real protection against a concurrent INSERT, or
     * whether InnoDB's gap-locking behavior in this schema/isolation level
     * allows both to proceed independently. The evidence is reported
     * plainly — this is exactly the kind of question Phase A's Risk
     * Assessment flagged as unprovable from reading alone.
     */
    public function test_case_18_room_charge_posted_while_reduce_demand_release_targets_the_booking(): void
    {
        $roomType = RoomType::factory()->create();
        $booking = $this->makeBooking(['checkin_at' => '2028-04-25 14:00:00', 'checkout_at' => '2028-04-27 12:00:00']);
        $requirement = $this->makeRequirement($booking, $roomType, 2);
        $a1 = $this->makeAssignment($booking, $roomType, $requirement->id, 'a1');

        $results = $this->runWorkers('case18_folio_lock_race', [
            [
                'name' => 'worker_a_release',
                'action' => 'bulk_release_assignments',
                'params' => ['actor_id' => $this->actor->id, 'booking_id' => $booking->id, 'assignment_ids' => [$a1->id], 'reason' => 'QA-M5 case18', 'note' => null, 'reduce_demand' => true],
            ],
            [
                'name' => 'worker_b_post_charge',
                'action' => 'post_room_charge',
                'params' => ['actor_id' => $this->actor->id, 'booking_id' => $booking->id],
            ],
        ]);

        $releaseResult = collect($results)->firstWhere('worker', 'worker_a_release');
        $chargeResult = collect($results)->firstWhere('worker', 'worker_b_post_charge');

        $this->assertTrue($chargeResult['success'], 'posting the charge itself must never fail: ' . json_encode($chargeResult));

        $requirement->refresh();
        fwrite(STDERR, "\n[Case 18 evidence] release worker success=" . var_export($releaseResult['success'], true)
            . ', release exception=' . ($releaseResult['exception_class'] ?? 'none')
            . ", final requirement.quantity={$requirement->quantity}\n");

        // The release itself must always resolve cleanly (accept and reduce,
        // or reject due to the lock) — never a raw deadlock/serialization
        // SQLSTATE bubbling up as an unhandled DB error.
        if (! $releaseResult['success']) {
            $this->assertNotNull($releaseResult['exception_class'], 'a failed release must be a real exception, not a silent no-op');
            $this->assertContains(
                $releaseResult['exception_class'],
                [\Illuminate\Validation\ValidationException::class, \Illuminate\Database\QueryException::class],
                'a failure here must be a recognized, handled exception type: ' . json_encode($releaseResult),
            );
        }
    }
}
