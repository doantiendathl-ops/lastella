# Unified Services & Requests — Architecture Review

**Ngày:** 2026-08-16
**Phạm vi:** Toàn bộ audit runtime hiện tại trước khi thiết kế kiến trúc mới, theo `docs/yeucaumoi.txt` Mục 23/31.
**Trạng thái:** Slice 1 (Giường phụ) đã triển khai. Xem `unified-services-implementation-plan.md` và `unified-services-implementation-report.md`.

---

## 1. Kiến trúc hiện tại (trước Slice 1)

Hệ thống hiện có **4 module quản lý "dịch vụ/yêu cầu" tách biệt hoàn toàn**, không chia sẻ mô hình dữ liệu, không chia sẻ nguồn giá, không chia sẻ luồng nghiệp vụ:

| Module | Bảng dữ liệu | Trang quản trị | Nguồn giá | Job tính tiền |
|---|---|---|---|---|
| Gói dịch vụ (dynamic catalog) | `service_packages`, `service_package_rates`, `booking_package_flags` | `/admin/service-packages` | `service_package_rates` (qua `ServicePackage::currentRate()`) | 2 đường: `ServicePackagePostingJob` (gói "dynamic") **hoặc** 1 trong 3 job cứng bên dưới nếu mã khớp |
| Phụ phí hệ thống (legacy) | `service_rates` | `/admin/service-rates` | `service_rates` (qua `ServiceRateService::resolveFor()`) | `BreakfastPostingJob`, `ExtraPersonPostingJob`, `ExtraBedPostingJob`, `CityTaxPostingJob` — mỗi job hard-code đúng 1 `ChargeType` |
| Yêu cầu đặc biệt Booking | `booking_special_requests` | Không có trang quản trị catalog — danh mục viết cứng trong `StoreBookingSpecialRequestRequest::ALLOWED_REQUEST_TYPES` (backend) + `SpecialRequestPanel.vue::REQUEST_TYPE_CATALOG` (frontend), 2 nơi phải tự đồng bộ tay | Không có giá — module này **không có khái niệm tính phí** | Không tính phí (trừ 1 trường hợp đặc biệt: `twin_to_double` được Sơ đồ thao tác đọc trực tiếp để hiện icon, không qua billing) |
| Sản phẩm/Dịch vụ (kiểm phòng + cộng tay) | `product_services`, `product_service_categories` | `/admin/product-services` | `product_services.price` (1 giá duy nhất, không có lịch sử theo effective_from) | Cộng trực tiếp vào Folio qua form "Thêm phụ phí" hoặc luồng Kiểm đồ trả phòng — không qua Night Audit |

### Sơ đồ luồng hiện tại

```
[Gói dịch vụ]         service_package_rates ─┐
                                              ├─→ ServicePackagePostingJob ─┐
[Phụ phí hệ thống]    service_rates ─────────┼─→ 4 job cứng theo ChargeType ┼─→ FolioEntry → Folio
                                              │                             │
[Yêu cầu đặc biệt]     (không có giá) ────────┘  (không tham gia billing)   │
                                                                             │
[Sản phẩm/Dịch vụ]     product_services.price ──────────────────────────────┘ (post trực tiếp, không qua Night Audit)
```

---

## 2. Các vấn đề đã xác định (bằng chứng cụ thể, đã kiểm tra trực tiếp)

### 2.1. Duplicate pricing — 2 nguồn giá cho cùng 1 nghiệp vụ

`ServicePackagePostingJob` định giá từ `service_package_rates`; 3 job cứng (Breakfast/ExtraPerson/ExtraBed) định giá từ `service_rates`. Không có ràng buộc nào bắt 2 bảng này khớp nhau. Đã xác nhận thực tế trên production: gói "Giường phụ" có giá 150.000đ ở cả 2 nơi — trùng ngẫu nhiên, không có cơ chế đồng bộ.

### 2.2. Hard-coded service/request definitions

