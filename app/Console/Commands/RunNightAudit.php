<?php

namespace App\Console\Commands;

use App\Services\BusinessDateService;
use App\Services\NightAuditService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunNightAudit extends Command
{
    protected $signature = 'audit:night-audit
                            {--date= : Business date to run audit for (Y-m-d). Defaults to current business date.}
                            {--dry-run : Simulate without writing to the database.}';

    protected $description = 'Run the night audit pipeline for a given business date.';

    public function handle(NightAuditService $nightAudit, BusinessDateService $businessDate): int
    {
        $dateOption = $this->option('date');
        $dryRun     = (bool) $this->option('dry-run');

        $targetDate = $dateOption
            ? Carbon::parse($dateOption)->startOfDay()
            : $businessDate->currentBusinessDate();

        $this->info("Night audit — business date: {$targetDate->toDateString()}" . ($dryRun ? ' [DRY RUN]' : ''));

        if ($dryRun) {
            $this->warn('Dry-run mode: no database changes will be made.');

            return self::SUCCESS;
        }

        $run = $nightAudit->runForDate($targetDate);

        if ($run->isCompleted()) {
            $this->info("Completed. Stays: {$run->stays_processed}, Posted: {$run->entries_posted}, Skipped: {$run->entries_skipped}");

            return self::SUCCESS;
        }

        if ($run->isFailed()) {
            $this->error("Night audit failed: {$run->error_message}");

            return self::FAILURE;
        }

        $this->warn("Night audit already ran for {$targetDate->toDateString()} with status: {$run->status}");

        return self::SUCCESS;
    }
}
