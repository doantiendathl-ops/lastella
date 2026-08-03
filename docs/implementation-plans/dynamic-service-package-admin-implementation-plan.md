# Dynamic Service Package Administration — Implementation Plan

**Ngày:** 2026-08-02
**Căn cứ:** `docs/reviews/dynamic-service-package-admin-architecture-review.md`
**Trạng thái triển khai code trong lượt này:** Chỉ Milestone 1.

**ChatGPT review: APPROVED FOR MILESTONE 1 COMMIT.**
**Milestone 1 status: COMPLETE.** Chưa deploy production. **Toàn bộ chức năng "Gói dịch vụ": NOT YET COMPLETE** — Milestone 2-4 (Admin UI, Booking Enrollment Integration, Posting/Pricing Integration) vẫn **bắt buộc** trước khi đưa chức năng quản trị giá gói vào vận hành thật.

**Khoá quyết định sản phẩm (đã duyệt, áp dụng cho Milestone 2+):**
1. Menu "Biểu giá dịch vụ" → "Gói dịch vụ".
2. Menu "Gói dịch vụ" dẫn tới trang quản trị `ServicePackage` mới, không tái dùng `/admin/service-rates`.
3. `/admin/service-rates` giữ nguyên cho CityTax/LateCheckout/EarlyCheckin/AddChargeForm, không còn là mục quản trị gói chính trên sidebar sau Milestone 2.
4. Không đưa UI chỉnh giá gói lên production trước khi cả 3 Posting Job đọc `service_package_rates` (Milestone 4).
5. Giá hiển thị ở UI quản trị và giá Posting Job đọc phải luôn cùng 1 nguồn/1 resolver — không được lệch nhau.

---

## 1. Objective

Cho phép Product Owner tự quản trị tên, giá theo thời gian, trạng thái, đơn vị và cách tính của các "gói dịch vụ" (package) qua giao diện quản trị — không cần sửa code — trong khi bảo toàn 100% hành vi và dữ liệu hiện có (booking cũ, FolioEntry đã post, Product/Service catalog, biểu giá chung cho CityTax/LateCheckout/EarlyCheckin/AddChargeForm).

## 2. Approved Architecture

- Data model: Phương án B — `service_packages` + `service_package_rates`, `service_rates` giữ nguyên.
- Enrollment: giữ `booking_package_flags.package_key` làm khoá tương thích chính.
- Posting: giữ 3 adapter Posting Job hiện tại (Milestone 4 tương lai), không xây Generic Engine ngay.
- Permission: thêm `service_packages.manage`, giữ `service_rates.manage`.
- Calculation strategy: enum registry, chỉ 2 strategy được "implemented" (`ONCE_PER_STAY_PER_NIGHT`, `MANUAL_QUANTITY_PER_NIGHT`) — khớp đúng 3 package hiện tại (Breakfast dùng cái đầu; ExtraPerson/ExtraBed dùng cái sau).

## 3. Out of Scope (lượt triển khai này)

Milestone 2 (Admin UI), Milestone 3 (Booking Enrollment Integration), Milestone 4 (Posting/Pricing Integration), Milestone 5 (Cleanup) — **được lập kế hoạch đầy đủ trong tài liệu này (mục 6-13) nhưng KHÔNG triển khai code ở lượt này.** Không đổi `AppLayout.vue`, không đổi bất kỳ Vue booking nào, không đổi Posting Job nào, không đổi `/admin/service-rates`.

---

## 4. Data Model Changes

### `service_packages`

| Cột | Kiểu | Ghi chú |
|---|---|---|
| id | bigint PK | |
| code | string(50), unique | Bất biến sau khi đã dùng (Milestone 2 validation) |
| name | string(150) | |
| description | text, nullable | |
| charge_type | string(30) | Khớp `ChargeType::cases()`, không cast enum (đồng bộ style với `ServiceRate`) |
| calculation_strategy | string(40) | Khớp `PackageCalculationStrategy` |
| quantity_mode | string(30) | Khớp `PackageQuantityMode` |
| default_quantity | unsignedInteger, default 1 | |
| unit_label | string(30) | |
| posting_frequency | string(20) | Khớp `PackagePostingFrequency` |
| is_active | boolean, default true | Còn được chọn khi tạo giá mới / dùng cho posting |
| is_bookable | boolean, default true | Còn xuất hiện để đăng ký mới trên booking |
| display_order | integer, default 0 | |
| created_by / updated_by | FK users, nullable | |
| timestamps | | |

Index: unique(`code`); index(`is_active`, `is_bookable`, `display_order`).