- 3 mã cố định trong PHP (`PackageEnrollmentService::BREAKFAST_PER_NIGHT`, `EXTRA_PERSON_PER_NIGHT`, `EXTRA_BED_PER_NIGHT`) — nếu `ServicePackage.code` trong database không khớp CHÍNH XÁC những chuỗi này, gói đó rơi khỏi luồng tính tiền chuyên dụng.
- Danh mục Yêu cầu đặc biệt (21 loại, 5 nhóm) viết cứng ở 2 file riêng biệt (`StoreBookingSpecialRequestRequest.php` + `SpecialRequestPanel.vue`), không có trang quản trị.

### 2.3. Extra Bed special-case — lỗi thật đã xác nhận trên production

Gói "Giường phụ" trên `pms.lastella.com.vn` có `code = GIUONGPHU`, trong khi mã đúng theo `ServicePackageSeeder.php` phải là `EXTRA_BED_PER_NIGHT`. Hệ quả:

1. UI nhập "giường phụ theo phòng" không bao giờ hiện (`Packages.vue:233` so khớp cứng `pkg.key === 'EXTRA_BED_PER_NIGHT'`).
2. `PackageEnrollmentService::updateExtraBedRoomQuantities()` sẽ báo lỗi "Gói dịch vụ không tồn tại." nếu cố lưu qua đường đúng.
3. **Rủi ro tính tiền sai:** vì `enroll()` chỉ chặn đúng chuỗi `EXTRA_BED_PER_NIGHT` (`PackageEnrollmentService.php:84`), gói `GIUONGPHU` **không bị chặn** khỏi luồng đăng ký chung — nếu nhân viên bấm "Đăng ký" thường, `BookingPackageFlag(package_key=GIUONGPHU)` được tạo, và `ServicePackagePostingJob` (không phải `ExtraBedPostingJob`) sẽ tính tiền **theo từng phòng/từng lượt lưu trú của booking** — tái hiện đúng lỗi nhân giá 3 lần cho booking nhiều phòng mà "Room-Scoped Bed Operations Correction" đã từng sửa cho đường đúng.

Audit trực tiếp trên production (đọc qua phiên trình duyệt đã đăng nhập, chỉ đọc): **0/38 booking đã từng đăng ký `GIUONGPHU`, 0 phòng nào có `extra_bed_quantity > 0`**. Night Audit **chưa từng chạy lần nào** trên production kể từ booking đầu tiên (08/08/2026) — nghĩa là chưa từng có `FolioEntry` nào được post qua bất kỳ job kiểm toán đêm nào, không riêng gì Giường phụ.

### 2.4. Night Audit — cơ chế bắt kịp (catch-up) có giới hạn cứng

- `audit_window_days` (mặc định 7) chặn chạy bù quá 7 ngày so với ngày kế toán hiện tại.
- **Giới hạn nghiêm trọng hơn:** `NightAuditPipeline::run()` chỉ quét `Stay::whereIn('status', [CheckedIn])` — theo trạng thái **hiện tại lúc chạy**, không theo ngày được chọn để chạy bù. Khách đã trả phòng thì các đêm ở của họ **không thể ghi phí lại được nữa**, bất kể còn trong hạn 7 ngày hay không.
- Cơ chế double-posting protection THẬT SỰ nằm ở `posting_key` unique-check trong từng `FolioEntry`, không phải ở lớp `NightAuditRun`/`audit_window_days` — 2 lớp khóa đó chỉ là rào chắn thao tác, không phải rào chắn tài chính.

### 2.5. Fulfillment vs Billing — chưa từng tách biệt

Không có khái niệm "trạng thái vận hành" tách biệt "trạng thái tính tiền" ở bất kỳ module cũ nào. `BookingPackageFlag` chỉ là 1 cờ nhị phân (đăng ký/chưa đăng ký) — không có CREATED/CONFIRMED/COMPLETED, không audit trail theo từng bước.

---

## 3. Kiến trúc đích (đã triển khai ở Slice 1)

