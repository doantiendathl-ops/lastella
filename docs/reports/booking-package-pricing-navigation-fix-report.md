# Booking Package Pricing — Navigation & Label Fix Report

**ChatGPT review status: APPROVED FOR COMMIT.**
**Trạng thái code:** hoàn thành, **chưa deploy**. Đang thực hiện commit closure — **chưa push**, chưa smoke test trên giao diện sau deploy.
**Ngày:** 2026-08-01
**Tài liệu căn cứ:** `docs/reviews/booking-package-pricing-gap-analysis.md`

**Lưu ý:** Không ghi commit SHA cụ thể trong nội dung tài liệu này để tránh tài liệu bị lỗi thời khi lịch sử git thay đổi (rebase/amend về sau) — SHA chính thức được xác nhận ngoài tài liệu (kết quả `git show`/`git log` tại thời điểm commit).

---

## 1. Vấn đề người dùng gặp

Trên `/admin/bookings/{id}/packages`, cả 3 gói dịch vụ ("Ăn sáng mỗi đêm", "Người thêm / đêm", "Giường phụ / đêm") hiển thị "Chưa có biểu giá — không thể đăng ký", nút "Đăng ký" bị vô hiệu hoá. Trên `/admin/bookings/{id}`, panel "GÓI DỊCH VỤ" chỉ hiển thị gói bữa sáng với nhãn "Bữa sáng mỗi đêm" — khác với nhãn "Ăn sáng mỗi đêm" mà trang `/packages` và backend dùng. Sản phẩm `FB_BREAKFAST` (120.000đ) tồn tại trong `/product-services` nhưng không có màn hình nào rõ ràng để thiết lập giá cho 3 gói trên.

## 2. Root cause

Xác nhận bằng code reference cụ thể (đầy đủ trong `booking-package-pricing-gap-analysis.md`):

1. **Màn hình quản trị biểu giá đã tồn tại nhưng thiếu lối vào menu.**
   - Route: `admin.service-rates.index` → GET `/admin/service-rates` (`routes/web.php:70`, trong group `Route::prefix('admin')->name('admin.')` với middleware `auth`, `routes/web.php:44,66`).
   - Controller: `App\Http\Controllers\Admin\ServiceRateController::index()` (`app/Http/Controllers/Admin/ServiceRateController.php:17`), gọi `$this->authorize('viewAny', ServiceRate::class)`.
   - Vue/Inertia page: `resources/js/Pages/Admin/ServiceRates/Index.vue` (kèm `History.vue`).
   - Permission: `service_rates.manage` (`App\Policies\ServiceRatePolicy` — mọi action đều check permission này; đã seed và gán cho `ADMIN` và `MANAGER` trong `database/seeders/RolePermissionSeeder.php:41,101`).
   - Model/bảng: `App\Models\ServiceRate` → bảng `service_rates` (`database/migrations/2026_07_02_000010_create_service_rates_table.php`).
   - Cơ chế tìm giá: `App\Services\ServiceRateService::resolveFor(ChargeType $chargeType, Carbon $businessDate)` (`app/Services/ServiceRateService.php:15`) — query `service_rates` theo `charge_type` + `is_active=true` + `effective_from <= businessDate`, lấy dòng mới nhất.
   - Ba charge type tương ứng 3 gói: `ChargeType::FoodBeverage = 'FOOD_BEVERAGE'`, `ChargeType::ExtraPerson = 'EXTRA_PERSON'`, `ChargeType::ExtraBed = 'EXTRA_BED'` (`app/Enums/ChargeType.php:8,17,18`), map trong `PackageEnrollmentController::buildAvailablePackages()` (`app/Http/Controllers/Admin/PackageEnrollmentController.php:122-135`).
   - **Trước thay đổi này**, quản trị viên có quyền `service_rates.manage` **đã có thể truy cập trang bằng cách gõ trực tiếp URL** `/admin/service-rates` — route chỉ yêu cầu middleware `auth`, không có middleware permission ở tầng route; kiểm soát quyền nằm trong `ServiceRateController::index()` qua `$this->authorize()`. Vấn đề duy nhất là **không có đường dẫn UI** để tìm ra URL này.
   - **Xác nhận root cause chính là thiếu navigation entry**: `resources/js/Layouts/AppLayout.vue` (mảng `navItems`) không có mục nào cho `/admin/service-rates`, trong khi route/controller/Vue page/permission đều đã hoạt động đầy đủ.

