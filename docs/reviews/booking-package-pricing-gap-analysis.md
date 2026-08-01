# Booking Package Pricing Gap Analysis

**Phạm vi điều tra ban đầu:** Chỉ điều tra, không sửa code, không sửa dữ liệu, không migration/seed.
**Ngày điều tra:** 2026-08-01
**Người điều tra:** Senior Laravel/Vue/Inertia Architect (Claude)

**Cập nhật trạng thái triển khai (2026-08-01):** Hai khoảng trống thuộc "Phương án A" (mục 13) đã được **sửa trong code**:
- `resources/js/Layouts/AppLayout.vue` — đã bổ sung mục menu "Biểu giá dịch vụ" trỏ tới `admin.service-rates.index` (`/admin/service-rates`), điều kiện `can('service_rates.manage')`.
- `resources/js/Pages/Admin/Bookings/Partials/PackagePanel.vue` — đã đổi nhãn "Bữa sáng mỗi đêm" → "Ăn sáng mỗi đêm" cho nhất quán với backend.

**ChatGPT review status: APPROVED FOR COMMIT.**
**Trạng thái code: hoàn thành, chưa deploy** (đã/đang commit vào lịch sử git local, chưa push, chưa smoke test trên môi trường đã deploy).

Nội dung điều tra gốc (mục 1–12, 14–17) được giữ nguyên nguyên trạng làm hồ sơ căn cứ — không đổi kết luận, không đổi cảnh báo nghiệp vụ, và **vẫn giữ nguyên việc chưa tự tạo/seed giá `FOOD_BEVERAGE`** (chờ Product Owner quyết định, xem mục 17). Mục 11, 13, 18 đã được cập nhật để phản ánh trạng thái "đã triển khai, đã duyệt commit". Chi tiết triển khai đầy đủ xem tại `docs/reports/booking-package-pricing-navigation-fix-report.md`.

---

## 1. Executive Summary

Ba gói dịch vụ ("Ăn sáng mỗi đêm", "Người thêm / đêm", "Giường phụ / đêm") hiển thị "Chưa có biểu giá" **không phải vì thiếu chức năng**, mà vì:

1. **Bảng `service_rates` đang rỗng** trên môi trường được kiểm tra (0 dòng) — nguồn giá duy nhất cho ba gói này không có dữ liệu nào cả, bất kể loại phí.
2. **Ngay cả khi seed đúng**, seeder `ServiceRateSeeder` chỉ tạo giá cho `CITY_TAX`, `EXTRA_PERSON`, `EXTRA_BED` — **cố ý bỏ qua `FOOD_BEVERAGE` (bữa sáng)**. Vì vậy gói "Ăn sáng mỗi đêm" sẽ luôn thiếu giá cho tới khi có người tạo biểu giá thủ công.
3. **Màn hình quản trị biểu giá đã tồn tại đầy đủ** (`/admin/service-rates`, CRUD + versioning + toggle + lịch sử, có policy, có test) nhưng **không có mục nào trong menu điều hướng** (`resources/js/Layouts/AppLayout.vue`) trỏ tới trang này — nhân viên/quản trị viên không có cách nào tìm thấy nó qua UI dù đã có quyền.
4. Sản phẩm `FB_BREAKFAST` (120.000đ) trong `/product-services` **không liên quan về mặt kỹ thuật** tới gói "Ăn sáng mỗi đêm". Đây là hai hệ thống được thiết kế tách biệt có chủ đích: Product/Service catalog (phát sinh đơn lẻ) và Booking recurring package + Service Rate (phát sinh tự động mỗi đêm qua Night Audit). Giá 120.000đ không bao giờ được code nào đọc để tính giá gói.

Kết luận: đây là **gap về vận hành/điều hướng + gap dữ liệu seed**, không phải lỗi logic truy vấn giá, không phải lỗi permission, không phải thiết kế sai kiến trúc.

---

## 2. User-Observed Problem

- `/admin/bookings/6`: panel "GÓI DỊCH VỤ" chỉ hiển thị 1 gói ("Bữa sáng mỗi đêm") với nút "Đăng ký" — đây là `PackagePanel.vue`, phiên bản rút gọn chỉ hỗ trợ `BREAKFAST_PER_NIGHT`.
- `/admin/bookings/6/packages`: trang đầy đủ (`Packages.vue`) hiển thị cả 3 gói, cả 3 đều "Chưa có giá" / "Chưa có biểu giá — không thể đăng ký", nút Đăng ký bị `disabled`.
- `/product-services`: sản phẩm `FB_BREAKFAST` ("Bữa sáng thêm", nhóm Ăn uống, 120.000đ) tồn tại và active, nhưng không có UI nào quản lý ba gói trên hay biểu giá của chúng.

