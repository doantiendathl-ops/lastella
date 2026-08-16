# Unified Services & Requests — Implementation Plan (Slice 1)

**Ngày:** 2026-08-16
**Slice:** 1/N — chứng minh toàn bộ kiến trúc mới end-to-end qua đúng 1 nghiệp vụ thật: **Giường phụ**.
**Slice được người dùng duyệt (GATE 1) trước khi triển khai.**

---

## 1. Vì sao chọn Giường phụ làm slice đầu

Đây là nghiệp vụ đã được audit kỹ trong phiên làm việc trước khi viết prompt này — đã biết rõ dữ liệu thật, đã biết rõ lỗi (`GIUONGPHU`), và nó chạm đủ những đặc tính khó nhất của kiến trúc đích: theo phòng (`scope=ROOM`), tính qua đêm (`billing_mode=PER_NIGHT`), cần xác nhận/hoàn thành (`fulfillment_required=true`), tích hợp Night Audit, cần snapshot giá. Nếu chạy đúng cho Giường phụ, kiến trúc coi như đã được chứng minh cho mọi trường hợp còn lại.

---

## 2. Quyết định kiến trúc đã tự chọn (không có Business Blocker nào phát sinh)

Tất cả quyết định dưới đây được tự suy luận từ code/database/prompt, theo đúng nguyên tắc Mục 2 của `docs/yeucaumoi.txt` — không có quyết định nào thỏa cả 4 điều kiện của "Business Blocker thực sự" (2 cách hiểu hợp lý khác nhau + không suy luận được từ code + ảnh hưởng tính tiền/quyền/mất dữ liệu + không có giải pháp backward-compatible an toàn).

| Quyết định | Lựa chọn | Lý do |
|---|---|---|
| `ChargeType` cho FolioEntry của dịch vụ hợp nhất | `ChargeType::Other` | Không thêm case mới vào enum đang được nhiều nơi khác đọc (RevenueReport, Reconciliation...) trong 1 slice nhỏ — giữ additive tối đa. Rủi ro đã ghi vào Risks. |
| Route booking-side | Trang mới `/admin/bookings/{booking}/services`, KHÔNG sửa `Packages.vue` | Tách rủi ro hoàn toàn khỏi luồng cũ đang chạy; cả 2 cùng tồn tại trong giai đoạn chuyển đổi (Mục 1 cho phép). |
| Migration GIUONGPHU | Audit trước (đã xong, 0 usage), chỉ **deactivate**, không rename/xóa | `code` bị khóa bởi `hasBeenUsed()` một khi có giá — dù usage thật = 0, `service_package_rates` vẫn có 1 dòng giá nên field bị khóa qua UI. Deactivate là thay đổi an toàn nhất, đảo ngược được (bật lại `is_active`/`is_bookable`). |
| Permission mới | `services.manage` (catalog), tái dùng `booking.package.manage` (booking-side) | Mục 13 yêu cầu tái dùng permission đã có nếu phù hợp — `booking.package.manage` đã đúng nghĩa "quản lý dịch vụ trong 1 booking". |
| Category cho Giường phụ | Tạo mới `BED_CONFIG` ("Cấu hình phòng") | Không có danh mục hợp nhất nào có sẵn để tái dùng (danh mục cũ là 2 enum khác: `RequestCategory` và `ProductServiceCategory`, không migrate ở slice này). |
| Giá khởi tạo | 150.000đ, hiệu lực 01/08/2026 (khớp `service_rates` hiện tại) | Đây là giá **thật sự đang được dùng để tính tiền** hiện nay (qua `ExtraBedPostingJob`) — mang đúng số này sang để Admin chỉ cần xác nhận, không phải đoán. |

---

## 3. Data model

Xem chi tiết cột ở `unified-services-architecture-review.md` Mục 3. Tóm tắt 4 bảng mới:

- `service_categories` (id, code, name, description, sort_order, is_active, created_by, updated_by)
- `services` (id, category_id FK, code, name, description, is_chargeable, scope, billing_mode, quantity_enabled, default_quantity, unit_label, fulfillment_required, is_active, is_bookable, sort_order, created_by, updated_by)
- `service_prices` (id, service_id FK, unit_price, effective_from, is_active, created_by)
- `booking_services` (id, booking_id FK, service_id FK, room_assignment_id FK nullable, quantity, billing_mode_selected, suggested_price, actual_price, price_override_reason, fulfillment_status, created_by, confirmed_by/at, completed_by/at, cancelled_by/at)

---

## 4. Build order (đã thực hiện theo đúng thứ tự này)

