# Dynamic Service Package Administration — Milestone 2 Report

**Ngày:** 2026-08-03 (cập nhật Manual QA + commit closure: 2026-08-04)
**Manual QA: PASS.** **ChatGPT review: APPROVED FOR MILESTONE 2 COMMIT.** **Milestone 2: COMPLETE.**
**Trạng thái:** Code hoàn tất trên máy phát triển, đã Manual QA PASS. **Chưa push, chưa deploy production.**
**Toàn bộ chức năng "Gói dịch vụ": vẫn NOT YET COMPLETE** — Milestone 3 (Booking Enrollment Integration) và Milestone 4 (Posting/Pricing Integration) vẫn bắt buộc trước khi đưa chức năng quản trị giá gói vào vận hành thật.

---

## 1. Scope

Xây giao diện + backend quản trị "Gói dịch vụ" (tạo/sửa/ngừng package, quản lý giá theo thời gian), đổi menu sidebar sang "Gói dịch vụ". **Không** tích hợp booking enrollment, **không** đổi Posting Job, **không** deploy production.

## 2. Milestone 1 Checkpoint

Commit `e9ffea6` (`feat(service-packages): add package catalog and rate foundation`) đã **push lên `origin/phase-3`** ở bước đầu task này (`9f71b1e..e9ffea6`). Không divergence, `origin/phase-3` = local HEAD trước khi bắt đầu code Milestone 2.

## 3. Admin Routes

Trong group `Route::prefix('admin')->name('admin.')`:

| Route name | Method | URI | Controller@method |
|---|---|---|---|
| `admin.service-packages.index` | GET | `/admin/service-packages` | `ServicePackageController@index` |
| `admin.service-packages.store` | POST | `/admin/service-packages` | `ServicePackageController@store` |
| `admin.service-packages.update` | PATCH | `/admin/service-packages/{servicePackage}` | `ServicePackageController@update` |
| `admin.service-packages.toggle` | PATCH | `/admin/service-packages/{servicePackage}/toggle` | `ServicePackageController@toggle` |
| `admin.service-packages.history` | GET | `/admin/service-packages/{servicePackage}/history` | `ServicePackageController@history` |
| `admin.service-packages.rates.store` | POST | `/admin/service-packages/{servicePackage}/rates` | `ServicePackageRateController@store` |
| `admin.service-packages.rates.toggle` | PATCH | `/admin/service-packages/{servicePackage}/rates/{rate}/toggle` | `ServicePackageRateController@toggle` |

Không có route `destroy` — chủ đích, theo khuyến nghị "không xây chức năng xóa công khai" (mục 7).

## 4. Authorization

- Permission dùng: `service_packages.manage` (đã seed từ Milestone 1, gán ADMIN + MANAGER, không gán RECEPTION/SALES/HOUSEKEEPING/ACCOUNTANT — không đổi gì ở seeder trong task này).
- `ServicePackagePolicy` mới: `viewAny`, `view`, `create`, `update`, `toggle`, `manageRates` (đều check `service_packages.manage`); `delete` trả `false` cứng (không hard delete). Đăng ký `Gate::policy(ServicePackage::class, ServicePackagePolicy::class)` trong `AppServiceProvider`.
- Mọi controller action đều gọi `$this->authorize(...)` — bảo vệ ở backend, không chỉ ẩn menu. Test xác nhận Reception nhận **403** trên index/store/update/toggle/rates.store (`ServicePackageAdminTest`, `ServicePackageRateAdminTest`).
- `service_rates.manage` **không đổi**.

## 5. Package Administration

`ServicePackageController` (index/store/update/toggle/history) + `StoreServicePackageRequest`/`UpdateServicePackageRequest`:

- **Trường quản trị được:** name, description, unit_label, default_quantity, display_order, is_active, is_bookable — luôn sửa được.
- **Trường bị khoá sau khi package đã dùng:** code, charge_type, calculation_strategy, quantity_mode, posting_frequency.
- **`code`:** chuẩn hoá uppercase tự động (`prepareForValidation`), regex `^[A-Z0-9_]+$`, unique.
- **`calculation_strategy`/`quantity_mode`/`posting_frequency`:** validate `Rule::in()` chỉ với `implemented()` — **không thể** gửi giá trị chưa triển khai qua API, kể cả khi frontend bị bypass (test `unimplemented_calculation_strategy_is_rejected`, `unimplemented_quantity_mode_is_rejected`).
- **Tương thích strategy/quantity_mode:** kiểm tra qua `PackageCalculationStrategy::isCompatibleWith()` (đăng ký mới trong enum, dùng lại logic đã có ở Milestone 1, không tạo bản sao thứ hai).
- **Toggle:** 1 route, nhận `field` (`is_active` hoặc `is_bookable`), validate `Rule::in`, chỉ lật đúng 1 field — **không gộp 2 trạng thái**.

## 6. Package Rate Versioning UI

`ServicePackageRateController` (store/toggle) + `StoreServicePackageRateRequest` + `resources/js/Pages/Admin/ServicePackages/History.vue`:

- Tạo giá luôn là **`create()` dòng mới**, không có đường nào `update()` `unit_price`/`effective_from` của dòng cũ trong toàn bộ code Milestone 2.
- Cho phép tạo giá đầu tiên, giá tương lai (`effective_from` bất kỳ ngày nào ≥ không giới hạn).
- `unit_price` bắt buộc (`required`), `min:0` — cho phép 0 nếu admin **chủ động** nhập (ví dụ gói miễn phí), nhưng không bao giờ tự động tạo dòng giá 0 nếu không có input rõ ràng (test `a_price_of_zero_is_never_created_implicitly`: mở trang History không tạo rate nào).
- `ServicePackage::currentRate(string $businessDate)` (mới, thêm vào model Milestone 1) — resolver dùng `service_package_id`, **không dùng `charge_type`**, tie-breaker `orderByDesc('effective_from')->orderByDesc('id')`. Test `resolving_current_rate_never_uses_charge_type` xác nhận 2 package cùng `charge_type` không lẫn giá của nhau.
- Toggle rate kiểm tra `$rate->service_package_id === $servicePackage->id`, 404 nếu sai package (test `rate_belongs_to_the_correct_package_only`).

## 7. Used-Package Guards

`ServicePackage::hasBeenUsed(): bool` (mới, thêm vào model Milestone 1) — `true` nếu có `rates()` hoặc có `BookingPackageFlag::where('package_key', code)`. **Không** query `FolioEntry` theo tên.

Bảo vệ 2 lớp:
1. **FormRequest** (`UpdateServicePackageRequest::withValidator`) — nếu package đã dùng và giá trị gửi lên khác giá trị hiện tại của field khoá, trả lỗi 422. **Quan trọng:** dùng so sánh "giá trị có đổi không", không dùng `prohibited` — vì form Inertia luôn gửi đủ field (input bị `disabled` ở Vue vẫn có trong payload với giá trị hiện tại) — `prohibited` sẽ sai (chặn cả khi không đổi gì). Đã phát hiện và sửa lỗi này trong quá trình viết test trước khi hoàn tất task.
2. **Model** (`ServicePackage::booted()`, mở rộng guard Milestone 1) — chặn cứng ở tầng `saving` nếu bất kỳ field khoá bị `isDirty()` và `hasBeenUsed()` — an toàn ngay cả khi có bug ở Controller/FormRequest.

Không xây chức năng xóa: không có route `destroy`, `ServicePackagePolicy::delete()` trả `false` cứng.

**Vẫn cho sửa tên/mô tả/đơn vị của package đã dùng** — form hiển thị cảnh báo: *"Thay đổi tên hoặc đơn vị chỉ áp dụng cho hiển thị/các giao dịch tương lai; các FolioEntry đã phát sinh không thay đổi."*