```
                         ┌─────────────────┐
                         │ service_categories│  (Mục 3 — quản trị qua DB/UI)
                         └────────┬─────────┘
                                  │
                         ┌────────▼─────────┐
                         │     services      │  (Mục 2 — 1 catalog duy nhất)
                         │ scope / billing_mode /│
                         │ quantity_enabled /    │
                         │ fulfillment_required  │
                         └────────┬─────────┘
                                  │
                         ┌────────▼─────────┐
                         │  service_prices   │  (Mục 5 — 1 nguồn giá chuẩn, có lịch sử)
                         └────────┬─────────┘
                                  │ ServicePricingResolver (Mục 5 — resolver duy nhất)
                                  │
                         ┌────────▼─────────┐
                         │ booking_services  │  (Mục 6/7/8/9/10/11 — 1 giao dịch/booking)
                         │ suggested_price snapshot│
                         │ actual_price + reason   │
                         │ fulfillment_status ≠ billing│
                         └────────┬─────────┘
                                  │
                    ┌─────────────┴──────────────┐
                    │                             │
          ONE_TIME (post ngay lúc enroll) PER_NIGHT (Night Audit)
                    │                             │
                    └─────────────┬──────────────┘
                                  │ UnifiedServicePostingJob (Mục 15/16)
                                  │ posting_key = booking_service_id + scope + business_date
                                  ▼
                             FolioEntry → Folio
```

### Nguyên tắc đã tuân thủ

- **Không xóa/đổi bất kỳ bảng cũ nào** — `service_packages`, `service_rates`, `service_package_rates`, `booking_package_flags`, `booking_special_requests`, `product_services`, `room_assignments.extra_bed_quantity` đều còn nguyên, còn chạy song song.
- **4 bảng mới hoàn toàn additive** (`service_categories`, `services`, `service_prices`, `booking_services`) — không có migration nào ALTER bảng cũ.
- `UnifiedServicePostingJob` được **thêm vào** danh sách job của `NightAuditService`, không thay thế job nào.
- Route mới (`/admin/services`, `/admin/service-categories`, `/admin/bookings/{booking}/services`) hoàn toàn tách biệt route cũ — route cũ (`/admin/service-packages`, `/admin/service-rates`, `/admin/bookings/{booking}/packages`) vẫn hoạt động y nguyên (Mục 29 — chưa cần deprecate/redirect ở slice này).
- Không có `code` string nào được so sánh cứng trong logic nghiệp vụ mới — mọi hành vi (scope/billing/quantity/fulfillment) đọc từ cột cấu hình trên `services`.

---

## 4. Pricing ownership

**Một resolver duy nhất:** `App\Services\ServicePricingResolver::resolve(Service, businessDate): ?ServicePrice`. Mọi nơi cần giá (hiển thị UI, validate lúc đăng ký, tính tiền lúc posting) đều gọi qua đây — không có đường tắt đọc thẳng `service_prices` ở nơi khác.

---

## 5. Billing lifecycle

| | ONE_TIME | PER_NIGHT |
|---|---|---|
| Thời điểm post | Ngay khi `BookingServiceEnrollmentService::enroll()` tạo dòng, gọi `UnifiedServicePostingJob::postOneTime()` | Qua Night Audit, mỗi ngày kế toán, `UnifiedServicePostingJob::execute()` |
| Idempotency key | `UNIFIED_SVC_ONE_TIME_{booking_service_id}` | `UNIFIED_SVC_{booking_service_id}_{stay_id}_{business_date}` |
| Retry-safe | Có — khóa theo id duy nhất của dòng, dòng chỉ tạo 1 lần | Có — khóa theo tổ hợp giao dịch+phòng/booking+ngày, `FolioEntry` lock trong transaction |

---

## 6. Fulfillment lifecycle

`ServiceFulfillmentStatus`: `CREATED → CONFIRMED → COMPLETED`, có thể `CANCELLED` từ `CREATED`/`CONFIRMED` (không được từ `COMPLETED`). Mỗi bước ghi `*_by`/`*_at` riêng (tái sử dụng đúng pattern audit trail đã có ở `BookingSpecialRequest`). **Chỉ `CANCELLED` mới dừng billing** — `COMPLETED` không dừng PER_NIGHT (đã có test xác nhận `test_completed_fulfillment_status_does_not_stop_per_night_billing`).

