# Dynamic Service Package Administration — Implementation Report

**Ngày:** 2026-08-02 (cập nhật commit closure: 2026-08-03)
**ChatGPT review: APPROVED FOR MILESTONE 1 COMMIT.**
**Milestone 1 status: COMPLETE.** Chưa deploy production.
**Toàn bộ chức năng "Gói dịch vụ": NOT YET COMPLETE.** Đây là commit nền tảng dữ liệu (Package Catalog Foundation) — **không** phải chức năng Gói dịch vụ hoàn thành cho người dùng cuối. Hiện vẫn chưa có: giao diện quản trị gói dịch vụ, booking đọc tên gói từ database, booking đọc trạng thái bookable từ database, Posting Job đọc `service_package_rates`, giá thật trong `service_package_rates`, menu "Gói dịch vụ" hoàn chỉnh. **Milestone 2-4 vẫn bắt buộc** trước khi đưa chức năng quản trị giá gói vào vận hành.

**Khoá quyết định sản phẩm cho Milestone tiếp theo:** xem `docs/reviews/dynamic-service-package-admin-architecture-review.md` (đầu tài liệu) — 5 điểm đã duyệt, không tự đổi khi triển khai Milestone 2+.

**Không ghi commit SHA trong tài liệu này trước khi commit thật diễn ra** — SHA được xác nhận riêng ngoài tài liệu tại thời điểm commit.

---

## 1. Product Requirement

Cho phép Product Owner tự quản trị tên, giá theo thời gian, trạng thái, đơn vị, cách tính của các "gói dịch vụ" qua giao diện quản trị, không cần sửa code/deploy. Yêu cầu đầy đủ nằm trong Prompt gốc; căn cứ thiết kế nằm trong `docs/reviews/dynamic-service-package-admin-architecture-review.md` và `docs/implementation-plans/dynamic-service-package-admin-implementation-plan.md`.

## 2. Previous Hard-Coded Behavior

3 gói (Ăn sáng mỗi đêm/Người thêm/đêm/Giường phụ/đêm) là hằng số PHP (`PackageEnrollmentService::ALLOWED_PACKAGES`) với tên hard-code ở `PackageEnrollmentController::buildAvailablePackages()` và `PackagePanel.vue::PACKAGE_LABELS`. Giá mượn gián tiếp qua `service_rates.charge_type` — bảng này còn phục vụ CityTax/LateCheckout/EarlyCheckin/AddChargeForm, không phải bảng package riêng. Chi tiết đầy đủ ở Architecture Review mục 2-6.

## 3. Architecture Implemented

**Chỉ Milestone 1 — Package Catalog Foundation.** Đã xây: 2 bảng mới hoàn toàn tách biệt khỏi `service_rates` (Phương án B), 2 model, 3 enum registry cho calculation strategy/quantity mode/posting frequency, 1 seeder backfill, permission mới, audit observer. **Chưa đổi** bất kỳ Controller, Vue, Posting Job, hay route nào đang chạy.

## 4. Data Model

```
service_packages          (id, code[unique], name, description, charge_type,
                            calculation_strategy, quantity_mode, default_quantity,
                            unit_label, posting_frequency, is_active, is_bookable,
                            display_order, created_by, updated_by, timestamps)

service_package_rates     (id, service_package_id[FK restrictOnDelete], unit_price,
                            effective_from, is_active, tax_rate, gl_account_code,
                            created_by, timestamps)
```

`service_rates` không đổi 1 cột nào. Xem Architecture Review mục 8-9 cho phân tích đầy đủ 3 phương án và lý do chọn B.

## 5. Package Catalog

3 package đã backfill (idempotent qua `firstOrCreate(['code' => ...])`), giữ đúng `code` = `package_key` hiện có trong `booking_package_flags` (không theo tóm tắt sai lệch trong Prompt — xem Architecture Review mục 3):

