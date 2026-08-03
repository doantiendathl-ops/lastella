# Dynamic Service Package Administration — Architecture Review

**Ngày:** 2026-08-02
**Nhánh:** `phase-3`
**Phạm vi:** Chỉ điều tra + thiết kế trong tài liệu này. Chưa sửa code (code sẽ chỉ triển khai sau khi tài liệu này ở trạng thái READY, và chỉ ở phạm vi Milestone đã được xác định an toàn — xem mục 24-25).

**ChatGPT review: APPROVED FOR MILESTONE 1 COMMIT.**
**Milestone 1 status: COMPLETE.** Chưa deploy production.
**Toàn bộ chức năng "Gói dịch vụ": NOT YET COMPLETE.** Đây chỉ là commit nền tảng dữ liệu (catalog + rate versioning), **không** được mô tả hoặc coi là chức năng Gói dịch vụ đã hoàn thành cho người dùng cuối. Hiện vẫn chưa có: giao diện quản trị gói, booking đọc tên/trạng thái bookable từ database, Posting Job đọc `service_package_rates`, giá thật trong `service_package_rates`, menu "Gói dịch vụ" hoàn chỉnh. **Milestone 2-4 vẫn bắt buộc** trước khi đưa chức năng quản trị giá gói vào vận hành.

**Khoá quyết định sản phẩm cho Milestone tiếp theo (đã duyệt, không tự đổi khi triển khai Milestone 2+):**
1. Menu "Biểu giá dịch vụ" sẽ được thay bằng "Gói dịch vụ".
2. Menu "Gói dịch vụ" sẽ dẫn tới trang quản trị `ServicePackage` (route mới, không tái dùng `/admin/service-rates`).
3. `/admin/service-rates` vẫn được giữ để tương thích và phục vụ phụ phí hệ thống (CityTax/LateCheckout/EarlyCheckin/AddChargeForm), nhưng **không tiếp tục là mục quản trị gói chính** trên sidebar sau khi Milestone 2 hoàn tất.
4. **Không đưa phần chỉnh giá gói lên production** trước khi cả 3 Posting Job (`BreakfastPostingJob`, `ExtraPersonPostingJob`, `ExtraBedPostingJob`) đã đọc `service_package_rates` (Milestone 4).
5. **Không để giá hiển thị trong quản trị khác với giá Night Audit thực tế sử dụng** — nguồn giá hiển thị ở UI quản trị và nguồn giá Posting Job đọc phải luôn là cùng 1 bảng/1 truy vấn resolver, không phép có 2 đường đọc giá khác nhau cho cùng 1 package.

---

## 1. Executive Summary

Ba "gói dịch vụ" hiện có (Ăn sáng mỗi đêm / Người thêm mỗi đêm / Giường phụ mỗi đêm) không tồn tại như một thực thể dữ liệu — chúng là **hằng số PHP + nhãn hard-code** rải ở 4 nơi (`PackageEnrollmentService`, `PackageEnrollmentController`, `PackagePanel.vue`, và gián tiếp trong 3 Posting Job). Muốn đổi tên, đổi cách tính, hay thêm gói mới, bắt buộc phải sửa code và deploy lại — Product Owner hoàn toàn không có khả năng tự quản trị.

Giá của gói lại tách hẳn sang một bảng khác (`service_rates`), được định danh bằng `charge_type` — một enum kế toán dùng chung cho **nhiều mục đích không liên quan tới package** (thuế du lịch, phí trả phòng muộn, phí nhận phòng sớm, và giá nhanh trong form "Thêm phí"). Vì vậy `service_rates` **không phải** là bảng package, và không thể/không nên biến nó thành bảng package.

Khuyến nghị kiến trúc: xây một **catalog package mới** (`service_packages`) hoàn toàn tách biệt, có **bảng giá riêng theo package_id** (`service_package_rates`, không phải theo `charge_type`), giữ nguyên `service_rates` không đổi. Đây là Phương án B (mục 8-9) — ít regression nhất vì không đụng vào bất kỳ luồng đang chạy nào (CityTax/LateCheckout/EarlyCheckin/AddChargeForm).

Do khối lượng công việc (data model + 3 posting job + UI booking + UI quản trị + audit + test matrix 55 test) vượt quá phạm vi có thể triển khai an toàn, kiểm thử đầy đủ trong một lượt, tài liệu này **giới hạn triển khai code thực tế ở Milestone 1 (Package Catalog Foundation)** — hoàn toàn cộng thêm (additive), không đổi hành vi bất kỳ tính năng đang chạy nào. Milestone 2-5 được lập kế hoạch chi tiết trong Implementation Plan nhưng **chưa triển khai code** ở lượt này.

---

## 2. Current Problem

- Đổi tên gói ("Ăn sáng mỗi đêm" → tên khác) đòi hỏi sửa `PackageEnrollmentController.php` **và** `PackagePanel.vue` rồi deploy.
- Thêm gói mới đòi hỏi: thêm hằng số vào `PackageEnrollmentService::ALLOWED_PACKAGES`, thêm nhánh `match` trong `unenroll()`, thêm entry trong `buildAvailablePackages()`, viết Posting Job mới, đăng ký Posting Job vào `NightAuditService`, và thêm `ChargeType` mới nếu cần — tất cả là thay đổi code.
- Giá gói không nằm "trong" gói — nó được tra cứu **gián tiếp** qua `charge_type`, và bảng chứa giá đó (`service_rates`) đồng thời phục vụ các mục đích hoàn toàn khác (thuế, phụ phí, catalog nhanh cho nhân viên thêm phí tay). Không có khái niệm "giá của gói X" tồn tại độc lập trong dữ liệu.
- Không có màn hình nào cho phép Product Owner tự cấu hình cách tính (theo đêm, theo số lượng, theo người...) — cách tính hiện tại là **logic viết tay riêng trong từng Posting Job**, không phải dữ liệu.

