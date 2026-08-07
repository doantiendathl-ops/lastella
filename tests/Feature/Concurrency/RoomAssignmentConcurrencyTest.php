<?php

namespace Tests\Feature\Concurrency;

use App\Enums\BookingStatus;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use Tests\Support\Concurrency\ConcurrencyTestCase;

/**
 * Room Demand/Room Board Unification M5 — real two-process, real-MySQL
 * concurrency races for the assignment side (demand-first and
 * Room-Board-first). Race case numbers reference the Final Gap Closure
 * Milestone 5 prompt Mục XI.
 */
class RoomAssignmentConcurrencyTest extends ConcurrencyTestCase
{
    /**
     * Race case 1 — two users assign the SAME room to two DIFFERENT,
     * time-overlapping bookings at the same instant.
     *
     * Expected: exactly one succeeds (hasConflict() under the Room lock
     * rejects the other), no duplicate active assignment on that room for
     * the overlapping window, no deadlock.
     */
    public function test_case_1_two_bookings_race_to_assign_the_same_room(): void
    {
        $roomType = RoomType::factory()->create();
        $room = $this->makeRoom($roomType);

        $bookingA = $this->makeBooking(['checkin_at' => '2028-01-10 14:00:00', 'checkout_at' => '2028-01-12 12:00:00']);
        $bookingB = $this->makeBooking(['checkin_at' => '2028-01-11 14:00:00', 'checkout_at' => '2028-01-13 12:00:00']);
        $this->makeRequirement($bookingA, $roomType, 1);
        $this->makeRequirement($bookingB, $roomType, 1);

        $results = $this->runWorkers('case1_same_room_two_bookings', [
            [
                'name' => 'worker_a',
                'action' => 'assign_rooms_with_requirement_link',
                'params' => [
                    'actor_id' => $this->actor->id,
                    'booking_id' => $bookingA->id,
                    'assignments' => [[
                        'room_id' => $room->id,
                        'room_type_id' => $roomType->id,
                        'start_at' => $bookingA->checkin_at->toDateTimeString(),
                        'end_at' => $bookingA->checkout_at->toDateTimeString(),
                    ]],
                ],
            ],
            [
                'name' => 'worker_b',
                'action' => 'assign_rooms_with_requirement_link',
                'params' => [
                    'actor_id' => $this->actor->id,
                    'booking_id' => $bookingB->id,
                    'assignments' => [[
                        'room_id' => $room->id,
                        'room_type_id' => $roomType->id,
                        'start_at' => $bookingB->checkin_at->toDateTimeString(),
                        'end_at' => $bookingB->checkout_at->toDateTimeString(),
                    ]],
                ],
            ],
        ]);

        $successCount = collect($results)->where('success', true)->count();
        $this->assertSame(1, $successCount, 'exactly one worker must succeed: ' . json_encode($results));

        $failed = collect($results)->firstWhere('success', false);
        $this->assertNotNull($failed);
        $this->assertSame(\Illuminate\Validation\ValidationException::class, $failed['exception_class']);

        // No deadlock: neither result is a lock-wait-timeout/deadlock SQLSTATE.
        $this->assertNull($failed['sql_state'], 'the loser must be a clean validation rejection, not a DB deadlock: ' . json_encode($failed));

        $activeOnRoom = RoomAssignment::where('room_id', $room->id)
            ->whereIn('status', ['ASSIGNED', 'CHECKED_IN'])
            ->count();
        $this->assertSame(1, $activeOnRoom, 'exactly one active assignment must exist on the contested room');
    }