| code | name | charge_type | strategy | quantity_mode | unit_label |
|---|---|---|---|---|---|
| BREAKFAST_PER_NIGHT | Ăn sáng mỗi đêm | FOOD_BEVERAGE | ONCE_PER_STAY_PER_NIGHT | NONE | đêm |
| EXTRA_PERSON_PER_NIGHT | Người thêm / đêm | EXTRA_PERSON | MANUAL_QUANTITY_PER_NIGHT | MANUAL_INPUT | người |
| EXTRA_BED_PER_NIGHT | Giường phụ / đêm | EXTRA_BED | MANUAL_QUANTITY_PER_NIGHT | MANUAL_INPUT | giường |

**Không tạo bất kỳ `service_package_rates` nào** — không có giá thật để backfill an toàn (đã kiểm tra `service_rates` rỗng trên môi trường local; không suy diễn giá).

## 6. Pricing Versioning

`ServicePackageRate` dùng đúng pattern "temporal rows" của ADR-66 (đổi giá = tạo dòng mới, không update dòng cũ), scope `active()`/`effectiveOn()`. Đã test đầy đủ: rate đúng ngày được chọn, rate tương lai chưa áp dụng, rate inactive bị loại, rate cũ giữ nguyên khi có rate mới hơn (`ServicePackageRateVersioningTest`, 7 test PASS).

## 7. Calculation Strategies

3 enum: `PackageCalculationStrategy` (7 case, 2 implemented), `PackageQuantityMode` (5 case, 2 implemented), `PackagePostingFrequency` (2 case, 1 implemented). Mỗi enum có `implemented(): array` + `isImplemented(): bool` làm registry tường minh — chỉ 2 strategy khớp đúng hành vi 3 package hiện tại được đánh dấu "implemented"; 5 strategy còn lại được khai báo cho tương lai nhưng **không** được đánh dấu sẵn sàng dùng (chưa có posting job/test hỗ trợ) — đúng yêu cầu không cho UI/PO chọn thứ chưa chạy được.

## 8. Booking Enrollment Integration

**Không triển khai ở lượt này (Milestone 3, ngoài phạm vi).** `PackageEnrollmentController.php`, `PackagePanel.vue`, `Packages.vue`, `PackageEnrollmentService.php` — 0 thay đổi. `booking_package_flags` không đổi schema. Kế hoạch chi tiết ở Implementation Plan mục 9.

## 9. Posting Integration

**Không triển khai ở lượt này (Milestone 4, ngoài phạm vi).** `BreakfastPostingJob.php`, `ExtraPersonPostingJob.php`, `ExtraBedPostingJob.php`, `NightAuditPipeline.php`, `NightAuditService.php` — 0 thay đổi. Phương án đã chọn cho tương lai: giữ 3 adapter, chỉ đổi nguồn đọc tên/giá (Implementation Plan mục 10).

## 10. Admin UI

**Không triển khai ở lượt này (Milestone 2, ngoài phạm vi).** `AppLayout.vue`, `/admin/service-rates` — 0 thay đổi hành vi (chỉ còn 1 hunk CSS header-padding có sẵn từ trước, không thuộc task này, vẫn unstaged). Thiết kế đầy đủ ở Implementation Plan mục 11.

## 11. Backward Compatibility

- `ALLOWED_PACKAGES`/`package_key` giữ nguyên 100% — xác nhận bằng `rg -n "ALLOWED_PACKAGES" app resources tests` vẫn trả về đúng các dòng cũ, không đổi.
- `rg -n "Bữa sáng mỗi đêm|Ăn sáng mỗi đêm|Người thêm / đêm|Giường phụ / đêm" app resources` vẫn còn 5 dòng trong `PackageEnrollmentController.php`/`PackagePanel.vue` — **đúng như dự kiến**, vì Milestone 1 không đổi nguồn hiển thị tên; các dòng này sẽ được thay ở Milestone 3.
- `booking_package_flags`, `service_rates`, `ServiceRateController`, `ServiceRateService`, `AddChargeForm.vue`, `FolioPanel.vue`, `ProductService` — 0 thay đổi.
- `BookingPackageController.php` (dead code, không gắn route — phát hiện lại, ghi nhận, không xoá vì ngoài phạm vi).