---

## 3. Current Hard-Coded Package Architecture

Định danh gói (`package_key`, còn gọi "package identity") là hằng số PHP trong **`App\Services\PackageEnrollmentService`**:

```php
public const BREAKFAST_PER_NIGHT    = 'BREAKFAST_PER_NIGHT';
public const EXTRA_PERSON_PER_NIGHT = 'EXTRA_PERSON_PER_NIGHT';
public const EXTRA_BED_PER_NIGHT    = 'EXTRA_BED_PER_NIGHT';
public const ALLOWED_PACKAGES = [self::BREAKFAST_PER_NIGHT, self::EXTRA_PERSON_PER_NIGHT, self::EXTRA_BED_PER_NIGHT];
```

**Ghi chú quan trọng — sai khác với Prompt:** Prompt (mục VI) liệt kê 3 mã cần backfill là `BREAKFAST_PER_NIGHT`, `EXTRA_PERSON`, `EXTRA_BED`. Kiểm tra code thực tế cho thấy mã đăng ký thật (`package_key` lưu trong `booking_package_flags`, dùng trong `Rule::in()`, dùng trong `posting_key` sinh ra, dùng trong toàn bộ test) là **`EXTRA_PERSON_PER_NIGHT`** và **`EXTRA_BED_PER_NIGHT`** — có hậu tố `_PER_NIGHT`, khác với `EXTRA_PERSON`/`EXTRA_BED` (đó là giá trị của `ChargeType`, không phải `package_key`). Tài liệu này dùng đúng mã thật trong code (`EXTRA_PERSON_PER_NIGHT`, `EXTRA_BED_PER_NIGHT`) để đảm bảo tương thích ngược 100% với `booking_package_flags` hiện có — không tự đổi theo tóm tắt trong prompt.

**Tên hiển thị hard-code ở 2 nơi độc lập, đã từng lệch nhau** (đã fix ở hotfix trước trong phiên này):
- `App\Http\Controllers\Admin\PackageEnrollmentController::buildAvailablePackages()` — mảng `$packageMap` gắn `label`, `charge_label`, `charge_type` cho từng `package_key`. Đây là nguồn label cho trang `/admin/bookings/{id}/packages`.
- `resources/js/Pages/Admin/Bookings/Partials/PackagePanel.vue` — hằng số `PACKAGE_LABELS` (chỉ có `BREAKFAST_PER_NIGHT`), dùng cho panel rút gọn trên trang chi tiết booking. Đây là lý do panel rút gọn chỉ hiển thị đúng 1 gói — nó không đọc từ backend, mà tự hard-code danh sách riêng.
- `resources/js/Pages/Admin/Booking/Packages.vue` **không** hard-code label — nó hiển thị `pkg.label` do backend cung cấp, nên tên gói ở trang này đổi được nếu sửa `PackageEnrollmentController`. Đây là điểm khác biệt quan trọng giữa 2 nơi hiển thị package trên booking.

**Số nơi phải sửa để đổi 1 tên gói hiện tại: tối thiểu 2 file code** (`PackageEnrollmentController.php` cho `Packages.vue`, `PackagePanel.vue` cho panel rút gọn) — không tính Posting Job (Posting Job không hard-code tên hiển thị, chỉ hard-code `package_key` và mô tả FolioEntry ví dụ `"Bữa sáng đêm {ngày}"` → cũng là 1 chuỗi cứng khác, ở `BreakfastPostingJob::execute()`, `ExtraPersonPostingJob::execute()`, `ExtraBedPostingJob::execute()`).

---

## 4. Current Service Rate Architecture

Bảng `service_rates` (model `App\Models\ServiceRate`) là một **bảng giá tổng quát theo `charge_type`**, không phải bảng package:

- Cột: `name`, `charge_type` (string khớp `ChargeType` enum, **không cast enum**, chỉ là string), `unit_price`, `effective_from`, `unit_label`, `tax_rate`, `gl_account_code`, `is_active`, `display_order`, `created_by`.
- Versioning kiểu "temporal rows" (ADR-66): đổi giá = tạo dòng mới, giữ dòng cũ làm lịch sử. Trường không phải giá thì update tại chỗ (`ServiceRateController::update()`).
- Resolver: `ServiceRateService::resolveFor(ChargeType $chargeType, Carbon $businessDate): ?ServiceRate` — lấy dòng `is_active=true`, `effective_from <= businessDate`, mới nhất.
- **`ServiceRateService::activeRatesGrouped()`** — trả về **mảng gom nhóm theo `charge_type`** (dùng cho "Dịch vụ nhanh" trong `AddChargeForm.vue`, KHÔNG dùng cho package — đã xác nhận qua hotfix trước trong phiên này, `BookingController::show()` chỉ dùng nó cho prop `serviceRates` phục vụ form thêm phí thủ công, không liên quan `Packages.vue`).
- **Không xóa vĩnh viễn** (`ServiceRatePolicy::delete()` luôn `false`).
- **`service_rates` phục vụ 6 charge_type khác nhau trong code hiện tại**, không chỉ 3 package:
  - `FOOD_BEVERAGE` — dùng bởi `BreakfastPostingJob` (package) **và** có thể bị chọn thủ công qua `AddChargeForm` (ad-hoc, không liên quan package).
  - `EXTRA_PERSON`, `EXTRA_BED` — dùng bởi 2 posting job package tương ứng, **và** cũng xuất hiện trong `activeRatesGrouped()` cho `AddChargeForm`.
  - `CITY_TAX` — dùng bởi `CityTaxPostingJob` — **không phải package**, là phụ phí tự động toàn khách sạn theo cấu hình (`hotel_settings.city_tax_enabled`), khách không "đăng ký" khoản này.
  - `LATE_CHECKOUT` — dùng bởi `LateCheckoutFeePostingJob` — **không phải package**.
  - `EARLY_CHECKIN` — dùng bởi `EarlyCheckinFeePostingJob` — **không phải package**.
