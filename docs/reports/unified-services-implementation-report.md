# Unified Services & Requests — Implementation Report (Slice 1)

**Ngày:** 2026-08-16
**Slice:** 1/N — Giường phụ, chứng minh kiến trúc mới end-to-end.
**Trạng thái triển khai:** COMPLETE (cho phạm vi Slice 1 đã duyệt).

---

## 1. Files changed

### Migrations mới (4, thuần additive)

| File | Nội dung |
|---|---|
| `database/migrations/2026_08_16_000001_create_service_categories_table.php` | Bảng `service_categories` |
| `database/migrations/2026_08_16_000002_create_services_table.php` | Bảng `services` |
| `database/migrations/2026_08_16_000003_create_service_prices_table.php` | Bảng `service_prices` |
| `database/migrations/2026_08_16_000004_create_booking_services_table.php` | Bảng `booking_services` |

Không có migration nào ALTER bảng cũ. Cả 4 đều có `down()` để rollback an toàn.

### Models mới

`app/Models/ServiceCategory.php`, `Service.php` (khóa field sau khi dùng, mirror `ServicePackage::booted()`), `ServicePrice.php`, `BookingService.php`.

### Model sửa (additive)

`app/Models/Booking.php` — thêm quan hệ `bookingServices(): HasMany` (không đụng quan hệ nào có sẵn).

### Enum mới

`app/Enums/ServiceScope.php`, `ServiceBillingMode.php`, `ServiceFulfillmentStatus.php`.

### Services mới

`app/Services/ServicePricingResolver.php`, `BookingServiceEnrollmentService.php`, `ServiceCategoryService.php`, `app/Services/Posting/UnifiedServicePostingJob.php`, `app/Repositories/Eloquent/ServiceCategoryRepository.php`.

### Service sửa (additive)

`app/Services/NightAuditService.php` — inject + đăng ký thêm `UnifiedServicePostingJob` vào pipeline (2 dòng, không đổi logic 4 job cũ).

### Controllers/Requests/Policies mới

`ServiceCatalogController`, `ServiceCategoryController`, `ServicePriceController`, `BookingServiceController` (`app/Http/Controllers/Admin/`); `StoreServiceRequest`, `UpdateServiceRequest`, `StoreServiceCategoryRequest`, `UpdateServiceCategoryRequest`, `StoreServicePriceRequest` (`app/Http/Requests/Admin/`); `ServicePolicy`, `ServiceCategoryPolicy` (`app/Policies/`).

### Console command mới

`app/Console/Commands/AuditMiscodedLegacyPackages.php` — dry-run mặc định, `--apply` để vô hiệu hóa gói bị đặt sai mã (chỉ khi 0 usage thật).

### Seeders

`database/seeders/UnifiedServiceSeeder.php` (mới, idempotent). `RolePermissionSeeder.php` sửa: thêm `services.manage` vào `PERMISSIONS` + ADMIN + MANAGER (additive, không đổi quyền khác). `DatabaseSeeder.php` sửa: thêm 1 dòng gọi seeder mới.

### Routes

`routes/web.php` — thêm 6 route admin catalog (`admin.service-categories.*` resource, `admin.services.*`, `admin.services.prices.*`) + 5 route booking-side (`admin.bookings.services.*`). Không sửa route cũ nào.

### Frontend mới

`resources/js/Pages/Admin/Services/Index.vue`, `resources/js/Pages/Admin/Booking/Services.vue`.

### Frontend sửa (additive, 1 dòng mỗi chỗ)

`resources/js/Layouts/AppLayout.vue` — thêm 1 mục menu "Dịch vụ & Yêu cầu". `resources/js/Pages/Admin/Bookings/Show.vue` — thêm 1 link "Dịch vụ & Yêu cầu" cạnh link "Gói dịch vụ" cũ (cả 2 cùng hiển thị).

### Tests mới (66 test, tất cả PASS)

`tests/Unit/Services/ServicePricingResolverTest.php` (5), `BookingServiceEnrollmentServiceTest.php` (16), `tests/Feature/UnifiedServicePostingJobTest.php` (16, gồm cả 3 test thêm sau khi sửa lỗi nghiêm trọng), `NightAuditUnifiedServiceIntegrationTest.php` (2), `Console/AuditMiscodedLegacyPackagesTest.php` (5), `ServiceCatalogAdminTest.php` (9), `ServiceCategoryAdminTest.php` (2), `BookingServiceControllerTest.php` (10).

---

## 2. Legacy paths retained / deprecated

