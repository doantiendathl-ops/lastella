# Package Enrollment Dynamic Catalog Integration — Implementation Report

**Ngày:** 2026-08-08 · **Nhánh:** `phase-3` · **Không commit, không push, không deploy** (theo phạm vi task).

Đóng gap: `docs/reports/package-enrollment-hardcoded-catalog-gap.md`.
Kiến trúc: `docs/reviews/package-enrollment-dynamic-catalog-architecture-review.md`.
Chi tiết code: `docs/implementation-plans/package-enrollment-dynamic-catalog-implementation-plan.md`.

## 1. Root Cause

`PackageEnrollmentController::buildAvailablePackages()` xây catalog từ mảng `$packageMap` viết cứng (3 key cố định), và `PackageEnrollmentController::enroll()`/`unenroll()` validate `package_key` bằng `Rule::in(PackageEnrollmentService::ALLOWED_PACKAGES)` — một hằng số PHP, không truy vấn `service_packages`. Do đó `is_active`/`is_bookable` đặt trong `/admin/service-packages` không có tác dụng gì trên `/admin/bookings/{booking}/packages`, và package tạo mới trong Admin (ví dụ QA PILOT PACKAGE) không bao giờ xuất hiện được ở đó.

## 2. Fix

`PackageEnrollmentService::catalogForBooking()` (method mới) truy vấn `ServicePackage::active()->bookable()` UNION mọi `package_key` booking đã từng đăng ký, dùng chung cho cả catalog hiển thị và trạng thái đăng ký. Giá hiển thị chuyển từ `service_rates` (cũ) sang `ServicePackage::currentRate()` → `service_package_rates` (đúng nguồn Milestone 1/2 đã xây). `enroll()` re-validate active/bookable/rate ngay trong service, không chỉ ở tầng validation controller. Chi tiết đầy đủ: xem Implementation Plan.

## 3. Exact Bug Proof — Before / After

**BEFORE (xác nhận bằng code đọc trực tiếp trước khi sửa, `git show` + đọc file tại HEAD `58ad07d`):**
`PackageEnrollmentController::buildAvailablePackages()` lặp qua đúng 3 key trong `$packageMap` cứng — không có bất kỳ câu lệnh nào query `ServicePackage`. Một package tạo qua `/admin/service-packages` (ví dụ `QA_PILOT_PACKAGE`) — dù `is_active=true`, `is_bookable=true`, có `service_package_rates` — **không có đường nào** để lọt vào response `available_packages`, vì controller không hề đọc bảng đó.

**AFTER (xác nhận bằng test tự động, `PackageEnrollmentDynamicCatalogTest`):**
`test_active_bookable_package_with_rate_appears_in_booking_catalog` tạo một `ServicePackage` với `code=QA_PILOT_PACKAGE`, gán rate `50000`, gọi `GET /admin/bookings/{booking}/packages`, và assert `available_packages` chứa `key === 'QA_PILOT_PACKAGE'` — **PASS**. `test_package_price_shown_on_booking_page_matches_admin_catalog_rate` xác nhận giá hiển thị đúng bằng giá đã tạo trong `service_package_rates` (không phải giá cứng) — **PASS**. Đây là bằng chứng end-to-end mức HTTP+DB thật (RefreshDatabase, request thật qua route thật), không phải mock.

**Browser-level (trực quan, real click-through):** xem mục 6 — **PENDING**, phiên trình duyệt hết hạn giữa task, chưa hoàn tất (không tự đăng nhập thay Product Owner).

## 4. Hardcode Removal Proof

```
$ grep -n "BREAKFAST_PER_NIGHT.*=>.*\[" app/ resources/js/ -r     → (không có kết quả)
$ grep -n "Rule::in(PackageEnrollmentService" app/ -r             → (không có kết quả)
$ grep -rl "ALLOWED_PACKAGES" app/ resources/js/ tests/
    app/Services/PackageEnrollmentService.php   (định nghĩa hằng số — PostingJob vẫn cần, xem mục 5)
    tests/Feature/*.php                          (test file, không phải execution path)
```

`$packageMap` (mảng cứng cũ trong `PackageEnrollmentController`) đã bị xoá hoàn toàn khỏi code — không còn tồn tại ở bất kỳ file nào. `Rule::in(ALLOWED_PACKAGES)` đã bị xoá khỏi cả `enroll()` và `unenroll()`, thay bằng `Rule::exists('service_packages', 'code')`.

**Kết luận: BOOKING PACKAGE CATALOG SOURCE OF TRUTH = `service_packages`.** Không có execution path nào của Booking Package Enrollment (hiển thị catalog, validate enroll, validate unenroll, tính giá hiển thị) còn đọc hardcoded catalog.

## 5. Legacy Constants Giữ Lại — Có Lý Do