- **Dữ liệu thực tế hiện tại (kiểm tra DB local qua Tinker, chỉ đọc):** bảng `service_rates` **rỗng** (0 dòng) trên môi trường local đang dùng để phát triển. Không tìm thấy các dòng ví dụ "X" hay "Nghỉ tháng" nêu trong Prompt — có thể đây là ví dụ minh hoạ giả định của Product Owner (cảnh báo rủi ro), không phải dữ liệu thật quan sát được ở đây. **Nguyên tắc thiết kế vẫn phải giữ**: không được coi mọi dòng `service_rates` là package, bất kể `charge_type` — vì `service_rates` được chia sẻ bởi 5 charge_type không-phải-package như trên.

**Kết luận mục 4:** Giá package hiện tại **mượn** hạ tầng `service_rates` qua trung gian `charge_type`, không sở hữu giá của riêng nó. Đây là nguồn gốc sâu của vấn đề: không thể phân biệt "giá của gói Ăn sáng" với "giá FOOD_BEVERAGE nói chung dùng cho form Thêm phí" — cùng 1 dòng dữ liệu, 2 mục đích.

---

## 5. Current Enrollment Architecture

Bảng `booking_package_flags` (model `BookingPackageFlag`, migration `2026_07_03_000000_create_booking_package_flags_table.php`):

- Cột: `booking_id`, `package_key` (string, 1 trong 3 hằng số), `value` (string — lưu số lượng dạng chuỗi), `created_by`, timestamps.
- **Đăng ký theo Booking, không theo Stay** — `BookingPackageFlag.booking_id` trỏ tới `Booking`, không trỏ `Stay`. Một booking có nhiều `Stay` (nhiều phòng) thì gói áp dụng chung cho cả booking, không phân biệt theo từng stay/phòng.
- **Không snapshot giá tại thời điểm đăng ký** — `enroll()` chỉ `updateOrCreate` cờ bật + số lượng, hoàn toàn không lưu `unit_price` hay `charge_type` tại thời điểm đó. Giá chỉ được tra (`resolveFor`) tại thời điểm **posting** (Night Audit), không phải tại thời điểm đăng ký.
- `PackageEnrollmentService::enroll()`/`unenroll()`/`isEnrolled()`/`getEnrollmentSummary()` là toàn bộ business logic — không có bảng trung gian versioning nào cho enrollment.
- Guard hủy đăng ký: chặn nếu **đã có FolioEntry hôm nay** cho charge_type tương ứng (không phải theo `package_key` — dùng `ChargeType::FoodBeverage`/`ExtraPerson`/`ExtraBed` trực tiếp trong guard).

**Call site phụ thuộc `package_key`/`ALLOWED_PACKAGES` (đếm được qua `rg`):**
- `PackageEnrollmentController.php` (validate `Rule::in(ALLOWED_PACKAGES)`, dựng `$packageMap` hard-code) — 1 file, nhiều dòng.
- `BookingPackageController.php` — **phát hiện: controller này KHÔNG được gắn vào bất kỳ route nào** (`use` import ở `routes/web.php:18` nhưng không có `Route::` nào gọi tới `BookingPackageController::class`) — là dead code, đã ghi nhận từ phiên trước. Không cần xử lý tương thích cho nó, nhưng cũng không nên xoá trong Milestone 1 (ngoài phạm vi, rủi ro không cần thiết).
- `ExtraPersonPostingJob.php`, `ExtraBedPostingJob.php` — query trực tiếp `BookingPackageFlag::where('package_key', PackageEnrollmentService::EXTRA_PERSON_PER_NIGHT)`.
- `BreakfastPostingJob.php` — dùng `PackageEnrollmentService::isEnrolled($booking, BREAKFAST_PER_NIGHT)`.
- `PackagePanel.vue` — hard-code `package_key: 'BREAKFAST_PER_NIGHT'` trong `useForm`.
- `Packages.vue` — nhận `available_packages` (có `key`) từ backend, không hard-code key ở phía Vue (chỉ hard-code cách hiển thị đơn vị "người"/"giường" theo key — dòng 205).
- 5 file test (`BookingPackageEnrollmentTest`, `PackageEnrollmentControllerTest`, `PackageEnrollmentServiceTest`, `ExtraPersonPostingJobTest`, `ExtraBedPostingJobTest`, `BreakfastPostingJobFeatureTest`) — ~60 dòng tham chiếu trực tiếp hằng số.

**Kết luận:** `package_key` (string) đã là một **khóa nghiệp vụ ổn định** được dùng nhất quán ở mọi nơi — không có nơi nào dùng tên hiển thị làm khóa. Đây là điểm thuận lợi lớn: có thể giữ `package_key` (đổi tên thành `code` trong model mới) làm khóa tương thích ngược 100%, không cần migrate lại `booking_package_flags.package_key` hiện có.

---

## 6. Current Posting Architecture

