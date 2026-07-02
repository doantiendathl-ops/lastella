<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FolioStatus;
use App\Enums\StayStatus;
use App\Models\FolioEntry;
use App\Models\Stay;
use App\Services\BusinessDateService;
use App\Services\Posting\PostingContext;
use App\Services\Posting\RoomChargePostingJob;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackfillPerNightCharges extends Command
{
    protected $signature = 'folio:backfill-per-night-charges
        {--dry-run : Report what would be posted without writing to the database}
        {--date= : Business date ceiling in Y-m-d format (defaults to current business date)}';

    protected $description = 'Backfill missing per-night ROOM_NIGHT folio entries for currently checked-in stays';

    public function handle(BusinessDateService $businessDate, RoomChargePostingJob $roomChargeJob): int
    {
        $targetDate = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : $businessDate->currentBusinessDate();

        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->info('[DRY-RUN] No entries will be written.');
        }

        $stays = Stay::where('status', StayStatus::CheckedIn)
            ->whereNotNull('actual_checkin_at')
            ->with(['booking.folio', 'booking.bookingRequirements', 'room'])
            ->get();

        $staysChecked = 0;
        $nightsPosted = 0;
        $nightsSkipped = 0;

        foreach ($stays as $stay) {
            $folio = $stay->booking?->folio;

            if ($folio === null || $folio->status !== FolioStatus::Open) {
                continue;
            }

            $staysChecked++;

            $checkinDate = Carbon::parse($stay->actual_checkin_at)->startOfDay();
            $endDate = $targetDate->copy()->subDay()->startOfDay();

            if ($checkinDate->gt($endDate)) {
                continue;
            }

            $cursor = $checkinDate->copy();

            while ($cursor->lte($endDate)) {
                $postingKey = sprintf('ROOM_NIGHT_%d_%s', $stay->id, $cursor->toDateString());

                $alreadyPosted = FolioEntry::where('folio_id', $folio->id)
                    ->where('posting_key', $postingKey)
                    ->whereNull('voided_at')
                    ->exists();

                if ($alreadyPosted) {
                    $nightsSkipped++;
                } elseif ($isDryRun) {
                    $this->line("  [DRY] Would post {$postingKey} for stay #{$stay->id}");
                    $nightsPosted++;
                } else {
                    $context = new PostingContext(
                        booking:      $stay->booking,
                        folio:        $folio,
                        businessDate: $cursor->copy(),
                        stay:         $stay,
                        postedBy:     null,
                    );

                    $result = $roomChargeJob->execute($context);

                    if ($result->entry !== null) {
                        $nightsPosted++;
                    } else {
                        $nightsSkipped++;
                    }
                }

                $cursor->addDay();
            }
        }

        $this->info("Stays checked: {$staysChecked}");
        $this->info("Nights posted: {$nightsPosted}");
        $this->info("Nights skipped (already exist): {$nightsSkipped}");

        return self::SUCCESS;
    }
}