Ghi chú xác minh dữ liệu: trong database hiện tại của workspace này, `bookings` có id từ 393–447 (55 bản ghi) — **không tồn tại booking id = 6**. Vì vậy phần "Booking ID 6" trong báo cáo này được suy luận từ code/logic, không xác minh trực tiếp trên đúng bản ghi id=6 mà người dùng thấy (có thể là DB khác, ví dụ snapshot `storage/backups/database/lastella-db-20260729-002144.sql`, hoặc DB đã bị reset từ lúc chụp màn hình). Logic tính giá không phụ thuộc vào ID cụ thể nên kết luận vẫn áp dụng.

---

## 3. Current Application Flow

```
Booking Show (/admin/bookings/{id})
  └─ PackagePanel.vue  → chỉ BREAKFAST_PER_NIGHT (rút gọn)
       POST/DELETE /admin/bookings/{id}/packages[/{key}]

Booking Packages (/admin/bookings/{id}/packages)
  └─ Packages.vue → cả 3 package key, hiển thị current_rate
       PackageEnrollmentController@show
         → PackageEnrollmentController::buildAvailablePackages()
              for each package key:
                ServiceRateService::resolveFor(ChargeType, businessDate)
                → SELECT * FROM service_rates
                     WHERE charge_type = ?
                       AND is_active = 1
                       AND effective_from <= businessDate
                     ORDER BY effective_from DESC, id DESC
                     LIMIT 1
              nếu NULL → current_rate = null → UI hiện "Chưa có biểu giá"
```

Việc "Đăng ký" (enroll) chỉ tạo một dòng `BookingPackageFlag` (cờ bật/tắt + số lượng) — **không lưu giá tại thời điểm đăng ký**. Giá thực tế chỉ được chốt khi Night Audit chạy job posting tương ứng (`BreakfastPostingJob`, `ExtraPersonPostingJob`, `ExtraBedPostingJob`), lúc đó mới gọi lại `ServiceRateService::resolveFor()` để lấy giá tại business date đang audit và ghi vào `FolioEntry.unit_price`.

Nút "Đăng ký" bị vô hiệu hoá ở tầng UI khi `current_rate === null` — đây là **client-side guard**, backend `enroll()` **không kiểm tra** có rate hay không (xem mục 10 – Regression Risks / Open Questions).

---

## 4. Routes and UI Entry Points

| Route | Method | Controller | Tồn tại? | Trong menu? |
|---|---|---|---|---|
| `admin.bookings.packages` → `/admin/bookings/{booking}/packages` | GET | `PackageEnrollmentController@show` | ✅ | Có (link "Đặt phòng" → Show → Packages) |
| `admin.bookings.packages.enroll` | POST | `PackageEnrollmentController@enroll` | ✅ | — |
| `admin.bookings.packages.unenroll` | DELETE | `PackageEnrollmentController@unenroll` | ✅ | — |
| `admin.service-rates.index` → `/admin/service-rates` | GET | `ServiceRateController@index` | ✅ | ✅ **Đã thêm** (`AppLayout.vue` dòng 51, chờ commit — trước đó KHÔNG có) |
| `admin.service-rates.store` | POST | `ServiceRateController@store` | ✅ | — |
| `admin.service-rates.update` | PATCH | `ServiceRateController@update` | ✅ | — |
| `admin.service-rates.toggle` | PATCH | `ServiceRateController@toggleActive` | ✅ | — |
| `admin.service-rates.history` | GET | `ServiceRateController@history` | ✅ | — (chỉ truy cập được từ trong trang Index) |
| `/product-services` | GET | Product/Service management | ✅ | ✅ Có trong menu (`Sản phẩm/Dịch vụ`) |

`resources/js/Layouts/AppLayout.vue` (dòng 36–54, sau khi sửa) liệt kê toàn bộ menu chính. **Tại thời điểm điều tra**, mảng này không có bất kỳ dòng nào cho `/admin/service-rates`, dù route, controller, policy, Vue page (`Admin/ServiceRates/Index.vue`, `Admin/ServiceRates/History.vue`) đều đã tồn tại đầy đủ và hoạt động. **Đã bổ sung** dòng menu tại vị trí sau "Giá phòng" (xem mục 11).

---

## 5. Data Model

