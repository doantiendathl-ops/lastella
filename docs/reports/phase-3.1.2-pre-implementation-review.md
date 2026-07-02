# Phase 3.1.2 – Pre-Implementation Review

**Date:** 2026-07-02
**Status:** Awaiting confirmation before implementation

---

## Đồng ý với thiết kế tổng thể

Business rule hợp lý. Confirmation gate + backend enforcement là đúng hướng.
Charge lock sau final checkout là cần thiết về mặt nghiệp vụ.

---

## 3 điểm cần xác nhận trước khi viết code

### 1. "Charge lock" phần lớn đã tồn tại — cần xác định phần nào thực sự mới

Khi `finaliseBookingCheckout()` chạy thành công với balance = 0, folio đã
**tự đóng** (`FolioStatus::Closed`). `addCharge()` hiện đã throw `FolioClosedException`
khi folio closed. Vậy charge lock *trên thực tế* chỉ phát huy tác dụng trong trường hợp:

```
Tất cả phòng đã trả → balance > 0 → OBE → checkout thất bại
```

Nhưng kịch bản đó không xảy ra — OBE đã chặn checkout khi còn dư nợ.
Nếu checkout thành công, folio đã bị close tự động.

**Câu hỏi cần xác nhận:** Charge lock có cần thiết như một state độc lập,
hay chỉ cần là alias cho `booking.status === CHECKED_OUT`?

→ Đề xuất: derive từ booking status, không tạo cột mới.

---

### 2. `confirmed` flag trên checkOutAll — cần làm rõ cơ chế

Với flow checkOutAll hiện tại (N sequential POST requests), backend chỉ biết
stay nào là "last" khi `remainingActive === 0`. Vấn đề:

```
checkOutAll (3 phòng A, B, C):
  POST /checkout A  →  non-final  →  không cần confirmed ✓
  POST /checkout B  →  non-final  →  không cần confirmed ✓
  POST /checkout C  →  FINAL      →  cần confirmed=true
```

Frontend phải biết C là last và gắn `confirmed=true` chỉ vào request đó.
Điều này **hoàn toàn khả thi** — frontend lọc `activeStays.filter(can_check_out)`,
stay cuối cùng trong mảng là final.

Nhưng: Nếu người dùng nhấn **Checkout All**, toàn bộ luồng là final ngay từ đầu.
Confirmation dialog nên hiện **một lần trước khi bắt đầu chuỗi**,
không phải sau N-1 requests.

→ Kế hoạch: dialog hiện trước `doNext(0)`, chỉ gắn `confirmed=true`
vào request cuối cùng trong chuỗi.

---

### 3. "Edit Charge" hiện không tồn tại trong hệ thống

Hiện tại FolioEntry là **immutable sau khi create** (ADR-50).
Không có HTTP endpoint edit charge.
Sửa charge = void cái cũ + tạo cái mới.

Vậy "Edit Charge fails" trong test thực ra là "Void + Add Charge" —
cả hai đều bị block bởi charge lock.

→ Implement theo semantics thực tế này, không tạo edit endpoint mới.

---

## Về label "Hotfix"

Về kỹ thuật đây là **feature addition**, không phải bug fix.
- 3.1.1 sửa code sai (quantity = 1 thay vì nights)
- 3.1.2 thêm behavior mới (confirmation gate + charge lock)

Vẫn thực hiện theo yêu cầu — chỉ ghi nhận để ADR mới phản ánh đúng tính chất.

---

## Kế hoạch implement (sau khi xác nhận)

| Bước | Nội dung |
|------|---------|
| 1 | ADR-55: Checkout Confirmation Gate |
| 2 | ADR-56: Post-Checkout Charge Lock |
| 3 | `CheckOutStayRequest` — thêm `confirmed` field |
| 4 | `StayService::checkOut()` — final checkout gate (reject nếu `confirmed` không có khi last stay) |
| 5 | `FolioService::addCharge()` — charge lock guard (deny nếu booking CHECKED_OUT) |
| 6 | `FolioService::voidEntry()` — charge lock guard (operational entries only, system entries vẫn theo ADR-50) |
| 7 | `RoomBoardPanel.vue` — confirmation dialog trước final checkout và checkOutAll |
| 8 | UI badge "Charges Locked" + disable Add/Void buttons |
| 9 | 10 tests theo spec |

---

## Câu hỏi chờ xác nhận

1. Charge lock: derive từ `booking.status === CHECKED_OUT` hay tạo state riêng?
2. checkOutAll: xác nhận cơ chế gắn `confirmed=true` vào request cuối cùng ổn không?
3. "Edit Charge" = void + add mới — xử lý theo semantics này ổn không?