**Retained (không đụng gì cả):** `service_packages`, `service_package_rates`, `service_rates`, `booking_package_flags`, `booking_special_requests`, `product_services`, `room_assignments.extra_bed_quantity`, `ExtraBedPostingJob`, `ServicePackagePostingJob`, `BreakfastPostingJob`, `ExtraPersonPostingJob`, `CityTaxPostingJob`, toàn bộ route `/admin/service-packages`, `/admin/service-rates`, `/admin/product-services`, `/admin/bookings/{booking}/packages`.

**Deprecated:** Chưa route nào bị deprecate/redirect ở slice này (đúng theo Mục 29, có thể để sau).

---

## 3. Data migration behavior

Không có dữ liệu lịch sử nào được chuyển từ bảng cũ sang bảng mới — vì audit xác nhận Giường phụ trên production chưa từng có giao dịch thật (0 enrollment, 0 posting). Catalog "Giường phụ" mới được **tạo mới hoàn toàn** qua `UnifiedServiceSeeder` (idempotent — chạy lại không tạo trùng), không phải "migrate" theo nghĩa chuyển đổi bản ghi cũ.

`AuditMiscodedLegacyPackages` command đã viết và test đầy đủ, nhưng **chưa chạy trên production** — chỉ chạy an toàn ở môi trường dev/test theo đúng Mục 22.

---

## 4. Lỗi thật đã bắt được và sửa trong quá trình TDD/review (minh bạch đầy đủ)

| # | Lỗi | Phát hiện bởi | Mức độ | Đã sửa |
|---|---|---|---|---|
| 1 | `BookingServiceEnrollmentService::confirm()/complete()/cancel()` validate đúng trạng thái chuyển tiếp nhưng **quên lưu `fulfillment_status` mới vào DB** | Unit test tự viết (TDD) | Cao (tính năng không hoạt động) | Có |
| 2 | Route `ServiceCategoryController` thiếu tiền tố `/admin` trong các redirect/`baseUrl` (route thật nằm trong nhóm `admin.` nhưng code viết như route ở ngoài, theo nhầm quy ước của `ProductServiceCategoryController`) | HTTP test tự viết | Trung bình (404 khi dùng thật) | Có |
| 3 | **`UnifiedServicePostingJob` tính phí trùng cho dịch vụ áp dụng toàn booking (`scope=BOOKING`) khi booking có nhiều phòng** — mỗi phòng/lượt lưu trú tạo 1 khóa chống trùng khác nhau, nên Night Audit tính tiền N lần cho N phòng thay vì đúng 1 lần/đêm | Agent rà soát bảo mật (`security-reviewer`) | **Nghiêm trọng** — đúng loại lỗi tính tiền sai mà toàn bộ tính năng này được viết ra để ngăn chặn, chỉ khác là xảy ra ở phạm vi "toàn booking" thay vì "theo phòng" | Có — sửa khóa chống trùng để không phụ thuộc vào lượt lưu trú khi dịch vụ áp dụng toàn booking; thêm 2 test hồi quy xác nhận (`test_booking_scoped_service_posts_exactly_once_per_night_regardless_of_room_count`, `test_booking_scoped_service_posts_a_new_charge_on_a_different_night`) |
| 4 | `UnifiedServicePostingJob::postPerNight()` thiếu chặn giá 0/âm (có ở `postOneTime()` nhưng thiếu ở nhánh qua đêm) | Agent rà soát code (`code-reviewer`) | Thấp | Có |
| 5 | `UnifiedServicePostingJob::rollback()` xóa nhầm dữ liệu của phòng khác nếu bị gọi (thiếu điều kiện lọc theo đúng lượt lưu trú) | Agent rà soát code (`code-reviewer`) | Trung bình (đường code chưa từng được gọi tới, nhưng có thật) | Có — thêm điều kiện lọc, kèm test `test_rollback_only_removes_the_entry_for_its_own_stay` |
| 6 | Trong lúc viết test cho lỗi #5, phát hiện thêm: so khớp ngày (`entry_date`) bằng chuỗi thường không khớp trên SQLite (dùng cho test) vì cột có kèm giờ; và câu lệnh `LIKE` có escape ký tự gạch dưới bằng dấu `\` chỉ hoạt động đúng trên MySQL, không hoạt động trên SQLite (không tự hiểu `\` là ký tự escape nếu không khai báo `ESCAPE`) | Tự phát hiện khi viết test cho #5 | Thấp/Trung bình — **đặc điểm này cũng tồn tại y hệt trong `ExtraBedPostingJob::rollback()` và `ServicePackagePostingJob::rollback()` (legacy), chưa từng bị phát hiện vì cả 2 file đó chưa từng có test cho `rollback()`** | Đã sửa trong file mới (`whereDate()` thay vì `where()`, bỏ escape không cần thiết). **Không sửa 2 file legacy** — ngoài phạm vi Slice 1, chỉ ghi nhận ở đây để không mất dấu vết. |

