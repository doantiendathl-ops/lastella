<?php

declare(strict_types=1);

namespace App\Services\Posting;

use App\Enums\ChargeType;
use App\Enums\PackageCalculationStrategy;
use App\Models\BookingPackageFlag;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\ServicePackage;
use App\Services\PackageEnrollmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Generic financial posting for dynamic (non-legacy) ServicePackage
 * enrollments — Active Pilot phase.
 *
 * Unlike BreakfastPostingJob/ExtraPersonPostingJob/ExtraBedPostingJob (each
 * hardcoded to exactly one package_key and priced from the legacy
 * service_rates table), this job:
 *  - discovers ALL of a booking's enrolled packages that are NOT one of the
 *    3 legacy dedicated-posting keys (PackageEnrollmentService::
 *    isLegacyDedicatedPostingPackage() — single source of truth for the
 *    exclusion, so it can never drift out of sync with the legacy jobs);
 *  - resolves price from ServicePackage::currentRate() → service_package_rates,
 *    never service_rates;
 *  - applies the package's own calculation_strategy (both currently
 *    implemented values: ONCE_PER_STAY_PER_NIGHT and
 *    MANUAL_QUANTITY_PER_NIGHT) instead of hardcoding a strategy per class.
 *
 * A single stay can have multiple dynamic packages enrolled at once, but the
 * PostingJob contract (and NightAuditPipeline, which is not being rewritten)
 * calls execute() exactly once per (job, stay) per run. This job therefore
 * posts a FolioEntry for every eligible dynamic enrollment within one
 * execute() call, each under its own idempotency check, and returns a
 * single aggregate PostingResult (the last entry posted, or the first
 * skip/already-posted reason) — the NightAuditBookingLog row is a
 * diagnostic summary; FolioEntry is the actual source of truth for what
 * was charged.
 */
class ServicePackagePostingJob implements PostingJob
{
    private const POSTING_KEY_PREFIX = 'SVC_PKG';

    public function execute(PostingContext $context): PostingResult
    {
        if ($context->stay === null) {
            return PostingResult::skipped('No stay in context');
        }

        $flags = $this->dynamicFlagsFor($context->booking->id);

        if ($flags->isEmpty()) {
            return PostingResult::skipped('No dynamic package enrollments');
        }

        $lastEntry       = null;
        $anyNewlyPosted  = false;
        $anyAlreadyPosted = false;
        $lastMessage     = 'No dynamic package produced a charge';

        foreach ($flags as $flag) {
            $result = $this->postOnePackage($context, $flag);

            if ($result->entry !== null) {
                $lastEntry = $result->entry;
            }
            if ($result->success && ! $result->alreadyPosted && $result->entry !== null) {
                $anyNewlyPosted = true;
            }
            if ($result->alreadyPosted) {
                $anyAlreadyPosted = true;
            }
            if ($result->message !== '') {
                $lastMessage = $result->message;
            }
        }

        if ($anyNewlyPosted && $lastEntry !== null) {
            return PostingResult::posted($lastEntry);
        }

        if ($anyAlreadyPosted) {
            return PostingResult::alreadyPosted();
        }

        return PostingResult::skipped($lastMessage);
    }

    public function rollback(PostingContext $context): void
    {
        if ($context->stay === null) {
            return;
        }

        FolioEntry::where('folio_id', $context->folio->id)
            ->where('stay_id', $context->stay->id)
            ->where('entry_date', $context->businessDate->toDateString())
            ->where('posting_key', 'like', addcslashes(self::POSTING_KEY_PREFIX, '_%').'\_%')
            ->delete();
    }

    public function isAlreadyPosted(PostingContext $context): bool
    {
        if ($context->stay === null) {
            return false;
        }

        $flags = $this->dynamicFlagsFor($context->booking->id);

        if ($flags->isEmpty()) {
            return false;
        }

        foreach ($flags as $flag) {
            $postingKey = $this->buildPostingKey($flag->package_key, $context);

            $posted = FolioEntry::where('folio_id', $context->folio->id)
                ->where('posting_key', $postingKey)
                ->whereNull('voided_at')
                ->exists();

            if (! $posted) {
                return false;
            }
        }

        return true;
    }

    public function shouldProcess(PostingContext $context): bool
    {
        if ($context->stay === null || $context->folio->status->value !== 'OPEN') {
            return false;
        }

        return $this->dynamicFlagsFor($context->booking->id)->isNotEmpty();
    }

    public function dependsOn(): array
    {
        return [RoomChargePostingJob::class];
    }

    /**
     * All of this booking's enrollments that are NOT one of the 3 legacy
     * dedicated-posting keys. Historical enrollments are included
     * unconditionally — is_active/is_bookable only gate NEW enrollment
     * (PackageEnrollmentService::enroll()), never whether an existing,
     * already-enrolled package keeps posting. This matches the legacy
     * jobs' own behavior (they check BookingPackageFlag existence only,
     * never the package's current active/bookable state).
     */
    private function dynamicFlagsFor(int $bookingId)
    {
        return BookingPackageFlag::where('booking_id', $bookingId)
            ->get()
            ->reject(fn (BookingPackageFlag $flag): bool => PackageEnrollmentService::isLegacyDedicatedPostingPackage($flag->package_key));
    }