---

## 7. Night Audit integration

`UnifiedServicePostingJob` đăng ký thêm vào `NightAuditService::runForDate()`, chạy sau `RoomChargePostingJob` (dependency), song song với 4 job cũ trong cùng 1 lượt `NightAuditPipeline::run()`. Đã kiểm chứng bằng test chạy qua **pipeline thật** (không mock), xác nhận cả 7 job cùng chạy không xung đột.

---

## 8. Folio integration

Không có "sổ tiền song song" — `UnifiedServicePostingJob` tạo `FolioEntry` giống hệt cấu trúc các job cũ (`charge_type`, `posting_source`, `posting_key`, `entry_date`...), cùng đổ vào `Folio` hiện có. `charge_type` gán `ChargeType::Other` (giống cách `ServicePackagePostingJob` đã làm cho gói "dynamic") vì chưa có subtype riêng trong enum `ChargeType` cho catalog hợp nhất — mô tả (`description`) mang tên + mã dịch vụ để truy vết.

---

## 9. Room Inspection integration

**Chưa migrate ở Slice 1.** `product_services`/Kiểm đồ trả phòng giữ nguyên luồng cũ, không đụng tới. Việc hợp nhất Sản phẩm/Dịch vụ (kể cả minibar/kiểm phòng) vào catalog mới là slice sau (xem `unified-services-implementation-plan.md` Mục "Slice 2+").

---

## 10. Migration strategy & Legacy compatibility

- **GIUONGPHU (production):** đã audit xác nhận 0 usage thật (0 enrollment, 0 posting) — an toàn để chỉ **vô hiệu hóa** (`is_active=false`, `is_bookable=false`), không cần đổi mã/xóa. Công cụ `php artisan services:audit-miscoded-legacy-packages` (dry-run mặc định, `--apply` để thực thi) đã viết và test — **chưa chạy trên production**, chỉ chạy read-only qua trình duyệt để audit.
- **Local dev DB:** đã có sẵn 3 gói đúng mã (do `ServicePackageSeeder` từng chạy) — không có bản ghi miscoded nào cần sửa.
- **Dữ liệu lịch sử:** không migrate bất kỳ `BookingPackageFlag`/`ServicePackageRate` nào sang bảng mới ở slice này — theo đúng cho phép của Mục 20 ("có thể giữ lịch sử cũ read-only, chỉ migrate giao dịch đang mở nếu an toàn hơn"). Vì Giường phụ trên production hoàn toàn chưa có giao dịch nào, "migrate" ở đây chỉ là tạo catalog mới, không có dữ liệu cũ cần chuyển.

---

## 11. Risks còn lại

1. **`ChargeType::Other` dùng chung cho mọi dịch vụ hợp nhất** — báo cáo doanh thu theo loại phí (`RevenueReportService`) sẽ gộp chung mọi dịch vụ mới vào "Khác", mất độ chi tiết so với 3 job cũ (vốn có `ChargeType` riêng: `EXTRA_BED`, `FOOD_BEVERAGE`, `EXTRA_PERSON`). Cần cân nhắc thêm `ChargeType::UnifiedService` hoặc đọc `description` để phân loại lại ở slice sau.
2. **2 route booking-side song song** (`/packages` cũ và `/services` mới) — nhân viên có thể vô tình dùng nhầm đường cũ cho dịch vụ đã migrate (đúng như đã xảy ra với GIUONGPHU). Khuyến nghị: ẩn "Giường phụ" khỏi trang Gói dịch vụ cũ ngay khi migrate xong (đã làm bằng cách gợi ý deactivate `GIUONGPHU`), và cân nhắc ẩn hẳn nút "Gói dịch vụ" khỏi Booking Show một khi đủ dịch vụ đã chuyển hết sang catalog mới.
3. **Chưa có trang admin hợp nhất theo đúng nghĩa Mục 1** — hiện có 2 trang riêng (`/admin/services` mới và `/admin/service-packages` cũ) tồn tại song song, chưa gộp UI. Đây là slice sau.