`PackageEnrollmentService::ALLOWED_PACKAGES`/3 hằng số (`BREAKFAST_PER_NIGHT`, `EXTRA_PERSON_PER_NIGHT`, `EXTRA_BED_PER_NIGHT`) **vẫn giữ nguyên trong code**, nhưng không còn là catalog source of truth — lý do duy nhất còn lại: `BreakfastPostingJob`/`ExtraPersonPostingJob`/`ExtraBedPostingJob` (`app/Services/Posting/*.php`, không đụng trong task này) gọi `PackageEnrollmentService::isEnrolled($booking, self::BREAKFAST_PER_NIGHT)` bằng đúng literal string này để quyết định ghi phí Night Audit — dependency thật, ngoài phạm vi sửa (Section XVI/XVII cấm đụng Night Audit).

## 6. Browser QA

**Chưa hoàn tất — phiên trình duyệt hết hạn giữa task** (redirect về `/login` khi mở `/admin/service-packages`). Theo nguyên tắc an toàn tuyệt đối (không tự nhập mật khẩu thay người dùng dù trường đã được trình duyệt tự điền), không tự đăng nhập. Cần Product Owner đăng nhập lại Local browser để hoàn tất 17 bước walkthrough (Section XXIII của Prompt).

Bằng chứng mức HTTP+DB (mục 3) đã xác nhận chắc chắn phần backend/logic đúng. Phần còn lại thuần là xác nhận trực quan (hiển thị đúng trên UI thật, không lỗi console) — chưa thực hiện, không tự ghi PASS giả.

## 7. Regression Tests

| Suite | Kết quả |
|---|---|
| Package Enrollment (Service + Controller + Booking + Dynamic Catalog) | 51/51 PASS |
| Service Package Catalog (Milestone 1-2 domain) | 16/16 PASS |
| Service Package Admin + Rate Admin + Rate Versioning | 44/44 PASS |
| Room Assignment M2 (`RoomAssignmentAtomicMappingTest`) | 27/27 PASS |
| Room Assignment M3 (`RoomAssignmentFromRoomBoardTest`) | 29/29 PASS |
| Room Assignment M4 (`BulkRoomReleaseTest`) | 45/45 PASS |
| `BookingEngineFoundationTest` | 25/25 PASS |
| Posting Job (Breakfast/ExtraBed/ExtraPerson) + Night Audit Operations | 59/59 PASS |
| `npm run build` | PASS (2383 modules, 8.14s, 0 lỗi) |
| Full suite (`php artisan test`) | 1191 passed, 24 failed |

**24 failed — pre-existing, không liên quan task này:**
- `BookingManagementUiTest` (11 failed) — baseline time-dependent đã được ghi nhận từ trước trong hotfix report cùng phiên này (`docs/reports/booking-show-vue-template-syntax-production-blocker-hotfix.md` §7), không đổi.
- `RoomAvailabilityCheckerTest` (13 failed) — xác nhận qua `grep -rn "ServicePackage|PackageEnrollment|BookingPackageFlag" tests/Feature/RoomAvailabilityCheckerTest.php` → không có kết quả nào; file này zero overlap với 8 file task này sửa. Nằm ngoài phạm vi cho phép sửa (Section XVII cấm đụng Room Availability).

## 8. Files Changed

Backend: `app/Exceptions/PackageNotEnrollableException.php` (mới), `app/Services/PackageEnrollmentService.php`, `app/Http/Controllers/Admin/PackageEnrollmentController.php`.
Frontend: `resources/js/Pages/Admin/Booking/Packages.vue`.
Tests: `tests/Feature/PackageEnrollmentServiceTest.php`, `tests/Feature/PackageEnrollmentControllerTest.php`, `tests/Feature/BookingPackageEnrollmentTest.php`, `tests/Feature/PackageEnrollmentDynamicCatalogTest.php` (mới).
Docs: 3 file này.

Không migration. Không seeder mới. Không đổi `PackagePanel.vue` (lý do: mục "Không đổi" trong Implementation Plan). Không đổi Posting Job/Night Audit/Tourist Tax/Room Demand-Room Board/Booking core/Folio/Revenue.

## 9. Remaining Gap (ghi nhận, không tự mở rộng phạm vi để vá)

1. **Posting Job chưa generic hoá** — package mới tạo qua Admin (không phải 1 trong 3 mã legacy) chưa có cơ chế tự động ghi phí Night Audit. Đã biết từ gap report gốc, ngoài phạm vi task này.
2. **2 nguồn giá song song cho 3 gói legacy** — giá hiển thị lúc đăng ký (mới, từ `service_package_rates`) và giá thực tế ghi phí đêm (không đổi, từ `service_rates`) là 2 bảng khác nhau. Xem Architecture Review mục 4.
3. **`PackagePanel.vue`** (widget rút gọn trên Booking Show) chưa đọc trạng thái active/bookable động — nút "Đăng ký" nhanh cho Breakfast sẽ bắt đầu báo lỗi (không hiển thị được, vì panel chỉ đọc `errors.package` không phải `errors.package_key`) nếu Breakfast bị tắt trong Admin. Widget phụ, không phải nguồn catalog chính Prompt yêu cầu sửa.
4. **3 gói legacy hiện `is_active=false`/`is_bookable=false`, 0 rate trên máy dev** — xác nhận qua tinker trước khi sửa code. Sau task này, chúng sẽ **không xuất hiện** trong catalog đăng ký mới nữa (đúng theo thiết kế) — chỉ hiển thị nếu đã từng được đăng ký trước đó (booking 461 có 1 flag `BREAKFAST_PER_NIGHT` lịch sử — vẫn hiển thị đúng). Đây là dữ liệu do Admin từng thao tác, task này không tự ý bật lại.