## 12. Migration and Backfill

2 migration mới (`2026_08_02_000000_create_service_packages_table.php`, `2026_08_02_000010_create_service_package_rates_table.php`) — đã chạy và rollback thử thành công trên **DB local (`lastella_pms`, MySQL, theo `.env` — không phải production)**. Seeder `ServicePackageSeeder` đã chạy 2 lần liên tiếp trên DB local để xác nhận idempotent (vẫn đúng 3 dòng). **Không chạy migration/seeder trên production.**

## 13. Audit and Permissions

- Permission mới `service_packages.manage`: thêm vào `RolePermissionSeeder::PERMISSIONS`, gán ADMIN (tự động qua `syncPermissions(self::PERMISSIONS)`) và MANAGER (thêm dòng rõ), **không** gán RECEPTION/SALES/HOUSEKEEPING/ACCOUNTANT. Test xác nhận (`test_service_packages_manage_permission_is_seeded_and_granted_correctly`).
- `service_rates.manage` giữ nguyên, không đổi.
- Audit: đăng ký `ServicePackage::observe(AuditObserver::class)`, `ServicePackageRate::observe(AuditObserver::class)`, `BookingPackageFlag::observe(AuditObserver::class)` trong `AppServiceProvider::boot()`. Xác nhận qua test: tạo/sửa `ServicePackage` ghi đúng `AuditLog` (action `created`/`updated`, kèm old/new attribute diff). Đây cũng bổ khuyết audit trail còn thiếu cho enrollment (`BookingPackageFlag`) mà hệ thống trước đây chưa có.

## 14. Files Changed

**Mới:**
- `app/Enums/PackageCalculationStrategy.php`
- `app/Enums/PackageQuantityMode.php`
- `app/Enums/PackagePostingFrequency.php`
- `app/Models/ServicePackage.php`
- `app/Models/ServicePackageRate.php`
- `database/migrations/2026_08_02_000000_create_service_packages_table.php`
- `database/migrations/2026_08_02_000010_create_service_package_rates_table.php`
- `database/seeders/ServicePackageSeeder.php`
- `tests/Feature/ServicePackageCatalogTest.php`
- `tests/Feature/ServicePackageRateVersioningTest.php`
- `docs/reviews/dynamic-service-package-admin-architecture-review.md`
- `docs/implementation-plans/dynamic-service-package-admin-implementation-plan.md`
- `docs/reports/dynamic-service-package-admin-implementation-report.md` (file này)

**Sửa:**
- `database/seeders/DatabaseSeeder.php` (+1 dòng, đăng ký seeder)
- `database/seeders/RolePermissionSeeder.php` (+2 dòng, permission mới)
- `app/Providers/AppServiceProvider.php` (+2 import, +3 `observe()`)

**Không sửa** (đã xác nhận qua `git diff --stat`): mọi Controller/Service/Vue/route liên quan booking-package/service-rate hiện có.

## 15. Tests

