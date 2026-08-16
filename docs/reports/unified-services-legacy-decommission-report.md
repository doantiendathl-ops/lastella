# Unified Services & Requests — Legacy Decommission Report

**Ngày:** 2026-08-17
**Nhánh:** `phase-3`
**Phạm vi:** Di dời dữ liệu cũ sang hệ thống hợp nhất (local) + gỡ bỏ các chức năng cũ đã được thay thế hoàn toàn.

## 1. Bối cảnh

Sau khi hoàn tất 4 slice của kiến trúc "Dịch vụ & Yêu cầu hợp nhất" (theo `docs/yeucaumoi.txt`, các commit `ca188f0`, `ea46b44`, `c8f7bef`, `882d8e6`), người dùng yêu cầu:

1. Tự động cập nhật (di dời) toàn bộ dữ liệu gói dịch vụ / yêu cầu ở hệ thống cũ sang hệ thống mới trên môi trường local để test.
2. Xóa các chức năng cũ đã được hợp nhất: "Gói dịch vụ", "Phụ phí hệ thống", "Yêu cầu Booking cũ".

## 2. Di dời dữ liệu (local DB only)

Chạy qua `php artisan tinker` (script tạm `storage/migrate_legacy_local.php`, đã xóa sau khi dùng):

- **Giường phụ (`extra_bed_quantity` trên `RoomAssignment`)** → tạo `BookingService` (service `EXTRA_BED_PER_NIGHT`) tương ứng cho từng assignment đang có giường phụ, sau đó **zero-out** `extra_bed_quantity` gốc để tránh Night Audit ghi phí trùng (job cũ `ExtraBedPostingJob` vẫn đọc cột này).
  - Kết quả: 2 `BookingService` được tạo (booking 476, assignment 571 và 576).
- **`BookingPackageFlag`**: rà soát thấy 1 dòng còn sót lại, xác nhận không có job/route nào còn đọc bảng này (dead data) → **không di dời**, giữ nguyên để không mất dấu vết lịch sử.
- **`BookingSpecialRequest(extra_bed)`**: 2 dòng thông tin cho booking 461, không có đường hiển thị nào còn hoạt động (route/tab đã gỡ) và không có ý nghĩa tài chính → **không di dời** để tránh phát sinh phí hồi tố ngoài ý muốn; dữ liệu cũ vẫn còn nguyên trong bảng để tra cứu lịch sử nếu cần.

**Không thực hiện bất kỳ thao tác nào trên production** (`pms.lastella.com.vn`) — đúng nguyên tắc "production read-only" của `docs/yeucaumoi.txt` §22.

## 3. Chức năng đã gỡ bỏ (Gói dịch vụ + Yêu cầu Booking cũ)

### Route (`routes/web.php`)
Gỡ toàn bộ nhóm route: `service-packages.*`, `service-packages.rates.*`, `bookings.packages.*`, `bookings.special-requests.*` và các `use` import tương ứng. **Giữ lại** `service-rates.*` (xem mục 4).

### Controller / Request
Xóa: `ServicePackageController`, `ServicePackageRateController`, `PackageEnrollmentController`, `Admin/Booking/BookingSpecialRequestController`, cùng 4 FormRequest liên quan (`StoreServicePackageRequest`, `UpdateServicePackageRequest`, `StoreServicePackageRateRequest`, `StoreBookingSpecialRequestRequest`).

### Giao diện (Vue)
Xóa: `Admin/ServicePackages/Index.vue`, `History.vue`, `Admin/Booking/Packages.vue`, `Admin/Booking/SpecialRequests.vue`, `Admin/Bookings/Partials/PackagePanel.vue`, `Admin/Bookings/Partials/SpecialRequestPanel.vue`.

- `AppLayout.vue`: menu "Gói dịch vụ" được thay bằng liên kết trực tiếp tới "Phụ phí hệ thống" (`/admin/service-rates`) — vì trước đây trang này chỉ vào được gián tiếp qua trang Gói dịch vụ.
- `Admin/Bookings/Show.vue`: gỡ liên kết "Gói dịch vụ", gỡ `PackagePanel`/`SpecialRequestPanel`, gỡ tab "Yêu cầu" (`special_requests`) và badge số lượng chờ xử lý — toàn bộ chức năng này đã được thay thế bởi màn hình "Dịch vụ & Yêu cầu" (link ở đầu trang chi tiết booking).
- `Admin/Booking/BookingController.php`: `detailTabs()` chỉ còn trả về 4 tab cố định (`info`, `room_map`, `payments`, `history`).

