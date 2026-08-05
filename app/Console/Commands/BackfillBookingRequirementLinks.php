<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingRequirement;
use App\Models\RoomAssignment;
use Illuminate\Console\Command;

/**
 * Room Demand/Room Board Unification M1 — legacy backfill for
 * room_assignments.booking_requirement_id (Architecture Review REVISION 3
 * Mục 16, Implementation Plan Mục 5 Milestone 1 item 4).
 *
 * Matching rule (deliberately simple, never guesses): a legacy assignment
 * (booking_requirement_id IS NULL) matches a BookingRequirement when they
 * share the same booking_id AND room_type_id.
 *  - Exactly one match  -> unique, mappable.
 *  - Two or more matches -> ambiguous, left NULL, reported.
 *  - Zero matches        -> no_matching_requirement, left NULL, reported.
 *
 * Never creates a BookingRequirement, never changes quantity/price/source/
 * note on any BookingRequirement, never touches an assignment that already
 * has a mapping. Default mode is dry-run (report only) — `--apply` is
 * required to write. Safe to re-run: an assignment that already has
 * booking_requirement_id set is excluded from the candidate query entirely,
 * so re-running never re-matches or overwrites an existing mapping.
 *
 * There is intentionally no reverse/undo option in this command — see
 * Architecture Review REVISION 3 Mục VII and the Milestone 1 report for the
 * manual reconciliation plan for rows left ambiguous/no-match.
 */
class BackfillBookingRequirementLinks extends Command
{
    protected $signature = 'room-assignments:backfill-booking-requirements
        {--apply : Write the resolved mappings to the database. Without this flag the command only reports what it would do.}
        {--dry-run : Force dry-run even if --apply is also passed (safety override — never writes).}
        {--booking-id= : Restrict the backfill to a single booking ID.}
        {--chunk=500 : Number of candidate assignments to read per chunk.}';

    protected $description = 'Backfill room_assignments.booking_requirement_id for legacy rows, matching only when exactly one BookingRequirement of the same booking/room_type exists';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
        $bookingId = $this->option('booking-id');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if (! $apply) {
            $this->info('[DRY-RUN] No rows will be written. Pass --apply to write.');
        }

        $baseQuery = RoomAssignment::query();
        if ($bookingId !== null) {
            $baseQuery->where('booking_id', (int) $bookingId);
        }

        $totalConsidered = (clone $baseQuery)->count();
        $alreadyMapped = (clone $baseQuery)->whereNotNull('booking_requirement_id')->count();

        $uniqueMapped = 0;
        $ambiguous = 0;
        $noMatch = 0;
        $rows = [];

        (clone $baseQuery)
            ->whereNull('booking_requirement_id')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($assignments) use ($apply, &$uniqueMapped, &$ambiguous, &$noMatch, &$rows): void {
                foreach ($assignments as $assignment) {
                    $candidateIds = BookingRequirement::where('booking_id', $assignment->booking_id)
                        ->where('room_type_id', $assignment->room_type_id)
                        ->orderBy('id')
                        ->pluck('id');

                    if ($candidateIds->count() === 1) {
                        $result = 'unique';
                        $uniqueMapped++;

                        if ($apply) {
                            // Re-assert the NULL guard on write — another process could
                            // have mapped this row between the SELECT above and this
                            // UPDATE; if so, skip it rather than overwrite.
                            RoomAssignment::whereKey($assignment->id)
                                ->whereNull('booking_requirement_id')
                                ->update(['booking_requirement_id' => $candidateIds->first()]);
                        }
                    } elseif ($candidateIds->count() >= 2) {
                        $result = 'ambiguous';
                        $ambiguous++;
                    } else {
                        $result = 'no_matching_requirement';
                        $noMatch++;
                    }

                    $rows[] = [
                        'assignment_id' => $assignment->id,
                        'booking_id' => $assignment->booking_id,
                        'room_type_id' => $assignment->room_type_id,
                        'result' => $result,
                        'candidate_requirement_ids' => $candidateIds->implode(','),
                    ];
                }
            });

        $totalNull = $uniqueMapped + $ambiguous + $noMatch;

        $this->info("Total assignments considered: {$totalConsidered}");
        $this->info("Already mapped (skipped, untouched): {$alreadyMapped}");
        $this->info("NULL mapping found: {$totalNull}");
        $this->info('  Unique match (' . ($apply ? 'mapped' : 'would map') . "): {$uniqueMapped}");
        $this->info("  Ambiguous (>=2 candidates, left NULL): {$ambiguous}");
        $this->info("  No matching requirement (left NULL): {$noMatch}");

        if ($rows !== []) {
            $this->table(
                ['assignment_id', 'booking_id', 'room_type_id', 'result', 'candidate_requirement_ids'],
                $rows,
            );
        }

        return self::SUCCESS;
    }
}