`NightAuditPipeline::run()` lặp qua danh sách `PostingJob` đã **đăng ký cứng theo thứ tự cố định** trong `NightAuditService` (`RoomChargePostingJob → BreakfastPostingJob → ExtraPersonPostingJob → ExtraBedPostingJob → CityTaxPostingJob`) — **không** dùng `PostingJob::dependsOn()` để tự sắp thứ tự (không có `resolveExecutionOrder()`/topological-sort nào tồn tại trong `NightAuditPipeline.php` hiện tại, dù roadmap phase-3.2 có đề cập tính năng này cho "Phase 3.3+" — chưa được xây). `dependsOn()` hiện chỉ là khai báo tài liệu hoá, được thoả mãn thủ công bằng thứ tự `register()`.

3 Posting Job package có cấu trúc **giống nhau gần như 100%** (execute/rollback/isAlreadyPosted/shouldProcess/dependsOn/buildPostingKey):

| | BreakfastPostingJob | ExtraPersonPostingJob | ExtraBedPostingJob |
|---|---|---|---|
| Điều kiện chạy (`shouldProcess`) | `PackageEnrollmentService::isEnrolled(booking, BREAKFAST_PER_NIGHT)` | `isEnrolled(..., EXTRA_PERSON_PER_NIGHT)` | `isEnrolled(..., EXTRA_BED_PER_NIGHT)` |
| Giá | `resolveFor(ChargeType::FoodBeverage, date)` | `resolveFor(ChargeType::ExtraPerson, date)` | `resolveFor(ChargeType::ExtraBed, date)` |
| Số lượng | luôn `1` | `BookingPackageFlag.value` (nhập tay khi đăng ký) | `BookingPackageFlag.value` |
| `amount` | `= unit_price` | `= unit_price × quantity` | `= unit_price × quantity` |
| `posting_key` | `BREAKFAST_{stay_id}_{date}` | `EXTRA_PERSON_{stay_id}_{date}` | `EXTRA_BED_{stay_id}_{date}` |
| `charge_type` ghi vào FolioEntry | `FoodBeverage` | `ExtraPerson` | `ExtraBed` |
| Mô tả FolioEntry | `"Bữa sáng đêm {ngày}"` (chuỗi cứng) | `"Người thêm ({n} người) đêm {ngày}"` | `"Giường phụ ({n} giường) đêm {ngày}"` |

Idempotency: `posting_key` unique theo (stay, ngày), kiểm tra `whereNull('voided_at')`, có `lockForUpdate()` trong transaction — cơ chế này **áp dụng đều cho cả 3**, không có gì đặc biệt theo package cần bảo toàn riêng.

`FolioEntry` là **write-once, bất biến**: `voidEntry()` chặn tuyệt đối void với system entry (`posting_key !== null`) — ADR-50. Đây là điểm neo an toàn quan trọng nhất: **bất kể mô hình package mới thế nào, FolioEntry đã ghi sẽ không bao giờ bị đổi ngược**, vì cơ chế bất biến này nằm ở tầng `FolioService`/`FolioEntry`, hoàn toàn độc lập với việc package được định nghĩa ở đâu.

---

## 7. Product/Service Boundary

Đã phân tích đầy đủ trong `docs/reviews/booking-package-pricing-gap-analysis.md` (phiên trước). Tóm tắt lại để làm rõ ranh giới không được phá:

- `ProductService`/`product_services` — catalog **charge đơn lẻ, nhập tay, không có lịch sử giá theo thời gian** (chỉ 1 cột `price`, sửa là mất giá cũ — đã ghi nhận là khoảng trống thiết kế riêng, KHÔNG thuộc phạm vi task này).
- Không có FK giữa `product_services` và `service_rates`/`service_packages` (mới). Task này **không** tạo liên kết đó — giữ đúng nguyên tắc đã thiết lập.
- Menu "Sản phẩm/Dịch vụ" giữ nguyên, không đổi tên, không đổi hành vi.

---

## 8. Data Model Options

### Phương án A — `service_packages` mới + `service_package_id` (nullable) trên `service_rates`

- Migration: thêm cột `service_package_id` (nullable FK) vào `service_rates`.
- **Ảnh hưởng posting job:** không đổi câu query `resolveFor(ChargeType, date)` nếu vẫn lọc theo `charge_type` — nhưng khi đó không tận dụng được gì mới; nếu đổi sang lọc theo `service_package_id`, phải đảm bảo KHÔNG ảnh hưởng 3 job không-phải-package (`CityTaxPostingJob`, `LateCheckoutFeePostingJob`, `EarlyCheckinFeePostingJob`) vẫn dùng `resolveFor(ChargeType, ...)` — nghĩa là 2 luồng resolve (theo package_id và theo charge_type) phải cùng tồn tại trên 1 bảng, tăng độ phức tạp truy vấn và rủi ro nhầm dòng dữ liệu (1 dòng có thể vừa có `charge_type=FOOD_BEVERAGE` vừa có `service_package_id` gán nhầm).
- **Ảnh hưởng AddChargeForm/`activeRatesGrouped()`:** phải thêm điều kiện loại trừ dòng có `service_package_id` khỏi "Dịch vụ nhanh" (nếu không muốn giá package lẫn vào catalog ad-hoc) — thay đổi hành vi 1 tính năng đang chạy tốt, rủi ro regression thật.
- **Rollback:** phải rollback đúng cột thêm vào bảng đang có dữ liệu sản xuất (`service_rates`) — rủi ro cao hơn tạo bảng mới.
- **Đánh giá:** Regression trung bình-cao. Không chọn.

### Phương án B — Tạo riêng `service_packages` + `service_package_rates`, giữ `service_rates` nguyên vẹn cho charge type chung

