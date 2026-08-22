# Rà soát Doanh thu — Night Audit chưa từng chạy trên Production

**Ngày rà soát:** 2026-08-22 · **Người rà soát:** Claude (máy dev), theo yêu cầu Product Owner (`docs/Ghichuchosua.txt` + chat 2026-08-22) · **Môi trường:** `https://pms.lastella.com.vn/` (production, đọc trực tiếp qua trình duyệt) + rà mã nguồn local.

**Kết luận ngắn gọn:** Đúng như nghi ngờ — doanh thu tiền phòng trên production đang bị **thiếu nghiêm trọng và mang tính hệ thống**, không phải lỗi riêng của 1 booking. Nguyên nhân gốc: **Night Audit chưa từng chạy một lần nào trên production** (log trống hoàn toàn), và cơ chế ghi nhận phí phòng hiện tại **chỉ đăng đúng 1 đêm duy nhất tại thời điểm Check-in** — mọi đêm còn lại của một lượt lưu trú nhiều đêm sẽ không bao giờ được ghi sổ trừ khi có người chủ động chạy Night Audit.

---

## 1. Bằng chứng từ Production

### 1.1 Báo cáo doanh thu (01/08 – 22/08/2026, 22 ngày)

| Ngày | Số giao dịch | Thành tiền |
|---|---|---|
| 16-08-2026 | 44 | 28.800.000 đ |
| 18-08-2026 | 61 | 34.550.000 đ |
| 21-08-2026 | 32 | 20.300.000 đ |
| 22-08-2026 | 1 | 500.000 đ |
| **Các ngày còn lại (18 ngày)** | **0** | **0 đ** |
| **Tổng** | **138** | **84.150.000 đ** |

100% giao dịch có nguồn = "Night Audit" (nhãn hiển thị trên báo cáo), 100% loại phí = "Tiền phòng". Toàn bộ doanh thu chỉ tập trung đúng 4 ngày trùng khớp với các đợt nhân viên nhập liệu/check-in hàng loạt (xem "NGÀY TẠO" của các booking) — không phải phân bổ đều theo số đêm khách thực sự lưu trú.

### 1.2 Trang Night Audit (`/admin/night-audit`)

> Ngày kế toán hiện tại: 2026-08-22
> **Chưa có lần chạy nào.**

Xác nhận: **0 lần Night Audit từng được kích hoạt trên production**, từ trước tới nay.

### 1.3 Booking cụ thể — BK-20260818152635-FLIS (ID 74)

- Khách "a trọc", loại Qua đêm, **Nhận phòng 17-08-2026 14:00 → Trả phòng 20-08-2026 12:00 (3 đêm: 17→18, 18→19, 19→20)**.
- 23 phòng (22 TWIN + 1 DOUBLE), 450.000 đ/đêm/phòng.
- Trạng thái: **Đã trả phòng**.
- `actual_checkin_at` thực tế của mọi stay trong booking = **2026-08-18 17:42** (không phải 17-08 như kế hoạch — khả năng do nhập liệu lùi ngày).
- **Phí phát sinh đã ghi sổ: đúng 23 dòng, TẤT CẢ đều ghi ngày 18-08-2026** (= đúng ngày check-in thực tế), tổng **10.350.000 đ**.
- **Đêm 17-08 và đêm 19-08: KHÔNG có dòng phí nào** — mất trắng **2 đêm × 23 phòng × 450.000 đ = 20.700.000 đ** cho riêng booking này.
- Vì booking đã **Đã trả phòng**, khoản này **không thể khôi phục được nữa** (xem mục 2.2).

## 1.4 Vì sao "Tổng phí phát sinh" (10.350.000đ) khác "Dự kiến thanh toán" (20.700.000đ)?

Câu hỏi bổ sung của Product Owner (2026-08-22 chat). Xác nhận qua `app/Services/PaymentProjectionService.php`:

- **"Dự kiến thanh toán"** (`projectStayRoomCharges()`) là số **tính độc lập, không đọc từ sổ phí đã ghi**. Với 1 stay đã **Đã trả phòng**, `effectiveStayRange()` (dòng 128-139) dùng **giờ thực tế** `[actual_checkin_at, actual_checkout_at]`, không dùng ngày kế hoạch gốc của booking:
  ```php
  StayStatus::CheckedOut => [$stay->actual_checkin_at, $stay->actual_checkout_at],
  ```
  Khách của booking này check-in thực tế lúc **18-08-2026 17:42** (trễ 1 ngày so với kế hoạch 17-08), trả phòng đúng kế hoạch 20-08. → Số đêm tính = **2 đêm thực ở** (18→19, 19→20) × 23 phòng × 450.000đ = **20.700.000đ** — không phải 3 đêm theo kế hoạch gốc (31.050.000đ), vì hệ thống **chủ động không tính đêm 17-08** (đêm khách chưa tới) vào phần dự kiến của 1 stay đã kết thúc. Đây có vẻ là quyết định nghiệp vụ có chủ đích (comment code: "the actual, final duration, not an open-ended projection"), không phải bug tính toán — nhưng đáng để Product Owner xác nhận lại đây có đúng chính sách khách sạn muốn (khách check-in trễ có nên vẫn bị tính đêm no-show đầu tiên không?) hay cần điều chỉnh.
- **"Tổng phí phát sinh"** (10.350.000đ) là số **đã thực sự ghi vào FolioEntry** — đúng như mục 1.3: chỉ 1/2 đêm thực ở (18-08) được ghi, đêm 19-08 chưa từng được ghi vì Night Audit chưa chạy.
- **Chênh lệch 10.350.000đ giữa 2 con số = đúng bằng giá trị đêm 19-08 bị bỏ sót** — không phải một lỗi tính toán riêng, mà là hệ quả trực tiếp, nhất quán của nguyên nhân gốc đã nêu ở mục 2. Nếu Night Audit được chạy đủ, "Tổng phí phát sinh" sẽ tự động khớp đúng bằng "Dự kiến thanh toán" (20.700.000đ) — không phải 31.050.000đ của 3 đêm kế hoạch gốc.

## 2. Nguyên nhân gốc (xác nhận qua mã nguồn local)

### 2.1 Không có lịch tự động

`routes/console.php` không có bất kỳ `Schedule::` nào; `bootstrap/app.php` không gọi `->withSchedule()`. **Night Audit 100% là thao tác thủ công**, không có cron/lịch tự động chạy hàng đêm.

### 2.2 Cơ chế ghi phí phòng hiện tại

- `StayService::checkIn()` (dòng ~120) gọi `roomChargeJob->execute($context)` **ngay tại thời điểm Check-in** — đăng đúng 1 đêm phòng cho ngày kế toán hiện tại lúc đó. Đây là lý do vì sao vẫn có dữ liệu dù Night Audit chưa từng chạy.
- Các đêm **tiếp theo** của một lượt lưu trú dài chỉ được đăng khi **Night Audit thực sự chạy quét qua**, không có cơ chế nào khác.
- `NightAuditPipeline::run()` (dòng 44-46): mỗi lần chạy chỉ xử lý `Stay::whereIn('status', [StayStatus::CheckedIn])` — **chỉ những lượt lưu trú ĐANG check-in tại THỜI ĐIỂM CHẠY**, bất kể đang audit cho ngày kế toán nào trong quá khứ.
  → **Hệ quả nghiêm trọng:** một booking đã **Đã trả phòng** thì vĩnh viễn không bao giờ được Night Audit "hồi cứu" bù lại các đêm đã bỏ lỡ, dù chạy Night Audit cho bất kỳ ngày quá khứ nào — vì tại thời điểm chạy, `status` của nó không còn là `CheckedIn` nữa. Đây chính xác là lý do booking BK-...-FLIS mất trắng 20.700.000đ không thể cứu được.
- `NightAuditOperationsService::guardWindowConstraint()`: kích hoạt thủ công chỉ được phép trong phạm vi `audit_window_days` (mặc định **7 ngày**) tính từ ngày kế toán hiện tại. Ngày kế toán hiện tại trên production = 22-08-2026 → **chỉ còn kích hoạt được cho các ngày từ 15-08-2026 trở về sau**; mọi ngày trước 15-08 đã **vĩnh viễn ngoài tầm với của Night Audit thủ công**, kể cả với các booking vẫn đang check-in.