### `service_package_rates`

| Cột | Kiểu | Ghi chú |
|---|---|---|
| id | bigint PK | |
| service_package_id | FK -> service_packages, cascadeOnDelete? **Không** — dùng `restrictOnDelete` mặc định (không cho xoá package khi còn rate) | |
| unit_price | decimal(12,2) | |
| effective_from | date | |
| is_active | boolean, default true | |
| tax_rate | decimal(5,4), nullable, default 0 | |
| gl_account_code | string(30), nullable | |
| created_by | FK users, nullable | |
| timestamps | | |

Index: index(`service_package_id`, `is_active`, `effective_from`).

## 5. Migration and Backfill

Migration file (chạy thứ tự):
1. `2026_08_02_000000_create_service_packages_table.php`
2. `2026_08_02_000010_create_service_package_rates_table.php`

Backfill: `database/seeders/ServicePackageSeeder.php`, gọi `ServicePackage::firstOrCreate(['code' => $code], [...])` cho 3 package thật (mã đúng như code hiện tại, không theo tóm tắt của Prompt — xem Architecture Review mục 3):

| code | name | charge_type | calculation_strategy | quantity_mode | unit_label | posting_frequency | display_order |
|---|---|---|---|---|---|---|---|
| BREAKFAST_PER_NIGHT | Ăn sáng mỗi đêm | FOOD_BEVERAGE | ONCE_PER_STAY_PER_NIGHT | NONE | đêm | PER_NIGHT | 10 |
| EXTRA_PERSON_PER_NIGHT | Người thêm / đêm | EXTRA_PERSON | MANUAL_QUANTITY_PER_NIGHT | MANUAL_INPUT | người | PER_NIGHT | 20 |
| EXTRA_BED_PER_NIGHT | Giường phụ / đêm | EXTRA_BED | MANUAL_QUANTITY_PER_NIGHT | MANUAL_INPUT | giường | PER_NIGHT | 30 |

**Không tạo `service_package_rates` nào trong seeder** — không có giá thật để backfill an toàn (đã xác nhận `service_rates` rỗng ở môi trường kiểm tra; và dù có, không được suy diễn giá FOOD_BEVERAGE hiện có "chính là" giá gói ăn sáng mà không có xác nhận nghiệp vụ — đúng nguyên tắc mục VI Prompt). Ghi rõ trong `known limitations` của báo cáo: admin phải tự nhập giá sau khi Milestone 2 có UI (hoặc qua Tinker chỉ trên local/test nếu cần thử nghiệm — không áp dụng production).

Đăng ký `ServicePackageSeeder::class` vào `DatabaseSeeder::run()` (giống cách `ServiceRateSeeder` đã được đăng ký) — để môi trường local/test/fresh-install mới có đủ 3 package khi seed đầy đủ. **Không tự chạy `db:seed` trên production** — chỉ chạy migration/seeder trên DB local để kiểm thử trong lượt này.

Rollback: `down()` của cả 2 migration chỉ `Schema::dropIfExists` — an toàn, không đụng bảng khác.

## 6. Backend Services (Milestone 1)

- `App\Models\ServicePackage` — fillable đủ các cột trên, casts (`is_active`, `is_bookable` → boolean; `default_quantity`, `display_order` → integer), quan hệ `rates(): HasMany(ServicePackageRate::class)`, `createdBy()/updatedBy(): BelongsTo(User::class)`, scope `active()`, scope `bookable()`.
- `App\Models\ServicePackageRate` — fillable đủ cột, casts (`unit_price` decimal:2, `effective_from` date:Y-m-d, `tax_rate` decimal:4, `is_active` boolean), quan hệ `package(): BelongsTo(ServicePackage::class)`, `createdBy(): BelongsTo(User::class)`, scope `active()`, scope `effectiveOn(string $date)`.
- **Không tạo Controller/Route mới ở Milestone 1** — không có UI để phục vụ, tránh route "chết" không dùng.

## 7. Calculation Strategies (đăng ký, chưa gắn posting)

`App\Enums\PackageCalculationStrategy` (7 case, method `implemented(): array` trả `[OncePerStayPerNight, ManualQuantityPerNight]`), `App\Enums\PackageQuantityMode` (5 case, `implemented()` trả `[None, ManualInput]`), `App\Enums\PackagePostingFrequency` (2 case, `implemented()` trả `[PerNight]`). Đây là *registry*, không có logic tính toán thật ở Milestone 1 (logic tính toán thật thuộc Milestone 4, gắn với posting job).

## 8. Pricing Resolution