## 8. Audit

Không viết audit thủ công — dùng nguyên `AuditObserver` đã gắn với `ServicePackage`/`ServicePackageRate`/`BookingPackageFlag` từ Milestone 1. Mọi `create()`/`update()` trong Controller đều gọi trên **model instance đã load** (`$servicePackage->update(...)`, `$rate->update(...)`), không dùng `query()->update()` bulk — đảm bảo observer luôn chạy. Test xác nhận: tạo/sửa/toggle package → 3 audit log; toggle rate → audit log riêng.

## 9. Frontend

- `resources/js/Pages/Admin/ServicePackages/Index.vue` — danh sách 9 cột (Tên gói/Mã/Cách tính/Loại phí/Giá hiện tại/Đơn vị/Hiệu lực/Trạng thái/Thao tác), form tạo/sửa inline, dropdown strategy/quantity_mode/posting_frequency **chỉ nhận từ props do backend gửi** (backend chỉ gửi `implemented()`, không gửi enum thô) — Vue không tự liệt kê enum, không hard-code 3 mã package.
- `resources/js/Pages/Admin/ServicePackages/History.vue` — lịch sử giá + form thêm mức giá, không có input sửa trực tiếp `unit_price` dòng cũ.
- Link "Quản lý phụ phí hệ thống" trỏ `/admin/service-rates` đặt ngay trong trang Index (không gọi Service Rates cũ là "Gói dịch vụ").
- Ghi chú kỹ thuật dev-only: *"Giá gói đang được cấu hình; tích hợp tính phí tự động sẽ hoàn tất ở Milestone 4."* — chỉ hiện khi `import.meta.env.DEV` (Vite dev build), tự động **không xuất hiện trong bundle production** (`npm run build`).
- Responsive: bảng dùng `overflow-x-auto` (cùng pattern `ServiceRates/Index.vue` đã có), cột Thao tác dùng link/button chữ ngắn, không chiếm màn hình; mobile vẫn thao tác được đầy đủ (sửa/toggle/xem giá) qua cuộn ngang — không xây card layout riêng (giữ minimal, tái dùng pattern đã có sẵn trong dự án).

## 10. Backward Compatibility

- **`AppLayout.vue`:** đổi đúng 1 dòng — `{ label: 'Biểu giá dịch vụ', href: '/admin/service-rates', ... }` → `{ label: 'Gói dịch vụ', href: '/admin/service-packages', icon: DollarSign, show: can('service_packages.manage') }`. Hunk CSS header-padding có sẵn từ trước (ngoài phạm vi mọi task trước đó) vẫn còn nguyên, không bị mất.
- **`/admin/service-rates`:** route/controller/policy/Vue/history/CRUD/test **không đổi 1 dòng nào** — xác nhận bằng `git diff` rỗng cho `ServiceRateController.php`, `ServiceRatePolicy.php`, `ServiceRate.php`. Không redirect. Test `ServiceRateCrudTest`/`ServiceRateVersioningTest` PASS nguyên trong targeted suite.
- **Booking:** `PackageEnrollmentController.php`, `PackageEnrollmentService.php`, `Packages.vue`, `PackagePanel.vue`, migration `booking_package_flags` — **0 diff**. Booking vẫn hiển thị 3 gói hard-code như trước, chưa đọc `service_packages`.
- **Posting Job:** `BreakfastPostingJob.php`, `ExtraPersonPostingJob.php`, `ExtraBedPostingJob.php`, `NightAuditService.php` — **0 diff**. Night Audit vẫn tính giá qua `service_rates`/`ChargeType` như trước, chưa đọc `service_package_rates`.
- **Folio:** `FolioEntry.php`, `FolioService.php` — **0 diff**.
- **Product/Service:** không file `ProductService*` nào bị đụng.

