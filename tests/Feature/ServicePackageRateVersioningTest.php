<?php

namespace Tests\Feature;

use App\Models\ServicePackage;
use App\Models\ServicePackageRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePackageRateVersioningTest extends TestCase
{
    use RefreshDatabase;

    private ServicePackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->package = ServicePackage::create([
            'code'                 => 'VERSIONING_TEST_PKG',
            'name'                 => 'Versioning Test Package',
            'charge_type'          => 'OTHER',
            'calculation_strategy' => 'ONCE_PER_STAY_PER_NIGHT',
            'quantity_mode'        => 'NONE',
            'default_quantity'     => 1,
            'unit_label'           => 'đêm',
            'posting_frequency'    => 'PER_NIGHT',
        ]);
    }

    private function resolve(string $businessDate): ?ServicePackageRate
    {
        return ServicePackageRate::where('service_package_id', $this->package->id)
            ->active()
            ->effectiveOn($businessDate)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public function test_resolves_rate_active_on_business_date(): void
    {
        ServicePackageRate::create([
            'service_package_id' => $this->package->id,
            'unit_price'         => 150000,
            'effective_from'     => '2026-01-01',
        ]);

        $resolved = $this->resolve('2026-01-15');

        $this->assertNotNull($resolved);
        $this->assertEquals(150000, $resolved->unit_price);
    }

    public function test_resolves_new_rate_when_effective_from_matches_business_date(): void
    {
        ServicePackageRate::create([
            'service_package_id' => $this->package->id,
            'unit_price'         => 150000,
            'effective_from'     => '2026-01-01',
        ]);
        ServicePackageRate::create([
            'service_package_id' => $this->package->id,
            'unit_price'         => 180000,
            'effective_from'     => '2026-02-01',
        ]);

        $resolved = $this->resolve('2026-02-01');

        $this->assertEquals(180000, $resolved->unit_price);
    }

    public function test_resolves_old_rate_when_business_date_is_before_new_effective_from(): void
    {
        ServicePackageRate::create([
            'service_package_id' => $this->package->id,
            'unit_price'         => 150000,
            'effective_from'     => '2026-01-01',
        ]);
        ServicePackageRate::create([
            'service_package_id' => $this->package->id,
            'unit_price'         => 180000,
            'effective_from'     => '2026-02-01',
        ]);

        $resolved = $this->resolve('2026-01-15');

        $this->assertEquals(150000, $resolved->unit_price);
    }

    public function test_returns_null_when_no_active_rate_exists(): void
    {
        $this->assertNull($this->resolve('2026-01-15'));
    }

    public function test_inactive_rate_is_excluded_from_resolution(): void
    {
        ServicePackageRate::create([
            'service_package_id' => $this->package->id,
            'unit_price'         => 150000,
            'effective_from'     => '2026-01-01',
            'is_active'          => false,
        ]);

        $this->assertNull($this->resolve('2026-01-15'));
    }

    public function test_two_rates_with_same_effective_from_resolve_to_the_most_recently_created_row(): void
    {
        ServicePackageRate::create([
            'service_package_id' => $this->package->id,
            'unit_price'         => 150000,
            'effective_from'     => '2026-01-01',
        ]);
        $newer = ServicePackageRate::create([
            'service_package_id' => $this->package->id,
            'unit_price'         => 160000,
            'effective_from'     => '2026-01-01',
        ]);

        $resolved = $this->resolve('2026-01-01');

        $this->assertSame($newer->id, $resolved->id);
        $this->assertEquals(160000, $resolved->unit_price);
    }

    public function test_future_rate_is_not_applied_before_its_effective_date(): void
    {
        ServicePackageRate::create([
            'service_package_id' => $this->package->id,
            'unit_price'         => 150000,
            'effective_from'     => '2026-01-01',
        ]);
        ServicePackageRate::create([
            'service_package_id' => $this->package->id,
            'unit_price'         => 999999,
            'effective_from'     => '2099-01-01',
        ]);

        $resolved = $this->resolve('2026-06-01');

        $this->assertEquals(150000, $resolved->unit_price);
    }

    public function test_changing_price_does_not_overwrite_historical_row(): void
    {
        $original = ServicePackageRate::create([
            'service_package_id' => $this->package->id,
            'unit_price'         => 150000,
            'effective_from'     => '2026-01-01',
        ]);

        ServicePackageRate::create([
            'service_package_id' => $this->package->id,
            'unit_price'         => 180000,
            'effective_from'     => '2026-02-01',
        ]);

        $this->assertEquals(150000, $original->refresh()->unit_price);
        $this->assertSame(2, ServicePackageRate::where('service_package_id', $this->package->id)->count());
    }
}
