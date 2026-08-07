<?php

/**
 * Room Demand/Room Board Unification M5 — concurrency worker CLI entry point.
 *
 * Invoked ONLY by WorkerProcessRunner (from a PHPUnit test), never by any
 * route, controller, or Artisan command. Not registered anywhere in `app/`.
 *
 * Usage (all args required):
 *   php run_worker.php <workerName> <barrierDir> <totalWorkers> <action> <paramsJsonFile> <resultJsonFile>
 *
 * The worker:
 *   1. Boots a real standalone Laravel app against the disposable MySQL
 *      database named by CONCURRENCY_DB_DATABASE (env, not .env).
 *   2. Authenticates as the given actor (real User row, real Auth facade —
 *      so every service call sees a genuine Auth::id(), matching production
 *      behavior, never a null/fake actor).
 *   3. Waits on the shared Barrier so both workers reach this exact line at
 *      approximately the same instant.
 *   4. Calls the REAL, unmodified service method for $action with the given
 *      params — never a re-implementation, never a shortcut.
 *   5. Writes a JSON result (success, exception class/message, validation
 *      errors, SQLSTATE if present, and any state the test asked for) to
 *      $resultJsonFile, then exits.
 */

[, $workerName, $barrierDir, $totalWorkers, $action, $paramsFile, $resultFile] = $argv;

require __DIR__ . '/ConcurrencyWorkerBootstrap.php';
require __DIR__ . '/Barrier.php';

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\BookingRequirement;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\Support\Concurrency\Barrier;
use Tests\Support\Concurrency\ConcurrencyWorkerBootstrap;

// Registered lazily below so the two extra actions (used only by race cases
// 15/16) don't need their own service-container wiring beyond what already
// exists — both go through the real, unmodified HousekeepingService /
// BookingService methods, never a re-implementation.

$app = ConcurrencyWorkerBootstrap::boot();
$params = json_decode(file_get_contents($paramsFile), true, flags: JSON_THROW_ON_ERROR);

if (isset($params['actor_id'])) {
    Auth::loginUsingId($params['actor_id']);
}

$result = [
    'worker' => $workerName,
    'action' => $action,
    'success' => false,
    'exception_class' => null,
    'message' => null,
    'errors' => null,
    'sql_state' => null,
    'data' => null,
];

try {
    // Every worker reaches this line, then all release together — the
    // service call below genuinely races against the sibling process's
    // service call, both hitting real MySQL row locks.
    (new Barrier($barrierDir))->wait($workerName, (int) $totalWorkers);

    // Optional, test-support-only deterministic ordering: both workers still
    // release from the SAME barrier instant, but a worker carrying
    // `post_barrier_delay_ms` deliberately waits before making its own DB
    // call, giving the other worker a highly reliable head start to acquire
    // the contested row lock first. Used only by the two-orderings tests
    // (Case 16 Order A/B) where the specific winner must be deterministic,
    // not left to sub-millisecond timing — never used by the "genuinely
    // simultaneous" race cases, which omit this parameter entirely.
    if (isset($params['post_barrier_delay_ms'])) {
        usleep(((int) $params['post_barrier_delay_ms']) * 1000);
    }

    $assignments = $app->make(\App\Services\RoomAssignmentService::class);
    $bookings = $app->make(\App\Services\BookingService::class);

    $result['data'] = match ($action) {
        'assign_rooms_with_requirement_link' => (function () use ($assignments, $params): array {
            $booking = Booking::findOrFail($params['booking_id']);
            $created = $assignments->assignRoomsWithRequirementLink(
                $booking,
                $params['assignments'],
                $params['target_requirement_id_by_room_type'] ?? [],
            );

            return ['assignment_ids' => array_map(fn ($a) => $a->id, $created)];
        })(),

        'assign_rooms_from_room_board' => (function () use ($assignments, $params): array {
            $booking = Booking::findOrFail($params['booking_id']);
            $result = $assignments->assignRoomsFromRoomBoard(
                $booking,
                $params['groups'],
                $params['start_at'],
                $params['end_at'],
            );

            return [
                'assignment_ids' => array_map(fn ($a) => $a->id, $result['assignments']),
                'stay_ids' => array_map(fn ($s) => $s->id, $result['stays']),
            ];
        })(),

        'bulk_release_assignments' => (function () use ($assignments, $params): array {
            $booking = Booking::findOrFail($params['booking_id']);
            $result = $assignments->bulkReleaseAssignments(
                $booking,
                $params['assignment_ids'],
                $params['reason'],
                $params['note'] ?? null,
                (bool) ($params['reduce_demand'] ?? false),
            );

            return [
                'batch_id' => $result['batch']->id,
                'assignment_ids' => array_map(fn ($a) => $a->id, $result['assignments']),
            ];
        })(),

        'release_assignment' => (function () use ($assignments, $params): array {
            $assignment = RoomAssignment::findOrFail($params['assignment_id']);
            $released = $assignments->releaseAssignment($assignment, $params['reason'] ?? null);

            return ['assignment_id' => $released->id];
        })(),

        'update_requirement' => (function () use ($bookings, $params): array {
            $requirement = BookingRequirement::findOrFail($params['requirement_id']);
            $updated = $bookings->updateRequirement($requirement, $params['data']);

            return ['requirement_id' => $updated->id, 'quantity' => $updated->quantity];
        })(),

        'mark_room_out_of_order' => (function () use ($app, $params): array {
            $housekeeping = $app->make(\App\Services\HousekeepingService::class);
            $room = Room::findOrFail($params['room_id']);
            $actor = User::findOrFail($params['actor_id']);
            $updated = $housekeeping->markOutOfOrder($room, $actor, $params['reason'] ?? 'QA-M5 concurrency race');

            return ['room_id' => $updated->id, 'status' => $updated->status->value];
        })(),

        'cancel_booking' => (function () use ($bookings, $params): array {
            $booking = Booking::findOrFail($params['booking_id']);
            $cancelled = $bookings->cancelBooking($booking, $params['reason'] ?? 'QA-M5 concurrency race');

            return ['booking_id' => $cancelled->id, 'status' => $cancelled->status->value];
        })(),

        // Race case 18 — simulates a real Room charge landing on the Folio
        // at the same instant as a reduce_demand release, going through the
        // real FolioEntry model (same shape RoomChargePostingJob writes),
        // never a hand-rolled insert bypassing the schema.
        'post_room_charge' => (function () use ($params): array {
            $booking = Booking::findOrFail($params['booking_id']);
            $folio = $booking->folio ?? \App\Models\Folio::factory()->create(['booking_id' => $booking->id]);
            $entry = \App\Models\FolioEntry::factory()->create([
                'folio_id' => $folio->id,
                'charge_type' => \App\Enums\ChargeType::Room,
                'posted_by' => $params['actor_id'],
                'voided_at' => null,
            ]);

            return ['folio_entry_id' => $entry->id];
        })(),

        default => throw new \InvalidArgumentException("Unknown concurrency worker action: {$action}"),
    };

    $result['success'] = true;
} catch (\Illuminate\Validation\ValidationException $e) {
    $result['exception_class'] = $e::class;
    $result['message'] = $e->getMessage();
    $result['errors'] = $e->errors();
} catch (\Throwable $e) {
    $result['exception_class'] = $e::class;
    $result['message'] = $e->getMessage();

    if ($e instanceof \Illuminate\Database\QueryException) {
        $result['sql_state'] = $e->getCode();
    }
}

file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT));