    /**
     * Race case 2 — two users assign the SAME room to the SAME booking at
     * the same instant (both trying to fulfil the same demand line).
     *
     * Expected: exactly one succeeds, no duplicate active assignment.
     */
    public function test_case_2_two_workers_race_to_assign_the_same_room_to_the_same_booking(): void
    {
        $roomType = RoomType::factory()->create();
        $room = $this->makeRoom($roomType);
        $booking = $this->makeBooking(['checkin_at' => '2028-02-01 14:00:00', 'checkout_at' => '2028-02-03 12:00:00']);
        $this->makeRequirement($booking, $roomType, 1);

        $paramsFor = fn (): array => [
            'actor_id' => $this->actor->id,
            'booking_id' => $booking->id,
            'assignments' => [[
                'room_id' => $room->id,
                'room_type_id' => $roomType->id,
                'start_at' => $booking->checkin_at->toDateTimeString(),
                'end_at' => $booking->checkout_at->toDateTimeString(),
            ]],
        ];

        $results = $this->runWorkers('case2_same_room_same_booking', [
            ['name' => 'worker_a', 'action' => 'assign_rooms_with_requirement_link', 'params' => $paramsFor()],
            ['name' => 'worker_b', 'action' => 'assign_rooms_with_requirement_link', 'params' => $paramsFor()],
        ]);

        $successCount = collect($results)->where('success', true)->count();
        $this->assertSame(1, $successCount, 'exactly one worker must succeed: ' . json_encode($results));

        $activeOnRoom = RoomAssignment::where('room_id', $room->id)
            ->where('booking_id', $booking->id)
            ->whereIn('status', ['ASSIGNED', 'CHECKED_IN'])
            ->count();
        $this->assertSame(1, $activeOnRoom);
    }

    /**
     * Race case 3 — two DIFFERENT physical rooms of the same room_type,
     * concurrently assigned to the same booking, both auto-resolving to the
     * SAME single eligible BookingRequirement line (quantity=1).
     *
     * Architecture Gap Closure (M5): originally reproduced real
     * over-assignment (both workers succeeded, 2 active assignments against
     * quantity=1) — root cause was `assignRoomsWithRequirementLink()` never
     * counting active assignments against `quantity` at all. Fixed by a
     * capacity re-count strictly after the existing BookingRequirement lock
     * (Blocker A Option A) — no new lock, no quantity mutation, no
     * Room-Board-first change. With quantity=1 and both workers wanting the
     * SAME single line, the invariant now requires exactly ONE worker to
     * succeed and the other to be cleanly rejected — asserted for real
     * below, not merely "no crash".
     */
    public function test_case_3_two_different_rooms_same_room_type_race_for_the_single_eligible_requirement_line(): void
    {
        $roomType = RoomType::factory()->create();
        $roomX = $this->makeRoom($roomType);
        $roomY = $this->makeRoom($roomType);
        $booking = $this->makeBooking(['checkin_at' => '2028-02-05 14:00:00', 'checkout_at' => '2028-02-07 12:00:00']);
        $requirement = $this->makeRequirement($booking, $roomType, 1);

        $paramsFor = fn (Room $room): array => [
            'actor_id' => $this->actor->id,
            'booking_id' => $booking->id,
            'assignments' => [[
                'room_id' => $room->id,
                'room_type_id' => $roomType->id,
                'start_at' => $booking->checkin_at->toDateTimeString(),
                'end_at' => $booking->checkout_at->toDateTimeString(),
            ]],
        ];

        $results = $this->runWorkers('case3_two_rooms_same_line', [
            ['name' => 'worker_a', 'action' => 'assign_rooms_with_requirement_link', 'params' => $paramsFor($roomX)],
            ['name' => 'worker_b', 'action' => 'assign_rooms_with_requirement_link', 'params' => $paramsFor($roomY)],
        ]);

        $successCount = collect($results)->where('success', true)->count();
        $linkedCount = \App\Models\RoomAssignment::where('booking_requirement_id', $requirement->id)
            ->whereIn('status', ['ASSIGNED', 'CHECKED_IN'])
            ->count();
        $requirement->refresh();

        fwrite(STDERR, "\n[Case 3 evidence, post-fix] successCount={$successCount}, linkedActiveAssignments={$linkedCount}, "
            . "requirement.quantity={$requirement->quantity}\n");

        $failed = collect($results)->firstWhere('success', false);

        $this->assertSame(1, $successCount, 'exactly one worker must succeed against a quantity=1 line: ' . json_encode($results));
        $this->assertNotNull($failed, 'the losing worker must be present and rejected: ' . json_encode($results));
        $this->assertSame(\Illuminate\Validation\ValidationException::class, $failed['exception_class']);
        $this->assertNull($failed['sql_state'], 'a clean capacity rejection, never a raw deadlock/SQL error: ' . json_encode($failed));
        $this->assertSame(1, $linkedCount, 'active assignment count must never exceed quantity');
        $this->assertLessThanOrEqual($requirement->quantity, $linkedCount, 'core invariant: active_assignments_for_requirement <= requirement.quantity');
        $this->assertSame(1, $requirement->quantity, 'demand-first must never mutate quantity');
    }

