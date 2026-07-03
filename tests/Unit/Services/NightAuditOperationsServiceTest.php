<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ManualRunBlockedException;
use App\Exceptions\RunNotRetryableException;
use App\Models\NightAuditBookingLog;
use App\Models\NightAuditRun;
use App\Services\BusinessDateService;
use App\Services\HotelSettingsService;
use App\Services\NightAuditOperationsService;
use App\Services\NightAuditService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NightAuditOperationsServiceTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->businessDate = Carbon::parse('2026-07-02');
    }

    private function makeService(
        ?NightAuditService $nightAuditService = null,
        Carbon $currentBusinessDate = null,
        int $windowDays = 7,
    ): NightAuditOperationsService {
        $nightAuditService ??= $this->createMock(NightAuditService::class);

        $businessDateService = $this->createMock(BusinessDateService::class);
        $businessDateService->method('currentBusinessDate')
            ->willReturn($currentBusinessDate ?? $this->businessDate);

        $settings = $this->createMock(HotelSettingsService::class);
        $settings->method('getInt')
            ->with('audit_window_days', 7)
            ->willReturn($windowDays);

        return new NightAuditOperationsService($nightAuditService, $businessDateService, $settings);
    }

    public function test_trigger_throws_for_future_date(): void
    {
        $service = $this->makeService(currentBusinessDate: Carbon::parse('2026-07-02'));

        $this->expectException(ManualRunBlockedException::class);
        $this->expectExceptionMessageMatches('/tương lai/');

        $service->triggerManualRun(Carbon::parse('2026-07-03'));
    }

    public function test_trigger_throws_outside_window(): void
    {
        $service = $this->makeService(
            currentBusinessDate: Carbon::parse('2026-07-10'),
            windowDays: 3,
        );

        $this->expectException(ManualRunBlockedException::class);
        $this->expectExceptionMessageMatches('/phạm vi/');

        $service->triggerManualRun(Carbon::parse('2026-07-06')); // 4 days ago, window is 3
    }

    public function test_trigger_allows_date_at_window_boundary(): void
    {
        $nightAuditService = $this->createMock(NightAuditService::class);
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-07',
            'status'        => 'COMPLETED',
        ]);
        $nightAuditService->expects($this->never())->method('runForDate');

        // date exactly 3 days ago with window of 3 — should pass window but fail on COMPLETED
        $service = $this->makeService(
            nightAuditService: $nightAuditService,
            currentBusinessDate: Carbon::parse('2026-07-10'),
            windowDays: 3,
        );

        $this->expectException(ManualRunBlockedException::class);
        $this->expectExceptionMessageMatches('/đã hoàn tất/');

        $service->triggerManualRun(Carbon::parse('2026-07-07'));
    }

    public function test_trigger_throws_for_completed_run(): void
    {
        NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'COMPLETED',
        ]);

        $service = $this->makeService(currentBusinessDate: Carbon::parse('2026-07-02'));

        $this->expectException(ManualRunBlockedException::class);
        $this->expectExceptionMessageMatches('/đã hoàn tất/');

        $service->triggerManualRun(Carbon::parse('2026-07-01'));
    }

    public function test_trigger_throws_for_running_run(): void
    {
        NightAuditRun::factory()->create([
            'business_date' => '2026-07-02',
            'status'        => 'RUNNING',
        ]);

        $service = $this->makeService(currentBusinessDate: Carbon::parse('2026-07-02'));

        $this->expectException(ManualRunBlockedException::class);
        $this->expectExceptionMessageMatches('/đang chạy/');

        $service->triggerManualRun(Carbon::parse('2026-07-02'));
    }

    public function test_trigger_calls_run_for_date_on_valid_input(): void
    {
        $expectedDate = Carbon::parse('2026-07-02');
        $expectedRun  = NightAuditRun::factory()->make(['status' => 'COMPLETED']);

        $nightAuditService = $this->createMock(NightAuditService::class);
        $nightAuditService->expects($this->once())
            ->method('runForDate')
            ->with($this->callback(fn (Carbon $d): bool => $d->toDateString() === '2026-07-02'))
            ->willReturn($expectedRun);

        $service = $this->makeService(
            nightAuditService: $nightAuditService,
            currentBusinessDate: Carbon::parse('2026-07-02'),
        );

        $result = $service->triggerManualRun($expectedDate);

        $this->assertSame($expectedRun, $result);
    }

    public function test_trigger_allows_failed_run_to_be_re_triggered(): void
    {
        NightAuditRun::factory()->create([
            'business_date' => '2026-07-02',
            'status'        => 'FAILED',
        ]);

        $expectedRun = NightAuditRun::factory()->make(['status' => 'COMPLETED']);

        $nightAuditService = $this->createMock(NightAuditService::class);
        $nightAuditService->expects($this->once())
            ->method('runForDate')
            ->willReturn($expectedRun);

        $service = $this->makeService(
            nightAuditService: $nightAuditService,
            currentBusinessDate: Carbon::parse('2026-07-02'),
        );

        $result = $service->triggerManualRun(Carbon::parse('2026-07-02'));

        $this->assertSame($expectedRun, $result);
    }

    public function test_retry_throws_for_completed_run(): void
    {
        $run = NightAuditRun::factory()->create(['status' => 'COMPLETED']);

        $service = $this->makeService();

        $this->expectException(RunNotRetryableException::class);
        $this->expectExceptionMessageMatches('/FAILED/');

        $service->retryFailedRun($run);
    }

    public function test_retry_throws_for_pending_run(): void
    {
        $run = NightAuditRun::factory()->create(['status' => 'PENDING']);

        $service = $this->makeService();

        $this->expectException(RunNotRetryableException::class);

        $service->retryFailedRun($run);
    }

    public function test_retry_calls_run_for_date_on_failed_run(): void
    {
        $run = NightAuditRun::factory()->create([
            'business_date' => '2026-07-01',
            'status'        => 'FAILED',
        ]);

        $refreshedRun = NightAuditRun::factory()->make(['status' => 'COMPLETED']);

        $nightAuditService = $this->createMock(NightAuditService::class);
        $nightAuditService->expects($this->once())
            ->method('runForDate')
            ->with($this->callback(fn (Carbon $d): bool => $d->toDateString() === '2026-07-01'))
            ->willReturn($refreshedRun);

        $service = $this->makeService(nightAuditService: $nightAuditService);

        $result = $service->retryFailedRun($run);

        $this->assertSame($refreshedRun, $result);
    }

    public function test_get_run_summary_counts_results_correctly(): void
    {
        $run = NightAuditRun::factory()->create(['status' => 'COMPLETED']);

        NightAuditBookingLog::factory()->count(3)->create(['run_id' => $run->id, 'result' => 'POSTED']);
        NightAuditBookingLog::factory()->count(2)->create(['run_id' => $run->id, 'result' => 'ALREADY_POSTED']);
        NightAuditBookingLog::factory()->count(1)->create(['run_id' => $run->id, 'result' => 'SKIPPED']);
        NightAuditBookingLog::factory()->count(1)->create(['run_id' => $run->id, 'result' => 'FAILED']);

        $service = $this->makeService();
        $summary = $service->getRunSummary($run);

        $this->assertEquals(3, $summary['posted']);
        $this->assertEquals(2, $summary['already_posted']);
        $this->assertEquals(1, $summary['skipped']);
        $this->assertEquals(1, $summary['failed']);
        $this->assertEquals(7, $summary['total']);
    }

    public function test_get_booking_logs_returns_all_logs_unfiltered(): void
    {
        $run = NightAuditRun::factory()->create(['status' => 'COMPLETED']);
        NightAuditBookingLog::factory()->count(2)->create(['run_id' => $run->id, 'result' => 'POSTED']);
        NightAuditBookingLog::factory()->count(1)->create(['run_id' => $run->id, 'result' => 'SKIPPED']);

        $service = $this->makeService();
        $logs    = $service->getBookingLogs($run);

        $this->assertCount(3, $logs);
    }

    public function test_get_booking_logs_filters_by_result(): void
    {
        $run = NightAuditRun::factory()->create(['status' => 'FAILED']);
        NightAuditBookingLog::factory()->count(2)->create(['run_id' => $run->id, 'result' => 'POSTED']);
        NightAuditBookingLog::factory()->count(1)->create(['run_id' => $run->id, 'result' => 'FAILED']);

        $service = $this->makeService();
        $logs    = $service->getBookingLogs($run, 'FAILED');

        $this->assertCount(1, $logs);
        $this->assertEquals('FAILED', $logs->first()->result);
    }

    public function test_get_booking_logs_returns_empty_when_no_logs(): void
    {
        $run  = NightAuditRun::factory()->create(['status' => 'PENDING']);
        $logs = $this->makeService()->getBookingLogs($run);

        $this->assertCount(0, $logs);
    }
}