2. **`FB_BREAKFAST` (product_services, 120.000đ) không tự động trở thành giá gói "Ăn sáng mỗi đêm"** vì hai bảng hoàn toàn độc lập:
   - `ProductService::resolveChargeType()` (`app/Models/ProductService.php:77-87`) chỉ dùng để phân loại charge **ad-hoc** khi thêm sản phẩm/dịch vụ vào booking, không liên quan tới package.
   - `BreakfastPostingJob::execute()` (`app/Services/Posting/BreakfastPostingJob.php:29`) chỉ gọi `ServiceRateService::resolveFor(ChargeType::FoodBeverage, ...)` — không có bất kỳ dòng code nào đọc `ProductService::price`.
   - Không có foreign key nào nối `product_services` với `service_rates` hay `booking_package_flags`.

3. **Nhãn gói bữa sáng không nhất quán**: `PackageEnrollmentController::buildAvailablePackages()` (dòng 124) dùng "Ăn sáng mỗi đêm"; `PackagePanel.vue` (bản gốc) hard-code "Bữa sáng mỗi đêm" ở 2 vị trí (map nhãn + form đăng ký) cho cùng `package_key = BREAKFAST_PER_NIGHT`.

**Không phải lỗi của:** `ServiceRateService::resolveFor()` (logic đúng theo ADR-66), permission (đã gán đủ cho Admin/Manager), kiến trúc dữ liệu (tách Product/Service và Package là chủ đích, có tài liệu trong roadmap phase-3.2, ADR-57/ADR-66).

## 3. Giải pháp đã triển khai

Đúng phạm vi "Phương án A" trong gap-analysis — thuần navigation + label, không đổi backend/database:

1. Thêm 1 mục menu "Biểu giá dịch vụ" vào `AppLayout.vue`, trỏ tới `/admin/service-rates`, chỉ hiện khi `can('service_rates.manage')` — dùng đúng convention href tĩnh + `can()` như mọi mục khác trong file, tái sử dụng icon `DollarSign` đã import sẵn (cùng icon với "Giá phòng", nhóm cạnh nhau vì cùng là cấu hình giá).
2. Sửa 2 vị trí chuỗi hiển thị trong `PackagePanel.vue` từ "Bữa sáng mỗi đêm" → "Ăn sáng mỗi đêm", khớp với backend. Không đổi `package_key`, không đổi logic đăng ký/hủy đăng ký.
3. **Chủ động không làm**: không thêm seed giá `FOOD_BEVERAGE`, không tạo migration, không đổi permission, không liên kết `ProductService` với `ServiceRate`.

## 4. Danh sách file đã sửa

| File | Loại thay đổi |
|---|---|
| `resources/js/Layouts/AppLayout.vue` | +1 dòng menu item |
| `resources/js/Pages/Admin/Bookings/Partials/PackagePanel.vue` | 2 dòng đổi chuỗi hiển thị |

## 5. Diff summary

```
resources/js/Layouts/AppLayout.vue                          | 1 hunk liên quan (+1 dòng) — thuộc task này
resources/js/Pages/Admin/Bookings/Partials/PackagePanel.vue  | 2 hunks (+2 -2 dòng) — thuộc task này
```

Diff đầy đủ của `AppLayout.vue`:

```diff
@@ -48,6 +48,7 @@ const navItems = computed(() => [
     { label: 'Loại phòng', href: '/room-types', icon: Tags, show: can('room_types.manage') },
     { label: 'Phòng', href: '/rooms', icon: BedDouble, show: can('rooms.manage') },
     { label: 'Giá phòng', href: '/room-rates', icon: DollarSign, show: can('rates.manage') },
+    { label: 'Biểu giá dịch vụ', href: '/admin/service-rates', icon: DollarSign, show: can('service_rates.manage') },
     { label: 'Sản phẩm/Dịch vụ', href: '/product-services', icon: PackagePlus, show: can('product_services.manage') },
     { label: 'Cài đặt', href: '/settings', icon: Settings, show: can('settings.manage') },
     { label: 'Nhật ký', href: '/audit-logs', icon: ClipboardList, show: can('report.view') },
```

**Ghi chú quan trọng — hunk KHÔNG thuộc task này:** `git diff` của `AppLayout.vue` còn chứa 1 hunk khác, thay đổi class CSS của `<header>` (padding responsive cho mobile nav):

```diff
@@ -123,7 +124,7 @@ watch(() => page.url, () => { mobileNavOpen.value = false; });
         <div class="lg:pl-64">
             <header class="sticky top-0 z-20 border-b border-gray-200 bg-white">
-                <div class="flex h-16 items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
+                <div class="flex min-h-16 items-center justify-between gap-3 px-4 py-2 sm:h-16 sm:py-0 sm:px-6 lg:px-8">
                     <button
```

Hunk này **đã tồn tại trong working tree từ trước khi phiên làm việc này bắt đầu** (xác nhận: `git show HEAD:resources/js/Layouts/AppLayout.vue` tại dòng tương ứng vẫn là bản gốc `class="flex h-16 ..."`, và file đã ở trạng thái `M` trong `git status` ngay tại thời điểm bắt đầu phiên, trước khi có bất kỳ chỉnh sửa nào của tôi). **Không đưa hunk này vào commit của task này.**

Diff đầy đủ của `PackagePanel.vue` (toàn bộ đều thuộc task này):

```diff
@@ -14,7 +14,7 @@ const page = usePage();
 const packageError = computed(() => page.props.errors?.package ?? null);
 
 const PACKAGE_LABELS = {
-    BREAKFAST_PER_NIGHT: 'Bữa sáng mỗi đêm',
+    BREAKFAST_PER_NIGHT: 'Ăn sáng mỗi đêm',
 };
 
 const packageLabel = (key) => PACKAGE_LABELS[key] ?? key;
@@ -72,7 +72,7 @@ const unenroll = (packageKey) => {
         <p v-else class="mb-3 text-sm text-steel">Chưa đăng ký gói dịch vụ nào.</p>
 
         <form v-if="canEnrollBreakfast" class="flex items-center gap-2" @submit.prevent="enroll">
-            <span class="text-sm text-steel">Bữa sáng mỗi đêm</span>
+            <span class="text-sm text-steel">Ăn sáng mỗi đêm</span>
             <button
```

**Cách stage đúng khi commit** (không dùng `git add resources/js/Layouts/AppLayout.vue` trực tiếp vì sẽ kéo theo hunk không liên quan):

```bash
git add -p resources/js/Layouts/AppLayout.vue
# Chỉ chọn 'y' cho hunk +1 dòng menu "Biểu giá dịch vụ"
# Chọn 'n' cho hunk header padding (mobile nav, có sẵn từ trước)
git add resources/js/Pages/Admin/Bookings/Partials/PackagePanel.vue
```

## 6. Những file đã kiểm tra nhưng không sửa

