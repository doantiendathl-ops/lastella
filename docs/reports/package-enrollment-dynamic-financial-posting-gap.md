# Dynamic Package Financial Posting Gap

**Status:** RESOLVED FOR DYNAMIC NON-LEGACY PACKAGES (Active Pilot) — 3 deferred items remain open, listed at the bottom.
**Ngày:** 2026-08-08 (điều tra) → 2026-08-08 (đóng gap, Active Pilot) · **Nhánh:** `phase-3`
**Phát hiện trong:** ChatGPT review của task "Package Enrollment Dynamic Catalog Integration" — câu hỏi "package động có đi xuyên suốt tới charge/Folio/Night Audit/accounting không?"
**Đóng gap trong:** task "Dynamic Service Package Generic Financial Posting — Active Pilot Implementation", cùng nhánh, chưa commit.

## Resolution Summary (đọc mục này trước)

`App\Services\Posting\ServicePackagePostingJob` (mới) đăng ký vào `NightAuditService` cùng 5 job hiện có. Nó tự động post `FolioEntry` cho MỌI package động (không phải 1 trong 3 mã legacy) đang được enroll, đọc giá từ `service_package_rates` qua `ServicePackage::currentRate()` (resolver có sẵn, tái dùng nguyên), áp dụng đúng `calculation_strategy` của package (`ONCE_PER_STAY_PER_NIGHT` hoặc `MANUAL_QUANTITY_PER_NIGHT` — 2 chiến lược "implemented" duy nhất), dùng `charge_type = OTHER`, mô tả chứa tên + code package để truy vết. 19/19 test trong `tests/Feature/ServicePackagePostingJobTest.php` PASS — xem chi tiết mục "Resolution — Implementation Detail" bên dưới.

**KHÔNG resolved** (deliberately deferred, xem mục cuối): legacy price-source convergence (3 package legacy vẫn dùng `service_rates`, không đổi), tax/GL accounting integration, package-level Revenue granularity.

**Browser QA:** DEFERRED — CLAUDE-CONTROLLED BROWSER AUTHENTICATION LIMITATION (không phải FAIL, không phải PASS giả). Product Owner Manual Pilot QA required sau khi commit này được deploy tới môi trường có thể thao tác thủ công.

---

## Phần lịch sử: Điều tra gốc xác nhận CASE B (trước khi đóng gap)

## Kết luận: CASE B — CONFIRMED

Dynamic catalog + enrollment (task trước) hoạt động đúng: một `ServicePackage` mới (ví dụ `QA_PILOT_PACKAGE`) xuất hiện trên `/admin/bookings/{booking}/packages`, enroll được, `booking_package_flags` ghi đúng. **Nhưng financial posting (Night Audit → FolioEntry) vẫn chỉ hỗ trợ đúng 3 package legacy** (`BREAKFAST_PER_NIGHT`, `EXTRA_PERSON_PER_NIGHT`, `EXTRA_BED_PER_NIGHT`), qua 3 class `PostingJob` viết tay, đăng ký cứng theo tên class trong `NightAuditService`:

```php
// app/Services/NightAuditService.php
public function __construct(
    private readonly NightAuditPipeline $pipeline,
    private readonly RoomChargePostingJob $roomChargeJob,
    private readonly BreakfastPostingJob $breakfastJob,
    private readonly ExtraPersonPostingJob $extraPersonJob,
    private readonly ExtraBedPostingJob $extraBedJob,
    private readonly CityTaxPostingJob $cityTaxJob,
    ...
) {}
```

Không có `ServicePackagePostingJob` generic nào. `NightAuditPipeline::run()` chỉ chạy đúng 5 job đã đăng ký ở trên cho mọi stay đang check-in — một package mới hoàn toàn vô hình với pipeline này, bất kể `calculation_strategy`/`quantity_mode`/`posting_frequency` được cấu hình gì trong Admin.