### `service_rates` (migration `2026_07_02_000010_create_service_rates_table.php`, model `App\Models\ServiceRate`)
- `charge_type` (string, khớp `App\Enums\ChargeType`)
- `unit_price` (decimal 12,2)
- `unit_label`, `name`, `gl_account_code`, `tax_rate`
- `effective_from` (date) — dòng có hiệu lực từ ngày này
- `is_active` (boolean)
- `display_order`
- `created_by`
- **Không có `hotel_id` / `property_id`** → hệ thống hiện là single-property, không có khái niệm phạm vi khách sạn ở bảng này.
- **Không có FK tới `product_services`** → xác nhận không liên kết.
- Thiết kế "temporal rows" (ADR-66): thay đổi giá = tạo dòng mới (không update giá cũ) để bảo toàn giá lịch sử; đổi trường không phải giá thì update tại chỗ.

### `booking_package_flags` (migration `2026_07_03_000000_create_booking_package_flags_table.php`, model `App\Models\BookingPackageFlag`)
- `booking_id`, `package_key` (string, một trong 3 hằng số `PackageEnrollmentService::ALLOWED_PACKAGES`), `value` (số lượng, dạng string), `created_by`.
- **`package_key` là chuỗi hard-code trong code (không phải bảng danh mục riêng)** — 3 gói không nằm trong DB, mà là **hằng số PHP + hard-code label trong controller/Vue**:
  - `PackageEnrollmentController::buildAvailablePackages()` (backend, dùng cho `Packages.vue`)
  - `PACKAGE_LABELS` trong `PackagePanel.vue` (frontend, chỉ có `BREAKFAST_PER_NIGHT` — đây là lý do panel rút gọn trên Booking Show chỉ thấy 1 gói).
- Không lưu giá tại thời điểm đăng ký (không snapshot).

### `product_services` (migration `2026_07_26_000010_create_product_services_table.php`, model `App\Models\ProductService`)
- Catalog hoàn toàn độc lập: `category_id`, `code`, `name`, `type`, `unit`, `price`, `can_add_to_booking`, `use_in_checkout_inspection`, `is_active`...
- Có `resolveChargeType()` map `category.code` → `ChargeType` enum (chỉ dùng để phân loại khi ghi **charge đơn lẻ/ad-hoc** vào folio qua luồng "Thêm sản phẩm/dịch vụ vào booking" — không liên quan Package/ServiceRate).
- Không có cột hay bảng trung gian nào nối `product_services` với `service_rates` hay `booking_package_flags`.

---

## 6. Package Price Resolution Logic

`ServiceRateService::resolveFor(ChargeType $chargeType, Carbon $businessDate)`:

```php
ServiceRate::where('charge_type', $chargeType->value)
    ->where('is_active', true)
    ->whereDate('effective_from', '<=', $businessDate->toDateString())
    ->orderByDesc('effective_from')
    ->orderByDesc('id')
    ->first();
```

Điều kiện để **có** giá (trả về khác null), tất cả phải đúng:
1. Có ít nhất 1 dòng `service_rates` với đúng `charge_type` tương ứng gói:
   - Ăn sáng mỗi đêm → `FOOD_BEVERAGE`
   - Người thêm / đêm → `EXTRA_PERSON`
   - Giường phụ / đêm → `EXTRA_BED`
2. `is_active = true`
3. `effective_from <= business_date hiện tại` (không phải ngày hệ thống, mà `BusinessDateService::currentBusinessDate()` — trong DB kiểm tra là `2026-07-31`)
4. Không có ràng buộc phạm vi khách sạn (single-property) và không có ràng buộc loại phòng/loại booking — resolver chỉ theo `charge_type` + ngày.

Nếu bất kỳ điều kiện nào sai (không tồn tại dòng, `is_active=false`, hoặc `effective_from` trong tương lai) → trả `null` → `current_rate = null` → UI hiển thị "Chưa có giá" / "Chưa có biểu giá — không thể đăng ký".

Đây **không phải lỗi logic** — logic resolver đúng theo đặc tả ADR-66 (roadmap `phase-3.2-service-charges-night-audit-foundation.md`, mục 9.2 & 25.3). Vấn đề là **không có dữ liệu** để logic này trả về giá trị.

---

## 7. Product/Service vs Booking Package

Đây là **hai khái niệm được tách biệt có chủ đích**, xác nhận qua roadmap (phase-3.2, ADR-57, ADR-66) và qua việc hai bảng không có FK nào nối nhau:

| | A. Product/Service Catalog | B. Booking Recurring Package |
|---|---|---|
| Bảng | `product_services` | `booking_package_flags` + `service_rates` |
| Ví dụ | Minibar, khăn tắm, giặt là, FB_BREAKFAST ("Bữa sáng thêm") | Ăn sáng/đêm, Người thêm/đêm, Giường phụ/đêm |
| Cách tính phí | Thêm 1 lần, theo số lượng nhập tay tại thời điểm thêm (ad-hoc) | Tự động lặp lại mỗi đêm qua Night Audit posting job, số đêm = số đêm ở lại |
| Nơi cấu hình giá | `/product-services` (đã có menu) | `/admin/service-rates` (đã có route+UI, **thiếu menu**) |
| Đơn giá dùng khi ghi charge | `ProductService.price` | `ServiceRate.unit_price` theo `ChargeType` + business date |
| Được `PackageEnrollmentController`/`*PostingJob` đọc? | Không bao giờ | Có |

`FB_BREAKFAST` chỉ **trùng tên nghiệp vụ** ("bữa sáng") và trùng `ChargeType::FoodBeverage` khi phân loại kế toán cho charge ad-hoc — **không có bất kỳ dòng code nào** đọc `ProductService::where('code','FB_BREAKFAST')->price` để gán vào gói "Ăn sáng mỗi đêm". Xác nhận bằng cách đọc trực tiếp `BreakfastPostingJob::execute()`: nó chỉ gọi `ServiceRateService::resolveFor(ChargeType::FoodBeverage, ...)`, không đụng tới `ProductService` ở bất kỳ đâu.

Vì vậy: **không nên mặc định** giá 120.000đ của `FB_BREAKFAST` phải "tự động" trở thành giá gói — kiến trúc hiện tại không quy định điều đó, và gộp hai khái niệm này có rủi ro (xem mục 10, Phương án C).

---

## 8. Database Findings

Kiểm tra bằng Tinker (chỉ đọc, không ghi):

```
service_rates count: 0
business date (BusinessDateService): 2026-07-31
booking_package_flags count: 0
product_services count: 10
  id=6 code=FB_BREAKFAST name="Bữa sáng thêm" price=120000.00 is_active=1 can_add_to_booking=1
bookings: 55 rows, id range 393–447 (booking id=6 KHÔNG tồn tại trong DB hiện tại)
```

Kết luận dữ liệu:
- `service_rates` **rỗng hoàn toàn** — kể cả `EXTRA_PERSON`/`EXTRA_BED` (đáng lẽ có giá mặc định theo `ServiceRateSeeder` nếu seeder đã chạy). Điều này cho thấy `ServiceRateSeeder` **chưa từng chạy** trên database này (hoặc đã chạy trên DB khác/rồi bị reset).
- `ServiceRateSeeder` (file `database/seeders/ServiceRateSeeder.php`) hiện chỉ seed 3 dòng mặc định: `CITY_TAX` (50.000đ/đêm), `EXTRA_PERSON` (200.000đ/người/đêm), `EXTRA_BED` (150.000đ/giường/đêm). **Không có dòng seed cho `FOOD_BEVERAGE`** — đây là chủ đích hay thiếu sót cần Product Owner xác nhận (xem mục 17).
- `DatabaseSeeder` có gọi `ServiceRateSeeder::class` và `ProductServiceSeeder::class` theo đúng thứ tự — nghĩa là nếu chạy `php artisan db:seed` đầy đủ trên môi trường của người dùng, `EXTRA_PERSON`/`EXTRA_BED` sẽ có giá nhưng `FOOD_BEVERAGE` (Ăn sáng) vẫn sẽ thiếu.

---

## 9. Permission and Navigation Findings

- Permission `service_rates.manage` **đã tồn tại**, được seed trong `RolePermissionSeeder::PERMISSIONS`, và **đã được gán cho cả `ADMIN` và `MANAGER`** (dòng 41, 80, 101, 82-124). Không có vấn đề permission.
- Permission `booking.package.manage` cũng đã gán cho `ADMIN` và `MANAGER` — nút "Đăng ký"/"Hủy đăng ký" hoạt động bình thường về mặt quyền.
- `ServiceRatePolicy` chỉ kiểm tra đúng 1 permission `service_rates.manage` cho mọi action (viewAny/create/update/toggleActive) — nhất quán, không có lỗ hổng.
- **Vấn đề duy nhất về navigation (đã sửa)**: `resources/js/Layouts/AppLayout.vue` (mảng menu) thiếu mục cho `/admin/service-rates`. Không có middleware nào chặn truy cập trực tiếp bằng URL — nếu người dùng biết URL `/admin/service-rates` và có quyền `service_rates.manage`, trang vẫn load và hoạt động đầy đủ (CRUD, toggle, lịch sử giá) cả trước và sau khi sửa; điều duy nhất thay đổi là khả năng *tìm thấy* trang qua menu.

---

## 10. Root Cause

**Nguyên nhân gốc là tổ hợp của 3 lớp riêng biệt, KHÔNG phải một lỗi logic đơn lẻ:**

