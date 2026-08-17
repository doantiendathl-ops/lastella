# Booking Color Picker — Excel-style Redesign + Overlap-based Allocation

**Ngày:** 2026-08-17
**Nhánh:** `phase-3`
**Nguồn yêu cầu:** `docs/Prompt_1.txt` (thay thế mục V — Booking Color Picker giống Excel; bổ sung mục VI — cơ chế phân bổ màu Booking)

## 1. Audit hiện trạng (trước khi sửa)

- `booking_color` lưu **giá trị HEX trực tiếp** trên `bookings` (không phải palette index/ordinal) — đã đúng yêu cầu mục V.6 từ trước, không cần migrate dữ liệu.
- `booking_color` là **required** + regex `^#[0-9A-Fa-f]{6}$` ở cả Store/Update — xác nhận business rule hiện tại: Booking luôn phải có màu. → Không thêm "Không tô màu / No Fill" (đúng mục V.1.C, phương án backward-compatible).
- Cơ chế gợi ý màu cũ (`BookingController::options()`) loại trừ màu đang dùng theo **status toàn cục** (mọi booking chưa Cancelled/NoShow/CheckedOut, bất kể có trùng thời gian hay không) — đúng loại "global lock" mà mục VI cấm.
- Không có validate nào chặn việc 2 booking trùng thời gian chọn cùng màu khi lưu (chỉ có gợi ý ở phía tạo mới).
- So sánh màu dùng `in_array(..., true)` — case-sensitive, không normalize.
- Bug thực tế tìm thấy: `Admin/Bookings/Show.vue` — khi 1 phòng trong sơ đồ được chọn, nền ô phòng đổi thành `booking_color` (full-opacity) nhưng chữ/dot bị **hard-code `text-white`/`bg-white`**, sẽ mất chữ khi người dùng chọn màu sáng/trắng. Đây là điểm áp dụng thực tế duy nhất của mục V.4 (Room Tile) trong codebase — Sơ đồ thao tác (Room Operations Board) dùng bảng màu trạng thái riêng, không liên quan `booking_color`.

## 2. Kiến trúc mới

### `App\Services\BookingColorService` (nguồn canonical duy nhất)
- `themeGroups()` — 10 Theme Colors (Trắng, Đen, Xám, Navy, Xanh dương, Đỏ, Xanh lá, Tím, Cyan, Cam), mỗi màu có 5 shade sáng/tối tính bằng công thức tint/shade (không hard-code từng giá trị Excel).
- `standardColors()` — 10 Standard Colors (giống bảng Excel tham khảo).
- `flatPalette()` — gộp + normalize + dedupe, dùng cho auto-allocation.
- `overlappingColors()` / `hasConflict()` / `suggestColor()` — chỉ xét booking có khoảng `[checkin_at, checkout_at)` **giao nhau thực sự**; loại Cancelled/NoShow (chưa từng chiếm phòng); CheckedOut vẫn được tính nếu khoảng thời gian thật sự giao nhau (không có cutoff riêng theo status — không tồn tại global lock).
- `normalize()`/`sameColor()` — so sánh case-insensitive, không so theo tên/label.

### `Booking` model
- Thêm `Attribute` mutator: normalize `booking_color` thành chữ hoa **chỉ khi ghi**. Dữ liệu cũ (có thể chữ thường) giữ nguyên khi đọc — không âm thầm sửa lịch sử; mọi so sánh đều qua `BookingColorService::normalize()` nên vẫn đúng.

### Validation (server-side hard gate)
- `ValidatesBookingColorOverlap` (trait dùng chung Store/Update): sau khi field-rules pass, kiểm tra `hasConflict()`; nếu có → lỗi trên field `booking_color`.
- **Update** bỏ qua kiểm tra chỉ khi **cả màu lẫn khoảng thời gian đều không đổi** so với giá trị đã lưu (không chỉ riêng màu) — tránh việc một conflict màu tồn tại từ trước (grandfathered) chặn mọi chỉnh sửa không liên quan trong tương lai, đồng thời đảm bảo việc **đổi ngày** (dù không đổi màu) vẫn được tái kiểm tra vì bản thân việc đổi ngày có thể tạo ra overlap mới. *(Phát hiện + sửa sau vòng review — xem mục 4.)*

### `BookingController`
- `options()` trả về `booking_color_theme_groups`, `booking_color_standard_colors`, `recommended_booking_color` (1 màu gợi ý), `used_booking_colors` (danh sách xung đột) — thay cho `recommended_booking_colors` (mảng) cũ.
- `colorSuggestionRange()`: ưu tiên `checkin_at`/`checkout_at` trên query string (form đang nhập dở) > ngày đã lưu của booking (khi sửa) > cửa sổ mặc định (khi tạo mới, chưa có query).