- Ban đầu: `tests/Feature/ServicePackageCatalogTest.php` (9 test), `tests/Feature/ServicePackageRateVersioningTest.php` (7 test) — 16/16 PASS.
- **Sau review đóng Milestone 1 (mục 21):** bổ sung 6 test compatibility/guard còn thiếu (code unique, legacy package-key compatibility ×2, unimplemented-strategy guard, unimplemented-quantity-mode guard, incompatible-strategy/quantity-mode guard, backfill-passes-guard) + 1 test tie-breaker cho 2 rate cùng `effective_from` → **tổng 24/24 PASS** (`ServicePackageCatalogTest` 16, `ServicePackageRateVersioningTest` 8).
- Targeted suite (`--filter="ServicePackage|ServiceRate|PackageEnrollment|BookingPackage|BreakfastPosting|ExtraPersonPosting|ExtraBedPosting|NightAudit|Folio|BookingServiceRates"`), chạy lại sau khi bổ sung test: **226 passed, 0 failed** (log: `storage/logs/dynamic_package_closure_targeted_run.txt`). Xác nhận riêng từng class quan trọng đều PASS: `ServicePackageCatalogTest`, `ServicePackageRateVersioningTest`, `ServiceRateCrudTest`, `ServiceRateVersioningTest`, `PackageEnrollmentControllerTest`, `PackageEnrollmentServiceTest`, `BookingPackageEnrollmentTest`, `BreakfastPostingJobFeatureTest`, `ExtraPersonPostingJobTest`, `ExtraBedPostingJobTest`, `BookingServiceRatesPropTest`.
- Full suite: **970 passed, 24 failed** (log: `storage/logs/dynamic_package_full_run.txt`). 24 lỗi đều thuộc `BookingManagementUiTest` (11), `RoomAvailabilityCheckerTest` (12), `LateCheckoutFeeTest` (1) — **cùng nhóm lỗi pre-existing** đã ghi nhận và xác minh (git-stash) là không liên quan trong các báo cáo trước (`booking-package-pricing-navigation-fix-report.md`, `booking-service-rates-array-shape-hotfix-report.md`). Số lượng dao động nhẹ so với các lần chạy trước (24-26) do bản chất flaky/phụ thuộc thời gian (`PerStayAttributionTest` không xuất hiện lần này) — không có lỗi nào mới, không có lỗi nào thuộc phạm vi package/service-rate/posting.

## 16. Build

`npm run build`: **PASS**, 47.21s, output hash không đổi so với trước (`app-C7fPOWgo.css`, `app-BHcYkM-M.js`) — xác nhận không có bất kỳ file frontend nào bị đụng trong Milestone 1.

## 17. Known Limitations

- Chưa có UI quản trị package (Milestone 2) — Product Owner **chưa thể** tự đổi tên/giá qua giao diện ngay sau lượt này; đây vẫn cần thêm 1 lượt triển khai riêng.
- Chưa có giá thật cho 3 package trong `service_package_rates` (bảng rỗng có chủ đích) — cần Milestone 2 + nhập giá thủ công.
- `PackageEnrollmentController`/`PackagePanel.vue` vẫn hard-code tên (Milestone 3).
- 3 Posting Job vẫn đọc `service_rates`/`ChargeType`, chưa đọc `ServicePackageRate` (Milestone 4).
- 5/7 calculation strategy, 3/5 quantity mode, 1/2 posting frequency đã khai báo trong enum nhưng chưa có backend thật — không được phép chọn ở UI khi Milestone 2 triển khai.
- `BookingPackageController.php` (dead code, không route) vẫn còn tồn tại — ngoài phạm vi dọn dẹp lần này.
- Ví dụ "X"/"Nghỉ tháng" trong Prompt không khớp dữ liệu quan sát được (bảng `service_rates` rỗng ở local) — cần Product Owner xác nhận trước khi Milestone 2 xây tính năng "xem booking đang dùng gói này" trên môi trường có dữ liệu thật.

## 18. Deployment Steps

Chưa áp dụng — chưa commit/push/deploy. Khi được duyệt: migration mới (`service_packages`, `service_package_rates`) an toàn để chạy trên production (chỉ tạo bảng mới), nhưng vẫn phải qua đúng quy trình backup + review đã áp dụng ở các hotfix trước trong dự án này, không rút gọn quy trình chỉ vì thay đổi "chỉ là bảng mới".

## 19. Rollback