**Bằng chứng test tự động** (`tests/Feature/PackageEnrollmentFinancialPostingGapTest.php`):
- `test_qa_pilot_package_enrolls_but_night_audit_never_posts_a_charge_for_it` — enroll `QA_PILOT_PACKAGE` (active, bookable, có `service_package_rates`), chạy `NightAuditService::runForDate()` thật, xác nhận **0 FolioEntry**, **0 NightAuditBookingLog** nhắc tới package này. **PASS.**
- `test_arbitrary_dynamic_package_with_manual_quantity_strategy_also_never_posts` — lặp lại với `calculation_strategy = MANUAL_QUANTITY_PER_NIGHT` (chiến lược thứ 2 trong 2 chiến lược "implemented") — cùng kết quả. Gap không phụ thuộc `calculation_strategy` nào — nó là ở tầng **wiring theo package_key cụ thể**, không phải theo strategy.

## Second Source-of-Truth Gap — CONFIRMED

`service_package_rates` (đọc bởi Admin catalog + Booking Enrollment display, từ task trước) và `service_rates` (đọc bởi cả 3 Posting Job legacy để tính tiền thật) là **2 bảng hoàn toàn độc lập** cho cùng 1 package legacy.

Bằng chứng: `test_legacy_package_posting_amount_comes_from_service_rates_not_service_package_rates` — set giá `EXTRA_PERSON_PER_NIGHT` trong `service_package_rates` = 999999, giá trong `service_rates` = 200000. FolioEntry được Night Audit tạo ra dùng **200000** (từ `service_rates`), không phải 999999. **PASS.**

Hệ quả thực tế: nếu Admin đổi giá 1 trong 3 gói legacy tại `/admin/service-packages` (bảng `service_package_rates`), **Night Audit vẫn tính tiền theo giá cũ ở `service_rates`** — 2 màn hình quản trị giá cho "cùng 1 gói" không đồng bộ.

## Price Semantics = C (Effective rate tại posting date)

Đọc `ServiceRateService::resolveFor()` (docblock ghi rõ "ADR-66: Rate resolution uses business date...") và `ServicePackage::currentRate()` (cùng semantics: `effective_from <= $businessDate`, mới nhất theo `effective_from` rồi theo `id` thắng khi hoà) — cả 2 hệ giá đều là **effective-dated rate resolution theo business date được truyền vào**, không phải snapshot tại thời điểm enroll. Với 3 Posting Job legacy, `$context->businessDate` chính là ngày Night Audit đang xử lý (= ngày dịch vụ/đêm đó) — nên trong kiến trúc hiện tại, "effective tại posting date" và "effective tại service date" trùng nhau (mỗi đêm được Night Audit tính riêng cho đúng business date của đêm đó). `booking_package_flags` **chưa từng và không** có cột giá — xác nhận kiến trúc gốc **không chủ đích snapshot tại enrollment** (khớp với gợi ý B/C, loại trừ A).

Test khoá hành vi: `test_rate_change_after_posting_does_not_alter_already_posted_folio_entry` — đổi `service_rates` sau khi đã post, `FolioEntry` đã tạo **không đổi** (`unit_price`/`amount` giữ nguyên `200000.00`). **PASS.** `test_re_running_night_audit_for_the_same_completed_business_date_does_not_duplicate` — chạy `runForDate()` 2 lần cho cùng ngày đã `COMPLETED`, trả về cùng 1 `NightAuditRun`, không tạo `FolioEntry` trùng. **PASS.**

## Tại sao KHÔNG tự implement lúc điều tra ban đầu (đã giải quyết ở task Active Pilot)

Lý do gốc (không tự quyết định accounting semantics) đã được Product Owner/ChatGPT chốt rõ cho phase **Active Pilot** — xem "Resolution — Implementation Detail" bên dưới cho cách từng điểm được xử lý trong phạm vi được duyệt.

## Resolution — Implementation Detail (Active Pilot)

**File:** `app/Services/Posting/ServicePackagePostingJob.php` (mới), đăng ký trong `app/Services/NightAuditService.php`.