1. **Lớp dữ liệu (chặn ngay lập tức, cả 3 gói):** bảng `service_rates` rỗng trên môi trường đang xem → `resolveFor()` luôn trả `null` cho mọi `ChargeType` → cả 3 gói đều "Chưa có biểu giá", kể cả `EXTRA_PERSON`/`EXTRA_BED` vốn có seed mặc định.
2. **Lớp thiết kế/seed (chặn riêng gói Ăn sáng, tồn tại vĩnh viễn kể cả khi seed đủ):** `ServiceRateSeeder` không seed `FOOD_BEVERAGE`. Đây có thể là chủ đích (giá bữa sáng phải do khách sạn tự cấu hình, không có giá mặc định hợp lý) — cần xác nhận với Product Owner.
3. **Lớp vận hành/UI (khiến quản trị viên không tự sửa được ngay cả khi có quyền):** trang `/admin/service-rates` đã được xây dựng đầy đủ (route, controller, policy, Vue Index+History, test) nhưng **bị bỏ sót khỏi menu điều hướng chính**. Không phải "chưa triển khai UI" — là "triển khai rồi nhưng quên gắn vào menu".

Không có bằng chứng về: sai điều kiện truy vấn, sai ngày hiệu lực do bug, sai phạm vi khách sạn (hệ thống single-property, không có khái niệm đó ở tầng này), sai trạng thái active do bug, hay chặn do permission/role.

Trả lời nhanh 12 câu hỏi bắt buộc:

1. **Nguồn tạo 3 gói:** hard-code hằng số PHP (`PackageEnrollmentService::ALLOWED_PACKAGES`) + label hard-code trong controller/Vue — không nằm trong bảng danh mục DB.
2. **Vì sao FB_BREAKFAST có giá nhưng gói Ăn sáng không có:** vì hai bảng không liên kết; gói đọc giá từ `service_rates` (đang rỗng cho `FOOD_BEVERAGE`), không đọc `product_services.price`.
3. **Package có bắt buộc liên kết Product/Service không:** Không. Theo code hiện tại, hoàn toàn độc lập.
4. **Nếu có liên kết, thiếu ở đâu:** N/A — không có liên kết trong kiến trúc hiện tại (không phải "thiếu FK do bug", mà là chưa từng thiết kế FK).
5. **Nếu không liên kết, quản trị viên thiết lập giá package ở đâu:** `/admin/service-rates`, chọn `charge_type` tương ứng (`FOOD_BEVERAGE`/`EXTRA_PERSON`/`EXTRA_BED`).
6. **Màn hình đó có tồn tại không:** **Có**, đầy đủ CRUD + toggle + lịch sử.
7. **Route/Permission/vì sao không truy cập được từ menu:** Route `admin.service-rates.*`; Permission `service_rates.manage` (đã gán Admin & Manager); không truy cập được từ menu vì `AppLayout.vue` chưa có mục menu trỏ tới route này (bỏ sót khi thêm tính năng, không phải chặn có chủ đích).
8. **Nếu chưa tồn tại, backend đã đủ nền tảng chưa:** Không áp dụng — backend + UI đã tồn tại.
9. **"Ăn sáng mỗi đêm" và "Bữa sáng mỗi đêm" có phải cùng 1 gói bị đặt tên không nhất quán:** **Đúng, cùng 1 `package_key = BREAKFAST_PER_NIGHT`**, nhưng label bị hard-code khác nhau ở 2 nơi: `PackageEnrollmentController::buildAvailablePackages()` dùng "Ăn sáng mỗi đêm", còn `PackagePanel.vue::PACKAGE_LABELS` dùng "Bữa sáng mỗi đêm". Đây là lỗi nhất quán UI nhỏ, không ảnh hưởng logic (cùng key), nhưng gây nhầm lẫn cho người dùng như phản ánh trong bug report.
10. **Giá package nên là loại nào:** Theo dữ liệu seed hiện có, hệ thống đã thiết kế: Ăn sáng = cố định/đêm (theo phòng/booking, không nhân theo số khách), Người thêm = theo người/đêm (`quantity` nhân), Giường phụ = theo giường/đêm (`quantity` nhân). Đây là thiết kế đã có sẵn (`quantityEditable()` trong `Packages.vue` cho phép nhập số lượng cho 2 gói sau) — không cần thiết kế lại, chỉ cần nhập giá.
11. **Giải pháp tối thiểu nhất:** xem mục 13.
12. **Ảnh hưởng folio/charges/checkout/night audit:** Có liên quan nhưng an toàn — `FolioEntry.unit_price` được snapshot tại thời điểm posting (Night Audit), không bị ảnh hưởng ngược khi giá `service_rates` thay đổi sau này (ADR-66 đã đảm bảo tính bất biến này).

