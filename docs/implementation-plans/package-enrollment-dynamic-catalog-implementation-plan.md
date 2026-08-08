# Package Enrollment Dynamic Catalog Integration — Implementation Plan

**Ngày:** 2026-08-08 · **Nhánh:** `phase-3` · Đã triển khai xong, tài liệu này phản ánh code thực tế đã viết (không phải kế hoạch lý thuyết).

Xem `docs/reviews/package-enrollment-dynamic-catalog-architecture-review.md` cho phân tích kiến trúc đầy đủ.

## Không có migration mới

Schema `service_packages`/`service_package_rates`/`booking_package_flags` hiện tại đủ cho toàn bộ scope. Đã xác nhận trước khi viết code (Section XVIII).

## Files thay đổi

| File | Thay đổi |
|---|---|
| `app/Exceptions/PackageNotEnrollableException.php` | **Mới.** Domain exception khi `enroll()` bị chặn (không tồn tại / không active / không bookable / chưa có giá). `render()` trả `withErrors(['package_key' => ...])`, theo đúng pattern `BreakfastAlreadyPostedException`/`PackageAlreadyPostedException` đã có. |
| `app/Services/PackageEnrollmentService.php` | Thêm `catalogForBooking(Booking): Collection<ServicePackage>` (active+bookable UNION đã từng đăng ký). `enroll()` re-validate active/bookable/currentRate từ DB, ném `PackageNotEnrollableException` nếu sai. `getEnrollmentSummary()` đổi từ lặp `ALLOWED_PACKAGES` sang dùng `catalogForBooking()`. `ALLOWED_PACKAGES`/3 hằng số giữ nguyên (Posting Job vẫn cần). |
| `app/Http/Controllers/Admin/PackageEnrollmentController.php` | `buildAvailablePackages()` viết lại hoàn toàn: nhận `Collection<ServicePackage>` (không còn `$packageMap` cứng), map sang shape JSON cho Vue gồm `code/label/description/charge_label/unit_label/quantity_mode/is_active/is_bookable/current_rate`. `enroll()`/`unenroll()` đổi validation từ `Rule::in(ALLOWED_PACKAGES)` sang `Rule::exists('service_packages','code')`. `enroll()` bắt `PackageNotEnrollableException`. Bỏ dependency `ServiceRateService` (không còn dùng). |
| `resources/js/Pages/Admin/Booking/Packages.vue` | `AvailablePackage` interface thêm `code/description/unit_label/quantity_mode/is_active/is_bookable`. `quantityEditable()` đổi từ so sánh `package_key` cứng sang đọc `pkg.quantity_mode === 'MANUAL_INPUT'`. Đơn vị số lượng hiển thị (`người`/`giường`) đổi sang `pkg.unit_label` động. Thêm hiển thị `description`. `packageError` computed đọc thêm `errors.package_key` (trước chỉ đọc `errors.package`) để hiển thị lỗi validate mới. |
| `tests/Feature/PackageEnrollmentServiceTest.php` | `setUp()` seed `ServicePackageSeeder` + tạo rate cho 3 mã legacy (seeder cố tình không tạo giá — enroll() giờ bắt buộc có giá). |
| `tests/Feature/PackageEnrollmentControllerTest.php` | Tương tự. `test_show_page_includes_current_rate_when_rate_exists` đổi từ tạo `ServiceRate` (bảng cũ) sang tạo `ServicePackageRate` (bảng mới) — vì nguồn giá hiển thị đã đổi. |
| `tests/Feature/BookingPackageEnrollmentTest.php` | Tương tự seed + rate setup. |
| `tests/Feature/PackageEnrollmentDynamicCatalogTest.php` | **Mới.** 12 test case theo đúng Section XX của Prompt (active+rate hiển thị, inactive/not-bookable/no-rate bị chặn, rate resolution, không tin giá client, historical enrollment sống sót sau deactivate, duplicate-enroll idempotent trên package động, hardcode-removal proof). |

