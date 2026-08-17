# Unpaid Checkout — Architecture Review (Audit)

**Ngày:** 2026-08-17 · **Nhánh:** `phase-3` · **Nguồn:** `docs/Prompt_2.txt`

## 1. Chặn hiện tại — vị trí chính xác

`App\Services\BookingService::finaliseBookingCheckout()` (dòng ~683-688, cũ):

```php
$balanceDue = (float) bcsub((string) $totalCharges, (string) $paidTotal, 2);
if ($balanceDue > 0) {
    throw new OutstandingBalanceException($balanceDue); // ADR-40
}
$this->folios->autoCloseFolio($lockedFolio, $actingUser);
```

Được gọi bên trong `StayService::checkOut()` (khi đây là stay cuối cùng của booking), trong CÙNG một `DB::transaction()` — nên throw sẽ rollback toàn bộ (kể cả việc set `actual_checkout_at`, room release...).

**2 điểm bắt exception** (UI-facing):
- `StayController::checkOut()` — redirect sang tab "Tài chính" kèm flash `error`.
- `RoomOperationsController::checkOut()` (bulk, Sơ đồ thao tác) — gom lỗi theo `stay_id`.

**2 điểm chặn phía UI** (ADR-52, chỉ để UX, server vẫn là nguồn xác thực):
- `Bookings/Partials/RoomBoardPanel.vue` — nút "Trả phòng"/"Trả tất cả phòng" bị disable (span xám) khi `paymentSummary.balance_due > 0` và đây là stay cuối.

**Chặn payment SAU checkout** (khác vị trí, cùng gốc rễ terminal-status):
`BookingPaymentService::addPayment()` (và `addDeposit`/`addRefund`/`deletePayment`) gọi `assertBookingNotTerminal()` → `BookingStatus::isTerminal()` gồm `Cancelled|NoShow|CheckedOut` → **mọi payment vào booking đã CheckedOut đều bị chặn**, kể cả một payment thanh toán nợ hợp lệ. Đây là chặn thứ hai, độc lập với chặn checkout, và PHẢI được nới lỏng riêng cho mục XI/XII hoạt động được.

## 2. Kiến trúc đã có sẵn — tái sử dụng, không xây mới

- **`App\Services\ReconciliationService::outstandingBalances()`** — đã liệt kê MỌI booking có `balance_due > 0` hotel-wide, không lọc theo status → booking CheckedOut còn nợ sẽ tự động xuất hiện ngay khi được tạo ra, không cần sửa gì thêm (chỉ thiếu cột "ngày trả phòng" — bổ sung).
- **`ReconciliationService::discrepancies()`** — đã có sẵn type `CHECKED_OUT_OUTSTANDING_BALANCE` ("Checked-out booking still carrying a positive balance_due") — đúng 100% yêu cầu mục VII, nhưng **chưa từng kích hoạt được trong thực tế** vì checkout luôn bị chặn trước khi trạng thái này có thể tồn tại.
- **`Admin/Reconciliation/Index.vue`** — đã render đầy đủ cả 2 bảng (Tồn nợ + Bất thường tài chính) với badge "Đã trả phòng còn nợ", link về Booking, breakdown Tổng phí/Đã TT/Còn nợ.
- **`FolioService::autoCloseFolio()`** — idempotent (no-op nếu đã Closed), có docblock từ trước ghi rõ ý định "so BookingPaymentService can trigger auto-close when balance reaches zero after a payment" — nhưng **chưa từng được implement ở phía BookingPaymentService**. Đây chính là cơ chế cần hoàn thiện cho mục XI.
- **`StayPolicy::checkOut()`** — thuần permission-based (`stay.checkout`), không có logic liên quan số dư → mục IX (permission) đã thoả mãn kiến trúc hiện tại, không cần sửa.
- **`AddPaymentForm.vue`** hiển thị/ẩn thuần theo permission `payment.create` (`BookingController::permissions()`), không theo `booking.status` → form nhập thanh toán **đã sẵn sàng hoạt động** trên booking CheckedOut ngay khi backend cho phép, không cần sửa UI.
- **Cơ chế idempotent checkout** đã tồn tại: `StayService::checkOut()` kiểm tra `actual_checkout_at !== null` → `ValidationException` nếu gọi lại cho stay đã trả phòng. Mục XVI coi như đã thoả mãn, không cần thêm dedup logic mới.
- **`FinalCheckoutConfirmationRequiredException`** (ADR-55) — dialog "Xác nhận trả phòng cuối cùng" đã tồn tại sẵn cho MỌI checkout stay cuối cùng (không phụ thuộc số dư) → là vị trí tự nhiên để gắn cảnh báo công nợ (mục VIII) thay vì xây luồng confirm mới.

## 3. Rủi ro/độ phức tạp phát hiện

- **`RoomOperationsBoardService`'s "outstanding" field là PROJECTION** (từ `PaymentProjectionService`, ước tính), KHÔNG phải `balance_due` thực đã post trên Folio — không được dùng số này cho cảnh báo tài chính chính thức (Financial Separation principle). Cảnh báo mục VIII phải tính `balance_due` thật, giống công thức `BookingService::paymentSummary()`/`finaliseBookingCheckout()`/`ReconciliationService::computeBalance()` (công thức này đã bị lặp lại ở 3 nơi từ trước — không phải do sprint này gây ra, không mở rộng phạm vi để hợp nhất lại, chỉ tái sử dụng `paymentSummary()` public sẵn có cho các điểm hiển thị mới).
- **Night Audit / PER_NIGHT charges trước checkout (mục XIII)**: `StayService::checkOut()` đã chủ động post late-checkout fee (`lateCheckoutJob`) và charge kiểm phòng hoàn tất (`checkoutInspections->postCompletedChargesAtCheckout`) NGAY tại thời điểm checkout — đây chính là "final posting mechanism" đã tồn tại và được tái sử dụng nguyên vẹn (không đổi). Tuy nhiên, phí phòng đêm hiện tại/PER_NIGHT unified services của CHÍNH ngày trả phòng phụ thuộc Night Audit đã chạy cho ngày đó — Night Audit ở hệ thống này **chỉ chạy thủ công, chưa từng chạy tự động trên production** (đã ghi nhận từ phase trước trong session này). Đây là **defect độc lập, đã biết từ trước, KHÔNG thuộc phạm vi sprint này** — không rewrite Night Audit; ghi nhận rủi ro tại đây theo đúng chỉ dẫn mục XIII ("ghi nhận nhưng không rewrite Night Audit mù").

## 4. Kết luận Audit

Không cần dashboard công nợ mới, không cần bảng/migration mới, không cần financial ledger thứ hai. Toàn bộ thay đổi cần thiết là: (1) bỏ throw chặn checkout + giữ Folio Open khi còn nợ, (2) nới `addPayment()` cho booking CheckedOut + auto-close khi về 0, (3) UI: bỏ disable, thêm cảnh báo đúng nội dung mục VIII vào dialog xác nhận đã có sẵn, (4) bổ sung cột "ngày trả phòng" cho Đối soát, (5) audit trail tối thiểu trên StayEvent đã có.