```bash
php artisan migrate:rollback --step=2   # chỉ trên local/test đã dùng trong lượt này — đã verify chạy sạch
```
Revert 3 file sửa (`DatabaseSeeder.php`, `RolePermissionSeeder.php`, `AppServiceProvider.php`) bằng `git checkout -- <file>`, xoá các file mới bằng `git clean` **có chọn lọc** (không dùng `git clean -fd` toàn repo — chỉ xoá đúng danh sách file mới liệt kê ở mục 14).

## 20. Readiness Decision (ban đầu)

~~PARTIALLY IMPLEMENTED — REVIEW REQUIRED~~ — xem mục 21 cho trạng thái cuối sau review đóng Milestone 1.

---

## 21. Milestone 1 Closure Review (2026-08-02)

### Xác minh package key

- **Package key legacy trong code:** `BREAKFAST_PER_NIGHT`, `EXTRA_PERSON_PER_NIGHT`, `EXTRA_BED_PER_NIGHT` (hằng số `PackageEnrollmentService`).
- **Đang ghi vào `booking_package_flags.package_key`:** đúng 3 giá trị trên (cột `string(50)`, không FK, unique theo `(booking_id, package_key)`). DB local hiện có 0 dòng (chưa booking nào đăng ký gói) — không đối chiếu được dữ liệu thật, nhưng constant/schema là nguồn sự thật và đã xác nhận khớp.
- **Fixture/test hiện dùng key nào:** toàn bộ test cũ (`PackageEnrollmentControllerTest`, `PackageEnrollmentServiceTest`, `BookingPackageEnrollmentTest`, `ExtraPersonPostingJobTest`, `ExtraBedPostingJobTest`, `BreakfastPostingJobFeatureTest`) đều tham chiếu qua hằng số `PackageEnrollmentService::*`, không hard-code chuỗi riêng.
- **Backfill Milestone 1 tạo code nào:** `BREAKFAST_PER_NIGHT`, `EXTRA_PERSON_PER_NIGHT`, `EXTRA_BED_PER_NIGHT` — **khớp tuyệt đối**, không dùng `EXTRA_PERSON`/`EXTRA_BED` (đó là giá trị `ChargeType`, không phải `package_key`).
- **Kết luận mismatch:** **Không có mismatch.** Đã bổ sung 2 test compatibility (`test_backfilled_codes_match_the_legacy_package_enrollment_constants_exactly`, `test_a_legacy_booking_package_flag_resolves_to_the_matching_service_package`) tham chiếu trực tiếp hằng số thật (không hard-code lại chuỗi) để chống drift âm thầm về sau. Không cần sửa backfill, không tạo alias.

### Review 2 migration

- `service_packages`: `code` unique; `charge_type`/`calculation_strategy`/`quantity_mode`/`posting_frequency` string thô (không FK, không enum cast); `default_quantity`/`display_order` integer; `is_active`/`is_bookable` boolean; `created_by`/`updated_by` FK `users` nullable, `nullOnDelete()`; timestamps đầy đủ.
- `service_package_rates`: `service_package_id` FK `service_packages`, **`restrictOnDelete()`** (không nullable — bắt buộc phải có package); `unit_price` decimal(12,2); `effective_from` date; `is_active` boolean; `tax_rate` decimal(5,4) default 0; `gl_account_code` nullable; `created_by` FK `users` nullable.
- 1 package có nhiều rate version: **Có** — không có unique constraint nào chặn nhiều `service_package_rates` cùng `service_package_id` (đúng thiết kế versioning).
- Ngăn trùng package code: **Có** — unique index trên `code`.
- Ngăn rate mồ côi: **Có ở mức DB** — `service_package_id` không nullable + FK constraint, không thể tạo rate không gắn package hợp lệ.
- Rule cho nhiều rate cùng `effective_from`: **Có, ở tầng application** (`orderByDesc('effective_from')->orderByDesc('id')` — dòng tạo sau thắng), **không phải constraint DB**. Đã bổ sung test (`test_two_rates_with_same_effective_from_resolve_to_the_most_recently_created_row`) — trước đó thiếu.
- Rollback an toàn khi đã có rate: **Có** — đã verify thực tế (Bước 10): `migrate:rollback --step=2` tự drop `service_package_rates` trước `service_packages` (đúng thứ tự phụ thuộc FK), không lỗi.
- FK delete behavior: `restrictOnDelete()` chặn xoá package **khi đã có rate** — đúng yêu cầu "không xoá package đã dùng" cho trường hợp có giá. **Giới hạn còn lại (ghi rõ, không fix ở Milestone 1):** không có bảo vệ DB-level cho trường hợp package **chưa có rate nhưng đã có `booking_package_flags` tham chiếu** (không có FK giữa 2 bảng này theo thiết kế tương thích đã chọn) — Milestone 2 phải tự check `BookingPackageFlag::where('package_key', $code)->exists()` trước khi cho xoá.