- Hoàn toàn cộng thêm (additive): 2 bảng mới, 0 cột thêm vào bảng cũ, 0 dòng code cũ bị sửa để hỗ trợ mô hình mới ở Milestone 1.
- `service_rates`, `ServiceRateService`, `ServiceRateController`, `AddChargeForm.vue`, `CityTaxPostingJob`, `LateCheckoutFeePostingJob`, `EarlyCheckinFeePostingJob` — **không đổi 1 dòng nào**.
- Giá package có bảng lịch sử riêng, versioning theo đúng pattern ADR-66 đã kiểm chứng (sao chép logic, không sao chép bảng).
- **Nhược điểm duy nhất:** trùng lặp một phần logic versioning (2 service có cấu trúc tương tự `resolveFor`). Đây là trùng lặp có kiểm soát, dễ hiểu, dễ test độc lập — đánh đổi hợp lý để đạt "ít regression nhất".
- **Ảnh hưởng posting job:** Milestone 4 (không phải Milestone 1) sẽ đổi 3 job đọc rate từ `service_package_rates` theo `service_package_id` — hoàn toàn tách biệt khỏi `resolveFor(ChargeType)` đang phục vụ 3 job khác, không đụng chúng.
- **Rollback:** `dropIfExists` 2 bảng mới — an toàn tuyệt đối, không ảnh hưởng dữ liệu cũ.
- **Đánh giá:** Regression thấp nhất. **Chọn phương án này.**

### Phương án C — Refactor toàn bộ `service_rates` thành package-specific rate

- Phải viết lại `ServiceRateService`, `ServiceRateController`, `ServiceRatePolicy`, `AddChargeForm.vue`, và 3 posting job không-phải-package (`CityTax`/`LateCheckout`/`EarlyCheckin`) — các job này **không có khái niệm package**, không có gói nào để khách "đăng ký", nên ép chúng vào mô hình package là sai bản chất nghiệp vụ.
- Vi phạm trực tiếp nguyên tắc "Không coi mọi dòng service_rates hiện tại đều là Package".
- **Đánh giá:** Loại bỏ.

---

## 9. Recommended Data Model

**Chọn Phương án B.** Hai bảng mới, độc lập hoàn toàn với `service_rates`:

```
service_packages                       service_package_rates
------------------                      ----------------------
id                                      id
code            (string, unique)        service_package_id  (FK -> service_packages.id)
name             (string)                unit_price          (decimal 12,2)
description      (text, nullable)        effective_from      (date)
charge_type      (string, khớp ChargeType) is_active         (boolean, default true)
calculation_strategy (string, enum)      tax_rate            (decimal 5,4, nullable)
quantity_mode    (string, enum)          gl_account_code     (string, nullable)
default_quantity (unsignedInteger, default 1) created_by     (FK -> users, nullable)
unit_label       (string)                timestamps
posting_frequency (string, enum)
is_active        (boolean, default true)
is_bookable      (boolean, default true)
display_order    (integer, default 0)
created_by / updated_by (FK -> users, nullable)
timestamps
```

Không dùng soft-delete cho `service_packages` ở Milestone 1 (chưa xây UI xoá — xem mục 15 & Open Decisions). `service_package_rates` theo đúng "temporal rows" như `service_rates` (ADR-66) — không update `unit_price` của dòng cũ, luôn tạo dòng mới khi đổi giá.

---

## 10. Package Configuration Model

`ServicePackage` (Eloquent model) sở hữu:
- Định danh: `code` (bất biến sau khi đã dùng — xem validation mục XV), `name`, `description`.
- Phân loại kế toán: `charge_type` — vẫn dùng đúng `App\Enums\ChargeType`, để `FolioEntry.charge_type` tiếp tục ghi đúng như hiện tại khi Milestone 4 chuyển posting job qua dùng config này.
- Hành vi tính giá: `calculation_strategy`, `quantity_mode`, `default_quantity`, `posting_frequency` — xem mục 12.
- Vận hành: `is_active` (còn dùng để tính giá mới), `is_bookable` (còn cho đăng ký mới trên booking — 2 cờ tách biệt để có thể "ngừng bán nhưng vẫn tính phí cho người đã đăng ký" nếu nghiệp vụ cần, dù Milestone 1 chưa cần dùng khác nhau).
- `display_order` cho thứ tự hiển thị trong danh sách/trang booking.

Quan hệ: `ServicePackage::rates(): HasMany(ServicePackageRate)`.

---

## 11. Pricing Version Model

`ServicePackageRate` — sao chép nguyên tắc ADR-66 áp dụng cho `service_rates`, nhưng khoá theo `service_package_id` thay vì `charge_type`:

- Resolver mới (Milestone 1, thuần logic, chưa gắn vào posting job): `ServicePackageRate::query()->where('service_package_id', $id)->where('is_active', true)->whereDate('effective_from', '<=', $businessDate)->orderByDesc('effective_from')->orderByDesc('id')->first()`.
- Đổi giá = tạo dòng mới, dòng cũ giữ nguyên (bất biến).
- `FolioEntry` không tham chiếu `service_package_rates.id` — vẫn snapshot `unit_price`/`amount` trực tiếp vào chính nó tại thời điểm posting, đúng như thiết kế hiện tại. Điều này đảm bảo mục VIII (bảo vệ dữ liệu cũ) tự động đúng — không cần thêm cơ chế snapshot nào khác, vì FolioEntry **đã luôn** là điểm snapshot cuối cùng, độc lập với nguồn gốc của package.

---

## 12. Calculation Strategy Model

**Không cho nhập công thức tự do.** Thiết kế enum PHP `App\Enums\PackageCalculationStrategy` làm registry tường minh:

