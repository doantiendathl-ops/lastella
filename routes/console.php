<?php

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('about:pms', function (ClosureCommand $command): void {
    $command->info('Lastella PMS core system');
})->purpose('Display PMS application information');

/**
 * User request (2026-08-22 chat) — Phần 1 của kế hoạch tự động hóa Night
 * Audit ("Bổ sung thêm phần rà soát lại doanh thu" -> phát hiện Night Audit
 * chưa từng chạy trên production, xem docs/reports/revenue-audit-night-
 * audit-never-run-2026-08-22.md).
 *
 * Chạy đúng ĐÚNG lệnh thủ công vốn đã có (App\Console\Commands\RunNightAudit
 * / audit:night-audit) — không viết logic Night Audit mới, không phím tắt
 * nào bỏ qua NightAuditService::runForDate()'s idempotency (đã hoàn tất
 * cho 1 ngày thì gọi lại chỉ trả về nguyên trạng, không audit lại — an
 * toàn khi lịch chạy trùng giờ với 1 lần chạy tay).
 *
 * KHÔNG truyền --date: mặc định audit đúng
 * BusinessDateService::currentBusinessDate() tại THỜI ĐIỂM job chạy —
 * nhờ business_date_offset_hours (mặc định 6h) mà 00:00 giờ thực vẫn còn
 * TRONG ngày kế toán hôm trước (ngày kế toán chỉ sang ngày mới lúc 06:00
 * giờ thực), nên chạy đúng 00:00 sẽ tự động audit đúng "ngày vừa kết
 * thúc" — không cần tính lệch ngày thủ công ở đây.
 *
 * ->environments(['production']): không chạy ngầm trên máy dev cục bộ nếu
 * ai đó lỡ bật `schedule:work`/`schedule:run` — tránh audit nhầm dữ liệu
 * test. LƯU Ý VẬN HÀNH: dòng lịch này chỉ có tác dụng nếu máy chủ THẬT
 * SỰ có 1 cron hệ điều hành gọi `php artisan schedule:run` mỗi phút (xem
 * docs/yeucauchomaychu.md) — bản thân Laravel Scheduler không tự chạy nền.
 *
 * Vẫn giữ nguyên nút "Kích hoạt Night Audit" thủ công trên UI + cơ chế
 * bắt kịp audit_window_days làm lưới an toàn nếu lịch tự động lỡ không
 * chạy được (server sập, deploy lỗi...) — không gỡ bỏ đường thủ công.
 */
Schedule::command('audit:night-audit')
    ->dailyAt('00:00')
    ->environments(['production'])
    ->appendOutputTo(storage_path('logs/night-audit-schedule.log'))
    ->onFailure(function (): void {
        Log::error('Scheduled Night Audit (audit:night-audit) thất bại — cần kiểm tra thủ công.');
    });
