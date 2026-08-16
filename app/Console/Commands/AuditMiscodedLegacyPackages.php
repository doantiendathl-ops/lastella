<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingPackageFlag;
use App\Models\ServicePackage;
use App\Services\PackageEnrollmentService;
use Illuminate\Console\Command;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 19) — audit for
 * the exact class of bug diagnosed this session: a ServicePackage whose
 * `charge_type` matches one of the 3 legacy dedicated-posting charge types
 * (EXTRA_BED/FOOD_BEVERAGE/EXTRA_PERSON) but whose `code` does NOT match
 * the corresponding hard-coded PackageEnrollmentService constant (the
 * GIUONGPHU vs EXTRA_BED_PER_NIGHT mismatch on production).
 *
 * Such a package silently falls out of its dedicated ExtraBedPostingJob /
 * BreakfastPostingJob / ExtraPersonPostingJob and into the generic
 * ServicePackagePostingJob instead — which prices from
 * service_package_rates (not service_rates) and posts BOOKING-wide per
 * stay, reproducing the exact multi-room over-posting bug the Room-Scoped
 * Bed Operations Correction fixed for the correctly-coded package.
 *
 * Read-only by default (dry-run). `--apply` deactivates
 * (is_active=false, is_bookable=false) — never deletes, never renames —
 * ONLY when the package has zero real usage (no rates, no enrollment;
 * see ServicePackage::hasBeenUsed()). A package with real usage is
 * reported but left untouched — it needs a human migration decision, not
 * an automated one.
 *
 * Section 22: this command must never be run against production from this
 * environment. It is meant to be reviewed and then run manually by an
 * authorized operator against the target database.
 */
class AuditMiscodedLegacyPackages extends Command
{
    protected $signature = 'services:audit-miscoded-legacy-packages
                            {--apply : Deactivate confirmed-unused miscoded packages instead of only reporting}';

    protected $description = 'Detect ServicePackage rows whose charge_type matches a legacy dedicated-posting type but whose code does not match the expected constant (dry-run by default).';

    private const EXPECTED_CODE_BY_CHARGE_TYPE = [
        'EXTRA_BED' => PackageEnrollmentService::EXTRA_BED_PER_NIGHT,
        'FOOD_BEVERAGE' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
        'EXTRA_PERSON' => PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT,
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply ? 'Running in APPLY mode.' : 'Running in DRY-RUN mode (pass --apply to deactivate).');

        $suspects = ServicePackage::whereIn('charge_type', array_keys(self::EXPECTED_CODE_BY_CHARGE_TYPE))
            ->get()
            ->filter(fn (ServicePackage $p): bool => $p->code !== self::EXPECTED_CODE_BY_CHARGE_TYPE[$p->charge_type]);

        if ($suspects->isEmpty()) {
            $this->info('No miscoded legacy packages found.');

            return self::SUCCESS;
        }

        $deactivated = 0;
        $skipped = 0;

        foreach ($suspects as $package) {
            $expectedCode = self::EXPECTED_CODE_BY_CHARGE_TYPE[$package->charge_type];
            $enrollmentCount = BookingPackageFlag::where('package_key', $package->code)->count();
            $rateCount = $package->rates()->count();
            $used = $package->hasBeenUsed();

            $this->line('');
            $this->warn("Package #{$package->id} [{$package->code}] \"{$package->name}\" — charge_type={$package->charge_type}, expected code={$expectedCode}");
            $this->line("  enrollments (booking_package_flags): {$enrollmentCount}");
            $this->line("  price rows (service_package_rates): {$rateCount}");
            $this->line('  hasBeenUsed(): '.($used ? 'true' : 'false'));

            if (! $used) {
                if ($apply) {
                    $package->update(['is_active' => false, 'is_bookable' => false]);
                    $this->info('  -> deactivated (is_active=false, is_bookable=false).');
                    $deactivated++;
                } else {
                    $this->line('  -> would deactivate (dry-run).');
                }
            } else {
                $this->error('  -> HAS real usage or a configured price. NOT touched — needs a human migration decision (rename vs new canonical Service + compatibility alias), see docs/reports/unified-services-implementation-plan.md Section 19.');
                $skipped++;
            }
        }

        $this->line('');
        $this->info("Summary: {$suspects->count()} suspect package(s), {$deactivated} deactivated, {$skipped} left untouched (has real usage).");

        return self::SUCCESS;
    }
}