---

## 11. Affected Files

**Đã sửa (chờ commit):**

- `resources/js/Layouts/AppLayout.vue` — đã thêm menu item cho `/admin/service-rates` (permission `service_rates.manage`).
- `resources/js/Pages/Admin/Bookings/Partials/PackagePanel.vue` — đã sửa label `BREAKFAST_PER_NIGHT` từ "Bữa sáng mỗi đêm" → "Ăn sáng mỗi đêm" để nhất quán với `PackageEnrollmentController`.

**Chưa sửa, chờ quyết định riêng của Product Owner:**

- `database/seeders/ServiceRateSeeder.php` — có bổ sung dòng seed mặc định cho `FOOD_BEVERAGE` hay không. Không nằm trong phạm vi lần sửa này.

**Xác nhận không cần sửa** (đúng thiết kế, giữ nguyên): `PackageEnrollmentController.php`, `ServiceRateService.php`, `ServiceRateController.php`, `ServiceRatePolicy.php`, các model (`ServiceRate`, `ProductService`, `BookingPackageFlag`), migrations.

---

## 12. Proposed Options

### Phương án A — Khôi phục chức năng đã có (menu link)
Thêm 1 dòng menu trong `AppLayout.vue` trỏ tới `/admin/service-rates`, điều kiện `show: can('service_rates.manage')`. Không đổi backend, không đổi DB.
- **Phù hợp khi:** đúng như phát hiện ở đây — backend + UI đã có sẵn, chỉ thiếu lối vào.

### Phương án B — Bổ sung màn hình quản trị biểu giá package
Không cần — màn hình `/admin/service-rates` đã đáp ứng đủ các trường yêu cầu: package (qua `charge_type`), đơn giá (`unit_price`), đơn vị tính (`unit_label`), ngày hiệu lực từ (`effective_from`, không có "đến" — theo thiết kế temporal-row, dòng mới thay thế dòng cũ), trạng thái (`is_active` + toggle), cách tính theo đêm/người/số lượng (đã xử lý ở tầng `Packages.vue` qua `quantityEditable()`). **Không cần xây mới.**
- Ghi chú: hiện tại không có trường "ngày hiệu lực đến" tường minh — hiệu lực kết thúc ngầm định khi có dòng mới hơn (`effective_from` mới hơn) được tạo. Nếu nghiệp vụ cần đặt lịch giá tương lai có ngày kết thúc rõ ràng, đây là khoảng trống thiết kế nhỏ, không phải bug.

### Phương án C — Liên kết package với Product/Service
Không khuyến nghị. Rủi ro:
- `product_services.price` không có cơ chế temporal-row/versioning như `service_rates` (ADR-66) → nếu dùng trực tiếp, sửa giá catalog sẽ ảnh hưởng ngay tới booking cũ đang tính toán/hiển thị (vi phạm nguyên tắc "giá cũ không đổi" đã được thiết kế riêng cho `service_rates`).
- `product_services` được thiết kế cho charge đơn lẻ theo số lượng nhập tay, không có khái niệm "theo đêm" hay resolver theo business date.
- Gộp 2 khái niệm sẽ phá vỡ ranh giới kiến trúc đã được tài liệu hoá rõ trong roadmap phase-3.2 (ADR-57, ADR-66).

---

## 13. Recommended Solution

**Khuyến nghị: Phương án A** — **đã triển khai** (chờ review/commit), kèm 1 việc còn treo có chủ đích:

1. ✅ **Đã làm:** Thêm menu item `/admin/service-rates` vào `AppLayout.vue` (điều kiện quyền `service_rates.manage`).
2. ✅ **Đã làm:** Sửa label không nhất quán "Bữa sáng mỗi đêm" → "Ăn sáng mỗi đêm" trong `PackagePanel.vue`.
3. ⏳ **Chưa làm, cần quyết định của Product Owner:** có seed giá mặc định cho `FOOD_BEVERAGE` trong `ServiceRateSeeder` hay không, và giá trị mặc định là bao nhiêu. Việc này chủ động **không thực hiện** trong lần sửa này — không tự chọn số để "làm sáng nút" theo đúng yêu cầu của người dùng. Xem chi tiết vận hành tại `docs/reports/booking-package-pricing-navigation-fix-report.md`.

**Vì sao phù hợp nhất:**
- Không đổi schema, không đổi logic tính giá, không đổi permission.
- Giải quyết đúng root cause quan sát được (không tìm thấy nơi quản trị giá) mà không đụng vào phần đã đúng.
- Rủi ro regression gần như bằng 0.