| Strategy | Ý nghĩa | Gói hiện tại dùng | Có posting job thật + test hiện có? |
|---|---|---|---|
| `ONCE_PER_STAY_PER_NIGHT` | Số lượng cố định = 1, tính mỗi đêm | Ăn sáng mỗi đêm | ✅ (`BreakfastPostingJob`, `BreakfastPostingJobFeatureTest`) |
| `MANUAL_QUANTITY_PER_NIGHT` | Số lượng nhập tay khi đăng ký, tính mỗi đêm | Người thêm/đêm, Giường phụ/đêm | ✅ (`ExtraPersonPostingJob`+Test, `ExtraBedPostingJob`+Test) |
| `ONCE_PER_BOOKING` | Tính đúng 1 lần cho cả booking | — | ❌ chưa có posting job/test |
| `ONCE_PER_ROOM` | Tính 1 lần cho mỗi phòng | — | ❌ |
| `PER_ROOM_PER_NIGHT` | Tính theo số phòng mỗi đêm | — | ❌ |
| `PER_ADULT_PER_NIGHT` | Tính theo số người lớn mỗi đêm | — | ❌ |
| `MANUAL_QUANTITY_ONCE` | Số lượng nhập tay, tính 1 lần | — | ❌ |

Theo đúng chỉ đạo *"Chỉ đưa vào giao diện những strategy mà backend có thể thực hiện đúng và có test"*: enum khai báo đủ 7 case (để không phải sửa migration/enum lần sau khi bổ sung), nhưng có method `PackageCalculationStrategy::implemented(): array` trả về **chỉ 2 case đầu** — đây là danh sách duy nhất được phép hiển thị trong dropdown UI (Milestone 2) và duy nhất được validate hợp lệ khi tạo package mới (Milestone 2 validation). 5 case còn lại tồn tại như "đã đăng ký trong registry, chưa có backend" — đúng yêu cầu *"thiết kế registry rõ ràng để bổ sung strategy sau"* mà không tạo ảo tưởng UI cho phép chọn cái chưa chạy được.

`PackageQuantityMode` tương tự: `NONE` (không nhập, luôn = `default_quantity`), `MANUAL_INPUT` (nhân viên nhập khi đăng ký, có `min/max` như hiện tại `Packages.vue` đang giới hạn 1-4) — 2 case này khớp đúng hành vi hiện tại của `quantityEditable()` trong `Packages.vue`. Các mode khác (`FROM_ADULTS`, `FROM_TOTAL_GUESTS`, `FROM_ROOMS`) khai báo cho tương lai, chưa implemented.

`PackagePostingFrequency`: `PER_NIGHT` (cả 3 gói hiện tại) — implemented. `ONCE` (tính 1 lần khi check-in/check-out) — khai báo, chưa implemented.

---

## 13. Enrollment Compatibility

Giữ nguyên `booking_package_flags.package_key` làm khoá tương thích — **không đổi tên cột, không đổi giá trị hiện có**. `ServicePackage.code` dùng đúng giá trị `package_key` cũ (`BREAKFAST_PER_NIGHT`, `EXTRA_PERSON_PER_NIGHT`, `EXTRA_BED_PER_NIGHT`) làm khoá nối logic (không FK cứng ở Milestone 1, vì `booking_package_flags` không đổi schema ở Milestone 1 — xem mục 16). Milestone 3 (ngoài phạm vi triển khai lần này) sẽ đánh giá thêm cột `service_package_id` (nullable, backfill dần) trên `booking_package_flags` để tăng tốc truy vấn và tránh phụ thuộc string-matching lâu dài, nhưng **giữ `package_key` làm nguồn sự thật chính** để không phải migrate lại dữ liệu enrollment cũ.

---

## 14. Posting Compatibility

Milestone 1 **không đổi bất kỳ Posting Job nào**. Khi tới Milestone 4 (kế hoạch, chưa triển khai): chọn **Phương án 1** (giữ 3 adapter hiện tại, chỉ đổi nguồn đọc tên/giá/trạng thái sang `ServicePackage`/`ServicePackageRate` qua `code`) thay vì Phương án 2 (Generic Engine) — lý do nêu ở Implementation Plan mục 10.

---

## 15. Snapshot and Historical Integrity

Chọn **Phương án A (Snapshot tại điểm ghi Folio)** — thực ra đây **chính là hành vi hiện tại**, không phải thay đổi mới: `FolioEntry` luôn tự chứa `description`/`unit_price`/`quantity`/`amount` tại thời điểm posting, bất biến sau đó (ADR-50). Không cần "Version package configuration" (Phương án B trong Prompt) vì đơn vị bất biến nhỏ nhất trong hệ thống đã là `FolioEntry`, không phải cấu hình package — versioning ở tầng package (mục 11) chỉ cần đủ để **posting job trong tương lai luôn đọc đúng giá tại đúng business date**, không cần tự nó bất biến theo kiểu snapshot lồng nhau. Việc này tránh over-engineering (Prompt cũng cảnh báo không cần công thức/độ phức tạp không cần thiết).

---

## 16. Migration and Backfill

Milestone 1 tạo 2 migration mới, **hoàn toàn additive**:
1. `create_service_packages_table`
2. `create_service_package_rates_table`

Backfill: `ServicePackageSeeder` (idempotent qua `firstOrCreate(['code' => ...])`), tạo đúng 3 dòng catalog (code/name/charge_type/calculation_strategy/quantity_mode/unit_label/posting_frequency) — **không tạo dòng `service_package_rates` nào** (không có giá thật để backfill — `service_rates` rỗng trên môi trường kiểm tra, và dù có dữ liệu, Prompt cấm "không tự đặt giá mặc định mới"). Admin phải tự nhập giá qua UI ở Milestone 2.

Không migrate/backfill `booking_package_flags` ở Milestone 1 (không cần — vẫn dùng `package_key` string nguyên trạng).

---

