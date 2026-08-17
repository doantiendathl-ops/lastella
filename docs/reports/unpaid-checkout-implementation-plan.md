# Unpaid Checkout — Implementation Plan

**Ngày:** 2026-08-17 · **Nhánh:** `phase-3` · Xem audit đầy đủ: `unpaid-checkout-architecture-review.md`

## Nguyên tắc thiết kế

- Folio vẫn là nguồn sự thật tài chính duy nhất — không tạo bảng/ledger công nợ mới.
- `balance_due` được BẢO TOÀN, không zero-hoá, không rewrite lịch sử charge.
- Đối soát/Bất thường tài chính là DERIVED (tính từ Folio+Payment mỗi lần đọc) — không có cờ "is_outstanding" nào cần đồng bộ tay; nợ tự "biến mất" khỏi 2 bảng ngay khi `balance_due` về 0 qua thanh toán mới, không cần bước "clear anomaly" riêng.
- Room lifecycle không phụ thuộc tài chính (giữ nguyên `autoMarkDirtyOnCheckout`, không đổi).

## Thay đổi Backend

1. `BookingService::finaliseBookingCheckout()`: bỏ throw `OutstandingBalanceException`; khi `balanceDue > 0` → giữ Folio `Open`, booking vẫn chuyển `CheckedOut`; khi `<= 0` → giữ nguyên hành vi cũ (auto-close). Đổi return type `void → float` (trả về balance tại thời điểm checkout) để phục vụ audit trail.
2. `StayService::checkOut()`: nhận giá trị trả về, ghi vào `StayEvent(Checkout)` metadata dưới key `outstanding_balance_at_checkout` — không tạo bảng mới, tái dùng audit log đã có (mục X).
3. `BookingPaymentService`: thêm `assertBookingAcceptsPayment()` (chỉ chặn Cancelled/NoShow) dùng riêng cho `addPayment()`; `addDeposit()/addRefund()/deletePayment()` giữ nguyên hành vi chặn CheckedOut như cũ (ngoài phạm vi yêu cầu). Sau khi insert payment, nếu booking đã CheckedOut và balance về `<= 0` → gọi `FolioService::autoCloseFolio()` (idempotent, tái dùng nguyên vẹn).
4. Xoá `App\Exceptions\OutstandingBalanceException` (không còn nơi throw) + 2 catch-block chết ở `StayController`/`RoomOperationsController`.
5. `RoomOperationsController::checkOut()`: khi gặp `FinalCheckoutConfirmationRequiredException`, tính kèm `balance_due` thật (`BookingService::paymentSummary()`) theo `stay_id`, flash `final_checkout_balances` (key mới, đăng ký trong `HandleInertiaRequests`).
6. `ReconciliationService::outstandingBalances()`: thêm `checkout_at` (max `Stay.actual_checkout_at`) vào row — trường còn thiếu duy nhất so với danh sách mục VI.
7. `ReconciliationController::exportOutstanding()`: thêm cột "Ngày trả phòng" vào CSV.

## Thay đổi Frontend

1. `RoomBoardPanel.vue` (trang chi tiết Booking): bỏ nút disable khi còn nợ (cả single-stay và "Trả tất cả phòng"); thêm cảnh báo đúng nội dung mục VIII vào 2 dialog xác nhận cuối cùng đã có sẵn (ADR-55) + dialog cảnh báo non-final-stay đã có.
2. `RoomOperationsController` bulk path: `RoomOperations/Index.vue` đọc `flash.final_checkout_balances`, gắn `balance_due` vào từng `finalRoom`; `CheckoutFlowDialogs.vue` hiển thị cảnh báo theo từng phòng/booking (bulk có thể gồm nhiều booking khác nhau).

## Rủi ro cần review kỹ trước khi coi là an toàn

- **"Bỏ qua kiểm tra nếu payment không đổi"** không áp dụng ở đây (khác slice trước) — nhưng cần đặc biệt cẩn trọng: guard `addPayment()` mới không được vô tình mở rộng sang `addDeposit`/`addRefund`/`deletePayment` (giữ nguyên `assertBookingNotTerminal()` cho 3 hàm này, viết test khoá riêng).
- Toàn bộ test cũ khẳng định hành vi ADR-40 (chặn cứng) phải được audit và viết lại đúng ngữ nghĩa mới, không được xoá bỏ mù quáng — đã xác định 5 file test bị ảnh hưởng qua `grep OutstandingBalanceException` trên toàn bộ `tests/`.

## Test bắt buộc (mục XVII)

File mới `tests/Feature/UnpaidCheckoutTest.php` — bám sát ví dụ số liệu chính xác trong spec (Total 2.000.000 / Paid 1.500.000 / Outstanding 500.000): zero-balance, outstanding-balance PASS, room released, folio outstanding giữ nguyên, reconciliation liệt kê đúng, discrepancy xuất hiện, later full payment (500k) → balance=0 + rơi khỏi cả 2 bảng, later partial payment (200k) → còn 300k, retry idempotent, permission RECEPTION, audit trail metadata.

Cộng với việc audit + sửa 5 file test hiện có đang khẳng định hành vi CŨ (ADR-40/ADR-53/ADR-56 liên quan payment-blocked).
