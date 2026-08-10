<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AssignmentStatus;
use App\Models\BookingPackageFlag;
use App\Models\RoomAssignment;
use App\Services\PackageEnrollmentService;
use Illuminate\Console\Command;

/**
 * Room-Scoped Bed Operations Correction — legacy backfill for
 * room_assignments.extra_bed_quantity from the old booking-level
 * BookingPackageFlag(EXTRA_BED_PER_NIGHT) rows (Mục XI).
 *
 * Matching rule (deliberately simple, never guesses):
 *  - Booking has EXACTLY ONE applicable RoomAssignment (Assigned/CheckedIn)
 *    -> unambiguous, map the flag's quantity onto that one room.
 *  - Booking has TWO OR MORE applicable RoomAssignments -> AMBIGUOUS. Left
 *    untouched (every room stays at extra_bed_quantity = 0), reported. Does
 *    NOT split the quantity across rooms, does NOT assign to "the first"
 *    room, does NOT copy the quantity onto every room — any of those would
 *    be a guess the source data cannot support.
 *  - Booking has ZERO applicable RoomAssignments -> no_room, reported,
 *    nothing to backfill yet.
 *
 * The legacy BookingPackageFlag row is NEVER deleted by this command — it
 * remains as the historical record of the original (possibly ambiguous)
 * enrollment. Only room_assignments.extra_bed_quantity is written.
 *
 * Safe to re-run: a RoomAssignment that already has extra_bed_quantity > 0
 * is excluded from the candidate set entirely, so re-running never
 * overwrites a value someone has since set (via the new per-room UI or a
 * prior run of this command).
 *
 * Default mode is dry-run (report only) — `--apply` is required to write.
 */
class BackfillExtraBedRoomQuantity extends Command
{
    protected $signature = 'room-assignments:backfill-extra-bed
        {--apply : Write the resolved quantities to the database. Without this flag the command only reports what it would do.}
        {--dry-run : Force dry-run even if --apply is also passed (safety override — never writes).}
        {--booking-id= : Restrict the backfill to a single booking ID.}';

    protected $description = 'Backfill room_assignments.extra_bed_quantity from legacy BookingPackageFlag(EXTRA_BED_PER_NIGHT) rows, mapping only when the booking has exactly one applicable room';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
        $bookingId = $this->option('booking-id');

        if (! $apply) {
            $this->info('[DRY-RUN] No rows will be written. Pass --apply to write.');
        }

        $flagsQuery = BookingPackageFlag::where('package_key', PackageEnrollmentService::EXTRA_BED_PER_NIGHT);
        if ($bookingId !== null) {
            $flagsQuery->where('booking_id', (int) $bookingId);
        }
        $flags = $flagsQuery->orderBy('booking_id')->get();

        $mapped = 0;
        $ambiguous = 0;
        $noRoom = 0;
        $alreadySet = 0;
        $rows = [];

        foreach ($flags as $flag) {
            $applicableAssignments = RoomAssignment::where('booking_id', $flag->booking_id)
                ->whereIn('status', [AssignmentStatus::Assigned, AssignmentStatus::CheckedIn])
                ->orderBy('id')
                ->get();

            if ($applicableAssignments->isEmpty()) {
                $noRoom++;
                $rows[] = [
                    'booking_id' => $flag->booking_id,
                    'flag_quantity' => $flag->value,
                    'applicable_rooms' => 0,
                    'result' => 'no_room',
                ];

                continue;
            }

            if ($applicableAssignments->count() > 1) {
                $ambiguous++;
                $rows[] = [
                    'booking_id' => $flag->booking_id,
                    'flag_quantity' => $flag->value,
                    'applicable_rooms' => $applicableAssignments->count(),
                    'result' => 'ambiguous — left untouched',
                ];

                continue;
            }

            /** @var RoomAssignment $assignment */
            $assignment = $applicableAssignments->first();

            if ($assignment->extra_bed_quantity > 0) {
                $alreadySet++;
                $rows[] = [
                    'booking_id' => $flag->booking_id,
                    'flag_quantity' => $flag->value,
                    'applicable_rooms' => 1,
                    'result' => "already set ({$assignment->extra_bed_quantity}), skipped",
                ];

                continue;
            }

            $quantity = max(1, (int) $flag->value);
            $mapped++;
            $rows[] = [
                'booking_id' => $flag->booking_id,
                'flag_quantity' => $flag->value,
                'applicable_rooms' => 1,
                'result' => ($apply ? 'mapped' : 'would map')." to assignment #{$assignment->id} (qty {$quantity})",
            ];

            if ($apply) {
                // Re-assert the 0 guard on write — another process could have
                // set this between the SELECT above and this UPDATE; if so,
                // skip rather than overwrite.
                RoomAssignment::whereKey($assignment->id)
                    ->where('extra_bed_quantity', 0)
                    ->update(['extra_bed_quantity' => $quantity]);
            }
        }

        $this->info("Legacy EXTRA_BED_PER_NIGHT flags considered: {$flags->count()}");
        $this->info('  Unique room match (' . ($apply ? 'mapped' : 'would map') . "): {$mapped}");
        $this->info("  Ambiguous (>=2 applicable rooms, left untouched): {$ambiguous}");
        $this->info("  No applicable room (left untouched): {$noRoom}");
        $this->info("  Already set on the one applicable room (skipped): {$alreadySet}");

        if ($rows !== []) {
            $this->table(
                ['booking_id', 'flag_quantity', 'applicable_rooms', 'result'],
                $rows,
            );
        }

        return self::SUCCESS;
    }
}