## 17. Admin UI Design

**Không triển khai ở Milestone 1** (theo đúng "Không đổi UI booking trước khi backend ổn định", áp dụng tương tự cho UI quản trị — chưa có gì để quản trị nếu chưa có bảng giá package thật). Thiết kế đầy đủ (danh sách, form tạo/sửa, khu vực biểu giá, cảnh báo đổi strategy, modal ngừng hoạt động...) được đặc tả chi tiết trong Implementation Plan mục 11, dành cho Milestone 2.

---

## 18. Authorization

Đề xuất: **thêm permission mới `service_packages.manage`**, giữ `service_rates.manage` nguyên trạng (không đổi tên, không gán chồng lẫn) vì `service_rates.manage` vẫn đang bảo vệ `/admin/service-rates` — trang này **tiếp tục hoạt động y nguyên** cho tới khi Milestone 2 build UI package thật (không redirect/deprecate ngay). Gán `service_packages.manage` cho ADMIN (qua `syncPermissions(self::PERMISSIONS)` — tự động) và MANAGER (thêm dòng rõ trong danh sách sync riêng), **không** gán RECEPTION/SALES/HOUSEKEEPING/ACCOUNTANT — khớp chính sách hiện tại của `service_rates.manage`.

---

## 19. Audit

**Phát hiện quan trọng:** hệ thống đã có sẵn `App\Observers\AuditObserver` — observer tổng quát (model-agnostic), tự động ghi `AuditLog` (action Created/Updated/Deleted/Restored, kèm old/new attribute diff, user, IP, user-agent) cho bất kỳ model nào được `Model::observe(AuditObserver::class)` trong `AppServiceProvider::boot()`. Hiện tại `ServiceRate` **và** `BookingPackageFlag` **chưa** được đăng ký observer này — nghĩa là **hiện tại không có audit log nào** cho thay đổi biểu giá hay đăng ký/hủy gói.

Milestone 1 chỉ cần thêm 3 dòng `observe()` cho `ServicePackage`, `ServicePackageRate`, và `BookingPackageFlag` (bổ khuyết luôn audit còn thiếu cho enrollment, vì đây là thay đổi 1 dòng, an toàn, đúng yêu cầu mục XII của Prompt) — không cần viết audit logic riêng.

---

## 20. Risks

| Rủi ro | Mức độ | Giảm thiểu |
|---|---|---|
| Nhầm dòng `service_rates` (CityTax/LateCheckout/EarlyCheckin) là package | Cao nếu chọn A/C | Đã loại A/C, chọn B — 2 bảng tách biệt vật lý |
| Đổi hành vi `AddChargeForm`/`activeRatesGrouped()` | Trung bình nếu chọn A | Không xảy ra với B |
| Mất tương thích `booking_package_flags.package_key` | Cao nếu đổi khoá | Giữ `package_key` nguyên trạng, `ServicePackage.code` chỉ là bảng tra cứu bổ sung |
| FolioEntry bị ảnh hưởng ngược khi đổi tên/giá package | Không có, do FolioEntry bất biến (ADR-50) đã tồn tại độc lập | Không cần thêm cơ chế |
| Triển khai quá lớn trong 1 lượt gây lỗi dây chuyền | Cao nếu làm cả 5 Milestone 1 lần | Chỉ triển khai Milestone 1 lượt này |
| `BookingPackageController.php` (dead code) gây nhầm lẫn khi audit sau này | Thấp | Ghi nhận rõ trong tài liệu, không xoá ngoài phạm vi |

---

## 21. Test Strategy

Milestone 1 cần tối thiểu: migration tạo đúng bảng/cột; `ServicePackageSeeder` backfill đúng 3 dòng, idempotent (chạy 2 lần không nhân bản); quan hệ `ServicePackage::rates()`; scope `active()`/`bookable()` trên `ServicePackage`; scope `active()`/`effectiveOn()` trên `ServicePackageRate`; permission `service_packages.manage` được seed và gán đúng role; audit log được tạo khi tạo/sửa `ServicePackage`. Chi tiết đầy đủ (bao gồm test matrix Milestone 2-5, hiện chưa triển khai) nằm ở Implementation Plan mục 17.

---

## 22. Affected Files

**Milestone 1 (triển khai lượt này):**
- Mới: 2 migration, `ServicePackage` model, `ServicePackageRate` model, 3 enum (`PackageCalculationStrategy`, `PackageQuantityMode`, `PackagePostingFrequency`), `ServicePackageSeeder`, test(s) tương ứng.
- Sửa: `database/seeders/RolePermissionSeeder.php` (+permission), `database/seeders/DatabaseSeeder.php` (+seeder call), `app/Providers/AppServiceProvider.php` (+3 observe()).

**Không đổi ở Milestone 1** (đầy đủ, để tránh nhầm lẫn phạm vi): `AppLayout.vue`, `Packages.vue`, `PackagePanel.vue`, `PackageEnrollmentController.php`, `PackageEnrollmentService.php`, `BookingPackageController.php`, `BookingController.php`, `ServiceRateController.php`, `ServiceRateService.php`, `ServiceRatePolicy.php`, `ServiceRate.php`, `BookingPackageFlag.php`, `BreakfastPostingJob.php`, `ExtraPersonPostingJob.php`, `ExtraBedPostingJob.php`, `NightAuditPipeline.php`, `NightAuditService.php`, `routes/web.php`, `ChargeType.php`, `FolioEntry.php`, `AddChargeForm.vue`, `FolioPanel.vue`.

---

## 23. Open Product Decisions