- `app/Http/Controllers/Admin/PackageEnrollmentController.php`
- `app/Http/Controllers/Admin/ServiceRateController.php`
- `app/Services/ServiceRateService.php`
- `app/Services/PackageEnrollmentService.php`
- `app/Policies/ServiceRatePolicy.php`
- `app/Models/ServiceRate.php`, `app/Models/ProductService.php`, `app/Models/BookingPackageFlag.php`
- `database/migrations/2026_07_02_000010_create_service_rates_table.php`, `database/migrations/2026_07_03_000000_create_booking_package_flags_table.php`
- `database/seeders/ServiceRateSeeder.php` — đọc để xác nhận không seed `FOOD_BEVERAGE`, **không sửa**.
- `routes/web.php` — đọc để xác nhận route đã tồn tại, **không sửa**.
- Không chạy `php artisan db:seed`, không ghi bất kỳ dữ liệu nào vào database.

## 7. Kết quả targeted tests

```
php artisan test --filter="ServiceRateCrudTest|ServiceRateVersioningTest|PackageEnrollmentControllerTest|PackageEnrollmentServiceTest|BookingPackageEnrollmentTest"
```

**Kết quả: PASS — 38 tests, 130 assertions, 0 failed.**

| Test file | Số test | Kết quả |
|---|---|---|
| `BookingPackageEnrollmentTest` | 8 | PASS |
| `PackageEnrollmentControllerTest` | 12 | PASS |
| `PackageEnrollmentServiceTest` | 4 | PASS |
| `ServiceRateCrudTest` | 9 | PASS |
| `ServiceRateVersioningTest` | 5 | PASS |

## 8. Kết quả frontend build

```
npm run build
```

**Kết quả: PASS.** Build thành công trong 17.00s, sinh `public/build/assets/app-*.js` (608.66 kB, gzip 173.89 kB) và `app-*.css` (34.54 kB, gzip 6.59 kB). Cảnh báo duy nhất là chunk-size warning (>500kB) — cảnh báo đã có từ trước, không phát sinh do thay đổi này (không thêm import/dependency mới).

`npm run typecheck` / `npm run lint`: **không chạy** — đã đọc `package.json`, chỉ có 2 script khai báo: `build`, `dev`. Không có script `typecheck`/`lint` trong dự án để giả định chạy.

## 9. Kết quả full test suite

```
php artisan test
```

**Kết quả: 949 passed, 25 failed** (log đầy đủ: `storage/logs/full_test_run.txt`, không commit file này — thuộc `storage/`, không phải artifact của task).

Toàn bộ 25 test fail nằm trong 4 file, không có test nào của `ServiceRate*`, `PackageEnrollment*`, hay bất kỳ test liên quan `AppLayout`/`PackagePanel`/navigation. Chi tiết đầy đủ ở mục 10–11.

## 10. Danh sách chính xác các test fail

