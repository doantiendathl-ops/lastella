# Package Enrollment Dynamic Catalog Integration — Architecture Review

**Ngày:** 2026-08-08
**Nhánh:** `phase-3`
**Phạm vi:** Đóng gap đã ghi nhận tại `docs/reports/package-enrollment-hardcoded-catalog-gap.md` — nối luồng đăng ký gói của booking (`/admin/bookings/{booking}/packages`) vào catalog động (`service_packages` + `service_package_rates`) do tính năng Dynamic Service Package Admin (Milestone 1-2, đã production) xây dựng, thay cho catalog viết cứng song song.

---

## 1. Old Source of Truth (trước task này)

Catalog hiển thị + được phép đăng ký trên booking đến từ **2 hằng số PHP viết cứng**, không đọc DB:

- `PackageEnrollmentController::buildAvailablePackages()` — mảng `$packageMap` (3 key cố định: label, charge_label, charge_type).
- `PackageEnrollmentService::ALLOWED_PACKAGES` — dùng làm `Rule::in()` cho cả `enroll()` lẫn `unenroll()`, và để lặp trong `getEnrollmentSummary()`.

Giá hiển thị trên trang booking đến từ `ServiceRateService::resolveFor(ChargeType, businessDate)` → bảng `service_rates` — **không phải** `service_package_rates`. Đây là bảng giá cũ dùng chung cho nhiều mục đích không liên quan tới package (thuế, phí trả phòng muộn/nhận phòng sớm, form "Thêm phí" nhanh).

Hệ quả (đúng như gap report mô tả): bật/tắt `is_active`/`is_bookable` ở `/admin/service-packages` **không có tác dụng gì** trên `/admin/bookings/{booking}/packages` — 2 màn hình đọc 2 nguồn hoàn toàn độc lập.

## 2. New Source of Truth (sau task này)

`service_packages` + `service_package_rates` là nguồn duy nhất cho: catalog hiển thị, quyền đăng ký mới, và giá hiển thị trên trang booking.

- `PackageEnrollmentService::catalogForBooking(Booking $booking): Collection<ServicePackage>` — method mới, dùng chung cho cả `available_packages` (hiển thị) và `enrollments` (trạng thái đăng ký) trong `PackageEnrollmentController::show()`, tránh 2 nguồn logic lệch nhau.
- Danh sách trả về = `(is_active=true AND is_bookable=true)` **UNION** mọi `package_key` mà booking này đã từng đăng ký (kể cả đã bị vô hiệu hoá sau đó) — xem mục 3.
- Giá hiển thị = `ServicePackage::currentRate($businessDate)` → `service_package_rates`, đúng rule đã tồn tại từ Milestone 1 (`effective_from <= businessDate`, mới nhất theo `effective_from` rồi theo `id` thắng khi hoà) — **không tự invent rule mới**, tái dùng nguyên method đã có sẵn và đã được test kỹ (`ServicePackageRateVersioningTest`).
- `enroll()` re-validate lại từ DB ngay trong service layer (không tin `package_key` đã qua `Rule::exists()` ở controller là đủ): tồn tại → `is_active` → `is_bookable` → có `currentRate()` khác null. Sai bất kỳ điều kiện nào → `PackageNotEnrollableException` (mới), controller trả `withErrors(['package_key' => ...])`.
- `unenroll()` chỉ còn yêu cầu `package_key` là mã package có thật (`Rule::exists('service_packages','code')`) — **cố ý không** yêu cầu active/bookable, vì phải luôn huỷ được một gói đã đăng ký kể cả sau khi gói đó bị vô hiệu hoá (mục 3).

`ALLOWED_PACKAGES` hằng số **vẫn giữ nguyên** — không xoá. Lý do duy nhất còn lại: 3 Posting Job (`BreakfastPostingJob`/`ExtraPersonPostingJob`/`ExtraBedPostingJob`) gọi `PackageEnrollmentService::isEnrolled($booking, self::BREAKFAST_PER_NIGHT)` bằng đúng literal string này để quyết định có ghi phí Night Audit hay không — đây là dependency thật (Section X cho phép giữ constant khi có evidence), không phải catalog source of truth nữa.

## 3. Historical Compatibility