    /**
     * Race case 4 — two Room-Board-first requests concurrently INCREASE the
     * SAME existing requirement line (both select rooms of a room_type that
     * already has one under-capacity line).
     *
     * Expected: the Booking-level lock (acquired first in
     * `assignRoomsFromRoomBoard()`) fully serializes the two transactions;
     * the second worker's excess-to-add calculation reads the FIRST
     * worker's already-committed new quantity, so quantities never
     * double-count.
     */
    public function test_case_4_two_room_board_requests_race_to_increase_the_same_requirement(): void
    {
        $roomType = RoomType::factory()->create();
        $roomX = $this->makeRoom($roomType);
        $roomY = $this->makeRoom($roomType);
        $booking = $this->makeBooking(['checkin_at' => '2028-02-10 14:00:00', 'checkout_at' => '2028-02-12 12:00:00']);
        // Capacity 0 remaining (quantity=0 → not eligible as "existing" but
        // still resolvable as the merge target once excess is computed) —
        // use quantity=1 with zero pre-existing assignments so remaining=1,
        // and each worker selects 1 room each → both should need +0 excess
        // for the first accepted room and the SECOND worker's request must
        // recompute remaining against the post-worker-A state.
        $requirement = $this->makeRequirement($booking, $roomType, 1, [
            'room_price' => 500000, 'price_source' => \App\Enums\PriceSource::RateTable->value,
        ]);

        $groupFor = fn (Room $room): array => [[
            'room_type_id' => $roomType->id,
            'room_ids' => [$room->id],
            'target_requirement_id' => $requirement->id,
            'room_price' => 500000.0,
            'price_source' => \App\Enums\PriceSource::RateTable->value,
            'note' => null,
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
        ]];

        $paramsFor = fn (Room $room): array => [
            'actor_id' => $this->actor->id,
            'booking_id' => $booking->id,
            'groups' => $groupFor($room),
            'start_at' => $booking->checkin_at->toDateTimeString(),
            'end_at' => $booking->checkout_at->toDateTimeString(),
        ];

        $results = $this->runWorkers('case4_two_roomboard_increase_same_requirement', [
            ['name' => 'worker_a', 'action' => 'assign_rooms_from_room_board', 'params' => $paramsFor($roomX)],
            ['name' => 'worker_b', 'action' => 'assign_rooms_from_room_board', 'params' => $paramsFor($roomY)],
        ]);

        $this->assertTrue(collect($results)->every(fn ($r) => $r['success'] === true), 'both should succeed (different rooms): ' . json_encode($results));

        $requirement->refresh();
        $activeCount = \App\Models\RoomAssignment::where('booking_requirement_id', $requirement->id)
            ->whereIn('status', ['ASSIGNED', 'CHECKED_IN'])
            ->count();

        // required starting quantity 1, 2 rooms selected total (1 each) →
        // excess_to_add must sum to exactly 1 across both requests (never 2),
        // proving the second worker read the first worker's committed value.
        $this->assertSame(2, $activeCount, 'both rooms must be linked to the same requirement line');
        $this->assertSame(2, $requirement->quantity, 'quantity must reflect exactly the true excess (1 base + 1 excess), never double-counted to 3');
    }

