# Final Room Map Implementation Report

**Ngày:** 2026-08-17 · **Nhánh:** `phase-3` · 5 commit trên `phase-3` (chưa push) + 1 commit bổ sung "true shared component" (xem mục cuối)

## Files Changed

### Commit `be8488d` — Sơ đồ thao tác (Room Operations Board)
- `app/Services/RoomOperationsBoardService.php`: thêm `booking_color` vào `occupant` payload; historical occupancy resolver (`eligibleAssignmentStatuses()`) dùng `BusinessDateService` để phân biệt view quá khứ (gồm CheckedOut) vs view hiện tại/tương lai (chỉ Assigned/CheckedIn); constructor thêm `BusinessDateService`.
- `resources/js/Pages/Admin/RoomOperations/Partials/RoomOperationsCell.vue`: nền chính = `booking_color` (thay 5-state tint cũ) khi có occupant; auto-contrast qua `colorContrast.js`; icon row có nền `bg-white/75` (status strip); nút "chọn tất cả phòng của booking này".
- `resources/js/Pages/Admin/RoomOperations/Partials/RoomOperationsBoard.vue`: forward event `select-booking-rooms`.
- `resources/js/Pages/Admin/RoomOperations/Index.vue`: `selectBookingRooms()`, `selectAllVisible()`, nút "Chọn tất cả đang hiển thị".
- Test mới: `tests/Feature/RoomOperationsHistoricalOccupancyTest.php` (3 test).

### Commit `3c9d02f` — Sơ đồ chọn phòng (Booking Room Selection)
- `resources/js/Pages/Admin/Bookings/Show.vue`: xóa hover-popup (`group-hover:block`, ~70 dòng), thay bằng `roomTooltip()` (native `title` attribute) — không chặn tap trên mobile, không có phần tử tương tác lồng bên trong. Click-to-detail cho phòng conflict/current_booking giữ nguyên (đã đúng từ trước). Xóa `anyModalOpen` (dead code) và import `Eye` không dùng.
- Không đổi backend (payload đã đủ).

### Commit `d9c1c9d` — Sơ đồ Check phòng (Kiểm tra phòng)
- `resources/js/Pages/Admin/RoomAvailability/Index.vue`: khi đúng 1 booking chiếm phòng (reserved/occupied/overstay + `booking_count === 1`), nền chính = `primary_color` (đã có sẵn ở backend), border-only cho trạng thái ngữ nghĩa, auto-contrast text. `multi_booking`/`overlap`/`available`/`out_of_order`/`cleaning` giữ nguyên full-tile cũ (không có 1 booking duy nhất để tô).
- Không đổi backend.

### Commit `e82cccd` — Room Charge Multi-Rate Group (mục 20-22/37)
- `app/Services/Posting/RoomChargePostingJob.php`: `resolveUnitPrice()` ưu tiên `stay->roomAssignment->bookingRequirement->room_price` (link đã có sẵn), fallback về `firstWhere('room_type_id', ...)` chỉ cho assignment cũ chưa có link.
- `app/Services/PaymentProjectionService.php`: mirror y hệt (`resolveUnitPrice(Booking, Stay)`), cập nhật eager-load `stays.roomAssignment.bookingRequirement`.
- Test mới: `tests/Feature/RoomChargeMultiRateGroupTest.php` (3 test — đúng kịch bản mục 37: Twin Group A 650k / Group B 300k).

## Schema

**Không có migration mới.** Toàn bộ hạ tầng cần thiết (`booking_requirements` không unique theo room_type, `room_assignments.booking_requirement_id`) đã tồn tại từ phase trước — chỉ sửa logic ĐỌC dữ liệu, không đổi cấu trúc bảng.

## API Changes

Không có route mới/đổi. Payload bổ sung field `booking_color` trong `RoomOperationsBoardService::buildRoomCell()`'s `occupant` — additive, không phá vỡ consumer cũ nào (frontend duy nhất tiêu thụ field này đã được cập nhật trong cùng commit).

## Legacy Behavior Retained

- Toàn bộ luồng nghiệp vụ Kiểm đồ trả phòng (`CheckoutInspectionService`) — không đổi.
- `ProductService`/`/admin/product-services`, `/admin/service-rates` — giữ nguyên như quyết định đã có từ Prompt_1 decommission (quản lý phần chưa hợp nhất).
- Toàn bộ Unpaid Checkout architecture (`BookingService::finaliseBookingCheckout`, `BookingPaymentService`) — không đổi.
- 5-state `status_theme` (unavailable/vacant_clean/vacant_dirty/assigned/checked_in) vẫn được backend trả về và dùng cho phòng TRỐNG (không có booking) — chỉ ngừng dùng làm nền cho phòng CÓ booking.

## Migrations

Không có.

## Tests

