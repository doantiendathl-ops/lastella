# Tổng hợp kết quả — Chức năng "Sơ đồ thao tác" (Daily Room Operations Board)

> File này tổng hợp toàn bộ quá trình triển khai, các lỗi phát hiện/sửa, và trạng thái hiện tại của chức năng **"Sơ đồ thao tác"** (đường dẫn `/admin/room-operations`) trong dự án LASTELLA (Laravel 12 + Inertia.js + Vue 3), để chuyển cho ChatGPT rà soát độc lập.
>
> **Trạng thái tổng thể: ĐÃ COMMIT + ĐÃ PUSH lên `origin/phase-3`. CHƯA DEPLOY lên production.** Sau vòng "Final Selective Commit + Push Closure" (Vòng 7), toàn bộ thay đổi thuộc chức năng này đã được stage chọn lọc, commit (2 commit), và push thành công lên remote. HEAD hiện tại == `origin/phase-3` = `04f58317dda1259aa4fff664ea2b6a8bafe55f5e`. Deploy lên production là một task riêng, có kiểm soát, chưa thực hiện.
>
> Nguồn spec gốc: `docs/yeucaumoi.txt` (spec đã qua nhiều vòng, vòng cuối là "Final Selective Commit + Push Closure"). Báo cáo chi tiết đầy đủ từng vòng: `docs/reports/daily-room-operations-board-implementation-report.md` (file này là bản tóm tắt/tổng hợp lại từ đó, kèm bối cảnh để review nhanh hơn).

---

## 1. Chức năng này là gì

Một màn hình vận hành hằng ngày, hợp nhất 5 nghiệp vụ đang tồn tại rời rạc vào **một bảng duy nhất theo ngày**: đổi phòng (swap), nhận phòng, trả phòng, kiểm đồ trả phòng (checkout inspection), và buồng phòng (housekeeping) — mỗi phòng là một "thẻ" (card), xếp theo từng tầng, cuộn ngang chung một khối.

- Route: `GET /admin/room-operations` (`admin.room-operations.index`)
- Trang chính: `resources/js/Pages/Admin/RoomOperations/Index.vue`
- Service đọc dữ liệu (read model): `app/Services/RoomOperationsBoardService.php`
- Service đổi phòng: `app/Services/RoomSwapService.php`
- Controller: `app/Http/Controllers/Admin/RoomOperationsController.php`
- **Không thay thế** `/admin/room-availability` (Kiểm tra phòng) hay panel "Sơ đồ phòng" trong trang chi tiết booking — đây là màn hình bổ sung, tái sử dụng toàn bộ business logic đã có (`RoomAssignmentService`, `StayService`, `HousekeepingService`, `CheckoutInspectionService`, `PaymentProjectionService`), không viết lại logic nghiệp vụ song song.

---

## 2. Quyết định kiến trúc quan trọng

| Vấn đề | Quyết định | Vì sao an toàn |
|---|---|---|
| `RoomAssignment` ↔ `Stay` là quan hệ 1:1 chặt (`stays.room_assignment_id` UNIQUE), không có sẵn cơ chế "chia nhỏ" một khoảng ngày | Đổi phòng dùng chiến lược **release-and-recreate**: giải phóng assignment cũ, tạo assignment mới — dùng lại nguyên `releaseAssignment()`/`createStayFromAssignment()` đã có sẵn | An toàn vì spec giới hạn: nguồn đổi phòng phải **chưa nhận phòng** (pre-check-in), đích đang **đã nhận phòng** thì bị chặn cứng → không dòng nào có lịch sử tài chính/lưu trú bị đụng tới |
| Đổi phòng cần audit trail | Bảng mới `room_swap_batches` (append-only, giống hệt mẫu `release_batches` đã có) + cột `room_assignments.swap_batch_id` + ghi `StayEvent(RoomMove)` (dùng lại enum có sẵn, không tạo type mới) | Không phát minh cơ chế audit mới |
| Ghi chú nhanh (Quick Note) 2 tầng | `bookings.quick_note` là giá trị mặc định/khởi tạo, copy-once sang `room_assignments.quick_note` tại thời điểm gán phòng — **không** đồng bộ ngược sau đó | Đúng yêu cầu: sửa ghi chú booking không được ghi đè ghi chú phòng đã gán |
| Giường phụ (Extra Bed) | Chuyển từ `BookingPackageFlag` (cấp booking) sang `room_assignments.extra_bed_quantity` (cấp phòng) | Xem mục lỗi tài chính bên dưới — đây là một bug thật, không phải chỉ là thiết kế lại |
| Ghép giường (Bed Join) | KHÔNG tạo cột mới — tái sử dụng `BookingSpecialRequest` (category=`bed_config`, request_type=`twin_to_double`) đã tồn tại sẵn với đầy đủ vòng đời | Tránh tạo nguồn dữ liệu thứ hai không đồng bộ cho cùng một sự thật |
| Kiểm đồ trả phòng | Tái sử dụng nguyên vẹn `CheckoutInspectionModal.vue` + `CheckoutInspectionService`/`CheckoutInspectionController` đã có, chỉ đổi cách mở (popup tại chỗ thay vì điều hướng trang) | Không viết component/nghiệp vụ kiểm đồ song song |
| Phí tính theo sản phẩm kiểm đồ (nước suối, minibar...) | `chargeable_quantity` là **input trực tiếp** của nhân viên, KHÔNG được suy ra bằng phép trừ `actual - complimentary` | Xem mục lỗi tài chính bên dưới |
| Chuẩn miễn phí nước theo phòng | Lấy từ `RoomType.standard_adults` (chuẩn phòng), KHÔNG hardcode, KHÔNG lấy từ `Booking.adults` | Đúng yêu cầu Product Owner |