## 3. Tác động thực tế

1. **Mọi booking lưu trú nhiều đêm** (không chỉ riêng BK-...-FLIS) đều có nguy cơ chỉ được ghi sổ đúng 1 đêm (đêm check-in) — cần coi đây là **lỗi hệ thống, không phải cá biệt**.
2. Các booking **đã trả phòng** với các đêm bị bỏ lỡ: **mất doanh thu vĩnh viễn**, không có cách khôi phục tự động nào trong hệ thống hiện tại.
3. Các booking **vẫn đang check-in**: nếu chạy Night Audit NGAY BÂY GIỜ cho các ngày trong vòng 7 ngày gần nhất (15-08 → 22-08), có thể **cứu được một phần** — nhưng nếu có stay nào check-in trước 15-08 và vẫn chưa được audit, phần đêm trước 15-08 của chính stay đó cũng đã ngoài tầm với.
4. Số 84.150.000đ trên báo cáo doanh thu hiện tại **thấp hơn đáng kể** so với doanh thu thực tế lẽ ra phải ghi nhận trong 22 ngày — chưa thể tính được con số chính xác thiếu bao nhiêu nếu không truy vấn trực tiếp toàn bộ 50 booking (cần quyền truy cập DB hoặc rà thủ công từng booking qua trình duyệt).

## 4. Khuyến nghị (chờ Product Owner quyết định — chưa thực hiện gì trên production)

1. **Khẩn cấp, ít rủi ro nhất:** kích hoạt Night Audit thủ công NGAY cho các ngày còn trong phạm vi 7 ngày (15-08 → 21-08, mỗi ngày 1 lần, theo đúng thứ tự tăng dần) để chặn đà mất thêm doanh thu cho các booking vẫn đang check-in. **Đây là thao tác ghi vào production — tôi không tự làm, cần bạn xác nhận hoặc đích thân thực hiện.**
2. **Trung hạn, bắt buộc:** thiết lập lịch tự động chạy Night Audit hàng đêm (Laravel Scheduler + cron thật trên máy chủ) — hiện hoàn toàn không có, đây là lỗ hổng vận hành gốc rễ.
3. **Xử lý phần đã mất (checked-out, ngoài cửa sổ 7 ngày):** cần quyết định nghiệp vụ — có truy thu thủ công (thêm phí bằng tay từng booking) cho các booking đã trả phòng bị thiếu đêm hay chấp nhận mất, vì hệ thống hiện không có nút "truy hồi" nào khác ngoài nhập tay `Thêm phí` trên từng booking.
4. Cân nhắc rút ngắn hoặc bỏ giới hạn `audit_window_days` (hiện 7 ngày) cho các trường hợp catch-up đặc biệt — hoặc ít nhất cảnh báo rõ hơn trên UI khi một booking sắp/đã đi qua ngưỡng này.
5. Nếu cần con số thiệt hại chính xác toàn hệ thống (không chỉ 1 booking), cần rà thủ công qua từng booking hoặc cấp quyền truy vấn DB trực tiếp — hiện tại tôi chỉ đọc được qua trình duyệt trên production.

## 5. Khóa điều kiện — "đã thanh toán xong không được sửa giờ nhận/trả phòng"

Rà soát `StayService::updateActualCheckIn()`/`updateActualCheckOut()` và 2 FormRequest tương ứng (`UpdateActualCheckInRequest`/`UpdateActualCheckOutRequest`).

**Kết luận: khóa này CHƯA tồn tại.** Điều kiện hiện có chỉ gồm: có quyền `stay.actual_time.manage`, đã từng ghi giờ (không sửa khi chưa nhận/trả phòng), giờ mới không ở tương lai, và thứ tự nhận-trước/trả-sau hợp lệ. **Không có bước nào kiểm tra tình trạng thanh toán của booking.** Đây là một tính năng cần bổ sung, không phải khắc phục lỗi có sẵn — cần Product Owner xác nhận rõ tiêu chí "đã hoàn thành thanh toán" trước khi triển khai (đủ 100% `balance_due` = 0? hay folio đã đóng? áp dụng cho sửa giờ nhận, giờ trả, hay cả hai?).

