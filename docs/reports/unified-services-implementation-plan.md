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