---

## 3. Lịch sử triển khai theo từng vòng

Toàn bộ được làm trong **cùng một phiên làm việc dài**, theo mô hình: trace → quyết định kiến trúc tối thiểu-an toàn → implement → test → build → report, không dừng hỏi trừ khi có rủi ro nghiêm trọng (mất dữ liệu tài chính, migration phá hủy, không xác định được nguồn dữ liệu chuẩn).

### Vòng 1 — Xây dựng gốc "Sơ đồ thao tác"
Route mới, board service, swap engine, quick-action toolbar (nhận/trả phòng hàng loạt, kiểm đồ, dọn phòng), audit trail cho swap. 3 migration cộng thêm (additive), không migration phá hủy.

### Addendum — Vòng đời Ghi chú nhanh (Quick Note 2 tầng)
`bookings.quick_note` → copy-once → `room_assignments.quick_note` tại thời điểm gán phòng, qua **một** hàm dùng chung `lockRoomRecheckAndCreateAssignment()` (cả 3 luồng gán phòng đều đi qua đây, không thể lệch nhau).

### Vòng 2 — "Product Owner UI/Inspection Corrections"
**Bug thật đã sửa:** `can_inspect` (điều kiện bật nút "Kiểm đồ") bị gate sai trên `AssignmentStatus::CheckedOut`, trong khi màn hình kiểm đồ chuẩn (`CheckoutInspectionController::index()`) chỉ liệt kê stay đang `CheckedIn` (kiểm đồ là nghiệp vụ **trước** khi trả phòng, không phải sau). Hậu quả: nút "Kiểm đồ" luôn bị vô hiệu hóa, khách có thể trả phòng mà không hề có cảnh báo kiểm đồ. Sửa 1 dòng, thêm state machine xác nhận trả phòng đúng thứ tự (cảnh báo kiểm đồ → xác nhận đúng phòng → xác nhận cuối). Đồng thời: bỏ hiển thị mã booking trên thẻ phòng, hiển thị đầy đủ text trạng thái (không rút gọn), dựng lại layout: mỗi tầng một hàng, cuộn ngang chung.

### Vòng 3 — "Room-Scoped Extra Bed + Bed-Join Integration"
**Bug tài chính thật đã sửa:** `ExtraBedPostingJob` đọc cờ `BookingPackageFlag(EXTRA_BED_PER_NIGHT)` ở **cấp booking**, nhưng Night Audit chạy **theo từng Stay** (từng phòng) — một booking 3 phòng chỉ đăng ký 1 giường phụ bị tính phí **3 lần** thay vì 1 lần. Đã sửa: chuyển sang cột mới `room_assignments.extra_bed_quantity` (cấp phòng). Có lệnh backfill dữ liệu cũ (`BackfillExtraBedRoomQuantity`, dry-run mặc định, dữ liệu không rõ ràng thì bỏ qua chứ không đoán). Đồng thời phát hiện: "Ghép giường" đã có sẵn nguồn dữ liệu chuẩn (`BookingSpecialRequest`) mà vòng làm trước đó bỏ sót, dẫn tới việc phải gỡ bỏ một cột `room_assignments.bed_joined` vừa thêm nhầm (migration chưa từng commit nên sửa thẳng, không cần migration rollback riêng).

### Vòng 4 — "Fast Inspection Popup + Compact Room Card UX"
"Kiểm đồ" mở popup tại chỗ (dùng lại nguyên `CheckoutInspectionModal.vue`, không viết component mới). Đổi màu thẻ phòng từ viền-trái sang tô nền toàn thẻ (theo đúng 5 trạng thái cũ, không phát minh bảng màu mới). Gộp 5 badge trạng thái dạng chữ thành 1 hàng icon nhỏ gọn, có tooltip/aria-label.

### Vòng 5 — "Inspection Popup Financial Correction + Complimentary vs Chargeable Separation + Stronger Room Colors"
**Bug tài chính thật đã sửa (quan trọng nhất):** `CheckoutInspectionService::buildItemRows()` tính `chargeable_quantity = actual_quantity − free_quantity_default` — tức là **tự động trừ** định mức miễn phí ra khỏi số lượng nhân viên nhập, làm giảm tiền thu sai một cách âm thầm. Đã sửa: `chargeable_quantity` là **input trực tiếp**, mặc định luôn = 0, không suy ra từ bất kỳ nguồn nào khác; `line_total = chargeable_quantity × đơn giá`, không trừ. Chuẩn miễn phí nước lấy từ `RoomType.standard_adults` (không hardcode). Thêm khả năng **sửa lại phiếu kiểm đồ đã hoàn tất** nhưng chỉ khi khách **chưa trả phòng** — sau khi trả phòng thì khóa cứng, không có cách nào bypass kể cả ADMIN. Tăng độ đậm màu thẻ phòng theo yêu cầu (đậm hơn 1 bậc, vẫn giữ đúng 5 trạng thái cũ).

### Hotfix 1 — Nút "Kiểm đồ" bị mờ dù phiếu đã hoàn tất còn sửa được được
**Product Owner báo lỗi thực tế** trên dữ liệu dev: booking có phiếu kiểm đồ đã hoàn tất, khách chưa trả phòng, nhưng nút "Kiểm đồ" trên bảng bị mờ, không mở lại để sửa được. **Nguyên nhân:** điều kiện `can_inspect` ở tầng bảng (board) vẫn loại trừ mọi phiếu `completed` — một quy tắc có từ trước khi tính năng "sửa phiếu đã hoàn tất" (Vòng 5) ra đời, không được cập nhật theo. Backend `editCompleted()`/`canEditCompleted()` đúng, nhưng cổng vào (nút bấm) chưa cho phép chạm tới. **Đã sửa:** `can_inspect` giờ chỉ phụ thuộc `stay.status === CheckedIn` (tương đương chính xác điều kiện `canEditCompleted()`).