## 6. Rà soát chức năng Đối soát (`/admin/reconciliation`, production)

### 6.1 Đối soát PHẢN ÁNH ĐÚNG hệ quả của lỗi Night Audit ở mục 1-4

- **Tổng tồn nợ hiện tại: 83.580.000 đ / 12 booking**, "Đã TT" = 0 đ cho toàn bộ 12 booking (chưa ghi nhận bất kỳ khoản thanh toán nào trong hệ thống cho các booking này).
- Cột "TỔNG PHÍ" trên Đối soát đọc **trực tiếp từ FolioEntry đã ghi sổ** (`ReconciliationService::computeBalance()`, dòng 196: `$booking->folio?->folioEntries->sum('amount')`) — **cùng nguồn dữ liệu bị thiếu đêm** như mục 1-4. Ví dụ BK-...-FLIS hiện ở đây đúng 10.350.000đ (1 đêm đã ghi) — **chưa phản ánh phần 20.700.000đ lẽ ra phải có** (theo mục 1.4).
  → **Số tồn nợ thực tế của toàn hệ thống còn CAO HƠN** con số 83.580.000đ hiện hiển thị, vì nhiều trong 12 booking này nhiều khả năng cũng bị thiếu đêm tương tự.
- Mục **"Bất thường tài chính"** của chính hệ thống đã tự phát hiện đúng 2 trường hợp "Đã trả phòng còn nợ" (BK-...-FLIS 10.350.000đ, BK-20260820100647-Z5CW 19.230.000đ) — cơ chế cảnh báo này hoạt động đúng, nhưng số tiền nó cảnh báo cũng bị thiếu vì cùng lý do trên.
- **Kết luận:** Doanh thu và Đối soát không mâu thuẫn nhau — cả hai đọc từ CÙNG một sổ FolioEntry, nên đồng nhất SAI với nhau theo cùng một hướng (đều thấp hơn thực tế). Sửa xong nguyên nhân gốc ở mục 2 (Night Audit) sẽ tự động sửa đúng cả 2 màn hình, không cần sửa riêng lẻ.

### 6.2 3 lỗi kỹ thuật nhỏ đã biết từ trước, xác nhận vẫn còn tồn tại (không phải phát hiện mới)

1. **Không phân trang** — `ReconciliationService::outstandingBalances()` dùng `->get()` tải toàn bộ booking có nợ, không giới hạn/phân trang. Hiện 12 dòng chưa vấn đề, nhưng sẽ chậm dần khi dữ liệu tăng.
2. **Tổng cộng tính ở frontend** — `resources/js/Pages/Admin/Reconciliation/Index.vue:93`: `totalOutstanding` là `.reduce()` trên toàn bộ mảng nhận từ backend, không phải số tổng hợp từ server. Chỉ đúng vì mục (1) chưa phân trang — nếu sau này thêm phân trang mà quên sửa chỗ này, tổng sẽ chỉ còn tính đúng cho 1 trang.
3. **Trùng lặp logic dấu thanh toán** — `computeBalance()` (dòng 199-208) tự viết lại y hệt logic phân loại Đặt cọc/Thanh toán/Hoàn tiền/Điều chỉnh đã có sẵn trong `BookingService::paymentSummary()` (docblock tự ghi "Formula matches... exactly") thay vì gọi lại hàm chung — 2 nơi giữ logic tài chính giống hệt nhau, dễ lệch nhau nếu sau này 1 trong 2 nơi được sửa mà quên sửa nơi còn lại.

Không khuyến nghị sửa ngay 3 mục này — mức độ ảnh hưởng thấp so với mục 1-4, và trước đây Product Owner đã quyết định trang Đối soát hiện tại là đủ dùng, chỉ ghi nhận lại để có trong hồ sơ.

---
*Báo cáo này tổng hợp đầy đủ cả 3 mục trong `docs/Ghichuchosua.txt`.*