### Review backfill/seeder

- Phân loại: đây là **Data Backfill (B)** — không phải schema migration, không phải dev-only seed data giả.
- Idempotent: **Có**, verify lại lần 2 trong lượt này (Bước 10): seed 2 lần liên tiếp trên DB local, vẫn đúng 3 `ServicePackage`, 0 `ServicePackageRate`.
- Không tự tạo giá mặc định: **Đúng**, xác nhận lại 0 `service_package_rates` sau backfill.
- Không tự liên kết dòng `service_rates` có sẵn (kiểu "X"/"Nghỉ tháng"): **Đúng** — seeder không import, không query `ServiceRate` ở bất kỳ đâu.
- Không coi mọi `FOOD_BEVERAGE` là breakfast package: **Đúng** — seeder không đọc `service_rates` nên không có giả định đó.
- Không đổi `product_services`/`service_rates`/`booking_package_flags`: xác nhận lại bằng đếm số dòng trước/sau backfill trong Bước 10 — cả 3 bảng giữ đúng số dòng cũ (`service_rates`=0, `product_services`=10, `booking_package_flags`=0).
- `DatabaseSeeder.php` có gọi backfill: **Có**, đăng ký sau `ServiceRateSeeder`. An toàn trên production nếu ai đó chạy `db:seed` đầy đủ: **Có**, vì `firstOrCreate` + không phụ thuộc/không sửa dữ liệu khác — rủi ro tương đương các seeder catalog khác đã có sẵn trong cùng danh sách (không phải rủi ro mới).
- **Khuyến nghị: giữ trong `DatabaseSeeder`**, không chuyển sang migration-backfill hay Artisan command riêng — lý do đầy đủ ở Implementation Plan mục 21.

### Review model

- `ServicePackage`: fillable đầy đủ (không `$guarded`), casts đúng kiểu, quan hệ `rates()`/`createdBy()`/`updatedBy()`, scope `active()`/`bookable()`. **Mới bổ sung ở lượt này:** guard `booted()` chặn strategy/quantity-mode/posting-frequency chưa `implemented()` và tổ hợp không tương thích — ném `InvalidArgumentException`. Code immutability: **chưa enforce** (không có gì chặn sửa `code` sau khi dùng) — đúng như đã ghi nhận, để lại cho Milestone 2 vì chưa có Controller nào gọi update. Used/unused detection: **chưa có** — để Milestone 2. Soft delete: **không dùng** (đúng quyết định kiến trúc — xoá thật chỉ cho package chưa dùng, kiểm tra ở tầng Controller tương lai, không phải tầng model/DB).
- `ServicePackageRate`: quan hệ `package()`, casts đúng (`unit_price` decimal:2, `effective_from` date:Y-m-d, `is_active` boolean), scope `active()`/`effectiveOn()`. Temporal-row: xác nhận qua test — không có `update()` nào chạm `unit_price` của dòng cũ ở bất kỳ đâu trong Milestone 1. Tie-breaker: đã có, đã test (xem trên).
- **Xác nhận rõ:** cả 2 model **chưa được** bất kỳ Controller, Vue, route, hay Posting Job nào sử dụng ngoài `AppServiceProvider` (chỉ để `observe()`) — xác nhận bằng `rg -ln "ServicePackage::|ServicePackageRate::"`. Đây là giới hạn có chủ đích của Milestone 1, không phải thiếu sót.