1. Có nên deprecate/redirect `/admin/service-rates` sau khi Milestone 2 hoàn tất, hay giữ song song vĩnh viễn cho CityTax/LateCheckout/EarlyCheckin? (Khuyến nghị: giữ song song — nó vẫn cần cho 3 charge_type không-phải-package.)
2. Có nên cho phép admin tự đổi `charge_type` của 1 package sau khi đã có `FolioEntry` sử dụng charge_type đó? (Khuyến nghị: không — validation Milestone 2 nên chặn, tương tự nguyên tắc "code bất biến sau khi dùng".)
3. `default_quantity` giới hạn 1-4 như `Packages.vue` hiện tại đang hard-code — có nên để admin tự cấu hình min/max theo package, hay giữ cứng 1-4 cho mọi package `MANUAL_QUANTITY_PER_NIGHT`? (Milestone 2/3 quyết định, chưa cần trả lời ở Milestone 1.)
4. Ví dụ "X"/"Nghỉ tháng" trong Prompt không khớp dữ liệu thật quan sát được (bảng rỗng) — cần Product Owner xác nhận đây là ví dụ minh hoạ hay có môi trường khác (staging/production) đang có dữ liệu thật cần rà soát riêng trước khi Milestone 2 build UI liệt kê "booking đang dùng gói này".

---

## 24. Architecture Decision

**Chọn Phương án B** (mục 8-9) cho data model. **Chọn Phương án 1 — giữ 3 adapter Posting Job hiện tại** (mục 14, chi tiết ở Implementation Plan) cho Milestone 4 tương lai, không xây Generic Engine ngay. **Giữ `service_rates.manage` + thêm `service_packages.manage`** (mục 18). **Giữ `package_key`/`ALLOWED_PACKAGES` làm khoá tương thích**, không migrate `booking_package_flags` ở Milestone 1 (mục 13).

Toàn bộ quyết định trên đều **additive/không phá vỡ** — không có quyết định nào yêu cầu sửa hành vi của tính năng đang chạy trong Milestone 1.

---

## 25. Readiness

**READY FOR IMPLEMENTATION — GIỚI HẠN Ở MILESTONE 1.**

Không có blocker nghiêm trọng cho Milestone 1 (Package Catalog Foundation) — toàn bộ thay đổi là cộng thêm, đã xác nhận không đụng bảng/model/service/controller/Vue nào đang chạy. Milestone 2-5 **chưa được coi là READY** để triển khai code trong cùng lượt này vì khối lượng + rủi ro test-coverage (đặc biệt Milestone 4 — đổi posting job — theo đúng cảnh báo mục IX của Prompt, cần một lượt review riêng sau khi Milestone 1 đã chạy ổn định) — xem Implementation Plan để biết kế hoạch đầy đủ và điều kiện tiếp tục.

---

## 26. Milestone 1 Closure Addendum (2026-08-02)

Bổ sung sau khi review/đóng an toàn Milestone 1 — xem chi tiết đầy đủ trong `docs/reports/dynamic-service-package-admin-implementation-report.md` mục "Milestone 1 Closure Review". Tóm tắt các điểm bắt buộc phải ghi rõ theo yêu cầu đóng Milestone:

- **Chỉ Milestone 1.** Không có Admin UI cho package. Không được deploy với kỳ vọng chức năng "Gói dịch vụ" đã hoàn thành cho người dùng cuối.
- **Booking chưa đọc package catalog** — `PackageEnrollmentController`/`Packages.vue`/`PackagePanel.vue` vẫn dùng `PackageEnrollmentService::ALLOWED_PACKAGES` hard-code, không đọc `service_packages`.
- **Posting chưa đọc package catalog/rates** — `BreakfastPostingJob`/`ExtraPersonPostingJob`/`ExtraBedPostingJob` vẫn đọc `ServiceRateService::resolveFor(ChargeType, ...)` trên `service_rates`, không đọc `ServicePackageRate`.
- **Tên gói hard-code vẫn còn trong runtime** — xác nhận lại bằng `rg -n "Bữa sáng mỗi đêm|Ăn sáng mỗi đêm|Người thêm / đêm|Giường phụ / đêm" app resources` sau đóng Milestone 1: vẫn còn đúng 5 dòng như trước, không đổi.
- **`service_package_rates` chưa có giá thật** — 0 dòng, có chủ đích, xác nhận lại qua Bước 10 (backfill x2, vẫn 0 `ServicePackageRate`).
- **Package key legacy đã được xác minh khớp tuyệt đối** — `BREAKFAST_PER_NIGHT`, `EXTRA_PERSON_PER_NIGHT`, `EXTRA_BED_PER_NIGHT` (không phải `EXTRA_PERSON`/`EXTRA_BED` như tóm tắt gốc trong Prompt gốc mục VI) — không có mismatch, không cần sửa backfill. Đã thêm test compatibility (`test_backfilled_codes_match_the_legacy_package_enrollment_constants_exactly`, `test_a_legacy_booking_package_flag_resolves_to_the_matching_service_package`).
- **Strategy chưa triển khai không thể dùng được** — đã bổ sung guard cứng ở tầng model (`ServicePackage::booted()` → ném `InvalidArgumentException` nếu `calculation_strategy`/`quantity_mode`/`posting_frequency` không thuộc `implemented()`, hoặc `quantity_mode` không tương thích `calculation_strategy`), có test xác nhận.
- **Production deployment chưa được duyệt** — chưa commit, chưa push, chưa chạy migration/seeder trên production.

**Trạng thái Architecture Review: APPROVED FOR MILESTONE 1 COMMIT.** (ChatGPT đã review; chưa deploy production; toàn bộ chức năng Gói dịch vụ vẫn NOT YET COMPLETE — xem khoá quyết định sản phẩm ở đầu tài liệu.)