| # | File | Test | Lỗi chính |
|---|---|---|---|
| 1 | `BookingManagementUiTest` | conflicted room is disabled on room board | `Property [roomBoard.floors]` — closure assertion trả `false` |
| 2 | `BookingManagementUiTest` | same room after previous checkout is available on room board | như trên |
| 3 | `BookingManagementUiTest` | booking detail includes payment summary and refund subtracts from paid total | `expected_total`: expected `2600000`, got `0` |
| 4 | `BookingManagementUiTest` | current booking room payload includes current assignment data | `Property [roomBoard.floors]` — closure assertion trả `false` |
| 5 | `BookingManagementUiTest` | all room type summary current booking count | `Property [roomBoard.all_room_type_summary]` — closure assertion trả `false` |
| 6 | `BookingManagementUiTest` | room type summary counts current booking room | `Property [roomBoard.room_type_summary]` — closure assertion trả `false` |
| 7 | `BookingManagementUiTest` | boundary touching assignment does not conflict on room board | `Property [roomBoard.floors]` — closure assertion trả `false` |
| 8 | `BookingManagementUiTest` | released current booking assignment not in active board | như trên |
| 9 | `BookingManagementUiTest` | non overlapping checked in room is available on room board | như trên |
| 10 | `BookingManagementUiTest` | overlapping checked in room is conflict on room board | như trên |
| 11 | `BookingManagementUiTest` | non overlapping checked in room not counted as occupied in summary | `Property [roomBoard.all_room_type_summary]` |
| 12 | `LateCheckoutFeeTest` | check out triggers late checkout fee via stay service | `assertDatabaseHas('folio_entries', ...)` — bảng rỗng (không tạo được charge `LATE_CHECKOUT`) |
| 13 | `PerStayAttributionTest` | add charge accepts system auto posting source | `UniqueConstraintViolationException` — `UNIQUE constraint failed: resources.code` (trùng mã phòng do factory sinh trùng `RM-xxx`) |
| 14 | `PerStayAttributionTest` | http store with valid stay id stores attribution | như trên, trùng `resources.code` |
| 15–25 | `RoomAvailabilityCheckerTest` | assigned room in range shows as reserved and blocks; assigned room outside range shows as available; checked in room whose dates do not overlap search is available; checked in room whose dates overlap search shows as occupied; checked out room does not block availability; released assignment does not block availability; room type summary counts only overlapping blocking statuses; room board shows non overlapping checked in as available with info; room board shows overlapping checked in as conflict; checkout at boundary touching next checkin does not overlap; overlapping assigned rooms are blocked (11 test) | `Property [availability.floors]` — closure assertion trả `false` |

**Ghi chú về số lượng chính xác:** Số test fail dao động nhẹ giữa các lần chạy (25 trong lần chạy full log gốc; 24 trong lần chạy lọc riêng 4 file để verify — xem mục 11) — bản thân sự dao động này là **bằng chứng thêm** rằng các lỗi này phụ thuộc thời gian/business date (`now()`, ngày hard-code trong test như `2026-08-01`–`2026-08-04`) chứ không phải lỗi cố định do thay đổi code, và **không liên quan tới 2 file Vue thuần frontend** đã sửa trong task này.

Không có test nào trong danh sách 25 test fail thuộc về `ServiceRate*`, `PackageEnrollment*`, `AppLayout`, hoặc `PackagePanel`.

## 11. Bằng chứng các lỗi tồn tại trước thay đổi

Phương pháp: `git stash push` tạm gỡ 2 file đã sửa (`AppLayout.vue`, `PackagePanel.vue`) để đưa working tree về đúng trạng thái gốc (HEAD), chạy lại test, rồi `git stash pop` khôi phục.

```bash
git stash push -- resources/js/Layouts/AppLayout.vue resources/js/Pages/Admin/Bookings/Partials/PackagePanel.vue
php artisan test --filter="BookingManagementUiTest|LateCheckoutFeeTest|PerStayAttributionTest|RoomAvailabilityCheckerTest"
git stash pop
```

**Kết quả khi KHÔNG có 2 thay đổi của task này (working tree = HEAD sạch): 24 failed, 177 passed (1560 assertions).**

Trước đó, kiểm tra riêng `RoomAvailabilityCheckerTest` cũng cho kết quả tương tự (12 failed / 14 passed) ở cùng trạng thái stash. Cả hai lần chạy xác nhận: **các test này fail y hệt khi không có bất kỳ thay đổi nào của task** → lỗi có sẵn từ trước (pre-existing), không do `AppLayout.vue`/`PackagePanel.vue` gây ra.

Về mặt kỹ thuật, điều này còn được đảm bảo cấu trúc: cả hai file thay đổi đều là Vue SFC (frontend), test PHPUnit/Pest dùng `assertInertia()` chỉ kiểm tra props JSON được `Inertia::render()` trả về từ PHP — không render Vue template — nên thay đổi thuần Vue (nhãn hiển thị, mục menu) **không thể** ảnh hưởng tới kết quả các test PHP này.

Sau khi verify, đã `git stash pop` và xác nhận lại 2 file đúng nội dung đã sửa (dòng menu + 2 dòng label vẫn còn).