## Không đổi (cố ý, có lý do)

- **`PackagePanel.vue`** (widget rút gọn trên `Bookings/Show.vue`) — không đụng. Đây là 1 shortcut riêng chỉ hỗ trợ Breakfast (hardcode `canEnrollBreakfast`), không phải nguồn catalog chính mà Prompt yêu cầu sửa (`/admin/bookings/{booking}/packages`). Sửa nó đòi hỏi đổi props đi qua `BookingController`/`Show.vue` — file vừa được hotfix production trong task trước, rủi ro không cần thiết cho 1 widget phụ. **Remaining gap** ghi ở báo cáo: sau task này, nếu 1 trong 3 gói legacy bị tắt qua Admin, nút "Đăng ký" ở panel này sẽ bắt đầu trả lỗi 422 (trước đây validation không kiểm tra DB nên luôn "thành công" bất kể trạng thái) — nhưng panel hiện không đọc `errors.package` cho field `package_key`, nên lỗi sẽ không hiển thị, nút chỉ "không có phản ứng". Đây là hành vi lộ ra do fix tính đúng đắn của validation, không phải do task này chủ động thay đổi UI panel.
- **`app/Services/Posting/*PostingJob.php`, `ServiceRateService`, bảng `service_rates`** — hoàn toàn không đụng (Night Audit/posting, ngoài phạm vi).
- **Tourist Tax / `city_tax_enabled`** — không đụng.
- **Migration/seeder** — không tạo mới, không chạy backfill data mới (3 gói legacy vẫn giữ nguyên trạng thái `is_active=false`/`is_bookable=false` hiện có trên máy dev — đây là data do Admin từng thao tác, không phải việc của task này để tự ý bật lại).

## Test/verification đã chạy

- 51 test targeted (Package Enrollment + Service Package Catalog) — PASS.
- 44 test Service Package Admin regression — PASS.
- 101 test Room Assignment M2/M3/M4 + 25 test BookingEngineFoundation — PASS (không đụng file nào trong scope này).
- 59 test Posting Job + Night Audit — PASS (xác nhận không phá vỡ luồng ghi phí legacy).
- `npm run build` — PASS.
- Full suite: 1191 passed / 24 failed — 24 failed là `BookingManagementUiTest` (11, baseline time-dependent đã biết từ trước) + `RoomAvailabilityCheckerTest` (13, xác nhận zero overlap file với scope task này qua `grep`, không liên quan Package Enrollment).

## Financial Posting Integration — KHÔNG nằm trong phạm vi task này (task riêng)

Task này (Catalog/Enrollment Integration) chỉ nối `/admin/bookings/{booking}/packages` vào `service_packages`/`service_package_rates` cho **hiển thị + đăng ký**. Nó **không** đổi bất kỳ dòng code nào trong `app/Services/Posting/*`, `NightAuditService`, `NightAuditPipeline` — đúng theo Section XVI/XVII của prompt gốc (cấm đụng Night Audit).

Điều tra riêng ở task kế tiếp ("Financial Posting / Billing Integration Closure") xác nhận **CASE B**: package động enroll được nhưng không bao giờ tạo `FolioEntry`, vì `NightAuditService` chỉ đăng ký đúng 3 `PostingJob` hardcode cho 3 mã legacy — không có job generic đọc `ServicePackage`. Chi tiết đầy đủ + support matrix: xem `docs/reviews/package-enrollment-dynamic-catalog-architecture-review.md` mục 7-8 và `docs/reports/package-enrollment-dynamic-financial-posting-gap.md`.

**File mới thêm ở task đó (điều tra, không đổi code triển khai, chỉ thêm test chứng minh gap):**
- `tests/Feature/PackageEnrollmentFinancialPostingGapTest.php` (ban đầu 5 test chứng minh gap — sau đó được thu gọn còn 3 test khi gap được đóng, xem bên dưới).