**Phạm vi code dự kiến:** 1 dòng thêm vào mảng menu (`AppLayout.vue`) + 1 dòng sửa label (`PackagePanel.vue`). Tuỳ theo quyết định PO, có thể thêm 1 entry seed trong `ServiceRateSeeder.php`.

**Rủi ro regression:** Không đáng kể — chỉ thêm liên kết điều hướng và sửa text hiển thị.

**Dữ liệu cần migration/seed:** Không cần migration. Việc seed `FOOD_BEVERAGE` là tuỳ chọn, chờ quyết định nghiệp vụ.

**Có cần snapshot giá khi đăng ký package không:** Không cần thay đổi — cơ chế hiện tại đã đúng: giá chỉ chốt (snapshot vào `FolioEntry.unit_price`) tại thời điểm Night Audit posting, không phải tại thời điểm "Đăng ký". Đây là thiết kế hợp lý cho nghiệp vụ khách sạn nhỏ (khách có thể đăng ký/hủy trước khi bị tính phí đêm đó).

**Cách bảo vệ booking cũ khi giá thay đổi:** Đã có sẵn qua ADR-66 (temporal rows trên `service_rates`) + `FolioEntry` bất biến sau khi tạo (chỉ có thể void, không sửa `unit_price`).

---

## 14. Regression Risks

- Thêm menu item: không có rủi ro logic, chỉ rủi ro UI (kiểm tra `can('service_rates.manage')` đúng điều kiện hiện có).
- Sửa label: cần kiểm tra không có test nào assert chuỗi "Bữa sáng mỗi đêm" (cần rà soát test snapshot nếu có trước khi đổi).
- Seed `FOOD_BEVERAGE` (nếu PO duyệt): rủi ro thấp vì `ServiceRateSeeder` đã có guard `alreadyExists` (idempotent), nhưng cần lưu ý **giá trị mặc định phải phản ánh giá thực tế của khách sạn**, tránh việc admin quên đổi giá seed "tạm" thành giá thật rồi vô tình tính sai phí cho khách.

---

## 15. Implementation Scope

Nhỏ, single-PR, ước tính:
1. `AppLayout.vue`: +1 object trong mảng `navigation`/menu items.
2. `PackagePanel.vue`: sửa 1 giá trị trong `PACKAGE_LABELS`.
3. (Tuỳ chọn, chờ PO) `ServiceRateSeeder.php`: +1 phần tử trong mảng `$defaults` cho `ChargeType::FoodBeverage`.
4. Không cần sửa controller, service, model, policy, migration.
5. Test hiện có (`ServiceRateCrudTest`, `ServiceRateVersioningTest`, `PackageEnrollmentControllerTest`, `PackageEnrollmentServiceTest`, `BookingPackageEnrollmentTest`) đã bao phủ logic backend — không cần test mới cho việc thêm menu; nếu sửa label cần cập nhật assertion nếu có test tham chiếu chuỗi cũ.

---

## 16. Test Checklist

| # | Kịch bản | Cách kiểm tra |
|---|---|---|
| 1 | Package không có biểu giá | `service_rates` rỗng cho charge_type → `resolveFor()` = null → UI "Chưa có giá", nút disabled |
| 2 | Có biểu giá nhưng chưa tới ngày hiệu lực | tạo dòng `effective_from` tương lai → `resolveFor()` bỏ qua dòng đó |
| 3 | Biểu giá đã hết hiệu lực (bị dòng mới hơn thay thế) | 2 dòng cùng charge_type, `effective_from` khác nhau → chỉ dòng mới nhất ≤ business date được chọn |
| 4 | Nhiều biểu giá chồng lấn cùng ngày | `orderByDesc('id')` là tie-breaker — cần test rõ ràng dòng nào thắng |
| 5 | Package bị `is_active=false` | `resolveFor()` phải bỏ qua |
| 6 | Booking 1 đêm | Night Audit chạy đúng 1 lần posting |
| 7 | Booking nhiều đêm | Mỗi đêm 1 `FolioEntry` riêng, `posting_key` unique theo stay+date |
| 8 | Booking đổi ngày lưu trú | Không tạo trùng/thiếu entry sau khi đổi ngày (kiểm tra `posting_key`) |
| 9 | Package tính theo số lượng (Extra Person/Bed) | `quantity` trong `BookingPackageFlag.value` được nhân đúng vào `amount`/`unit_price` khi posting (cần xác nhận `ExtraPersonPostingJob`/`ExtraBedPostingJob` có nhân quantity — **ngoài phạm vi đã đọc ở bước này, cần soát lại khi implement**) |
| 10 | Đăng ký/hủy đăng ký | `enroll()`/`unenroll()` idempotent qua `updateOrCreate`/`delete` |
| 11 | Giá vào folio đúng 1 lần | Guard `posting_key` unique + lock `lockForUpdate()` trong `BreakfastPostingJob` |
| 12 | Không tạo charge trùng | Test `isAlreadyPosted()` |
| 13 | Checkout tính đúng | Ngoài phạm vi file đã đọc — cần test tích hợp khi implement |
| 14 | Booking cũ không đổi giá khi rate mới cập nhật | Đảm bảo bởi ADR-66 (temporal rows) + `FolioEntry` bất biến — cần test hồi quy xác nhận |
| 15 | Quyền Admin/Manager thao tác được | Đã có `service_rates.manage`, `booking.package.manage` cho cả 2 role |
| 16 | Nhân viên không có quyền bị chặn | `RECEPTION`/`SALES`/`HOUSEKEEPING`/`ACCOUNTANT` — cần xác nhận role nào có `booking.package.manage`/`service_rates.manage` (ngoài Admin/Manager, chưa thấy role khác được gán trong đoạn đã đọc) |
| 17 | Không ảnh hưởng Product/Service, minibar | Xác nhận không có import chéo giữa `ProductService` và `ServiceRate`/`BookingPackageFlag` — đã xác nhận ở mục 7 |