### Review enum/strategy

7 `PackageCalculationStrategy`: `ONCE_PER_STAY_PER_NIGHT`, `MANUAL_QUANTITY_PER_NIGHT`, `ONCE_PER_BOOKING`, `ONCE_PER_ROOM`, `PER_ROOM_PER_NIGHT`, `PER_ADULT_PER_NIGHT`, `MANUAL_QUANTITY_ONCE`. **Implemented:** chỉ `ONCE_PER_STAY_PER_NIGHT` và `MANUAL_QUANTITY_PER_NIGHT` — vì đây là 2 strategy duy nhất có Posting Job thật + test thật (`BreakfastPostingJob`+`BreakfastPostingJobFeatureTest` cho cái đầu; `ExtraPersonPostingJob`/`ExtraBedPostingJob`+test tương ứng cho cái sau) đang chạy trong production hiện tại; 5 strategy còn lại chưa có bất kỳ dòng code posting nào, nên **không được** cho phép chọn — đã enforce cứng bằng guard model (mục trên), có test.

Mapping 3 package hiện tại:

| Package | calculation_strategy | quantity_mode | posting_frequency | charge_type |
|---|---|---|---|---|
| Ăn sáng mỗi đêm | ONCE_PER_STAY_PER_NIGHT | NONE | PER_NIGHT | FOOD_BEVERAGE |
| Người thêm / đêm | MANUAL_QUANTITY_PER_NIGHT | MANUAL_INPUT | PER_NIGHT | EXTRA_PERSON |
| Giường phụ / đêm | MANUAL_QUANTITY_PER_NIGHT | MANUAL_INPUT | PER_NIGHT | EXTRA_BED |

Quantity mode đã hỗ trợ (implemented): `NONE`, `MANUAL_INPUT` (trong tổng 5 khai báo: + `FROM_ADULTS`, `FROM_TOTAL_GUESTS`, `FROM_ROOMS` — chưa implemented).

### Review charge_type mapping

- `ServicePackage.charge_type` là **string thô**, không cast enum, không FK — giống hệt cách `ServiceRate.charge_type` đã làm trước đó (nhất quán style).
- 1 charge_type có thể có nhiều package: **Có thể về schema** (không có unique constraint nào chặn), dù hiện tại mỗi package đang dùng charge_type riêng.
- 1 package chỉ có 1 charge_type: **Đúng** (1 cột, không phải bảng nhiều-nhiều).
- Rate resolver tương lai (Milestone 4) sẽ resolve theo: **`service_package_id`**, không theo `charge_type` — đây chính là điểm khác biệt cốt lõi so với `service_rates` cũ.
- Thiết kế Milestone 1 có tránh lỗi "1 rate FOOD_BEVERAGE đại diện mọi package ăn uống" không: **Có** — vì giá được khoá theo `service_package_id` (1:1 với đúng 1 package), không khoá theo `charge_type` (bucket chung).
- Không có unique constraint sai nào chặn nhiều package cùng charge_type.
- **Không đổi `ProductService` architecture** trong review này — xác nhận `rg` không có thay đổi nào ở `app/Models/ProductService.php`, `ProductServiceController`.

### Review permission và provider