    /**
     * Race case 5 — two Room-Board-first requests concurrently try to
     * CREATE a brand-new requirement line for a room_type that has NO
     * existing line at all.
     *
     * Expected: the Booking-level lock still fully serializes both
     * transactions even though there is no requirement ROW to lock yet —
     * worker B's post-lock re-query of eligible lines sees worker A's
     * freshly-created line and correctly increases it instead of creating a
     * duplicate.
     */
    public function test_case_5_two_room_board_requests_race_to_create_the_same_new_requirement_line(): void
    {
        $roomType = RoomType::factory()->create();
        $roomX = $this->makeRoom($roomType);
        $roomY = $this->makeRoom($roomType);
        $booking = $this->makeBooking(['checkin_at' => '2028-02-15 14:00:00', 'checkout_at' => '2028-02-17 12:00:00']);
        // No BookingRequirement line exists for $roomType at all yet.

        $groupFor = fn (Room $room): array => [[
            'room_type_id' => $roomType->id,
            'room_ids' => [$room->id],
            'target_requirement_id' => null,
            'room_price' => 500000.0,
            'price_source' => \App\Enums\PriceSource::RateTable->value,
            'note' => null,
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
        ]];

        $paramsFor = fn (Room $room): array => [
            'actor_id' => $this->actor->id,
            'booking_id' => $booking->id,
            'groups' => $groupFor($room),
            'start_at' => $booking->checkin_at->toDateTimeString(),
            'end_at' => $booking->checkout_at->toDateTimeString(),
        ];

        $results = $this->runWorkers('case5_two_roomboard_create_new_line', [
            ['name' => 'worker_a', 'action' => 'assign_rooms_from_room_board', 'params' => $paramsFor($roomX)],
            ['name' => 'worker_b', 'action' => 'assign_rooms_from_room_board', 'params' => $paramsFor($roomY)],
        ]);

        $this->assertTrue(collect($results)->every(fn ($r) => $r['success'] === true), 'both should succeed: ' . json_encode($results));

        $lineCount = \App\Models\BookingRequirement::where('booking_id', $booking->id)
            ->where('room_type_id', $roomType->id)
            ->count();
        $this->assertSame(1, $lineCount, 'must never create two separate requirement lines for the same room_type — the Booking lock must serialize this');

        $line = \App\Models\BookingRequirement::where('booking_id', $booking->id)->where('room_type_id', $roomType->id)->first();
        $this->assertSame(2, $line->quantity, 'the single line must end up with quantity=2 (both rooms merged into it)');
    }

    /**
     * Race case 13 — double-submit of the SAME Room-Board-first request
     * (e.g. a user double-clicking submit, or a retried request after a
     * slow response).
     *
     * Expected: the second identical request re-validates under lock and
     * either creates a second, independent assignment (if the requirement
     * still has remaining capacity) or is correctly rejected — but never
     * silently duplicates state inconsistently. This test asserts the
     * concrete, reproducible outcome for a requirement with exactly enough
     * capacity for ONE of the two identical submissions.
     */
    public function test_case_13_double_submit_room_board_first(): void
    {
        $roomType = RoomType::factory()->create();
        $room = $this->makeRoom($roomType);
        $booking = $this->makeBooking(['checkin_at' => '2028-02-20 14:00:00', 'checkout_at' => '2028-02-22 12:00:00']);

        $params = [
            'actor_id' => $this->actor->id,
            'booking_id' => $booking->id,
            'groups' => [[
                'room_type_id' => $roomType->id,
                'room_ids' => [$room->id],
                'target_requirement_id' => null,
                'room_price' => 500000.0,
                'price_source' => \App\Enums\PriceSource::RateTable->value,
                'note' => null,
                'adults' => 2,
                'children_under_6' => 0,
                'children_over_6' => 0,
            ]],
            'start_at' => $booking->checkin_at->toDateTimeString(),
            'end_at' => $booking->checkout_at->toDateTimeString(),
        ];

        $results = $this->runWorkers('case13_double_submit_roomboard', [
            ['name' => 'worker_a', 'action' => 'assign_rooms_from_room_board', 'params' => $params],
            ['name' => 'worker_b', 'action' => 'assign_rooms_from_room_board', 'params' => $params],
        ]);

        // Identical payload selecting the SAME single room twice → the
        // second (serialized) submission's own Room lock recheck must find
        // the room already conflicted (hasConflict() inside
        // lockRoomRecheckAndCreateAssignment) and reject — never two
        // assignments on the same physical room.
        $successCount = collect($results)->where('success', true)->count();
        $this->assertSame(1, $successCount, 'double-submit of the identical room selection must not create two assignments: ' . json_encode($results));

        $activeOnRoom = RoomAssignment::where('room_id', $room->id)
            ->whereIn('status', ['ASSIGNED', 'CHECKED_IN'])
            ->count();
        $this->assertSame(1, $activeOnRoom);
    }