### Frontend
- `resources/js/Support/colorContrast.js` — luminance WCAG, `readableTextColor/Class/SurfaceClass`.
- `resources/js/Pages/Admin/Bookings/Partials/ExcelColorPicker.vue` — Theme Colors (hàng + shade dọc), Standard Colors, "Màu khác..." (hex input + native picker), selection indicator (checkmark + viền), swatch đang bị trùng lịch bị **disable + tooltip** (ưu tiên ngăn lựa chọn theo mục VI).
- `Form.vue`: dùng `ExcelColorPicker`; thêm partial-reload (Inertia, debounce 400ms) cho `options` mỗi khi ngày nhận/trả phòng đổi, để gợi ý/màu-đang-trùng luôn phản ánh đúng khoảng thời gian đang nhập (kể cả ở form Sửa).
- `Show.vue`: sửa bug hard-code `text-white`/`bg-white` → tính từ `booking_color` qua `colorContrast.js`.

## 3. Vòng review & phát hiện quan trọng

Dispatch `code-reviewer` subagent trước khi commit. Kết quả: **1 HIGH** — logic "bỏ qua kiểm tra nếu màu không đổi" ban đầu chỉ so màu, không so ngày → có thể bị lách: sửa `checkout_at` để tạo overlap mới với booking khác cùng màu mà không đổi field màu, validator không chạy lại `hasConflict()`. Đã xác minh có thể tái hiện qua PUT thực tế, không phải lý thuyết.

**Đã sửa:** điều kiện bỏ qua giờ yêu cầu **cả màu và khoảng ngày đều giữ nguyên** so với giá trị đã lưu; thêm test hồi quy `test_extending_dates_into_a_new_overlap_is_rejected_even_without_changing_color`.

**Tác dụng phụ phát hiện khi sửa:** hàng loạt test cũ trong `BookingManagementUiTest` (test "time conflict"/"time change" — kiểm tra xung đột PHÒNG, không liên quan màu) dùng chung 1 màu mặc định `#196251` cho mọi booking fixture; khi validator giờ tái kiểm tra mỗi lần đổi ngày, các fixture này vô tình vi phạm rule màu mới (2 booking cùng màu mặc định, trùng thời gian do chủ đích test phòng). **Sửa tận gốc:** đổi `bookingPayload()` helper của riêng file test này sang sinh màu mặc định **duy nhất tăng dần** mỗi lần gọi (thay vì hằng số cố định) — tách các fixture không liên quan màu khỏi rule mới, trong khi các test cố ý kiểm tra rule màu vẫn override `booking_color` tường minh và không bị ảnh hưởng.

## 4. Test

- **Mới:** `tests/Unit/Services/BookingColorServiceTest.php` (10 test — normalize, so sánh case-insensitive, palette ổn định 10 nhóm × 5 shade, overlap/loại trừ Cancelled-NoShow, CheckedOut vẫn tính khi giao nhau, tự loại trừ chính nó, gợi ý bỏ qua màu đã dùng).
- **Cập nhật/mới trong `BookingManagementUiTest`:** thay 4 test dựa trên `recommended_booking_colors` (mảng, exclude toàn cục) bằng test đúng ngữ nghĩa mới (`used_booking_colors`/`recommended_booking_color`, overlap-based); thêm test màu bị từ chối khi trùng lịch (tạo mới), test đổi ngày tạo overlap mới vẫn bị chặn dù không đổi màu.
- **Hồi quy toàn bộ:** `php artisan test` → 30 failed / 1328 passed, khớp chính xác baseline có sẵn (RoomAvailabilityCheckerTest 13, BookingManagementUiTest 11 phòng/room-board không liên quan màu, DashboardTest 3, ReleaseBatchSchemaTest 1, LateCheckoutFeeTest 1 flaky giờ đồng hồ, PerStayAttributionTest 1 flaky unique-constraint) — **0 lỗi mới** phát sinh từ tính năng này.
- `npm run build`: sạch, 2387 module (tăng đúng 2 so với trước — `ExcelColorPicker.vue` + `colorContrast.js`).

## 5. Phạm vi chủ động không làm

- Không xây accessibility/high-contrast infrastructure mới (mục V.2) — hệ thống hiện chưa có, đúng hướng dẫn "không bắt buộc nếu chưa có sẵn".
- Không đổi kiến trúc Sơ đồ thao tác (Room Operations Board) sang dùng `booking_color` làm nền — board này cố ý dùng bảng màu trạng thái riêng (quyết định từ phase trước), nằm ngoài phạm vi "thay thế Color Picker".
- Không backfill/normalize chữ hoa cho các `booking_color` cũ đã lưu (chữ thường) trong DB — chỉ chuẩn hóa khi ghi mới, đúng nguyên tắc không âm thầm sửa lịch sử.