## 11. Files Changed

**Mới:**
- `app/Http/Controllers/Admin/ServicePackageController.php`
- `app/Http/Controllers/Admin/ServicePackageRateController.php`
- `app/Policies/ServicePackagePolicy.php`
- `app/Http/Requests/Admin/StoreServicePackageRequest.php`
- `app/Http/Requests/Admin/UpdateServicePackageRequest.php`
- `app/Http/Requests/Admin/StoreServicePackageRateRequest.php`
- `resources/js/Pages/Admin/ServicePackages/Index.vue`
- `resources/js/Pages/Admin/ServicePackages/History.vue`
- `tests/Feature/ServicePackageAdminTest.php`
- `tests/Feature/ServicePackageRateAdminTest.php`
- `docs/reports/dynamic-service-package-admin-milestone-2-report.md` (file này)

**Sửa:**
- `routes/web.php` (+7 route, +2 import)
- `app/Providers/AppServiceProvider.php` (+1 import, +1 `Gate::policy()`)
- `resources/js/Layouts/AppLayout.vue` (1 dòng menu — hunk CSS ngoài phạm vi vẫn còn riêng, chưa stage/commit)
- `app/Enums/PackageCalculationStrategy.php` (+`compatibleQuantityModes()`, +`isCompatibleWith()` — registry tương thích tập trung, dùng chung Store/Update/model guard)
- `app/Models/ServicePackage.php` (+`hasBeenUsed()`, +`currentRate()`, mở rộng `booted()` chặn sửa field khoá khi đã dùng; refactor gọi `isCompatibleWith()` thay vì hàm `private` trùng lặp cũ)

Đã chạy lại toàn bộ 24 test Milestone 1 sau khi sửa 2 file trên — không regression.

## 12. Tests

- **Milestone 1 (chạy lại):** `ServicePackageCatalogTest` 16 + `ServicePackageRateVersioningTest` 8 = **24/24 PASS**.
- **Milestone 2 (mới):** `ServicePackageAdminTest` **23/23 PASS**, `ServicePackageRateAdminTest` **13/13 PASS** — tổng **36/36 PASS**.
- **Targeted suite** (`--filter="ServicePackage|ServiceRate|PackageEnrollment|BookingPackage|BreakfastPosting|ExtraPersonPosting|ExtraBedPosting|NightAudit|Folio|BookingServiceRates"`): **262 passed, 0 failed** (log: `storage/logs/milestone2_targeted_run.txt`) — tăng đúng từ 226 (baseline Milestone 1) + 36 (test Milestone 2 mới) = 262.
- **Full suite:** không chạy lại trong lượt này (không có thay đổi logic ngoài phạm vi 2 file M1 đã re-test riêng + code Milestone 2 hoàn toàn mới/cộng thêm); baseline full suite gần nhất (970 passed/24 failed pre-existing, không liên quan) từ báo cáo Milestone 1 vẫn còn hiệu lực làm tham chiếu.

## 13. Build

`npm run build`: **PASS**, 23.85s. Bundle tăng nhẹ do 2 trang Vue mới (`app-IoANUmoX.js` 628.45 kB so với 608.66 kB trước đó) — cảnh báo chunk-size >500kB đã có từ trước, không phải lỗi mới.

## 14. Known Limitations