`booking_package_flags` không có FK tới `service_packages`, chỉ có `package_key` (string) — **không đổi**, không migration mới. Một enrollment lịch sử phải tiếp tục hiển thị đúng kể cả sau khi package bị tắt, vì `catalogForBooking()` UNION thêm mọi `package_key` đã từng ghi flag cho booking đó, không chỉ lọc theo active/bookable. Đã test trực tiếp (`PackageEnrollmentDynamicCatalogTest::test_historical_enrollment_still_renders_after_package_is_deactivated`) và xác nhận thủ công với dữ liệu thật trên máy dev: booking 461 có flag `BREAKFAST_PER_NIGHT` từ trước, package này hiện `is_active=false`/`is_bookable=false` trong DB — flag đó vẫn phải hiển thị đúng "Đã đăng ký" sau thay đổi này (không bị catalog filter làm biến mất).

Việc `getEnrollmentSummary()` trước đây lặp cứng qua `ALLOWED_PACKAGES` có cùng rủi ro tương tự nếu catalog được lọc theo active — đã sửa để cùng dùng `catalogForBooking()` (union), không còn phụ thuộc hằng số.

## 4. Price Snapshot — Không đổi (quan trọng)

`booking_package_flags` **chưa từng** và **vẫn không** lưu giá tại thời điểm đăng ký — cột `value` chỉ lưu số lượng (quantity). Giá thực tế ghi vào folio được 3 Posting Job resolve **tại thời điểm Night Audit chạy**, qua `ServiceRateService`/`service_rates` — một hệ thống giá hoàn toàn tách biệt, **không đổi trong task này** (ngoài phạm vi, Section XVI/XVII cấm đụng Night Audit).

Điều này tạo ra một khác biệt cần ghi nhận rõ, không che giấu: sau task này, **giá hiển thị lúc đăng ký** (từ `service_package_rates`, do task này đổi sang) và **giá thực tế ghi phí đêm** (từ `service_rates`, không đổi) là **2 nguồn khác nhau** cho 3 gói legacy (Breakfast/ExtraPerson/ExtraBed). Đây không phải regression mới do task này gây ra — đây là khoảng cách kiến trúc đã tồn tại từ Milestone 1/2 (chính `docs/reports/dynamic-service-package-admin-milestone-2-report.md` cũng để ngỏ câu hỏi này), nằm ngoài phạm vi cho phép sửa của task hiện tại (không được đụng Posting Job/Night Audit). Ghi nhận là **remaining gap**, không tự ý mở rộng phạm vi để vá.

Với package mới tạo qua `/admin/service-packages` (ví dụ QA PILOT PACKAGE) mà không phải 1 trong 3 mã legacy: hiện **chưa có Posting Job nào** đọc `service_packages`/`service_package_rates` để ghi phí Night Audit — enrollment ghi được, hiển thị đúng, nhưng sẽ không tự động lên phí đêm cho tới khi có một task riêng làm Posting Job generic đọc `service_packages`. Đây là gap đã biết, đã ghi trong gap report gốc ("Cần rà soát 3 Posting Job... chưa trả lời"), **không phải phạm vi task này** (Section XVI/XVII cấm sửa Night Audit).

## 5. Tourist Tax Boundary — Không đổi

Thuế du lịch = `ChargeType::CityTax`, điều khiển bởi `HotelSettingsService::getBool('city_tax_enabled')` — một hotel setting hệ thống, không phải `ServicePackage`. Không có `ServicePackage` row nào dùng `charge_type = CITY_TAX`. Khối hiển thị "Thuế du lịch" trên `Packages.vue` là mục thông tin riêng, tách khỏi vòng lặp `available_packages` — hoàn toàn không đụng tới trong task này.

## 6. Kết luận (Catalog/Enrollment)

`service_packages` là nguồn duy nhất cho catalog hiển thị + quyền đăng ký mới trên booking. Không migration mới (schema hiện tại đủ). Không đổi Night Audit/Posting Job. Không đổi Tourist Tax. Existing/historical enrollment được bảo toàn qua UNION logic, không qua thay đổi schema.

**Lưu ý bắt buộc đọc tiếp:** mục 6 chỉ kết luận về phạm vi **catalog + enrollment**. Nó **không** đồng nghĩa với "package động đã đi xuyên suốt tới charge/Folio/Night Audit" — xem mục 7-8 dưới đây (điều tra ở task kế tiếp) cho câu trả lời đầy đủ về financial posting.