**Quyết định tại thời điểm đó:** không tự implement generic Posting Job — đây là quyết định accounting semantics chưa từng được Product Owner/kiến trúc gốc quyết định rõ, chính là Milestone 4 đã được `dynamic-service-package-admin-architecture-review.md` xác định từ trước là điều kiện bắt buộc, chưa triển khai.

## Financial Posting Integration — ĐÃ TRIỂN KHAI (task "Dynamic Service Package Generic Financial Posting — Active Pilot Implementation")

Sau khi Product Owner/ChatGPT chốt accounting semantics cho Active Pilot (xem `docs/reports/package-enrollment-dynamic-financial-posting-gap.md` cho quyết định đầy đủ), gap ở trên được đóng **cho package động không thuộc 3 mã legacy**.

**Files thay đổi:**

| File | Thay đổi |
|---|---|
| `app/Services/Posting/ServicePackagePostingJob.php` | **Mới.** Generic `PostingJob`: discover mọi `BookingPackageFlag` không phải 1 trong 3 mã legacy, resolve `ServicePackage`/`ServicePackageRate` qua `currentRate()`, áp `calculation_strategy` (2 strategy implemented), tạo `FolioEntry` với `charge_type=OTHER`, `posting_key` namespace riêng (`SVC_PKG_{code}_{stayId}_{date}`), description chứa tên+code package. |
| `app/Services/PackageEnrollmentService.php` | Thêm `static isLegacyDedicatedPostingPackage(string): bool` — single source of truth cho việc loại trừ 3 mã legacy khỏi job generic (dùng lại `ALLOWED_PACKAGES` có sẵn, không tạo danh sách thứ 2). |
| `app/Services/NightAuditService.php` | Inject `ServicePackagePostingJob`, đăng ký vào pipeline (giữa `ExtraBedJob` và `CityTaxJob`). Không sửa `NightAuditPipeline`. |
| `tests/Feature/ServicePackagePostingJobTest.php` | **Mới.** 19 test — đầy đủ 15 case bắt buộc (Prompt Section XV mục 1-15) + 4 test bổ sung (Tourist Tax, Folio total, Revenue, full-pipeline double-post check). |
| `tests/Feature/PackageEnrollmentFinancialPostingGapTest.php` | Thu gọn từ 5 → 3 test: xoá 2 test đã sai (khẳng định "0 FolioEntry" cho package động — nay không còn đúng), giữ 3 test về phần **vẫn deferred có chủ đích** (second source-of-truth cho legacy, historical correctness). |
| `docs/reviews/...architecture-review.md`, `docs/reports/...financial-posting-gap.md` | Cập nhật để phản ánh RESOLVED (dynamic) + 3 deferred item còn lại. |

**Không migration mới.** `ServicePackage`/`ServicePackageRate`/`FolioEntry`/`booking_package_flags` schema hiện tại đủ cho toàn bộ scope.

**Không đổi:** `BreakfastPostingJob`/`ExtraPersonPostingJob`/`ExtraBedPostingJob`/`CityTaxPostingJob`/`RoomChargePostingJob` (0 dòng sửa) — pricing behavior của 3 package legacy giữ nguyên 100%, đúng Prompt Section II.

**Test/verification đã chạy (task Active Pilot):**
- `ServicePackagePostingJobTest` — 19/19 PASS.
- `PackageEnrollmentFinancialPostingGapTest` (sau khi thu gọn) — 3/3 PASS.
- Toàn bộ Package Enrollment + Service Package + Posting Job + Folio + Revenue + Policy (256 test tổng hợp) — PASS.
- Room Assignment M2/M3/M4 (101) + BookingEngineFoundation (25) — PASS.
- Full suite: 1212 passed / 24 failed (24 failed = baseline pre-existing giống hệt trước, xác nhận qua đếm `⨯` chính xác — không có regression mới).
- `npm run build` — PASS (không đổi frontend task này).