- **Booking vẫn chưa đọc tên/giá từ `service_packages`** — `Packages.vue`/`PackagePanel.vue` vẫn hard-code như Milestone 1 để lại (Milestone 3).
- **Posting Job vẫn chưa đọc `service_package_rates`** — Night Audit tính phí 3 gói vẫn hoàn toàn qua `service_rates`/`ChargeType` cũ, không bị ảnh hưởng bởi bất kỳ giá nhập ở trang Gói dịch vụ mới (Milestone 4).
- **Không được deploy UI giá gói riêng lẻ lên production trước khi Milestone 4 hoàn tất** — nếu deploy sớm, admin có thể nhập giá ở `/admin/service-packages` nhưng giá đó **không có tác dụng thật** với khách, gây hiểu nhầm nghiêm trọng. Ghi chú dev-only trong UI (mục 9) nhằm giảm rủi ro này ở local, nhưng đây không phải giải pháp production — quyết định sản phẩm đã khoá (Architecture Review) là không đưa UI này lên production trước Milestone 4.
- **`/admin/service-rates` vẫn tồn tại** và vẫn là nơi duy nhất Night Audit thực sự dùng cho 3 gói hiện tại — hai màn hình (`/admin/service-packages` và `/admin/service-rates`) tạm thời cùng tồn tại, dễ gây nhầm "nhập giá ở đâu mới đúng" cho người vận hành nếu không được hướng dẫn rõ (đã có link chéo + ghi chú dev-only để giảm nhầm lẫn, nhưng rủi ro nhầm lẫn vẫn còn tới khi Milestone 4 xong).
- **Product/Service không thay đổi** — không file `ProductService*` nào bị đụng.
- **Chưa có "xem booking đang dùng package"** — cột này không có trong index (đúng phạm vi loại trừ của Milestone 2).
- **Hiệu năng index:** `hasBeenUsed()`/`currentRate()`/`rates()->count()` chạy riêng cho mỗi package trong `map()` — với số package nhỏ (hiện 3, dự kiến vài chục) không đáng kể, nhưng là N+1 nhẹ nếu danh mục phình to sau này — chưa tối ưu (không cần thiết ở quy mô Milestone 2).

## 15. Milestone 3 Dependencies

Cần: đổi `PackageEnrollmentController::buildAvailablePackages()` đọc `ServicePackage::active()->bookable()` thay `$packageMap` cứng; đổi `Packages.vue`/`PackagePanel.vue` bỏ label cứng; quyết định UX panel rút gọn có hiển thị đủ 3 gói hay không (open question từ trước, chưa trả lời).

## 16. Milestone 4 Dependencies

