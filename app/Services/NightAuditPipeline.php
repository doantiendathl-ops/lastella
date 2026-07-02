<?php

namespace App\Services;

use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Folio;
use App\Models\NightAuditBookingLog;
use App\Models\NightAuditRun;
use App\Models\Stay;
use App\Services\Posting\PostingContext;
use App\Services\Posting\PostingJob;
use App\Services\Posting\PostingResult;
use Carbon\Carbon;

class NightAuditPipeline
{
    /** @var PostingJob[] */
    private array $jobs = [];

    public function __construct(
        private readonly BusinessDateService $businessDate,
    ) {}

    public function register(PostingJob $job): static
    {
        $this->jobs[] = $job;

        return $this;
    }

    public function run(NightAuditRun $auditRun, Carbon $businessDate): void
    {
        $auditRun->update([
            'status'     => 'RUNNING',
            'started_at' => now(),
        ]);

        $staysProcessed = 0;
        $entriesPosted  = 0;
        $entriesSkipped = 0;

        try {
            $activeStays = Stay::with(['booking.folio', 'booking.bookingRequirements', 'room'])
                ->whereIn('status', [StayStatus::CheckedIn])
                ->get();

            foreach ($activeStays as $stay) {
                $booking = $stay->booking;
                if ($booking === null) {
                    continue;
                }

                $folio = $booking->folio;
                if ($folio === null) {
                    continue;
                }

                $staysProcessed++;

                $context = new PostingContext(
                    booking:      $booking,
                    folio:        $folio,
                    businessDate: $businessDate,
                    stay:         $stay,
                );

                foreach ($this->jobs as $job) {
                    if (! $job->shouldProcess($context)) {
                        $this->log($auditRun, $stay, $job, 'SKIPPED', null, 'shouldProcess returned false');
                        $entriesSkipped++;
                        continue;
                    }

                    $result = $job->execute($context);

                    $this->log($auditRun, $stay, $job, $this->resultCode($result), $result->entry?->posting_key, $result->message);

                    if ($result->success && ! $result->alreadyPosted && $result->entry !== null) {
                        $entriesPosted++;
                    } elseif (! $result->success) {
                        $entriesSkipped++;
                    }
                }
            }

            $auditRun->update([
                'status'          => 'COMPLETED',
                'completed_at'    => now(),
                'stays_processed' => $staysProcessed,
                'entries_posted'  => $entriesPosted,
                'entries_skipped' => $entriesSkipped,
            ]);
        } catch (\Throwable $e) {
            $auditRun->update([
                'status'        => 'FAILED',
                'completed_at'  => now(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function log(
        NightAuditRun $run,
        Stay $stay,
        PostingJob $job,
        string $result,
        ?string $postingKey,
        string $message,
    ): void {
        NightAuditBookingLog::create([
            'run_id'      => $run->id,
            'booking_id'  => $stay->booking_id,
            'stay_id'     => $stay->id,
            'job_class'   => get_class($job),
            'result'      => $result,
            'posting_key' => $postingKey,
            'message'     => $message,
        ]);
    }

    private function resultCode(PostingResult $result): string
    {
        if ($result->alreadyPosted) {
            return 'ALREADY_POSTED';
        }
        if (! $result->success) {
            return 'FAILED';
        }
        if ($result->entry !== null) {
            return 'POSTED';
        }

        return 'SKIPPED';
    }
}