Milestone 1 chỉ cần scope Eloquent (`active()`, `effectiveOn()`) trên `ServicePackageRate` — đủ để test độc lập cơ chế versioning, **chưa gắn** vào bất kỳ controller/job nào đọc theo `service_package_id` trong production flow (đó là Milestone 4).

## 9. Enrollment Changes

**Không có ở Milestone 1.** Milestone 3 (kế hoạch, chưa triển khai):
- `Packages.vue`/`PackagePanel.vue` sẽ nhận danh sách package từ `ServicePackage::active()->bookable()->orderBy('display_order')->get()` thay vì hằng số PHP.
- `PackageEnrollmentController::buildAvailablePackages()` sẽ thay `$packageMap` hard-code bằng query trên `ServicePackage`, join `ServicePackageRate` mới nhất hợp lệ để lấy `current_rate`.
- `BookingPackageFlag.package_key` **giữ nguyên** — tiếp tục là cột lưu trực tiếp, không đổi tên cột, không migrate dữ liệu.
- Cân nhắc thêm cột `service_package_id` (nullable) trên `booking_package_flags` ở một migration riêng của Milestone 3 để tăng tốc join, backfill từ `package_key` — idempotent, có rollback, **không chạy production trong lượt đó nếu chưa qua review riêng**.

## 10. Posting Changes

**Không có ở Milestone 1.** Milestone 4 (kế hoạch): **Phương án 1 — giữ 3 adapter**, mỗi job đổi đúng 2 điểm:
1. Thay `$this->rateService->resolveFor(ChargeType::X, $date)` bằng đọc `ServicePackageRate` mới nhất theo `service_package_id` tương ứng `code` cố định (`BREAKFAST_PER_NIGHT`/...).
2. Thay chuỗi mô tả cứng (`"Bữa sáng đêm {ngày}"`) bằng `"{$package->name} đêm {ngày}"` — tên package đọc từ DB tại thời điểm posting (không snapshot ngược — đây là hành vi *mới* cho lần posting *tiếp theo*, không đổi `FolioEntry` đã ghi, đúng ADR-50).

Không chọn Phương án 2 (Generic Engine) trong Milestone 4 vì: (a) chỉ có 2 strategy thật, chưa đủ đa dạng để việc trừu tượng hoá mang lại lợi ích rõ ràng; (b) rủi ro thay đổi hành vi idempotency/lock đồng thời cho cả 3 job trong 1 lần refactor lớn cao hơn nhiều so với sửa từng adapter riêng; (c) khớp nguyên tắc Minimal Change được Prompt ưu tiên rõ ràng.

## 11. Frontend Changes