- Permission mới: `service_packages.manage`. Gán: ADMIN (tự động), MANAGER (rõ ràng). Không gán RECEPTION/SALES/HOUSEKEEPING/ACCOUNTANT.
- **Chưa được dùng bởi Policy/Controller nào** ở Milestone 1 (chưa có `ServicePackagePolicy`, chưa có Controller) — permission tồn tại trong bảng `permissions` nhưng không cấp năng lực thực tế nào (không có `authorize()` nào tham chiếu tới nó).
- **Vì sao vẫn thêm ngay ở Milestone 1 (khuyến nghị: giữ):** (a) nền tảng rõ ràng, không mơ hồ — tên permission đã cố định trước khi Milestone 2 cần dùng, tránh phải sửa `RolePermissionSeeder` thêm 1 lần nữa; (b) hoàn toàn cộng thêm, không đổi hành vi bất kỳ permission/role nào hiện có; (c) có tiền lệ trong chính RolePermissionSeeder (nhiều permission được seed trước khi tính năng dùng nó hoàn thiện). Phương án thay thế (dời sang Milestone 2) cũng hợp lệ nếu Product Owner muốn giảm phạm vi diff Milestone 1 tối đa — không có rủi ro kỹ thuật ở phương án nào, đây là lựa chọn phong cách quản lý phạm vi thuần túy.
- `AppServiceProvider` đăng ký: import `BookingPackageFlag`, `ServicePackage`, `ServicePackageRate` + 3 `observe(AuditObserver::class)`.
- **Side effect runtime có thật, cần ghi rõ:** từ nay **mọi lần enroll/unenroll gói** (`BookingPackageFlag` create/delete) cũng ghi thêm 1 dòng `AuditLog` — trước đây enrollment không có audit trail. Đây là thay đổi hành vi *cộng thêm* (không ai bị mất khả năng gì), đã xác nhận targeted suite (`BookingPackageEnrollmentTest`, `PackageEnrollmentControllerTest`, `PackageEnrollmentServiceTest`) vẫn PASS toàn bộ sau thay đổi.
- Không có boot-time query, không N+1 — `observe()` chỉ đăng ký closure, không truy vấn DB tại thời điểm boot.

### Review test và bổ sung compatibility test

Đã đối chiếu đủ 12 mục checklist. Bổ sung 4 khoảng trống phát hiện được: (4) code unique, (5) unimplemented-strategy guard, (9) tie-breaker cùng `effective_from`, (12) legacy package-key compatibility — tổng 8 test mới, nâng tổng test Milestone 1 từ 16 lên **24**, tất cả PASS.

### Xác minh migration local (Bước 10)

Môi trường: **DB local `lastella_pms` (MySQL, theo `.env` — không phải production)**. Lệnh đã chạy:
```bash
php artisan migrate:rollback --step=2      # drop 2 bảng — verify thứ tự đúng, không lỗi
php artisan migrate                        # tạo lại 2 bảng
php artisan db:seed --class="Database\Seeders\ServicePackageSeeder"   # lần 1
php artisan db:seed --class="Database\Seeders\ServicePackageSeeder"   # lần 2 — vẫn 3 dòng
```
Kết quả: rollback sạch, `booking_package_flags`/`service_rates` không bị mất; backfill idempotent (3 `ServicePackage`, 0 `ServicePackageRate` sau cả 2 lần seed); `service_rates`=0, `product_services`=10, `booking_package_flags`=0 — không đổi so với trước backfill.

## 22. Readiness Decision (cuối, thay thế mục 20)

**MILESTONE 1 COMPLETE — APPROVED FOR MILESTONE 1 COMMIT**

Không phải READY FOR DEPLOYMENT của toàn chức năng "Gói dịch vụ" — chỉ riêng nền tảng dữ liệu Milestone 1 (catalog + rate versioning + backfill tương thích + guard strategy + audit) đã hoàn tất, kiểm thử đầy đủ (24/24 test mới PASS, 226/226 targeted PASS, 0 lỗi mới trong full suite), không regression, không mismatch package key, không tạo dữ liệu giả, chưa đụng UI/booking/posting. ChatGPT đã review và phê duyệt commit nền tảng này — xem mục "Commit Closure" (được bổ sung sau khi commit thật diễn ra) để biết SHA và xác nhận cuối cùng.