1. **Legacy exclusion**: `PackageEnrollmentService::isLegacyDedicatedPostingPackage(string $packageKey): bool` (mới, static, single source of truth = `ALLOWED_PACKAGES` constant có sẵn) — generic job loại bỏ mọi flag có `package_key` nằm trong danh sách này trước khi xử lý. Test khoá zero-double-post: `test_generic_job_skips_legacy_breakfast_dedicated_job_still_posts_once` (+ tương tự cho ExtraPerson/ExtraBed) và `test_full_night_audit_run_posts_legacy_and_dynamic_packages_without_double_posting` — chạy Night Audit thật với cả legacy lẫn dynamic cùng enroll, xác nhận đúng số lượng charge mỗi loại, không nhân đôi.
2. **Pricing source**: `ServicePackage::currentRate($businessDate)` — reuse nguyên resolver có sẵn, không copy-paste SQL. `service_rates` không được job này đọc bao giờ — xác nhận bởi `test_dynamic_package_price_comes_from_service_package_rates_not_service_rates`.
3. **charge_type = OTHER** — dùng case có sẵn trong `ChargeType` enum, không tạo enum mới.
4. **Traceability**: `FolioEntry.description = "{name} ({code}) đêm {ngày}"` — không có cột reference riêng trên `folio_entries`, nên identity đi qua description theo đúng khả năng schema hiện tại (không migration).
5. **Calculation strategy**: cả 2 strategy "implemented" (`ONCE_PER_STAY_PER_NIGHT`, `MANUAL_QUANTITY_PER_NIGHT`) được job đọc trực tiếp từ `ServicePackage.calculation_strategy` — không hardcode theo tên/code package. Test riêng cho từng strategy.
6. **Quantity**: dùng nguyên `booking_package_flags.value`, không thêm cột. Malformed value (`'0'`, `'-5'`, `'not-a-number'`, `''`) được `max(1, intval(...))` chặn về tối thiểu 1 — giống hệt cách `ExtraPersonPostingJob`/`ExtraBedPostingJob` đã làm từ trước, không phát minh rule mới.
7. **No-rate behavior**: safe skip (`PostingResult::skipped(...)`), không bao giờ tạo charge 0/null — cùng pattern 3 job legacy.
8. **Inactive / no-new-enrollment**: KHÔNG chặn posting cho enrollment đã tồn tại — chỉ `PackageEnrollmentService::enroll()` (đăng ký mới) mới kiểm tra `is_active`/`is_bookable`. Đúng theo historical-integrity rule đã có (Section XII của Prompt, khớp thiết kế `catalogForBooking()` union từ task trước).
9. **Idempotency**: `posting_key = SVC_PKG_{code}_{stay_id}_{date}` — namespace riêng (`SVC_PKG_` prefix), không đụng key legacy; lock + exists-check trong transaction giống hệt 3 job cũ.
10. **Tax/GL**: KHÔNG đụng — `ServicePackageRate.unit_price`/giá hiệu lực được coi là **FINAL FOLIO CHARGE AMOUNT** cho phase này, đúng quyết định Active Pilot.

## Test Coverage (19/19 PASS — `tests/Feature/ServicePackagePostingJobTest.php`)

Đầy đủ 15/15 test case bắt buộc từ Prompt Section XV (mục 1-15) + thêm test Tourist Tax/Folio/Revenue không regression (mục 16-18). Chi tiết: xem file test trực tiếp.

## 3 Deferred Items — CHƯA giải quyết (không tuyên bố đã xong)

1. **Legacy price-source convergence**: 3 package legacy (Breakfast/ExtraPerson/ExtraBed) tiếp tục post giá từ `service_rates`, KHÔNG chuyển sang `service_package_rates` trong phase này (Section II của Prompt: "Không đổi pricing behavior của legacy packages"). Hai màn hình quản trị giá vẫn không đồng bộ cho 3 gói này — `test_legacy_package_posting_amount_comes_from_service_rates_not_service_package_rates` (trong `PackageEnrollmentFinancialPostingGapTest.php`) khoá hành vi này lại như một regression guard, không phải một bug cần sửa ngay.
2. **`ServicePackageRate.tax_rate`/`gl_account_code` accounting integration**: `TAX/GL SERVICE PACKAGE ACCOUNTING = DEFERRED ENHANCEMENT`. Không có accounting engine mới, không schema mới, `FolioEntry` vẫn không có cột tax/GL — `unit_price` hiện tại được coi là final amount, không cộng/trừ gì thêm.
3. **Package-level Revenue granularity**: `PACKAGE-LEVEL REVENUE BREAKDOWN = DEFERRED`. Mọi charge từ package động gộp chung dưới `charge_type = OTHER` trên Revenue Report — không có breakdown theo từng `ServicePackage.code`/`name` riêng.