**Không có ở Milestone 1.** Milestone 2 (kế hoạch — Admin UI):
- Đổi route mới `admin.service-packages.*` (giữ `admin.service-rates.*` chạy song song, không xoá/redirect — theo Open Decision #1 của Architecture Review, khuyến nghị giữ song song vì `service_rates` vẫn phục vụ CityTax/LateCheckout/EarlyCheckin).
- Trang `Admin/ServicePackages/Index.vue`: bảng liệt kê đúng 9 cột theo Prompt mục X, form tạo/sửa với dropdown strategy **chỉ hiển thị `implemented()`**, khu vực biểu giá riêng (giống `ServiceRates/History.vue` nhưng theo `service_package_id`).
- Menu `AppLayout.vue`: đổi nhãn "Biểu giá dịch vụ" → "Gói dịch vụ" (hoặc thêm mục mới, tuỳ quyết định cuối theo Open Decision #1), permission `service_packages.manage`.
- Modal xác nhận ngừng hoạt động, cảnh báo đổi strategy, format tiền — theo đúng UX mục X Prompt.

Milestone 3 (kế hoạch — Booking integration): `Packages.vue` xoá bảng `JOB_CLASS_FOR_PACKAGE`/label cứng, nhận đủ từ backend; `PackagePanel.vue` xoá `PACKAGE_LABELS`, hiển thị toàn bộ package `bookable` (không chỉ Breakfast) — cần quyết định UX có mở rộng panel rút gọn hay không (Open Question đã nêu ở hotfix trước, vẫn còn mở).

## 12. Authorization

Thêm vào `RolePermissionSeeder::PERMISSIONS`: `service_packages.manage`. `ADMIN` tự động có (qua `syncPermissions(self::PERMISSIONS)`). `MANAGER` thêm dòng `'service_packages.manage'` vào mảng sync riêng. Không thêm cho SALES/RECEPTION/HOUSEKEEPING/ACCOUNTANT. `Permission::findOrCreate()` đã idempotent sẵn (không cần sửa cơ chế run()).

Milestone 2 sẽ tạo `ServicePackagePolicy`/`ServicePackageRatePolicy` (mirror `ServiceRatePolicy`) khi có Controller thật — không tạo Policy "treo" ở Milestone 1 (không có Controller nào gọi `authorize()` tới nó).

## 13. Audit

Milestone 1: đăng ký `ServicePackage::observe(AuditObserver::class)`, `ServicePackageRate::observe(AuditObserver::class)`, `BookingPackageFlag::observe(AuditObserver::class)` trong `AppServiceProvider::boot()` — 3 dòng, đúng vị trí các `observe()` khác đã có. `AuditObserver` tự động ghi old/new attribute diff, user, thời gian, IP — thoả toàn bộ yêu cầu mục XII (trừ "lý do" và "booking liên quan" nếu nghiệp vụ muốn ghi riêng — không có trong `AuditObserver` hiện tại, để Milestone 2 quyết định nếu cần).

## 14. Backward Compatibility

- `package_key`/`ALLOWED_PACKAGES` **giữ nguyên trong toàn bộ Milestone 1** — không sửa `PackageEnrollmentService.php`. `rg -n "ALLOWED_PACKAGES"` sau Milestone 1 vẫn trả về đúng các dòng hiện có (chưa xoá, vì Milestone 3 mới cần đánh giá lại — hiện vẫn là nguồn danh mục chính cho toàn bộ luồng booking).
- `/admin/service-rates` không đổi, không redirect.
- `booking_package_flags` không đổi schema, không backfill dữ liệu.

## 15. Milestones

Chỉ Milestone 1 nằm trong lượt triển khai này. Milestone 2-5 giữ đúng nội dung đã liệt kê trong Prompt mục XIV, không lặp lại ở đây — xem Prompt gốc + mục 9-11 tài liệu này làm chi tiết kỹ thuật bổ sung cho khi thực hiện.

## 16. File-Level Plan (Milestone 1)

| File | Loại |
|---|---|
| `database/migrations/2026_08_02_000000_create_service_packages_table.php` | Mới |
| `database/migrations/2026_08_02_000010_create_service_package_rates_table.php` | Mới |
| `app/Models/ServicePackage.php` | Mới |
| `app/Models/ServicePackageRate.php` | Mới |
| `app/Enums/PackageCalculationStrategy.php` | Mới |
| `app/Enums/PackageQuantityMode.php` | Mới |
| `app/Enums/PackagePostingFrequency.php` | Mới |
| `database/seeders/ServicePackageSeeder.php` | Mới |
| `database/seeders/RolePermissionSeeder.php` | Sửa (+permission) |
| `database/seeders/DatabaseSeeder.php` | Sửa (+seeder call) |
| `app/Providers/AppServiceProvider.php` | Sửa (+3 observe()) |
| `tests/Feature/ServicePackageCatalogTest.php` | Mới |
| `tests/Feature/ServicePackageRateVersioningTest.php` | Mới |

## 17. Test Matrix (Milestone 1)

1. Migration tạo đúng bảng/cột `service_packages`, `service_package_rates`.
2. `ServicePackageSeeder` backfill đúng 3 dòng với code/charge_type/strategy/quantity_mode đúng bảng mục 5.
3. Chạy seeder 2 lần không tạo trùng (idempotent).
4. `ServicePackage::rates()` trả đúng các `ServicePackageRate` liên kết.
5. Scope `ServicePackage::active()`/`bookable()` lọc đúng.
6. Scope `ServicePackageRate::active()`/`effectiveOn()` lọc đúng theo ngày hiệu lực (mirror `ServiceRateVersioningTest`: rate tương lai không resolve, rate inactive không resolve, rate cũ hơn business date được chọn khi có 2 rate).
7. Permission `service_packages.manage` được seed, `ADMIN`/`MANAGER` có, `RECEPTION` không có.
8. `AuditLog` được tạo khi tạo mới / cập nhật `ServicePackage` (qua `AuditObserver`).
9. Regression: `rg -n "ALLOWED_PACKAGES"` vẫn còn nguyên (chưa xoá — đúng dự kiến, giải thích trong báo cáo).
10. Regression: test suite hiện có (`ServiceRateCrudTest`, `ServiceRateVersioningTest`, `PackageEnrollmentControllerTest`, `PackageEnrollmentServiceTest`, `BookingPackageEnrollmentTest`, `BookingServiceRatesPropTest`, các Posting Job test, Folio/Booking test) **không đổi kết quả** — vì không file nào trong số đó bị sửa.

## 18. Rollback

```bash
php artisan migrate:rollback --step=2   # chỉ trên local/test — drop 2 bảng mới
```
Sửa `RolePermissionSeeder`/`DatabaseSeeder`/`AppServiceProvider` revert bằng `git checkout` từng file nếu cần — không có dữ liệu production bị đụng nên rollback code-only là đủ.

## 19. Deployment

Không nằm trong phạm vi tài liệu này (chưa commit/push/deploy ở lượt này). Khi được duyệt: migration mới chạy an toàn trên production (chỉ tạo bảng mới, không đụng bảng cũ) — nhưng vẫn phải qua đúng quy trình review/backup/staging đã dùng ở các hotfix trước, không tự suy diễn "an toàn nên bỏ qua quy trình".

## 20. Definition of Done (Milestone 1)

- 2 migration chạy thành công trên DB local/test, `down()` chạy được.
- 3 package backfill đúng, idempotent.
- Model + scope hoạt động đúng theo test.
- Permission mới seed đúng, đúng role.
- Audit log ghi được cho `ServicePackage`.
- Toàn bộ test cũ liên quan (targeted suite) vẫn PASS, không bị ảnh hưởng.
- `npm run build` PASS (không có thay đổi frontend, nhưng vẫn xác nhận theo đúng quy trình đã dùng ở các task trước).
- Không migration/seeder nào chạy trên production.
- Chưa commit, chưa push.

---

## 21. Milestone 1 Closure Addendum (2026-08-02)

Kết quả review đóng Milestone 1 — không đổi phạm vi đã duyệt (mục 2-3), chỉ bổ sung phát hiện/khuyến nghị/1 fix nhỏ:

**Xác minh package key:** Không có mismatch giữa mã backfill và `PackageEnrollmentService::ALLOWED_PACKAGES` — cả hai đều dùng `BREAKFAST_PER_NIGHT`/`EXTRA_PERSON_PER_NIGHT`/`EXTRA_BED_PER_NIGHT`. Đã thêm 2 test compatibility xác nhận trực tiếp bằng hằng số thật (không hard-code lại chuỗi trong test) để tránh drift âm thầm trong tương lai.

**Khuyến nghị vị trí backfill — Giữ trong `DatabaseSeeder`:** `ServicePackageSeeder` theo đúng pattern `firstOrCreate` đã có tiền lệ trực tiếp là `ServiceRateSeeder` (seeder liền kề trong cùng `DatabaseSeeder::run()`, cùng kiểu "backfill catalog mặc định nếu chưa có"). Không có tiền lệ "migration data backfill" hay "command riêng" nào khác trong codebase để theo, nên tạo một pattern mới chỉ cho seeder này là không nhất quán và không cần thiết cho quy mô 3 dòng dữ liệu. Rủi ro "chạy nhầm `db:seed` đầy đủ trên production" là rủi ro **đã tồn tại từ trước** với mọi seeder khác trong `DatabaseSeeder` (không phải rủi ro mới do `ServicePackageSeeder` gây ra) — seeder này an toàn để nằm trong batch đó vì `firstOrCreate` + không tạo giá + không đụng bảng khác. Không chuyển sang Artisan command riêng trong Milestone 1.

**Bổ sung 1 guard nhỏ ở tầng model (ngoài kế hoạch gốc, phát sinh từ review):** `ServicePackage::booted()` (`static::saving`) chặn cứng việc lưu `calculation_strategy`/`quantity_mode`/`posting_frequency` không thuộc `implemented()`, và chặn tổ hợp `quantity_mode` không tương thích `calculation_strategy` (`OncePerStayPerNight` ⇔ `None`, `ManualQuantityPerNight` ⇔ `ManualInput`). Đây là defense-in-depth ở tầng dữ liệu, độc lập với validation Form Request sẽ có ở Milestone 2 — không thay đổi phạm vi Milestone 1 (vẫn thuần backend, chưa UI), chỉ làm chắc thêm nền tảng trước khi Milestone 2 xây trên đó.

**Ghi nhận khoảng trống chưa xử lý (để lại cho Milestone 2, không fix ở đây):** không có kiểm tra "package đã được dùng chưa" (qua `booking_package_flags.package_key` hoặc `service_package_rates`) trước khi cho phép sửa `code`/xoá package — vì Milestone 1 chưa có Controller nào thực hiện các hành động đó, nên chưa cấp bách, nhưng Milestone 2 **bắt buộc** phải thêm guard này trước khi mở form sửa/xoá.
