<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\BookingPackageFlag;
use App\Models\ServicePackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 19/26) —
 * exercises the exact GIUONGPHU-class bug via a synthetic fixture (the
 * real production row cannot be touched from this environment — Section
 * 22 — so this proves the detection/deactivation logic in isolation).
 */
class AuditMiscodedLegacyPackagesTest extends TestCase
{
    use RefreshDatabase;

    private function makeMiscodedPackage(string $code = 'GIUONGPHU'): ServicePackage
    {
        return ServicePackage::create([
            'code' => $code,
            'name' => 'Giường phụ',
            'charge_type' => 'EXTRA_BED',
            'calculation_strategy' => 'ONCE_PER_STAY_PER_NIGHT',
            'quantity_mode' => 'NONE',
            'default_quantity' => 1,
            'unit_label' => 'đêm',
            'posting_frequency' => 'PER_NIGHT',
            'is_active' => true,
            'is_bookable' => true,
        ]);
    }

    public function test_correctly_coded_package_is_not_flagged(): void
    {
        $this->makeMiscodedPackage('EXTRA_BED_PER_NIGHT');

        $this->artisan('services:audit-miscoded-legacy-packages')
            ->expectsOutputToContain('No miscoded legacy packages found.')
            ->assertExitCode(0);
    }

    public function test_miscoded_unused_package_is_detected_and_left_alone_in_dry_run(): void
    {
        $package = $this->makeMiscodedPackage('GIUONGPHU');

        $this->artisan('services:audit-miscoded-legacy-packages')
            ->expectsOutputToContain('GIUONGPHU')
            ->expectsOutputToContain('would deactivate (dry-run)')
            ->assertExitCode(0);

        $package->refresh();
        $this->assertTrue($package->is_active);
        $this->assertTrue($package->is_bookable);
    }

    public function test_apply_deactivates_a_miscoded_unused_package(): void
    {
        $package = $this->makeMiscodedPackage('GIUONGPHU');

        $this->artisan('services:audit-miscoded-legacy-packages', ['--apply' => true])
            ->expectsOutputToContain('deactivated')
            ->assertExitCode(0);

        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertFalse($package->is_bookable);
    }

    public function test_apply_never_touches_a_miscoded_package_that_has_real_enrollment_history(): void
    {
        $package = $this->makeMiscodedPackage('GIUONGPHU');
        BookingPackageFlag::create([
            'booking_id' => \App\Models\Booking::factory()->create()->id,
            'package_key' => 'GIUONGPHU',
            'value' => '1',
        ]);

        $this->artisan('services:audit-miscoded-legacy-packages', ['--apply' => true])
            ->expectsOutputToContain('HAS real usage')
            ->assertExitCode(0);

        $package->refresh();
        $this->assertTrue($package->is_active, 'A package with real usage must be left untouched — needs a human migration decision.');
    }

    public function test_apply_never_deletes_never_renames_only_deactivates(): void
    {
        $package = $this->makeMiscodedPackage('GIUONGPHU');

        $this->artisan('services:audit-miscoded-legacy-packages', ['--apply' => true]);

        $this->assertDatabaseHas('service_packages', ['id' => $package->id, 'code' => 'GIUONGPHU']);
    }
}