    /**
     * Race case 15 — a Room is transitioned to OutOfOrder concurrently with
     * a Room-Board-first request selecting that same room.
     *
     * Expected: `markOutOfOrder()` and `assignRoomsFromRoomBoard()` both
     * lock the same Room row first — whichever acquires it first fully
     * determines a consistent outcome, and BOTH outcomes are valid (no
     * crash, no deadlock, no partial state): either the assignment sees
     * OutOfOrder under its own lock and is rejected, or the assignment
     * completes first (room was still available at its lock instant) and
     * the OutOfOrder flip lands afterward — a legitimate ordering, not a
     * bug, since the flip simply arrived too late to matter.
     */
    public function test_case_15_room_flips_out_of_order_while_a_room_board_request_targets_it(): void
    {
        $roomType = RoomType::factory()->create();
        $room = $this->makeRoom($roomType);
        $booking = $this->makeBooking(['checkin_at' => '2028-02-25 14:00:00', 'checkout_at' => '2028-02-27 12:00:00']);

        $results = $this->runWorkers('case15_room_out_of_order_race', [
            [
                'name' => 'worker_a_mark_ooo',
                'action' => 'mark_room_out_of_order',
                'params' => ['actor_id' => $this->actor->id, 'room_id' => $room->id, 'reason' => 'QA-M5 case15'],
            ],
            [
                'name' => 'worker_b_assign',
                'action' => 'assign_rooms_from_room_board',
                'params' => [
                    'actor_id' => $this->actor->id,
                    'booking_id' => $booking->id,
                    'groups' => [[
                        'room_type_id' => $roomType->id,
                        'room_ids' => [$room->id],
                        'target_requirement_id' => null,
                        'room_price' => 500000.0,
                        'price_source' => \App\Enums\PriceSource::RateTable->value,
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

        $markResult = collect($results)->firstWhere('worker', 'worker_a_mark_ooo');
        $assignResult = collect($results)->firstWhere('worker', 'worker_b_assign');

        $this->assertTrue($markResult['success'], 'marking out-of-order itself must never fail: ' . json_encode($markResult));

        $room->refresh();
        $assignmentExists = RoomAssignment::where('room_id', $room->id)
            ->whereIn('status', ['ASSIGNED', 'CHECKED_IN'])
            ->exists();

        // The only INVALID outcome: an active assignment coexisting with a
        // room that is OutOfOrder AND the assignment claims success while
        // having observed OutOfOrder — i.e. assign succeeded but was
        // rejected for unavailability at the same time. Concretely: if the
        // room ended up OutOfOrder, the assignment worker must have either
        // failed (rejected it) or succeeded BEFORE the flip (both fine) —
        // what must NEVER happen is assignResult success === true AND its
        // own error message says unavailable (a logical contradiction that
        // would indicate the recheck-under-lock is broken).
        if ($assignResult['success'] === false) {
            $this->assertSame(\Illuminate\Validation\ValidationException::class, $assignResult['exception_class']);
        }

        $this->assertSame($assignResult['success'], $assignmentExists, 'assignment existence in DB must match the worker-reported outcome — no phantom/missing state');
    }

    /**
     * Race case 16 — a Booking is Cancelled concurrently with a
     * Room-Board-first request targeting it.
     *
     * Architecture Gap Closure (M5): originally reproduced a real
     * inconsistent final state (booking.status=CANCELLED coexisting with an
     * active RoomAssignment). Root cause: `cancelBooking()` never called
     * `Booking::lockForUpdate()`, so it never joined the same lock queue
     * every other Booking-mutating method already uses. Fixed by adding
     * that lock as the very first statement in `cancelBooking()`'s
     * transaction (Blocker B Option A) — no business rule changed, only
     * lock timing.
     *
     * Both orderings are now tested DETERMINISTICALLY (not left to
     * sub-millisecond timing) via `post_barrier_delay_ms` — both workers
     * still release from the same barrier instant, but the intended loser
     * of the lock race waits a fixed 300ms before making its own DB call,
     * giving the other a highly reliable head start.
     */
    private function makeCase16Fixture(): array
    {
        $roomType = RoomType::factory()->create();
        $room = $this->makeRoom($roomType);
        $booking = $this->makeBooking(['checkin_at' => '2028-03-01 14:00:00', 'checkout_at' => '2028-03-03 12:00:00']);

        return [$booking, $roomType, $room];
    }

    private function case16AssignParams(Booking $booking, RoomType $roomType, Room $room, int $delayMs = 0): array
    {
        return [
            'actor_id' => $this->actor->id,
            'booking_id' => $booking->id,
            'groups' => [[
                'room_type_id' => $roomType->id,
                'room_ids' => [$room->id],
                'target_requirement_id' => null,
                'room_price' => 500000.0,
                'price_source' => \App\Enums\PriceSource::RateTable->value,
                'note' => null,
                'adults' => 2,
                'children_under_6' => 0,
                'children_over_6' => 0,
            ]],
            'start_at' => $booking->checkin_at->toDateTimeString(),
            'end_at' => $booking->checkout_at->toDateTimeString(),
            ...($delayMs > 0 ? ['post_barrier_delay_ms' => $delayMs] : []),
        ];
    }

    private function assertNoIllegalCancelledPlusActiveAssignmentState(Booking $booking, string $label): void
    {
        $booking->refresh();
        $activeAssignmentOnCancelledBooking = $booking->status->value === 'CANCELLED'
            && RoomAssignment::where('booking_id', $booking->id)
                ->whereIn('status', ['ASSIGNED', 'CHECKED_IN'])
                ->exists();

        fwrite(STDERR, "\n[{$label}] booking.status=" . $booking->status->value
            . ', invalid-coexistence(active assignment on a Cancelled booking)=' . var_export($activeAssignmentOnCancelledBooking, true) . "\n");

        $this->assertFalse(
            $activeAssignmentOnCancelledBooking,
            'a Cancelled booking must never end up with an active RoomAssignment',
        );
    }

    public function test_case_16_order_a_assignment_wins_first(): void
    {
        [$booking, $roomType, $room] = $this->makeCase16Fixture();

        $results = $this->runWorkers('case16_order_a_assignment_first', [
            [
                'name' => 'worker_assign',
                'action' => 'assign_rooms_from_room_board',
                'params' => $this->case16AssignParams($booking, $roomType, $room, 0),
            ],
            [
                'name' => 'worker_cancel',
                'action' => 'cancel_booking',
                'params' => ['actor_id' => $this->actor->id, 'booking_id' => $booking->id, 'reason' => 'QA-M5 case16 order A', 'post_barrier_delay_ms' => 300],
            ],
        ]);

        $assignResult = collect($results)->firstWhere('worker', 'worker_assign');
        $cancelResult = collect($results)->firstWhere('worker', 'worker_cancel');

        // Assignment wins the lock first → it succeeds (booking was not yet
        // cancelled at its lock instant). Cancellation, running after, must
        // still succeed and correctly release the assignment that was just
        // created — the existing, unchanged cancellation business rule.
        $this->assertTrue($assignResult['success'], 'assignment must succeed when it wins the lock first: ' . json_encode($assignResult));
        $this->assertTrue($cancelResult['success'], 'cancellation must still succeed afterward: ' . json_encode($cancelResult));

        $assignment = RoomAssignment::where('booking_id', $booking->id)->where('room_id', $room->id)->first();
        $this->assertNotNull($assignment);
        $this->assertSame('RELEASED', $assignment->status->value, 'cancellation must release the assignment that won the race, per existing cancellation behavior');

        $this->assertNoIllegalCancelledPlusActiveAssignmentState($booking, 'Case 16 Order A evidence');
    }

    public function test_case_16_order_b_cancellation_wins_first(): void
    {
        [$booking, $roomType, $room] = $this->makeCase16Fixture();

        $results = $this->runWorkers('case16_order_b_cancellation_first', [
            [
                'name' => 'worker_cancel',
                'action' => 'cancel_booking',
                'params' => ['actor_id' => $this->actor->id, 'booking_id' => $booking->id, 'reason' => 'QA-M5 case16 order B'],
            ],
            [
                'name' => 'worker_assign',
                'action' => 'assign_rooms_from_room_board',
                'params' => $this->case16AssignParams($booking, $roomType, $room, 300),
            ],
        ]);

        $cancelResult = collect($results)->firstWhere('worker', 'worker_cancel');
        $assignResult = collect($results)->firstWhere('worker', 'worker_assign');

        // Cancellation wins the lock first → assignment, running after,
        // re-reads the now-Cancelled status under its own fresh lock and
        // must be cleanly rejected — no assignment created.
        $this->assertTrue($cancelResult['success'], 'cancellation must succeed: ' . json_encode($cancelResult));
        $this->assertFalse($assignResult['success'], 'assignment must be rejected once it sees the already-cancelled booking: ' . json_encode($assignResult));
        $this->assertSame(\Illuminate\Validation\ValidationException::class, $assignResult['exception_class']);

        $this->assertDatabaseMissing('room_assignments', ['booking_id' => $booking->id, 'room_id' => $room->id]);
        $this->assertNoIllegalCancelledPlusActiveAssignmentState($booking, 'Case 16 Order B evidence');
    }
}