Không lỗi nào trong số này lọt tới production hay dữ liệu development thật — tất cả bắt được và sửa trước khi thực hiện bất kỳ commit nào, đúng quy trình TDD + review 2 lớp (security + code) mà `docs/yeucaumoi.txt` yêu cầu.

---

## 5. Test results

**Bộ test mới (Slice 1):** 66/66 PASS.

**Hồi quy toàn bộ hệ thống:**

| | Trước khi sửa (baseline) | Sau khi hoàn tất Slice 1 |
|---|---|---|
| Passed | 1336 | 1400 |
| Failed | 28 | 28 |

28 lỗi thất bại **giữ nguyên số lượng và giữ nguyên đúng 4 nhóm test cũ** (`RoomAvailabilityCheckerTest`, `BookingManagementUiTest`, `DashboardTest`, `ReleaseBatchSchemaTest`) — không liên quan gì tới bất kỳ file nào Slice 1 đã sửa, đã xác nhận qua diff. 64 test tăng thêm = đúng số lượng test mới của Slice 1.

`npm run build`: PASS, 0 lỗi, không có warning mới phát sinh (chỉ còn cảnh báo kích thước chunk JS đã có từ trước).

---

## 6. Known limitations (đã ghi nhận, không phải thiếu sót bị giấu)

1. `ChargeType::Other` dùng chung cho mọi dịch vụ hợp nhất — báo cáo doanh thu sẽ gộp chung, mất độ chi tiết so với 3 job cũ.
2. 2 route booking-side song song (`/packages` cũ, `/services` mới) — rủi ro nhân viên dùng nhầm đường cũ cho dịch vụ đã chuyển sang catalog mới.
3. Đặc điểm SQLite-vs-MySQL của `rollback()` (mục 4.6) tồn tại y hệt ở 2 job legacy, chưa sửa vì ngoài phạm vi.
4. Chưa có trang admin thật sự hợp nhất (Mục 1 của prompt) — 2 trang song song tồn tại.

---

# Slice 2 — Ăn sáng + Người thêm (2026-08-17)

**Trạng thái:** COMPLETE. Không có code nghiệp vụ mới — tái sử dụng 100% engine Slice 1.

## Files changed

| File | Thay đổi |
|---|---|
| `database/seeders/UnifiedServiceSeeder.php` | Sửa (additive) — thêm 2 category (`FOOD_BEVERAGE`, `GUEST_SURCHARGE`) + 2 Service (`BREAKFAST_PER_NIGHT`, `EXTRA_PERSON_PER_NIGHT`). Người thêm cố tình không có giá seed (production không có giá thật để mang forward). |
| `tests/Feature/UnifiedServiceSlice2SeedTest.php` | Mới — 8 test, chạy trên catalog seed thật. |

## Test results

Bộ test Slice 2: 8/8 PASS. Hồi quy toàn bộ: 1407 passed / 29 failed (tăng 1 lỗi so với sau Slice 1 — đã xác minh là flaky test có sẵn, không liên quan, xem `unified-services-regression-review.md` phần bổ sung Slice 2). `npm run build`: không chạy lại (Slice 2 không sửa file frontend nào).

## Known limitation mới

"Người thêm" hiện **chưa dùng được** cho tới khi Admin vào `/admin/services` tự nhập giá — đây là chủ đích, không phải lỗi (không có giá thật nào để tự động điền).

---

# Slice 3 — Danh mục Yêu cầu (2026-08-17)

**Trạng thái:** COMPLETE. Chi tiết đầy đủ (quyết định, lỗi bắt được, files changed) đã ghi trong `unified-services-implementation-plan.md` phần "Slice 3" — không lặp lại ở đây để tránh 2 nguồn thông tin lệch nhau.

**Tóm tắt:** 23 loại yêu cầu (loại trừ `extra_bed`) di dời sang catalog hợp nhất; `RoomOperationsBoardService`/`RoomSwapService` được cập nhật để badge "Ghép giường" hoạt động đúng với cả 2 nguồn dữ liệu (cũ + mới), kể cả khi đổi phòng. Rà soát code phát hiện + đã sửa 4 lỗi thật (2 fatal lúc TDD, 1 rủi ro bảo trì, 1 lỗi mất dữ liệu hiển thị khi đổi phòng — nghiêm trọng nhất). Test mới: 14/14 PASS. Hồi quy toàn bộ: 1421 passed / 29 failed (giữ nguyên 29 lỗi cũ, 0 lỗi mới).