1. Migrations (4 bảng, additive-only, không FK ngược vào bảng cũ trừ `users`/`bookings`/`room_assignments` là tham chiếu đọc, không sửa cấu trúc chúng).
2. Enum: `ServiceScope`, `ServiceBillingMode`, `ServiceFulfillmentStatus`.
3. Model: `ServiceCategory`, `Service` (có khóa field sau khi dùng, mirror `ServicePackage::booted()`), `ServicePrice`, `BookingService`.
4. `ServicePricingResolver` — resolver giá duy nhất.
5. `BookingServiceEnrollmentService` — enroll/confirm/complete/cancel, validate scope + giá + lý do đổi giá.
6. `UnifiedServicePostingJob` — PER_NIGHT (đăng ký thêm vào Night Audit) + `postOneTime()` (gọi trực tiếp lúc enroll).
7. Admin catalog: `ServiceCategoryController` (tái dùng `Admin/CrudIndex`+`CrudForm` có sẵn), `ServiceCatalogController`, `ServicePriceController`.
8. Booking-side: `BookingServiceController` + trang Vue riêng `Admin/Booking/Services.vue`.
9. `AuditMiscodedLegacyPackages` console command (dry-run mặc định).
10. `UnifiedServiceSeeder` (idempotent) — tạo catalog "Giường phụ" chuẩn.
11. Permission `services.manage` (RolePermissionSeeder, additive).
12. Test suite (xem Mục 5).
13. Báo cáo.

---

## 5. Test plan

| Nhóm | File | Số test |
|---|---|---|
| Pricing resolver | `tests/Unit/Services/ServicePricingResolverTest.php` | 5 |
| Enrollment (scope/giá/quantity/fulfillment) | `tests/Unit/Services/BookingServiceEnrollmentServiceTest.php` | 16 |
| Posting job (idempotency, multi-room, ONE_TIME) | `tests/Feature/UnifiedServicePostingJobTest.php` | 13 |
| Night Audit thật (pipeline thật, không mock) | `tests/Feature/NightAuditUnifiedServiceIntegrationTest.php` | 2 |
| Audit command (GIUONGPHU-class detection) | `tests/Feature/Console/AuditMiscodedLegacyPackagesTest.php` | 5 |
| Admin catalog HTTP | `tests/Feature/ServiceCatalogAdminTest.php` | 9 |
| Category CRUD HTTP | `tests/Feature/ServiceCategoryAdminTest.php` | 2 |
| Booking-side HTTP | `tests/Feature/BookingServiceControllerTest.php` | 10 |
| **Tổng mới** | | **62** |

Bắt lỗi thật trong lúc TDD: `BookingServiceEnrollmentService::confirm()/complete()/cancel()` ban đầu validate transition hợp lệ nhưng **quên thực sự lưu `fulfillment_status` mới** — 3 test fail ngay, sửa xong pass lại 100%. Một route path sai (`ServiceCategoryController` redirect thiếu tiền tố `/admin`) cũng bị bắt bởi test HTTP và sửa trước khi merge.

---

## 6. Regression plan

- Baseline trước khi sửa: `php artisan test` toàn bộ, ghi nhận **1336 passed / 28 failed** (28 lỗi thuộc `RoomAvailabilityCheckerTest`, đã biết từ trước, không liên quan gì tới slice này — lỗi phụ thuộc ngày hệ thống).
- Sau khi hoàn tất: chạy lại toàn bộ, xác nhận số lỗi cũ không tăng, cộng thêm 62 test mới pass. Kết quả đầy đủ ở `unified-services-implementation-report.md`.
- `npm run build`: PASS, 0 lỗi (chỉ có cảnh báo kích thước chunk JS đã tồn tại từ trước, không liên quan).

---

## 7. Production Actions Required (liệt kê, KHÔNG tự chạy)

1. Chạy migration 4 bảng mới trên production: `php artisan migrate` (chỉ tạo bảng mới, không ALTER bảng cũ — an toàn additive).
2. Seed permission mới: `php artisan db:seed --class=RolePermissionSeeder` (idempotent, chỉ thêm `services.manage`, không đổi quyền cũ).
3. Seed catalog Giường phụ chuẩn: `php artisan db:seed --class=UnifiedServiceSeeder` (idempotent — kiểm tra kỹ giá 150.000đ/01-08-2026 còn đúng thực tế trước khi chạy, vì đây là giá mang từ `service_rates` hiện tại sang, không phải giá mới).
4. Audit + xử lý `GIUONGPHU`: chạy `php artisan services:audit-miscoded-legacy-packages` (dry-run) trước để xem báo cáo, review kỹ, rồi mới chạy `--apply` nếu đồng ý — **không tự động hóa bước này, cần người có thẩm quyền xác nhận trên production**.
5. Sau khi xác nhận ổn định: cân nhắc gỡ hẳn nút "Đăng ký" thường cho gói `GIUONGPHU` khỏi `Packages.vue` (hoặc để nguyên vì đã deactivate, không hiện nữa).