## 7. Financial Posting Integration — CASE B CONFIRMED, sau đó RESOLVED cho package động (Active Pilot)

Điều tra riêng (task "Financial Posting / Billing Integration Closure") xác nhận CASE B: catalog/enrollment (mục 1-6) hoạt động đúng, nhưng financial posting (Night Audit → FolioEntry) hoàn toàn không đọc `ServicePackage`/`ServicePackageRate`. `NightAuditService` chỉ đăng ký đúng 5 `PostingJob` theo tên class cứng (`RoomCharge`, `Breakfast`, `ExtraPerson`, `ExtraBed`, `CityTax`).

Task tiếp theo ("Dynamic Service Package Generic Financial Posting — Active Pilot Implementation"), sau khi Product Owner/ChatGPT chốt accounting semantics cho Active Pilot, đã **đóng gap này cho mọi package động không thuộc 3 mã legacy**: thêm `App\Services\Posting\ServicePackagePostingJob` (generic), đăng ký vào `NightAuditService` cùng 5 job cũ. Chi tiết implementation + bằng chứng test đầy đủ: `docs/reports/package-enrollment-dynamic-financial-posting-gap.md`.

**Price semantics = C** (effective rate tại posting date — trùng với service date trong kiến trúc hiện tại vì Night Audit tính riêng từng đêm theo đúng business date của đêm đó). Không phải snapshot tại enrollment (A) — xác nhận bởi việc `booking_package_flags` chưa từng có cột giá; job generic mới cũng không thêm snapshot nào.

**Second source-of-truth gap** — vẫn tồn tại **có chủ đích** cho 3 package legacy: `service_package_rates` (Admin catalog + Booking Enrollment display) và `service_rates` (3 Posting Job legacy dùng để tính tiền thật) vẫn là 2 bảng độc lập cho `BREAKFAST_PER_NIGHT`/`EXTRA_PERSON_PER_NIGHT`/`EXTRA_BED_PER_NIGHT` — Active Pilot quyết định KHÔNG đổi pricing behavior của 3 package legacy (Section II của Prompt). Package động mới hoàn toàn dùng `service_package_rates`, không có gap này.

## 8. Dynamic Package End-to-End Support Matrix (cập nhật sau Active Pilot)

| Package | Catalog? | Enroll? | Price nguồn hiển thị | Posting Job? | Tạo Folio charge? | Price nguồn post thật | Khi nào post? | Historical amount preserved? | End-to-end? |
|---|---|---|---|---|---|---|---|---|---|
| Ăn sáng mỗi đêm (`BREAKFAST_PER_NIGHT`) | Có (dynamic) | Có | `service_package_rates` | `BreakfastPostingJob` (cứng, legacy) | Có | `service_rates` (không đổi) | Night Audit, mỗi đêm | Có | Có, nhưng giá hiển thị ≠ giá thật post (2 nguồn — deferred có chủ đích) |
| Người thêm / đêm (`EXTRA_PERSON_PER_NIGHT`) | Có (dynamic) | Có | `service_package_rates` | `ExtraPersonPostingJob` (cứng, legacy) | Có | `service_rates` (không đổi) | Night Audit, mỗi đêm | Có | Có, cùng caveat 2 nguồn giá |
| Giường phụ / đêm (`EXTRA_BED_PER_NIGHT`) | Có (dynamic) | Có | `service_package_rates` | `ExtraBedPostingJob` (cứng, legacy) | Có | `service_rates` (không đổi) | Night Audit, mỗi đêm | Có | Có, cùng caveat 2 nguồn giá |
| `QA_PILOT_PACKAGE` (test) | Có | Có | `service_package_rates` | `ServicePackagePostingJob` (generic, mới) | **Có** | `service_package_rates` | Night Audit, mỗi đêm | Có | **Có — RESOLVED** |
| Bất kỳ package động mới nào (2 strategy implemented) | Có | Có | `service_package_rates` | `ServicePackagePostingJob` (generic) | **Có** | `service_package_rates` | Night Audit, mỗi đêm | Có | **Có — RESOLVED**, cả `ONCE_PER_STAY_PER_NIGHT` lẫn `MANUAL_QUANTITY_PER_NIGHT` |