## 10. Financial Posting Integration

**CASE B CONFIRMED, sau đó RESOLVED cho package động (Active Pilot).** Điều tra đầu tiên (task "Financial Posting / Billing Integration Closure") xác nhận Night Audit → FolioEntry hoàn toàn không đọc `ServicePackage`/`ServicePackageRate`. Task tiếp theo ("Dynamic Service Package Generic Financial Posting — Active Pilot Implementation"), sau khi Product Owner/ChatGPT chốt accounting semantics, đã đóng gap này bằng `App\Services\Posting\ServicePackagePostingJob` (mới) đăng ký vào `NightAuditService`. Chi tiết đầy đủ + implementation detail + test coverage: `docs/reports/package-enrollment-dynamic-financial-posting-gap.md`.

**Kết quả hiện tại**: `QA_PILOT_PACKAGE` (và mọi package động khác dùng 1 trong 2 `calculation_strategy` implemented) enroll được VÀ post được `FolioEntry` thật qua Night Audit, giá lấy từ `service_package_rates`, `charge_type=OTHER`, mô tả chứa tên+code để truy vết, không double-post với 3 package legacy, không tạo charge khi thiếu rate, historical amount không đổi khi rate sau đó thay đổi. 19/19 test PASS (`tests/Feature/ServicePackagePostingJobTest.php`).

## 11. Dynamic Package End-to-End Support Matrix

Xem `docs/reviews/package-enrollment-dynamic-catalog-architecture-review.md` mục 8 (bảng đầy đủ, cập nhật sau Active Pilot — 3 package legacy + `QA_PILOT_PACKAGE` + package động tuỳ ý, cả 2 dòng cuối nay là **"Có — RESOLVED"**).

## 12. Regression (task Financial Posting Active Pilot)

| Suite | Kết quả |
|---|---|
| `ServicePackagePostingJobTest` (mới) | 19/19 PASS |
| `PackageEnrollmentFinancialPostingGapTest` (thu gọn, 3 deferred-gap test còn lại) | 3/3 PASS |
| CityTax Posting Job (Tourist Tax) | 9/9 PASS |
| Folio (CRUD + UI) | PASS |
| Revenue Report | 17/17 PASS |
| Folio/FolioEntry Policy | PASS |
| Toàn bộ suite Package Enrollment + Service Package + Posting Job + Night Audit + Folio + Revenue + Policy | 256/256 PASS |
| Room Assignment M2/M3/M4 + BookingEngineFoundation | 126/126 PASS |
| Full suite (`php artisan test`) | 1212 passed, 24 failed — 24 = baseline pre-existing y hệt trước (đếm `⨯` xác nhận chính xác, không regression mới) |
| `npm run build` | PASS |

## 13. Deferred Items (không tuyên bố đã giải quyết)

1. **Legacy price-source convergence** — DEFERRED. 3 package legacy vẫn post giá từ `service_rates`, không đổi.
2. **Tax/GL accounting integration** — DEFERRED ENHANCEMENT. `ServicePackageRate.unit_price` là final charge amount cho phase Active Pilot, không có accounting engine mới.
3. **Package-level Revenue granularity** — DEFERRED. Mọi charge package động gộp dưới `charge_type=OTHER`.

## 14. Browser QA Status

**DEFERRED — CLAUDE-CONTROLLED BROWSER AUTHENTICATION LIMITATION.** Không phải FAIL, không phải PASS giả.

Nhiều lần thử (tab hiện có, tab mới, sau khi Product Owner xác nhận đã đăng nhập thủ công) đều cho kết quả: `/admin/service-packages` redirect về `/login` trong Chrome instance mà Claude-in-Chrome điều khiển. Nguyên nhân xác định: session/cookie Product Owner đăng nhập thủ công không được chia sẻ với browser automation session/profile mà công cụ này điều khiển — đây là **giới hạn công cụ (tool limitation)**, không phải defect của ứng dụng. Không có credential nào được tự nhập/submit bởi Claude trong suốt quá trình.

Theo quyết định Active Pilot (ChatGPT/Product Owner), toàn bộ evidence backend/financial/build (mục 10-12) được coi là đủ để tiến tới commit mà không chặn bởi Browser QA. **Product Owner Manual Pilot QA required after deployment** — xác nhận trực quan (Admin catalog, Booking Enrollment, enroll qua UI, Night Audit trigger, Folio, Revenue, console/network) vẫn cần được Product Owner tự thực hiện trên máy dev, độc lập với việc commit này.

## 15. Readiness

**Catalog/Enrollment: implementation hoàn chỉnh, đã test.**
**Financial Posting: RESOLVED cho package động (Active Pilot) — 3 item deliberately deferred (mục 13), không phải oversight.**
**Browser QA: DEFERRED — tool authentication limitation, không phải kết quả kỹ thuật của tính năng.**

Xem phản hồi cuối trong hội thoại cho trạng thái chính xác.