Cần: đổi 3 Posting Job đọc `ServicePackage::currentRate()`/`ServicePackageRate` theo `code` cố định thay `ServiceRateService::resolveFor(ChargeType, ...)`; xoá ghi chú dev-only trong UI sau khi hoàn tất; xác nhận giá hiển thị ở `/admin/service-packages` khớp đúng giá Night Audit sử dụng (Product Decision #5 đã khoá).

## 17. Deployment Restrictions

**Không deploy production trong task này.** Khi tới lượt deploy Milestone 2 (sau Milestone 3/4 hoặc theo quyết định riêng của Product Owner nếu chỉ deploy phần quản trị catalog mà chưa bật cho khách): migration mới không có (Milestone 2 không tạo migration nào, chỉ dùng bảng Milestone 1 đã có) — chỉ cần deploy code, không cần chạy migration/seeder bổ sung. Vẫn phải qua đúng quy trình backup/review/smoke-test đã dùng ở các hotfix trước.

## 18. Rollback

Toàn bộ Milestone 2 là code thuần (routes, controllers, policy, requests, Vue, tests, model/enum mở rộng), không có migration mới. Rollback = revert/xoá đúng danh sách file ở mục 11, không cần `migrate:rollback`. Model/enum đã mở rộng (`ServicePackage.php`, `PackageCalculationStrategy.php`) có thể revert về đúng bản Milestone 1 bằng `git checkout e9ffea6 -- app/Models/ServicePackage.php app/Enums/PackageCalculationStrategy.php` nếu cần tách riêng.

## 19. Readiness (trạng thái tại thời điểm viết báo cáo, trước Manual QA)

~~MILESTONE 2 COMPLETE — READY FOR REVIEW~~ — xem mục 20-21 cho trạng thái cuối sau Manual QA và phê duyệt commit.

## 20. Manual QA

**Environment:** Local (`APP_ENV=local`, `DB_HOST=127.0.0.1`, `DB_DATABASE=lastella_pms` — không phải production).
**URL kiểm tra:** `http://127.0.0.1:8000/admin/service-packages` (và `http://127.0.0.1:8000/admin/service-rates` để kiểm tra tương thích ngược).
**Tài khoản/role đã kiểm tra:** 1 tài khoản role `ADMIN`/`MANAGER` (local, không ghi email/mật khẩu ở đây) và tài khoản role `RECEPTION` (local) để kiểm tra bị chặn quyền. Không dùng dữ liệu/tài khoản production.

**Ghi chú vận hành trước khi QA:** Lần đăng nhập đầu tiên gặp lỗi 403 tại `/admin/service-packages` do permission `service_packages.manage` chưa được seed vào DB local (đã xử lý riêng ở bước trước — chạy `RolePermissionSeeder`, không sửa code, không ảnh hưởng phạm vi Milestone 2). Sau khi seed đúng permission, Manual QA được thực hiện đầy đủ theo checklist dưới đây.

| # | Kịch bản | Kết quả |
|---|---|---|
| 1 | Truy cập `/admin/service-packages` bằng Admin | PASS |
| 2 | Menu "Gói dịch vụ" hiển thị đúng trong sidebar (không còn "Biểu giá dịch vụ" làm lối vào chính) | PASS |
| 3 | Danh sách hiển thị đủ 3 package backfill (BREAKFAST_PER_NIGHT, EXTRA_PERSON_PER_NIGHT, EXTRA_BED_PER_NIGHT) với tên tiếng Việt lấy từ database | PASS |
| 4 | Giao diện trang danh sách hiển thị bình thường | PASS |
| 5 | Form tạo/sửa gói hiển thị bình thường | PASS |
| 6 | Tạo gói mới và sửa gói trên Local | PASS |
| 7 | Toggle `is_active` và `is_bookable` — hai trạng thái độc lập, không gộp | PASS |
| 8 | Tạo mức giá đầu tiên + xem lịch sử giá | PASS |
| 9 | Tạo mức giá thứ hai (hiệu lực tương lai) — giá cũ không bị ghi đè | PASS |
| 10 | Các trường khoá (code/charge_type/calculation_strategy/quantity_mode/posting_frequency) của package đã dùng bị chặn sửa | PASS |
| 11 | Các trường name/description/unit_label/display_order/is_active/is_bookable vẫn sửa được dù package đã dùng | PASS |
| 12 | `/admin/service-rates` (route cũ) vẫn hoạt động bình thường | PASS |
| 13 | Giao diện responsive/mobile vẫn thao tác được (danh sách, form, lịch sử giá) | PASS |
| 14 | Admin có quyền `service_packages.manage`, vào được trang | PASS |
| 15 | Reception không thấy menu, truy cập trực tiếp bị chặn | PASS |

**Lỗi phát hiện trong Manual QA:** Không có lỗi thuộc phạm vi code Milestone 2. (Lỗi 403 ban đầu là vấn đề dữ liệu permission trên môi trường local, đã xử lý tách riêng trước khi QA, không phải lỗi trong 15 file thuộc commit này.)

**Kết luận Manual QA: PASS — toàn bộ 15/15 kịch bản đạt.**

## 21. Final Status

- **Manual QA: PASS.**
- **ChatGPT review: APPROVED FOR MILESTONE 2 COMMIT.**
- **Milestone 2: COMPLETE.**
- Chưa push Milestone 2 (chỉ commit local).
- Chưa deploy production.
- **Toàn bộ chức năng "Gói dịch vụ" vẫn chưa hoàn thành** — Milestone 3 (Booking Enrollment Integration) và Milestone 4 (Posting/Pricing Integration) chưa triển khai.

**MILESTONE 2 COMPLETE — READY FOR REVIEW**
