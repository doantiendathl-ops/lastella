# Final Room Map Architecture Review

**Ngày:** 2026-08-17 · **Nhánh:** `phase-3` · **Nguồn:** `docs/yeucaumoi.txt` ("FINAL COMPLETION RUN")

## 1. Baseline tại thời điểm bắt đầu

`HEAD` = `ee0d1aa` (commit cuối của Unpaid Checkout, đã COMPLETE). `git status` sạch ngoài các file không liên quan có sẵn từ đầu phiên (`.gitignore`, `bootstrap/app.php`, `resources/js/app.js`, các `docs/*` chưa track). `php artisan test` baseline: **28 failed / 1344 passed (5156 assertions)** — 28 lỗi này đã biết từ trước (không liên quan công việc phiên này), lặp lại xuyên suốt các lượt trước.

## 2. 4 hiện trạng Room Map — kiến trúc TRƯỚC khi sửa

| Màn hình | Backend | Frontend | Ghi chú |
|---|---|---|---|
| Sơ đồ thao tác | `RoomOperationsBoardService::boardForDate()` | `RoomOperationsCell.vue` (tự viết, không dùng grid dùng chung) | Đã có multi-select, filter, quick-note. Full-tile màu theo `status_theme` (5 trạng thái ngữ nghĩa: unavailable/vacant_clean/vacant_dirty/assigned/checked_in) — **không dùng `booking_color`**, một quyết định thiết kế có chủ đích từ phase trước ("No second color palette"). |
| Sơ đồ chọn phòng (Đặt phòng) | `RoomAssignmentService::getRoomBoard()` | Grid nhúng trực tiếp trong `Bookings/Show.vue` | Đã dùng `booking_color` cho trạng thái "đã chọn" (100% opacity) và "current_booking" (15% tint). Có **hover popup** (`group-hover:block`) hiển thị chi tiết phòng — đúng loại "legacy popup" mục 17 muốn bỏ, có nguy cơ chặn tap trên mobile. |
| Sơ đồ Check phòng (Kiểm tra phòng) | `RoomAvailabilityCheckerService::check()` | `RoomAvailability/Index.vue` (tự viết) | Truy vấn theo KHOẢNG NGÀY (không phải 1 thời điểm) — có thể có nhiều booking/phòng trong khoảng, khác bản chất với 3 màn còn lại. Chỉ dùng `primary_color` làm 1 gạch màu mỏng dưới đáy thẻ, không phải nền chính. Không có hover popup (click-to-detail đã đúng từ trước). |
| Sơ đồ Kiểm đồ trả phòng | `CheckoutInspectionController` | `CheckoutInspections/Index.vue`, dùng `RoomBoardGrid.vue` (component dùng chung có sẵn) | Thẻ được vẽ theo TRẠNG THÁI KIỂM ĐỒ (draft/completed/none), không liên quan `booking_color`. Payload hiện chưa có `booking_color`. |

**Phát hiện quan trọng:** đã tồn tại sẵn `resources/js/Components/RoomBoard/RoomBoardGrid.vue` — một component CHIA SẺ nhưng chỉ lo phần layout (floor grouping + grid responsive), KHÔNG có visual language (màu nền/status strip/border chọn/conflict). 3/4 màn hình (Housekeeping, Rooms, Kiểm đồ) đã dùng nó cho layout; Sơ đồ thao tác/Booking Selection/Kiểm tra phòng thì không (mỗi màn tự vẽ grid riêng).

## 3. Shared Architecture — quyết định

Theo đúng mục 6's dòng cuối ("Không ép mọi màn hình giống nghiệp vụ"), **không gộp các backend service khác biệt bản chất làm một** (chúng trả lời câu hỏi khác nhau: "phòng nào trống cho booking NÀY" vs "hôm nay đang có gì" vs "khoảng ngày này ai đang ở"). Thay vào đó, chuẩn hóa Ở TẦNG HIỂN THỊ:

- **`booking_color` là nguồn nền chính** khi phòng thuộc về đúng MỘT booking xác định tại thời điểm/khoảng đang xem — tái dùng `App\Services\BookingColorService`/`colorContrast.js` đã có, không tạo palette thứ hai.
- **Trạng thái vận hành** (assigned/checked-in/vacant clean-dirty) chuyển thành dải nhỏ (status strip/border), không còn chiếm nền chính.
- **Selected/Conflict** → viền (ring), không dùng nền — đã đúng từ trước ở Sơ đồ thao tác và Booking Selection, giữ nguyên.
- Không migrate BẮT BUỘC tất cả 4 màn sang `RoomBoardGrid.vue` — layout wrapper không phải trọng tâm mục 7-9; chỉ migrate phần NỘI DUNG THẺ (visual language) từng màn theo đúng bản chất nghiệp vụ của nó.

## 4. Phát hiện quan trọng ngoài dự kiến — Room Charge / Multi-Rate Group

Khi audit mục 20-23, phát hiện kiến trúc CẦN THIẾT đã **tồn tại sẵn từ phase trước** ("Room Demand/Room Board Unification"):

- `booking_requirements` KHÔNG có unique constraint trên `(booking_id, room_type_id)` — nhiều dòng nhu cầu cùng loại phòng, giá khác nhau đã luôn khả thi ở tầng schema.
- `room_assignments.booking_requirement_id` (FK, `restrictOnDelete`) đã liên kết CHÍNH XÁC assignment với dòng nhu cầu nó thực sự lấp đầy.
- `RoomAssignmentController::store()` → `assignRoomsWithRequirementLink()` đã YÊU CẦU chọn `target_requirement_id` tường minh khi 1 room_type có ≥2 dòng nhu cầu hợp lệ (validation lỗi rõ ràng nếu thiếu), tự động resolve khi chỉ có 1 dòng. Frontend (`Show.vue:1447`) đã có `<select>` cho việc này.

**Gap thật duy nhất tìm được:** `RoomChargePostingJob::resolveUnitPrice()` và bản mirror của nó `PaymentProjectionService::resolveUnitPrice()` **không đọc** `booking_requirement_id` — chỉ `firstWhere('room_type_id', ...)`, khiến MỌI assignment cùng room_type bị tính theo giá của dòng nhu cầu ĐẦU TIÊN, bất kể đã được phân vào dòng nào. Đây là lỗi tài chính thật (overcharge/undercharge), có thể xảy ra qua UI đã public từ trước — đã sửa (xem implementation-report).

## 5. Phạm vi chủ động KHÔNG làm trong lượt này (và lý do)

- **Kiểm đồ trả phòng (mục 18)**: không migrate sang `booking_color` — bản chất màn hình là trạng thái KIỂM ĐỒ (draft/completed/none), không phải "ai đang ở phòng nào"; đúng cảnh báo của chính mục 18 ("KHÔNG rewrite nghiệp vụ kiểm phòng ổn định"). Cần backend bổ sung `booking_color` vào payload nếu làm sau.
- **Service Icon admin-configurable (mục 14)**: cần cột DB mới trên `services` + UI admin + render trên Room Tile — CHƯA làm, việc còn lại.
- **Historical Room Reassignment reconstruction (mục 24)**: `RoomAssignment` hiện tại lưu 1 room_id CUỐI CÙNG cho toàn bộ interval — đổi phòng giữa chừng (Change Room) không để lại vệt lịch sử phòng-cũ/phòng-mới riêng biệt trong chính bảng này. Dữ liệu additive AN TOÀN đã tồn tại (`StayEvent(RoomMove)` ghi room cũ/mới/thời điểm) nhưng CHƯA được dùng để tái dựng interval lịch sử cho Room Map — việc còn lại, rủi ro thấp (audit trail, không phải tài chính) nhưng cần thời gian riêng để làm đúng.
- **Historical Night Audit catch-up (mục 27)**: đã biết từ các phase trước — Night Audit chỉ chạy thủ công trên production, chưa có lịch tự động. KHÔNG rewrite Night Audit trong lượt này theo đúng chỉ dẫn ("ghi nhận nhưng không rewrite Night Audit mù"). Bản thân occupancy resolver mới của Sơ đồ thao tác ĐÃ dùng chung `BusinessDateService` với Night Audit (nhất quán "today"), nhưng catch-up logic của chính Night Audit chưa được đại tu.
- **Folio traceability sạch (mục 3, phần cuối)**: hiện truy vết Service qua `posting_key` (string) chứ chưa có FK trực tiếp `FolioEntry.booking_service_id` — vẫn "biết được", nhưng chưa phải join sạch. Việc cải thiện (thêm cột + backfill) chưa thực hiện, rủi ro thấp.