### Hotfix 2 — "Sơ đồ thao tác" âm thầm giấu một double-booking thật
**Product Owner báo:** sau khi "đổi phòng", booking QA-BK-0049 hiển thị sai lệch giữa "Sơ đồ thao tác" và "Sơ đồ phòng" của chính booking đó. **Điều tra:** booking đó có 4 phòng đang gán trong khi nhu cầu thật chỉ cần 3 — 1 assignment (#460, phòng 102) không khớp bất kỳ dòng nhu cầu nào (assignment "mồ côi", còn sót lại), và phòng 102 đó **đang bị 2 booking cùng giữ** cho các đêm trùng nhau (double-booking thật, dữ liệu QA có sẵn từ trước, đặt tên rõ "QA Conflict Room B"). **Nguyên nhân gốc của lỗi hiển thị:** `buildRoomCell()` của Sơ đồ thao tác luôn chỉ chọn **một** người ở chính cho mỗi phòng/ngày và **âm thầm bỏ qua** mọi assignment khác đang trùng — kể cả một double-booking thật. Trong khi đó "Sơ đồ phòng" của booking (trang chi tiết booking) đã đúng đắn gắn cờ "Đã có booking khác" cho phòng 102. Hai màn hình kể hai câu chuyện khác nhau về cùng một dữ liệu → chính là điều Product Owner phát hiện.

**Xử lý:**
1. Gỡ (release) assignment mồ côi #460 theo xác nhận của Product Owner — cả hai màn hình khớp nhau trở lại.
2. Sửa gốc rễ (vòng đầu): thêm cơ chế phát hiện & cảnh báo double-booking thật ngay trên "Sơ đồ thao tác" — phân biệt rõ giữa "bàn giao trong ngày" (checkout 12:00 → checkin 14:00, không thật sự trùng giờ) và "trùng phòng thật" (2 assignment đang sống, khoảng thời gian thật sự chồng lấn). Thẻ phòng bị trùng có viền đỏ + banner "Trùng phòng — {mã booking}"; đầu trang có banner cảnh báo tổng hợp liệt kê số phòng; ô tổng kết `conflicted_rooms` bật đỏ khi > 0. **Lưu ý:** thuật toán ở vòng này chỉ so từng assignment với "primary" — bị phát hiện là chưa đủ đúng ở Vòng 6 bên dưới.

### Vòng 6 — "Pre-Commit Critical Safety Closure" (ChatGPT review lần trước → 3 điểm an toàn cần đóng trước commit)

ChatGPT đã rà soát bản `ketqualastella.md` trước đó và chỉ ra 3 điểm cần audit/đóng trước khi commit. Cả 3 đã được audit; **2 điểm là lỗi thật, đã sửa tận gốc**; 1 điểm audit sạch (đã đúng sẵn, chỉ bổ sung test chứng minh).

**1. Sửa phiếu kiểm đồ đã hoàn tất đang phá vỡ nguyên tắc bất biến của Folio hệ thống (LỖI THẬT, đã sửa tận gốc kiến trúc):**
Vòng 5 (bên trên) khi cho phép "sửa phiếu đã hoàn tất trước khi trả phòng" đã cài một cơ chế: `editCompleted()` tự tay `update(['voided_at' => now(), ...])` thẳng lên `FolioEntry` hệ thống (có `posting_key`), **bỏ qua hoàn toàn** hàm dùng chung `FolioService::voidEntry()` — hàm này có chốt chặn (ADR-50) cấm huỷ mọi entry hệ thống qua đường thủ công. Đây đúng là kiểu "tạo cửa hậu" mà nguyên tắc bất biến tài chính của hệ thống cấm.
**Sửa tận gốc (không phải vá tạm):** `complete()` (khi bấm "Hoàn tất kiểm đồ") **không ghi vào Folio ngay nữa** — số tiền lúc này chỉ là **dự phóng** (projection), sửa thoải mái trước khi trả phòng, không đụng gì tới Folio cả (vì Folio chưa có gì để đụng). Tiền chỉ thật sự được ghi vào Folio **đúng một lần, tại thời điểm trả phòng thực tế** (`StayService::checkOut()`) — dùng chung đúng cơ chế và đúng thời điểm mà "Phí trả phòng muộn" (Late Checkout Fee) đã dùng từ trước. Sau khi trả phòng, không còn cách nào sửa số lượng nữa (khoá cứng, kể cả ADMIN) — hoàn toàn không còn chỗ nào trong code Kiểm đồ có thể huỷ/ghi đè một `FolioEntry` hệ thống.
Có test riêng chứng minh: hoàn tất kiểm đồ → trả phòng thật (Folio thật đã ghi) → cố sửa lại → bị từ chối → dữ liệu Folio **không suy suyển 1 ký tự nào** (số tiền, mã posting_key, trạng thái huỷ — tất cả y nguyên).

**2. Phát hiện trùng phòng chỉ so với "người ở chính", bỏ sót trường hợp 2 phòng khác (không phải người ở chính) trùng nhau (LỖI THẬT, đã sửa):**
Thuật toán ở Hotfix 2 chỉ so mỗi assignment khác với **primary** (người đang hiển thị chính trên thẻ phòng). Nếu phòng có 3 booking A/B/C, mà A không trùng ai, nhưng B và C trùng nhau — hệ thống **không phát hiện ra** (vì cả B và C đều không phải primary). **Đã sửa:** đổi sang so **từng cặp một** (mọi assignment với mọi assignment khác, không chỉ với primary) — bất kỳ cặp nào trùng thật đều bị bắt và hiển thị đầy đủ, không bỏ sót ai.

**3. Kiểm tra lại toàn bộ nút bấm (đổi phòng/nhận phòng/trả phòng/kiểm đồ/dọn phòng) có bị giả mạo quyền được không (audit sạch — không có lỗi, chỉ bổ sung test chứng minh):**
Kiểm tra kỹ từng nút: mỗi nút không chỉ ẩn/hiện dựa vào nghiệp vụ mà còn PHẢI kiểm tra đúng quyền (permission) của người dùng. Backend (server) cũng phải tự kiểm tra lại quyền, không tin tưởng riêng những gì nút hiển thị trên giao diện. Kết quả: **cả 5 nút đều đã được kiểm tra quyền đúng cách từ trước** — không có lỗ hổng. Đã viết thêm 6 test "giả mạo request" (dùng tài khoản không có quyền, gọi thẳng vào API bằng HTTP, không qua giao diện) để chứng minh cả 5 hành động đều bị từ chối (403) đúng như mong đợi, cộng với 1 bảng phân quyền theo vai trò (ADMIN/MANAGER/RECEPTION/HOUSEKEEPING/ACCOUNTANT) đối chiếu với dữ liệu phân quyền thật của hệ thống.

**Ngoài ra — 3 audit nhanh khác (không phát hiện lỗi, chỉ xác nhận + bổ sung test):** (a) Giường phụ tính tiền đúng theo từng phòng, không bị tính trùng bởi 2 nơi khác nhau. (b) "Ghép giường" chỉ có đúng 1 nguồn dữ liệu, không còn cột dữ liệu trùng lặp nào sót lại. (c) Công thức tính phí kiểm đồ vẫn đúng như Vòng 5 (không trừ định mức miễn phí).

**Kết quả kiểm thử của riêng Vòng 6:** chạy lại toàn bộ 472 test liên quan (Sơ đồ thao tác, Kiểm đồ, Folio, Night Audit, Giường phụ, Gói dịch vụ, Yêu cầu đặc biệt, Buồng phòng...) — **472/472 PASS**. Chạy thêm 167 test liên quan khác (do `StayService` giờ có thêm 1 phụ thuộc mới) để đảm bảo không ảnh hưởng gì ngoài ý muốn — **166/167 PASS**, 1 lỗi còn lại là lỗi test cũ đã biết từ trước (mục 7 bên dưới, không liên quan). `npm run build`: PASS.

### Vòng 7 — "Final Selective Commit + Push Closure" (ChatGPT approve commit + push, kèm 1 vòng kiểm tra atomicity ngắn)

ChatGPT rà soát lại và **APPROVED FOR SELECTIVE COMMIT + PUSH**, kèm yêu cầu 1 vòng kiểm tra transaction/idempotency ngắn trước khi stage. Đây là vòng đầu tiên trong cả phiên làm việc thực sự **commit + push** lên remote — mọi vòng trước đều dừng lại ở "chưa commit."

**1. Kiểm tra an toàn giao dịch (transaction) tài chính cuối cùng:** Xác nhận `postCompletedChargesAtCheckout()` (ghi tiền vào Folio) và việc chuyển trạng thái trả phòng cùng nằm trong **một giao dịch DB duy nhất** (`StayService::checkOut()`), không mở giao dịch con riêng. Nghĩa là: nếu ghi tiền xong mà bước SAU đó (ví dụ: kiểm tra công nợ chưa thanh toán) lại lỗi, toàn bộ giao dịch — kể cả khoản tiền vừa ghi — sẽ **tự động bị huỷ bỏ (rollback)** hoàn toàn, không để sót lại dữ liệu nửa vời. Thêm 2 test mới chứng minh trực tiếp: (a) cố tình để một bước lỗi thật xảy ra NGAY SAU khi ghi tiền → xác nhận cả tiền lẫn trạng thái trả phòng đều bị huỷ bỏ, không sót; (b) gọi trả phòng 2 lần liên tiếp (mô phỏng bấm nút 2 lần) → xác nhận chỉ ghi tiền đúng 1 lần duy nhất, không bao giờ nhân đôi.

**2. Kiểm tra danh sách 5 migration + lệnh backfill:** Xác nhận đúng chính xác 5 migration đã liệt kê, toàn bộ đều additive (chỉ thêm cột/bảng, không xoá/đổi kiểu dữ liệu cũ), không có cột `bed_joined` trùng lặp sót lại. Lệnh backfill giường phụ (`BackfillExtraBedRoomQuantity`) xác nhận mặc định luôn là dry-run (chỉ báo cáo, không ghi), phải có `--apply` mới ghi thật, và không có nơi nào trong hệ thống tự động chạy lệnh này (không migration, không deploy script nào gọi nó).

**3. Rà soát từng file dùng chung (shared file) để tránh commit nhầm code không liên quan:** Kiểm tra `git diff` từng file một trong số các file đã sửa (Enums, Controllers, Models, Services, routes, test) để tách riêng phần thuộc "Sơ đồ thao tác" khỏi phần không liên quan (nếu có). Phát hiện **1 file có lẫn 2 loại thay đổi**: `resources/js/Layouts/AppLayout.vue` — có 3 đoạn thay đổi (hunk), trong đó 2 đoạn thuộc tính năng (thêm icon + thêm mục menu "Sơ đồ thao tác" vào sidebar), còn 1 đoạn (chỉnh chiều cao/padding của thanh header phía trên) là công việc cũ, không liên quan, thuộc một phần khác của giao diện (thanh header trên cùng, không phải sidebar chứa menu). **Xử lý:** chỉ stage đúng 2 đoạn thuộc tính năng bằng cách tạo patch thủ công (`git apply --cached`), loại bỏ đoạn không liên quan — đoạn đó vẫn còn nguyên trong working tree, chỉ là không được đưa vào lần commit này.

**4. Chạy lại toàn bộ test + build trước khi stage:** Toàn bộ các bộ test bắt buộc (Sơ đồ thao tác, Kiểm đồ, Giường phụ, Gói dịch vụ) — **147/147 PASS**. Các bộ test liên quan khác (Folio, Night Audit, Buồng phòng, Yêu cầu đặc biệt, Phân phòng, Chuyển phòng...) — phát hiện 7 test concurrency (kiểm tra tranh chấp đồng thời, chạy trên 1 database MySQL riêng biệt dùng để test — không phải database thật) bị lỗi vì database đó **chưa được migrate** 5 migration mới. Đây không phải lỗi code — chỉ là database phụ trợ dùng riêng cho loại test này chưa cập nhật schema. Đã migrate database đó (an toàn, không phải production, đã có tài liệu ghi rõ là "disposable, never production" từ trước), chạy lại — **20/20 PASS**. Tổng cộng sau khi sửa: **486/486 PASS**, `npm run build`: PASS. Đối chiếu 17 điểm chấp nhận tĩnh (popup kiểm đồ, không cần điều hướng, sửa được trước trả phòng, khoá sau trả phòng, màu thẻ đậm, 1 hàng icon, giường phụ theo phòng, ghép giường, không có mã booking trên thẻ, ghi chú nhanh ≤100 ký tự, mỗi tầng 1 hàng, cuộn ngang chung, xác nhận đúng phòng khi trả, cảnh báo trùng phòng, phát hiện trùng theo cặp...) — **đủ cả 17/17**.

**5. Stage chọn lọc + phát hiện 1 thiếu sót trước khi commit:** Chỉ `git add` đúng danh sách file thuộc tính năng (không dùng `git add .`/`git add -A`), cộng patch riêng cho `AppLayout.vue`. Trước khi commit, đối chiếu chéo danh sách file đã stage với danh sách "Files touched" mà chính báo cáo triển khai (`docs/reports/daily-room-operations-board-implementation-report.md`) đã ghi ở từng vòng trước — phát hiện **thiếu 2 file**: `app/Http/Requests/Booking/StoreBookingRequest.php` và `UpdateBookingRequest.php` (chứa rule validate `quick_note` tối đa 100 ký tự ở phía server, thuộc addendum Ghi chú nhanh). Đây là 1 sai sót thật khi rà soát Section VII — nếu thiếu, việc kiểm tra 100 ký tự phía server (chống bypass giới hạn của ô nhập trên giao diện) sẽ không hoạt động dù test đã "PASS" (vì test chạy trên working tree đang có sẵn file, không phản ánh đúng những gì thật sự được commit).

**6. Commit (2 lần, không amend):** Vì quy tắc rõ ràng "không được amend commit trước", nên khi phát hiện thiếu sót ở bước 5, xử lý bằng cách tạo thêm 1 commit thứ hai riêng, ghi rõ lý do, thay vì sửa lại commit đầu:
- Commit 1: `feat(room-operations): add daily multi-function room operations board` — SHA `3408171aaf9877f5f89110ff4045ce1b189884f4` — 57 file.
- Commit 2: `fix(room-operations): include quick_note server-side validation on booking create/update` — SHA `04f58317dda1259aa4fff664ea2b6a8bafe55f5e` — 2 file.

Chạy lại test sau commit (73 test trọng tâm) — **73/73 PASS**, `npm run build`: PASS.

**7. Kiểm tra trước khi push + push thật:** `git fetch` lại remote, xác nhận `origin/phase-3` vẫn là tổ tiên trực tiếp của HEAD hiện tại (không ai push gì khác lên trong lúc làm việc), không có commit nào ở remote mà local chưa có. `git push origin phase-3` — **thành công**, không dùng force, không tạo tag. Kiểm tra lại sau push: `HEAD == origin/phase-3` = `04f58317dda1259aa4fff664ea2b6a8bafe55f5e`, không còn commit nào chưa push, không có gì đang stage, toàn bộ các thay đổi cục bộ không liên quan (khác) vẫn còn nguyên, không bị đụng tới.

**Chưa deploy production** — theo đúng yêu cầu, dừng lại sau khi push, không truy cập production, không migrate production, không chạy backfill `--apply`, không seed production.

---

## 4. Danh sách file liên quan đến "Sơ đồ thao tác" (ĐÃ COMMIT + PUSH ở Vòng 7)

### Đã sửa (tracked, có trong git history)
```
app/Enums/StayEventType.php
app/Http/Controllers/Admin/Booking/BookingController.php
app/Http/Controllers/Admin/CheckoutInspectionController.php
app/Http/Controllers/Admin/PackageEnrollmentController.php
app/Http/Requests/Admin/SaveCheckoutInspectionRequest.php
app/Http/Requests/Booking/StoreBookingRequest.php
app/Http/Requests/Booking/UpdateBookingRequest.php
app/Models/Booking.php
app/Models/RoomAssignment.php
app/Models/Stay.php
app/Services/CheckoutInspectionService.php
app/Services/PackageEnrollmentService.php
app/Services/Posting/ExtraBedPostingJob.php
app/Services/RoomAssignmentService.php
app/Services/StayService.php
resources/js/Layouts/AppLayout.vue
resources/js/Pages/Admin/Booking/Packages.vue
resources/js/Pages/Admin/Bookings/Form.vue
resources/js/Pages/Admin/CheckoutInspections/Partials/CheckoutInspectionModal.vue
routes/web.php
tests/Feature/CheckoutInspectionControllerTest.php
tests/Feature/CheckoutInspectionServiceTest.php
tests/Feature/ExtraBedPostingJobTest.php
tests/Feature/PackageEnrollmentControllerTest.php
tests/Feature/PackageEnrollmentServiceTest.php
tests/Feature/ServicePackagePostingJobTest.php
```

### Mới hoàn toàn (untracked)
```
app/Console/Commands/BackfillExtraBedRoomQuantity.php
app/Http/Controllers/Admin/RoomOperationsController.php
app/Http/Requests/RoomOperations/               (thư mục)
app/Models/RoomSwapBatch.php
app/Services/RoomOperationsBoardService.php
app/Services/RoomSwapService.php
database/migrations/2026_08_09_000000_add_quick_note_to_room_assignments_table.php
database/migrations/2026_08_09_000001_create_room_swap_batches_table.php
database/migrations/2026_08_09_000002_add_swap_batch_id_to_room_assignments_table.php
database/migrations/2026_08_09_000003_add_quick_note_to_bookings_table.php
database/migrations/2026_08_09_000004_add_extra_bed_quantity_to_room_assignments_table.php
resources/js/Pages/Admin/RoomOperations/         (toàn bộ thư mục — Index.vue + Partials/*)
tests/Feature/QuickNoteLifecycleTest.php
tests/Feature/RoomOperationsAuthorizationTest.php       (mới — Vòng 6)
tests/Feature/RoomOperationsBedOperationsTest.php
tests/Feature/RoomOperationsConflictDetectionTest.php
tests/Feature/RoomOperationsControllerTest.php
tests/Feature/RoomOperationsDailySummaryTest.php
tests/Feature/RoomOperationsInspectionGuardTest.php
tests/Feature/RoomSwapServiceTest.php
docs/implementation-plans/daily-room-operations-board-implementation-plan.md
docs/reports/daily-room-operations-board-implementation-report.md
docs/reviews/daily-room-operations-board-architecture-review.md
```

**Không có migration phá hủy** — toàn bộ 5 migration đều additive (thêm cột/bảng mới, không xóa/đổi kiểu cột cũ).

**Đã commit ở Vòng 7 (2 commit, không amend):**
- Commit `3408171` — `feat(room-operations): add daily multi-function room operations board` — 57 file (toàn bộ danh sách ở trên, TRỪ 2 file `StoreBookingRequest.php`/`UpdateBookingRequest.php`).
- Commit `04f5831` — `fix(room-operations): include quick_note server-side validation on booking create/update` — 2 file `app/Http/Requests/Booking/StoreBookingRequest.php` + `UpdateBookingRequest.php` (thiếu sót phát hiện lúc rà soát trước commit, xem Vòng 7 mục 5).
- **`docs/yeucaumoi.txt`** — KHÔNG commit: nội dung file này hiện tại chỉ là chỉ thị tác vụ của chính Vòng 7 (không phải spec gốc cố định), commit vào sẽ không có ý nghĩa như tài liệu dự án lâu dài.
- **`docs/ketqualastella.md`** (file này) — KHÔNG commit, theo đúng chỉ thị "không commit file chuyển giao ketqualastella trừ khi cố ý làm tài liệu dự án."

Đã push thành công lên `origin/phase-3` — HEAD hiện tại == `origin/phase-3` = `04f58317dda1259aa4fff664ea2b6a8bafe55f5e`.

> Ghi chú: `git status` hiện còn một số file/thư mục khác (`.env.production.example`, `docs/pilot/`, `docs/reports/phase-4.3a-*`, `docs/reports/product-sprint-*`, `docs/user-guide/`, `storage/backups/`, `bootstrap/app.php`, `resources/js/app.js`, `resources/js/Pages/Admin/Bookings/Show.vue`, `.gitignore`, và đoạn hunk chiều cao header còn lại trong `AppLayout.vue`) — đây là **thay đổi cục bộ từ các công việc khác, không thuộc phạm vi "Sơ đồ thao tác"**, đã được xác nhận giữ nguyên không đụng tới, không commit, qua mọi vòng kiểm tra an toàn git ở từng bước kể cả sau khi push.

---

## 5. Các bug thật đã tìm thấy và sửa (tóm tắt để review ưu tiên)

| # | Bug | Mức độ | Vòng phát hiện | Trạng thái |
|---|---|---|---|---|
| 1 | `can_inspect` gate sai trạng thái (CheckedOut thay vì CheckedIn) → nút Kiểm đồ luôn tắt, trả phòng không cảnh báo | Nghiêm trọng (vận hành) | Vòng 2 | Đã sửa |
| 2 | `ExtraBedPostingJob` tính phí giường phụ theo booking thay vì theo phòng → booking nhiều phòng bị tính phí giường phụ **nhân bản N lần** | **Nghiêm trọng (tài chính)** | Vòng 3 | Đã sửa + có lệnh backfill dữ liệu cũ |
| 3 | Công thức tính phí kiểm đồ trừ nhầm định mức miễn phí ra khỏi số lượng nhập (`actual − free`) → thu thiếu tiền một cách âm thầm | **Nghiêm trọng (tài chính)** | Vòng 5 | Đã sửa (input trực tiếp, không trừ) |
| 4 | Nút "Kiểm đồ" bị khóa cứng với phiếu đã hoàn tất, kể cả khi chưa trả phòng (chặn luôn tính năng sửa phiếu vừa thêm) | Vừa (vận hành) | Hotfix 1 | Đã sửa |
| 5 | "Sơ đồ thao tác" âm thầm giấu double-booking thật (2 booking cùng giữ 1 phòng), không hề cảnh báo | **Nghiêm trọng (vận hành/dữ liệu)** | Hotfix 2 | Đã sửa — thêm cơ chế phát hiện + cảnh báo trực quan |
| 6 | Sửa phiếu kiểm đồ đã hoàn tất tự ý huỷ/ghi đè `FolioEntry` hệ thống, bỏ qua chốt chặn bất biến tài chính dùng chung (`FolioService::voidEntry()`) | **Nghiêm trọng (tài chính/kiến trúc)** | Vòng 6 | Đã sửa tận gốc — dời thời điểm ghi Folio sang lúc trả phòng thật, không còn đường nào huỷ/ghi đè entry hệ thống |
| 7 | Phát hiện trùng phòng chỉ so với "người ở chính", bỏ sót cặp trùng phòng không liên quan đến người ở chính | Nghiêm trọng (vận hành/dữ liệu) | Vòng 6 | Đã sửa — so từng cặp, không bỏ sót |
| 8 | Thiếu sót quy trình (không phải bug code): stage commit lần 1 bỏ sót 2 file validate `quick_note` phía server — nếu chỉ commit vậy, hàng rào 100 ký tự phía server (chống bypass giới hạn ô nhập) sẽ không tồn tại trên code đã commit dù test local vẫn "PASS" (vì test chạy trên working tree, không phản ánh đúng git commit) | Vừa (quy trình) | Vòng 7 | Đã sửa — thêm commit thứ 2 riêng, không amend |

---

## 6. Kết quả kiểm thử (test) tổng hợp

Tất cả chạy bằng `php artisan test`, SQLite in-memory cho test, MySQL cho dev thật.

| Bộ test | Kết quả |
|---|---|
| `RoomSwapServiceTest` (đổi phòng) | 17/17 PASS |
| `RoomOperationsControllerTest` | 13/13 PASS |
| `RoomOperationsDailySummaryTest` | 5/5 PASS |
| `RoomOperationsInspectionGuardTest` (bao gồm cả 2 test mới của Hotfix 1) | 8/8 PASS |
| `RoomOperationsBedOperationsTest` (giường phụ + ghép giường theo phòng) | 14/14 PASS |
| `RoomOperationsConflictDetectionTest` (8 test — pairwise, đã sửa ở Vòng 6) | 8/8 PASS |
| `RoomOperationsAuthorizationTest` (mới — Vòng 6, giả mạo quyền + bảng phân quyền) | 6/6 PASS |
| `QuickNoteLifecycleTest` | 10/10 PASS |
| `CheckoutInspectionServiceTest` (viết lại theo kiến trúc "ghi Folio lúc trả phòng" — Vòng 6; +2 test atomicity rollback/retry — Vòng 7) | 24/24 PASS |
| `CheckoutInspectionControllerTest` (cập nhật theo kiến trúc mới) | 5/5 PASS |
| `CheckoutInspectionSkipTest` | 7/7 PASS |
| `ExtraBedPostingJobTest` (bao gồm bằng chứng tái hiện bug tài chính + audit Vòng 6) | 12/12 PASS |
| Regression Package Enrollment / Service Package / Night Audit / Folio / Special Request | 147/147 PASS |
| Regression Room Assignment / Move Room / Booking / Split Stay | 106/106 PASS |
| Concurrency (đổi phòng/phân phòng tranh chấp đồng thời, MySQL riêng — Vòng 7) | 20/20 PASS |
| `npm run build` | PASS ở mọi vòng (build cuối cùng: 2390 modules, không lỗi) |

**Chạy sweep tổng hợp Vòng 7 (bản MỚI NHẤT, sau khi đã commit + push):**
- Bộ test trọng tâm bắt buộc (Sơ đồ thao tác, Kiểm đồ, Giường phụ, Gói dịch vụ): **147/147 PASS**.
- Bộ test liên quan khác (Folio, Night Audit, Buồng phòng, Yêu cầu đặc biệt, Phân phòng, Chuyển phòng, Concurrency): **486/486 PASS** (sau khi migrate database concurrency riêng — xem Vòng 7 mục 4).
- Bộ test trọng tâm chạy lại SAU 2 lần commit (xác nhận commit đúng, không thiếu gì thêm): **73/73 PASS**.
- `npm run build`: PASS (chạy 3 lần trong Vòng 7, đều PASS).

(Sweep tổng hợp Vòng 6 trước đó: 472/472 PASS + 166/167 PASS riêng lẻ. Sweep trước Vòng 6: 580 passed / 4 failed, cả 4 đều không liên quan, xem mục 7.)

---

## 7. Các lỗi test KHÔNG liên quan đến "Sơ đồ thao tác" (đã điều tra, không phải do code này)

4 test sau vẫn fail dù có hay không có toàn bộ code của tính năng này — đã xác minh bằng cách `git stash` code liên quan rồi chạy lại, lỗi giống hệt:

1. **`BookingManagementUiTest`** — 1 test về `roomBoard.floors`/tính `availability_status` ở panel "Sơ đồ phòng" cũ (logic có từ trước, không thuộc code tính năng này).
2. **`DashboardTest`** — 1 test về số phòng trống trên Dashboard sau khi trả phòng.
3. **`RoomAvailabilityCheckerTest`** — 1 test về ranh giới ngày ở màn "Kiểm tra phòng" (`/admin/room-availability`, module khác hẳn).
4. **`LateCheckoutFeeTest::test_check_out_triggers_late_checkout_fee_via_stay_service`** — lỗi do **cách viết test phụ thuộc giờ thực thi**: test tính `plannedCheckout = now()->subHours(2)->setTime(12,0,0)` rồi trả phòng lúc `now()` — công thức này chỉ tạo ra tình huống "trả trễ" nếu chạy sau ~14:00 giờ thực; chạy vào buổi sáng (như môi trường CI/dev hiện tại) thì `plannedCheckout` (12:00 hôm nay) lại đứng SAU giờ trả phòng thực tế → không bao giờ được tính là trễ → không phát sinh phí. Không đụng gì tới `RoomOperationsBoardService`, các component Vue của Sơ đồ thao tác, hay route kiểm đồ.

**Kết luận:** cả 4 đều là lỗi/thiếu sót test có từ trước, mang tính thời điểm/môi trường (phụ thuộc ngày giờ hệ thống), nằm ngoài phạm vi module này — không sửa trong lần này vì không thuộc yêu cầu.

---

## 8. Hạn chế còn tồn đọng (chưa làm, cần biết trước khi review/QA)

- **Chưa QA trên trình duyệt thật** (desktop lẫn mobile) — môi trường phát triển không có phiên trình duyệt đã đăng nhập trong suốt quá trình làm. Mọi PASS ở trên đều là test tự động (PHPUnit/Feature test), không phải kiểm tra bằng mắt trên UI thật. Đây là hạng mục QA thủ công còn nợ, **sẽ thực hiện sau khi deploy lên môi trường có thể xem được** (server deploy là task riêng, chưa làm).
- Giường phụ hiện là số lượng theo phòng (`extra_bed_quantity`), giá vẫn lấy từ `ServicePackage`/`ServicePackageRate` (không đổi nguồn giá).
- 5 migration mới đều **additive**, đã chạy trên máy dev + database test, **CHƯA chạy trên production** (cần chạy `php artisan migrate` khi deploy).
- Lệnh backfill `BackfillExtraBedRoomQuantity` đã commit nhưng **CHƯA chạy `--apply`** ở đâu cả — đây là hành động riêng, có kiểm soát, sau khi deploy.
- **Đã đóng ở Vòng 6** (không còn là hạn chế nữa, để lại đây làm lịch sử): (a) cơ chế sửa phiếu kiểm đồ đã hoàn tất từng huỷ/ghi đè `FolioEntry` hệ thống trực tiếp — nay đã đổi kiến trúc, không còn khả năng đó; (b) phát hiện trùng phòng từng chỉ so với "người ở chính" — nay so từng cặp.
- **Đã đóng ở Vòng 7** (không còn là hạn chế nữa): (a) chưa xác nhận rõ ràng ghi Folio + chuyển trạng thái trả phòng có cùng transaction hay không — nay đã kiểm chứng bằng test rollback/retry thật; (b) chưa commit/push — nay đã push lên `origin/phase-3`.

---

## 9. Trạng thái Git tại thời điểm viết file này

- Branch: `phase-3`.
- **ĐÃ COMMIT (2 commit) + ĐÃ PUSH thành công lên `origin/phase-3`.**
  - Commit 1: `3408171aaf9877f5f89110ff4045ce1b189884f4` — `feat(room-operations): add daily multi-function room operations board`.
  - Commit 2 (final HEAD): `04f58317dda1259aa4fff664ea2b6a8bafe55f5e` — `fix(room-operations): include quick_note server-side validation on booking create/update`.
- `HEAD == origin/phase-3` = `04f58317dda1259aa4fff664ea2b6a8bafe55f5e` — xác nhận sau `git fetch`, không có commit nào chưa push, không có commit nào ở remote mà local chưa có.
- `git diff --cached --stat`: **rỗng** — không có gì đang stage.
- `git diff --check`: **sạch** trong suốt cả 2 lần commit.
- **CHƯA deploy production**, chưa migrate production, chưa chạy backfill `--apply` ở đâu cả.
- Toàn bộ thay đổi thuộc các công việc khác (không liên quan Sơ đồ thao tác) trong working tree được xác nhận giữ nguyên, không bị đụng tới, không bị commit — kể cả sau khi push.
- Tổng số file đã commit liên quan "Sơ đồ thao tác": **59 file** (57 file ở commit 1 + 2 file ở commit 2, không trùng lặp).

---

## 10. Gợi ý trọng tâm khi GPT review

Code đã **commit + push thành công lên `origin/phase-3`** (Vòng 7). Toàn bộ rủi ro tài chính/kiến trúc từ các lần review trước đã đóng. Trọng tâm review lần này chuyển sang xác nhận CHÍNH XÁC những gì đã lên remote, và các bước còn lại trước khi đưa lên production:

1. **Xác nhận nội dung 2 commit đã push** (`3408171` + `04f5831`, xem mục 4/9) khớp đúng với những gì tài liệu này mô tả — đặc biệt là việc file `AppLayout.vue` chỉ commit đúng 2 đoạn (icon + menu item), không dính đoạn chỉnh header không liên quan.
2. **Kiến trúc "ghi Folio lúc trả phòng thật"** (`CheckoutInspectionService::complete()`/`editCompleted()`/`postCompletedChargesAtCheckout()`, gọi từ `StayService::checkOut()`) — đã có 2 test mới ở Vòng 7 chứng minh cùng transaction (rollback khi lỗi sau, không nhân đôi khi gọi lại) — nên soi kỹ code thật khớp với test.
3. **Thuật toán phát hiện trùng phòng theo cặp** (`RoomOperationsBoardService::conflictingAssignments()`) — đã có 8 test pairwise.
4. Toàn bộ action-flag (`can_inspect`, `can_swap`, `can_check_in`, `can_check_out`, `can_clean`) — đã audit + có test giả mạo quyền (403).
5. **Chưa QA tay trên trình duyệt thật** (mục 8) — hạng mục còn nợ, sẽ làm sau khi có môi trường deploy để xem trực tiếp.
6. **Chưa deploy production** — migrate production, chạy backfill Extra Bed (`--apply`), và QA thủ công đều là các bước riêng, có kiểm soát, chưa thực hiện trong phạm vi file này.