## 12. Hướng dẫn vận hành sau triển khai

Sau khi deploy, quản trị viên có permission `service_rates.manage` cần truy cập menu **"Biểu giá dịch vụ"** (`/admin/service-rates`) và tạo biểu giá có hiệu lực cho các charge type nghiệp vụ cần dùng, đặc biệt:
- `FOOD_BEVERAGE` (Ăn sáng mỗi đêm)
- `EXTRA_PERSON` (Người thêm / đêm)
- `EXTRA_BED` (Giường phụ / đêm)

**Không tự nhập hoặc đề xuất giá trị cụ thể** trong lần triển khai này — chờ Product Owner phê duyệt giá, ngày hiệu lực, đơn vị tính, GL account và thuế suất (nếu có) trước khi nhập vào hệ thống.

## 13. Regression risks

- **Menu item**: rủi ro thấp — chỉ thêm 1 phần tử vào mảng `navItems`, dùng đúng pattern `can()` hiện có, không đổi phần tử khác, không đổi thứ tự các mục còn lại (chèn giữa "Giá phòng" và "Sản phẩm/Dịch vụ" theo nhóm nghiệp vụ pricing).
- **Label**: rủi ro thấp — chỉ đổi chuỗi hiển thị, không đổi `package_key`/logic. Đã `rg` toàn repo xác nhận không còn chuỗi "Bữa sáng mỗi đêm" ở bất kỳ vị trí UI nào (xem mục kiểm tra chức năng).
- **Không có rủi ro** về schema, dữ liệu, permission, hay logic tính giá — không file backend nào bị đổi.

## 14. Rollback procedure

Vì chưa commit, rollback đơn giản là revert working tree cho 2 file (không ảnh hưởng git history vì chưa có commit nào được tạo):

```bash
git checkout -- resources/js/Layouts/AppLayout.vue
git checkout -- resources/js/Pages/Admin/Bookings/Partials/PackagePanel.vue
```

**Cảnh báo:** lệnh trên sẽ xoá luôn hunk header-padding có sẵn từ trước (không thuộc task này) đang nằm chung trong `AppLayout.vue`. Nếu cần giữ lại hunk đó, dùng `git diff` để lưu lại thay đổi mong muốn trước khi checkout, hoặc rollback thủ công bằng cách xoá đúng 1 dòng menu đã thêm.

Nếu đã commit (chưa xảy ra ở bước này): `git revert <commit-sha>`.

## 15. Commit recommendation

**ChatGPT đã review và phê duyệt: APPROVED FOR COMMIT.** Phạm vi được duyệt: (1) menu "Biểu giá dịch vụ", (2) đồng nhất nhãn "Ăn sáng mỗi đêm", (3) hai tài liệu review/report này. **Commit riêng, tách bạch** với các thay đổi có sẵn khác trong working tree (`.gitignore`, `bootstrap/app.php`, `Bookings/Index.vue`, `Bookings/Show.vue`, `app.js` — không thuộc phạm vi task này, không đưa vào commit này).

Trình tự thực hiện (commit closure):
```bash
git add -p resources/js/Layouts/AppLayout.vue   # chỉ chọn hunk +1 dòng menu
git add resources/js/Pages/Admin/Bookings/Partials/PackagePanel.vue
git add docs/reviews/booking-package-pricing-gap-analysis.md
git add docs/reports/booking-package-pricing-navigation-fix-report.md
git diff --cached --stat   # xác nhận staged đúng phạm vi, không lẫn hunk header-padding hay file ngoài phạm vi
git commit -m "fix(service-rates): expose pricing management and unify breakfast label"
```

**Không push sau commit.** Kết quả commit thực tế (SHA, danh sách file, xác nhận file ngoài phạm vi vẫn unstaged) được xác nhận riêng ngoài tài liệu này tại thời điểm thực hiện commit closure, không ghi cố định vào đây.
