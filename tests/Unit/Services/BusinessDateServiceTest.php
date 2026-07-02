<?php

namespace Tests\Unit\Services;

use App\Services\BusinessDateService;
use App\Services\HotelSettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessDateServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(int $offsetHours = 6): BusinessDateService
    {
        $settings = $this->createMock(HotelSettingsService::class);
        $settings->method('getInt')
            ->with('business_date_offset_hours', 6)
            ->willReturn($offsetHours);

        return new BusinessDateService($settings);
    }

    public function test_business_date_at_05_59_with_offset_6_is_yesterday(): void
    {
        $realTime = Carbon::create(2026, 7, 2, 5, 59, 0);

        $businessDate = $this->makeService(6)->businessDateFor($realTime);

        $this->assertEquals('2026-07-01', $businessDate->toDateString());
    }

    public function test_business_date_at_06_00_with_offset_6_is_today(): void
    {
        $realTime = Carbon::create(2026, 7, 2, 6, 0, 0);

        $businessDate = $this->makeService(6)->businessDateFor($realTime);

        $this->assertEquals('2026-07-02', $businessDate->toDateString());
    }

    public function test_business_date_at_02_30_with_offset_6_is_yesterday(): void
    {
        $realTime = Carbon::create(2026, 7, 2, 2, 30, 0);

        $businessDate = $this->makeService(6)->businessDateFor($realTime);

        $this->assertEquals('2026-07-01', $businessDate->toDateString());
    }

    public function test_business_date_at_23_30_with_offset_6_is_same_day(): void
    {
        $realTime = Carbon::create(2026, 7, 1, 23, 30, 0);

        $businessDate = $this->makeService(6)->businessDateFor($realTime);

        $this->assertEquals('2026-07-01', $businessDate->toDateString());
    }

    public function test_business_date_reads_offset_from_settings(): void
    {
        // With offset = 0, business date never trails the calendar date
        $realTime = Carbon::create(2026, 7, 2, 1, 0, 0);

        $businessDate = $this->makeService(0)->businessDateFor($realTime);

        $this->assertEquals('2026-07-02', $businessDate->toDateString());
    }

    public function test_current_business_date_returns_carbon_instance(): void
    {
        $service = $this->makeService(6);
        $result  = $service->currentBusinessDate();

        $this->assertInstanceOf(Carbon::class, $result);
    }
}