| Test file | Số test | Kết quả |
|---|---|---|
| `RoomOperationsHistoricalOccupancyTest.php` (mới) | 3 | PASS |
| `RoomChargeMultiRateGroupTest.php` (mới) | 3 | PASS |
| Toàn bộ `RoomOperations*` | 66 (63 cũ + 3 mới) | PASS |
| `RoomCharge*`/`PaymentProjection*`/`NightAudit*`/`RoomAssignment*` | 165 | PASS |
| `BookingManagementUiTest` | 157 (11 lỗi = baseline cũ) | không hồi quy mới |
| `RoomAvailabilityCheckerTest` | 26 (13 lỗi = baseline cũ) | không hồi quy mới |

Xem `final-room-map-regression-review.md` cho số liệu đầy đủ trước/sau toàn bộ suite.

## Commit bổ sung — True Shared Component (sau khi user xác nhận yêu cầu "quy về MỘT cách hiển thị", không chỉ cùng nguyên tắc màu)

Phát hiện qua câu hỏi trực tiếp của người dùng: 3/4 màn hình ở trên áp dụng ĐÚNG nguyên tắc màu (mục 7) nhưng qua 3 component thẻ RIÊNG BIỆT (kích thước/layout khác nhau), không phải MỘT component dùng chung thật sự. Đã sửa:

- **`resources/js/Components/RoomBoard/RoomTile.vue`** (MỚI) — shell dùng chung: nền = `booking_color` khi có, auto-contrast text (`colorContrast.js`), viền chọn/xung đột (ring, không dùng nền), banner conflict. Toàn bộ nội dung nghiệp vụ (checkbox, thân thẻ, dải trạng thái, footer) là slot — không ép các màn khác nhau về cùng một layout nghiệp vụ, chỉ ép về cùng một VỎ hiển thị (đúng dòng cuối mục 6).
- **`resources/js/Components/RoomBoard/RoomFloorGrid.vue`** (MỚI) — layout tầng/scroll ngang dùng chung, trích xuất y hệt từ Sơ đồ thao tác (màn tham chiếu). Khác `RoomBoardGrid.vue` cũ (wrapping-grid, vẫn dùng riêng cho Housekeeping/Rooms — không đổi).
- **`RoomOperationsCell.vue`/`RoomOperationsBoard.vue`** (Sơ đồ thao tác — màn tham chiếu): viết lại để dùng `RoomTile`/`RoomFloorGrid` thay vì tự vẽ; toàn bộ logic nghiệp vụ (housekeeping/occupancy/ghép giường/giường phụ/kiểm đồ/quick-note/pending-requests) giữ nguyên, chỉ chuyển vào slot.
- **`Bookings/Show.vue`** (Sơ đồ chọn phòng): thay lưới thẻ tự vẽ (`h-16 w-20`, khác kích thước hẳn) bằng `RoomFloorGrid`/`RoomTile` — cùng kích thước/layout với Sơ đồ thao tác. Không dùng `conflict` prop cho trạng thái "phòng thuộc booking khác" (khác bản chất với xung đột dữ liệu ở Sơ đồ thao tác — đây chỉ là thông tin, không phải cảnh báo).
- **`RoomAvailability/Index.vue`** (Sơ đồ Check phòng): thay nút tự vẽ bằng `RoomFloorGrid`/`RoomTile`; giữ nguyên toàn bộ logic nghiệp vụ (`hasSingleBookingColor`, color-hint bar cho multi_booking/overlap, badge yêu cầu đặc biệt).
- **`app/Http/Controllers/Admin/CheckoutInspectionController.php`** + **`CheckoutInspections/Index.vue`** (Sơ đồ Kiểm đồ — màn DUY NHẤT chưa từng được sửa ở 5 commit trước): thêm `booking_color` vào payload (`mapStay()`, eager-load `booking:id,booking_code,customer_name,booking_color,status`); chuyển từ `RoomBoardGrid.vue` (layout khác — wrapping grid) sang `RoomFloorGrid`/`RoomTile`. Trạng thái kiểm đồ (draft/completed/none) chuyển vào badge trong dải trạng thái (status-row), không còn chiếm nền/viền thẻ — đúng nguyên tắc mục 9 áp dụng nhất quán cho cả 4 màn.

**Kết quả:** cả 4 màn hình giờ dùng chung MỘT component vỏ (`RoomTile`) và MỘT component layout (`RoomFloorGrid`) — cùng kích thước thẻ, cùng vị trí số phòng/loại phòng/checkbox/dải trạng thái/footer, cùng quy tắc màu nền/viền. Khác biệt duy nhất giữa 4 màn là NỘI DUNG nghiệp vụ bên trong slot (đúng bản chất từng màn), không còn khác biệt về KIẾN TRÚC HIỂN THỊ.

## Phạm vi CHƯA hoàn thành (minh bạch, không tính là COMPLETE)

- Service Icon admin-configurable (mục 14) — cần cột DB + UI admin + render trên tile.
- Historical Room Reassignment reconstruction từ `StayEvent` (mục 24).
- Night Audit historical catch-up rewrite (mục 27) — ngoài phạm vi theo chỉ dẫn, chỉ ghi nhận.
- Folio traceability qua FK sạch thay vì `posting_key` string (mục 3).

Xem checklist đầy đủ YES/NO tại `lastella-final-feature-completion-report.md`.
