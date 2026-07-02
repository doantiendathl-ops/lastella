<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Models\ServiceRate;
use App\Services\ServiceRateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceRateVersioningTest extends TestCase
{
    use RefreshDatabase;

    private ServiceRateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ServiceRateService();
    }

    public function test_resolves_rate_active_on_business_date(): void
    {
        ServiceRate::create([
            'name'           => 'Giường phụ',
            'charge_type'    => 'EXTRA_BED',
            'unit_price'     => 300000,
            'effective_from' => '2026-01-01',
            'unit_label'     => 'đêm',
            'is_active'      => true,
        ]);

        $rate = $this->service->resolveFor(ChargeType::ExtraBed, Carbon::parse('2026-06-30'));

        $this->assertNotNull($rate);
        $this->assertEquals('300000.00', $rate->unit_price);
    }

    public function test_resolves_new_rate_when_effective_from_matches_date(): void
    {
        ServiceRate::create([
            'name'           => 'Giường phụ v1',
            'charge_type'    => 'EXTRA_BED',
            'unit_price'     => 300000,
            'effective_from' => '2026-01-01',
            'unit_label'     => 'đêm',
            'is_active'      => true,
        ]);
        ServiceRate::create([
            'name'           => 'Giường phụ v2',
            'charge_type'    => 'EXTRA_BED',
            'unit_price'     => 350000,
            'effective_from' => '2026-07-01',
            'unit_label'     => 'đêm',
            'is_active'      => true,
        ]);

        $rate = $this->service->resolveFor(ChargeType::ExtraBed, Carbon::parse('2026-07-01'));

        $this->assertEquals('350000.00', $rate->unit_price);
    }

    public function test_resolves_old_rate_when_business_date_is_before_new_effective_from(): void
    {
        ServiceRate::create([
            'name'           => 'v1',
            'charge_type'    => 'EXTRA_BED',
            'unit_price'     => 300000,
            'effective_from' => '2026-01-01',
            'unit_label'     => 'đêm',
            'is_active'      => true,
        ]);
        ServiceRate::create([
            'name'           => 'v2',
            'charge_type'    => 'EXTRA_BED',
            'unit_price'     => 350000,
            'effective_from' => '2026-07-01',
            'unit_label'     => 'đêm',
            'is_active'      => true,
        ]);

        $rate = $this->service->resolveFor(ChargeType::ExtraBed, Carbon::parse('2026-06-30'));

        $this->assertEquals('300000.00', $rate->unit_price);
    }

    public function test_returns_null_when_no_active_rate_exists_for_type(): void
    {
        $rate = $this->service->resolveFor(ChargeType::ExtraBed, Carbon::parse('2026-07-01'));

        $this->assertNull($rate);
    }

    public function test_inactive_rate_excluded_from_resolution(): void
    {
        ServiceRate::create([
            'name'           => 'Tắt',
            'charge_type'    => 'EXTRA_BED',
            'unit_price'     => 300000,
            'effective_from' => '2026-01-01',
            'unit_label'     => 'đêm',
            'is_active'      => false,
        ]);

        $rate = $this->service->resolveFor(ChargeType::ExtraBed, Carbon::parse('2026-07-01'));

        $this->assertNull($rate);
    }
}