### Test
Xóa 7 file test gắn với chức năng đã gỡ: `PackageEnrollmentControllerTest`, `PackageEnrollmentDynamicCatalogTest`, `BookingPackageEnrollmentTest`, `ServicePackageAdminTest`, `ServicePackageRateAdminTest`, `SpecialRequestUiTest`, `SpecialRequestCrudTest`.
Cập nhật `BookingManagementUiTest`: bỏ entry `special_requests` khỏi assertion mảng `tabs`.

## 4. Chức năng chủ động GIỮ LẠI (và lý do)

- **"Phụ phí hệ thống" (`/admin/service-rates`, `ServiceRate`)**: quản lý 13 `ChargeType`, chỉ 3 loại (giường phụ, ăn sáng, người thêm) đã được hợp nhất — 10 loại còn lại (thuế du lịch, trả phòng muộn, nhận phòng sớm...) **chưa** có trong catalog mới. Xóa toàn bộ trang này sẽ làm mất khả năng quản trị các loại phí chưa hợp nhất. Đúng theo câu chữ yêu cầu của người dùng ("đã được hợp nhất") nên trang này được giữ nguyên, chỉ đổi lối vào menu.
- **Model/Service/Posting Job cũ** (`ServicePackage`, `ServicePackageRate`, `BookingSpecialRequest`, `ExtraBedPostingJob`, `ExtraPersonPostingJob`, `BreakfastPostingJob`, `ServicePackagePostingJob`, `ServicePackagePolicy`, `BookingSpecialRequestPolicy`...): vẫn đăng ký trong `NightAuditService`/`AppServiceProvider`. Không gỡ vì:
  1. Không còn route/UI nào tạo enrollment mới → vô hại về mặt nghiệp vụ.
  2. Nhiều test tích hợp hiện có (Night Audit, RoomSwapService relink...) vẫn phụ thuộc các model/job này.
  3. Gỡ bỏ chỉ để "cho gọn" sẽ tạo rủi ro hồi quy không cần thiết, không nằm trong phạm vi yêu cầu.

## 5. Kiểm tra hồi quy

Chạy lại toàn bộ bộ test (`php artisan test`) sau khi hoàn tất xóa + sửa. Kết quả: **29 failed / 1316 passed** (4921 assertions) — khớp chính xác với baseline lỗi có sẵn từ trước (không liên quan đến thay đổi lần này):

| Nhóm test | Số lỗi | Ghi chú |
|---|---|---|
| `RoomAvailabilityCheckerTest` | 13 | Lỗi có sẵn trước phiên làm việc này |
| `BookingManagementUiTest` | 11 | Lỗi có sẵn (không còn lỗi "admin can view booking detail" — đã fix bằng cập nhật assertion tabs) |
| `DashboardTest` | 3 | Lỗi có sẵn (`ValidationException`) |
| `ReleaseBatchSchemaTest` | 1 | Lỗi có sẵn |
| `LateCheckoutFeeTest` | 1 | Flaky test phụ thuộc giờ đồng hồ thực (`now()`), không liên quan đến thay đổi lần này — đã xác minh `StayService::checkOut()` không phụ thuộc bất kỳ thứ gì bị xóa |

→ **Không phát sinh lỗi mới nào** do việc gỡ bỏ chức năng cũ. `npm run build` cũng sạch (2385 module, giảm đúng 9 module tương ứng 9 file Vue đã xóa).

## 6. Kết luận

- Dữ liệu local đã được di dời an toàn sang hệ thống mới (không double-billing).
- "Gói dịch vụ" và "Yêu cầu Booking cũ" đã được gỡ bỏ hoàn toàn khỏi route/controller/UI/test.
- "Phụ phí hệ thống" được giữ lại có chủ đích vì còn quản lý các loại phí chưa hợp nhất.
- Model/service/job nền tảng cũ được giữ nguyên (vô hại, vẫn được tham chiếu bởi test/luồng khác).
- Chưa commit/push lên remote — đợi xác nhận từ người dùng sau khi test thủ công trên local.
