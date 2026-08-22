<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * User request (2026-08-22 chat) — Phần 1 của kế hoạch tự động hóa Night
 * Audit: xác nhận routes/console.php thực sự đăng ký lịch chạy
 * `audit:night-audit` mỗi ngày lúc 00:00, chỉ trên môi trường production.
 * Không kiểm tra hành vi chạy thực tế (đã có RunNightAudit/NightAuditService
 * test riêng) — chỉ xác nhận LỊCH được đăng ký đúng, vì đây là phần dễ gõ
 * sai cron expression / quên giới hạn môi trường nhất mà không test nào
 * khác bắt được.
 */
class NightAuditScheduleTest extends TestCase
{
    public function test_night_audit_is_scheduled_daily_at_midnight(): void
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'audit:night-audit'));

        $this->assertNotNull($event, 'audit:night-audit chưa được đăng ký trong routes/console.php.');
        $this->assertSame('0 0 * * *', $event->expression, 'Phải chạy đúng 00:00 hàng ngày.');
    }

    public function test_night_audit_schedule_is_restricted_to_production(): void
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'audit:night-audit'));

        $this->assertNotNull($event);
        $this->assertContains('production', $event->environments, 'Không được chạy ngầm trên local/testing.');
        $this->assertFalse($event->runsInEnvironment('testing'), 'Đang chạy test ở môi trường "testing" — lịch KHÔNG được phép chạy ở đây.');
        $this->assertTrue($event->runsInEnvironment('production'));
    }
}