---

## 8. Explicitly out of scope (Slice 2+)

- Ăn sáng / Người thêm chuyển sang catalog mới.
- Danh mục Yêu cầu đặc biệt (`BookingSpecialRequest`) chuyển thành Service `is_chargeable=false`.
- Sản phẩm/Dịch vụ (kiểm phòng, minibar) hợp nhất.
- Trang admin hợp nhất thật sự (gộp `/admin/services` + `/admin/service-packages` + `/admin/service-rates` + `/admin/product-services` thành 1).
- Deprecate/redirect route cũ.
- Xóa bảng/cột legacy (`room_assignments.extra_bed_quantity`, `BookingPackageFlag`...).
- `ChargeType` riêng cho catalog hợp nhất (hiện dùng chung `Other`).

---

# Slice 2 — Ăn sáng + Người thêm

**Ngày:** 2026-08-17
**Đã duyệt (GATE):** người dùng chọn "Ăn sáng + Người thêm (Recommended)" trong 3 lựa chọn slice tiếp theo.

## Vì sao đây là slice rủi ro thấp

Trên production, `service_packages` hiện **không có** dòng nào mã `BREAKFAST_PER_NIGHT`/`EXTRA_PERSON_PER_NIGHT` (chỉ có `QA_PILOT_PKG` và `GIUONGPHU`) — nghĩa là 2 nghiệp vụ này **chưa từng đăng ký được qua UI cũ**, không có dữ liệu thật nào đang chạy để phải "di dời". Slice này chỉ thêm dữ liệu catalog mới (không có code mới ngoài test), tái sử dụng 100% engine Slice 1 đã xây và đã qua rà soát bảo mật/code.

## Quyết định đã tự chọn

| Quyết định | Lựa chọn | Lý do |
|---|---|---|
| Giá Ăn sáng | 120.000đ, hiệu lực 01/08/2026 | Khớp đúng giá `service_rates` (FOOD_BEVERAGE) đang là giá thật duy nhất từng dùng để tính tiền — mang forward, không đoán. |
| Giá Người thêm | **Không seed giá nào cả** | `service_rates` production **không có dòng EXTRA_PERSON nào** — không có số thật để mang forward. Tự bịa 1 con số sẽ đi ngược đúng nguyên tắc cả nỗ lực này hướng tới. Dịch vụ vẫn hiện trong catalog, nhưng `enroll()` sẽ từ chối ("chưa có giá chuẩn") cho tới khi Admin tự nhập giá thật — đã có test xác nhận hành vi này an toàn ở cả 2 trạng thái (trước/sau khi có giá). |
| Scope | `BOOKING` cho cả 2 | Đúng bản chất nghiệp vụ (ăn sáng/người thêm áp dụng cho cả booking, không riêng 1 phòng) — đồng thời là phép thử thực tế đầu tiên cho đường `scope=BOOKING` bằng dữ liệu seed thật (Slice 1 chỉ test bằng fixture tổng hợp). |
| `quantity_enabled` | Ăn sáng = false (luôn tính 1), Người thêm = true | Khớp đúng ngữ nghĩa `PackageQuantityMode` cũ (`NONE` vs `MANUAL_INPUT`). |
| `fulfillment_required` | false cho cả 2 | Không có bước "giao/xác nhận vật lý" thật như giường phụ — nhân viên không cần thao tác xác nhận/hoàn thành riêng cho từng đêm ăn sáng. |
| Code mới | Không có — chỉ sửa `UnifiedServiceSeeder.php` + thêm test | Đúng nguyên tắc Mục 24 "không rewrite mù" — engine Slice 1 đã đủ tổng quát. |

## Test mới

`tests/Feature/UnifiedServiceSlice2SeedTest.php` — 8 test, chạy trên **catalog seed thật** (không phải fixture tổng hợp): xác nhận đúng 3 Service được tạo, seeder idempotent, Ăn sáng có giá thật/Người thêm không có giá, không đăng ký được Người thêm tới khi có giá, số lượng ép về 1 khi `quantity_enabled=false`, cả 2 dịch vụ tính đúng 1 lần/đêm dù nhiều phòng, 2 dịch vụ cùng lúc tạo 2 khoản phí độc lập.

## Không cần rà soát bảo mật/code riêng cho slice này

Không có code nghiệp vụ mới — chỉ dữ liệu seed dùng lại đúng field/kiểu dữ liệu đã qua rà soát ở Slice 1, cộng test. Rủi ro tương đương 0.