    private function postOnePackage(PostingContext $context, BookingPackageFlag $flag): PostingResult
    {
        $package = ServicePackage::where('code', $flag->package_key)->first();

        if ($package === null) {
            // Enrolled key no longer resolves to any service_packages row
            // (should not happen — codes are immutable once used — but this
            // job must never silently fabricate a charge for an unknown
            // package). Safe skip, not a failure.
            return PostingResult::skipped("Unknown package [{$flag->package_key}] — safe skip");
        }

        $rate = $package->currentRate($context->businessDate->toDateString());

        if ($rate === null) {
            // No-rate behavior (Section XI): never post a null/zero-amount
            // charge. Safe skip with a diagnostic reason, same pattern the
            // 3 legacy jobs already use for "no active rate for date".
            return PostingResult::skipped("No effective ServicePackageRate for [{$package->code}] on this business date");
        }

        $quantity = $this->resolveQuantity($package, $flag);

        if ($quantity === null) {
            // Unimplemented/unexpected calculation_strategy — defensive
            // guard only; ServicePackage::booted() already rejects saving
            // any non-implemented strategy, so this should be unreachable.
            return PostingResult::skipped("Unsupported calculation_strategy [{$package->calculation_strategy}] for [{$package->code}]");
        }

        $unitPrice  = (string) $rate->unit_price;
        $amount     = bcmul($unitPrice, (string) $quantity, 2);
        $postingKey = $this->buildPostingKey($package->code, $context);
        $dateLabel  = $context->businessDate->format('d/m/Y');
        $stay       = $context->stay;

        return DB::transaction(function () use ($context, $package, $postingKey, $unitPrice, $amount, $quantity, $dateLabel, $stay): PostingResult {
            $folio = Folio::lockForUpdate()->findOrFail($context->folio->id);

            if ($folio->status->value !== 'OPEN') {
                return PostingResult::skipped('Folio not open');
            }

            // whereNull('voided_at') — see RoomChargePostingJob's identical fix
            // for the reasoning (posting_key is no longer DB-unique).
            if (FolioEntry::where('folio_id', $folio->id)
                ->where('posting_key', $postingKey)
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->exists()) {
                return PostingResult::alreadyPosted();
            }

            $entry = FolioEntry::create([
                'folio_id'           => $folio->id,
                'night_audit_run_id' => $context->nightAuditRun?->id,
                'stay_id'            => $stay->id,
                'posting_key'        => $postingKey,
                'posting_source'     => 'NIGHT_AUDIT',
                'charge_type'        => ChargeType::Other,
                // Package name + code both present for traceability, per
                // Active Pilot decision — no dedicated reference column
                // exists on folio_entries, so identity travels in description.
                'description'        => "{$package->name} ({$package->code}) đêm {$dateLabel}",
                'quantity'           => number_format($quantity, 2, '.', ''),
                'unit_price'         => $unitPrice,
                'amount'             => $amount,
                'entry_date'         => $context->businessDate->toDateString(),
                'posted_by'          => $context->postedBy?->id ?? Auth::id(),
            ]);

            return PostingResult::posted($entry);
        });
    }

    /**
     * ONCE_PER_STAY_PER_NIGHT (quantity_mode NONE, e.g. same shape as
     * legacy Breakfast) is always exactly 1 — the stored flag value is not
     * a real quantity for this strategy, matching BreakfastPostingJob's own
     * behavior of never reading the flag's value at all.
     *
     * MANUAL_QUANTITY_PER_NIGHT (quantity_mode MANUAL_INPUT, same shape as
     * legacy Extra Person/Extra Bed) reads the enrolled quantity, clamped to
     * a minimum of 1 — identical malformed-quantity handling to
     * ExtraPersonPostingJob/ExtraBedPostingJob (max(1, intval(...))), so a
     * missing/zero/negative/non-numeric flag value can never produce a
     * zero or negative charge.
     *
     * Returns null for any other calculation_strategy value (defensive —
     * see caller).
     */
    private function resolveQuantity(ServicePackage $package, BookingPackageFlag $flag): ?int
    {
        return match ($package->calculation_strategy) {
            PackageCalculationStrategy::OncePerStayPerNight->value => 1,
            PackageCalculationStrategy::ManualQuantityPerNight->value => max(1, intval($flag->value ?? '1')),
            default => null,
        };
    }

    private function buildPostingKey(string $packageCode, PostingContext $context): string
    {
        return sprintf(
            '%s_%s_%d_%s',
            self::POSTING_KEY_PREFIX,
            $packageCode,
            $context->stay->id,
            $context->businessDate->toDateString(),
        );
    }
}