Lưu ý: mục 9, 13, 16 cần đọc thêm code khi bước triển khai (ngoài phạm vi phân tích thuần menu/label ở khuyến nghị hiện tại), nhưng không chặn Phương án A.

---

## 17. Open Questions

1. Có nên seed giá mặc định cho `FOOD_BEVERAGE` trong `ServiceRateSeeder`, và giá trị bao nhiêu? (Cần Product Owner quyết định, không tự chọn số.)
2. Booking id=6 trong report gốc không khớp với dải id hiện có (393–447) trong database đã kiểm tra — cần xác nhận đây có phải cùng một môi trường/database hay không trước khi coi các phát hiện dữ liệu (mục 8) là đại diện chính xác cho ca cụ thể người dùng gặp.
3. Có cần bổ sung "ngày hiệu lực đến" tường minh cho `service_rates` để lên lịch giá tương lai có kết thúc rõ ràng không, hay giữ nguyên mô hình temporal-row hiện tại là đủ cho nghiệp vụ?
4. Có cần mở rộng `PackagePanel.vue` (trên Booking Show) để hiển thị đủ cả 3 gói giống `Packages.vue`, hay giữ nguyên chủ đích rút gọn chỉ hiển thị bữa sáng ở trang Show và để 2 gói còn lại chỉ quản lý ở trang Packages riêng?
5. Vai trò `RECEPTION`/`SALES`/`ACCOUNTANT`/`HOUSEKEEPING` có nên được cấp `booking.package.manage` (chỉ xem hay cả thao tác) không? (Ngoài phạm vi đọc RolePermissionSeeder đầy đủ ở bước này cho các role còn lại.)

---

## 18. Readiness Decision

**Trạng thái tại thời điểm điều tra (2026-08-01, trước sửa code): READY FOR IMPLEMENTATION WITH CONDITIONS**

**Trạng thái sau khi sửa code (chưa commit): READY FOR REVIEW**

**Trạng thái hiện tại: APPROVED FOR COMMIT (ChatGPT review).** Code đã hoàn thành, **chưa deploy**, **chưa push**. Không đổi thành READY FOR DEPLOYMENT ở đây vì chưa smoke test trên giao diện sau deploy thực tế — trạng thái đó chỉ được ghi nhận sau khi có bước smoke test riêng.

Đã hoàn tất phần code cho Phương án A (menu + đồng nhất nhãn). Điều kiện còn treo, không chặn việc commit 2 file code:
- Product Owner vẫn cần xác nhận việc bổ sung seed giá mặc định cho `FOOD_BEVERAGE` (có/không, giá trị bao nhiêu) — việc này **tách riêng** khỏi lần sửa navigation/label này và **chưa được thực hiện**. Sau khi menu được deploy, admin có quyền `service_rates.manage` có thể tự nhập biểu giá qua `/admin/service-rates` mà không cần chờ seed.
- Xác nhận lại booking id=6 thuộc đúng database/môi trường nào (mục Open Question #2) vẫn còn mở, không ảnh hưởng tới tính đúng đắn của 2 thay đổi code này.

Chi tiết kiểm thử, git diff, và xác nhận commit: xem `docs/reports/booking-package-pricing-navigation-fix-report.md`.
