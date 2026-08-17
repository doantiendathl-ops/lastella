# Unpaid Checkout — Implementation Report

**Ngày:** 2026-08-17 · **Nhánh:** `phase-3` (chưa push, chưa commit tại thời điểm viết báo cáo)

## Implementation Status: **COMPLETE**

## Current Blocker Removed

`BookingService::finaliseBookingCheckout()` — đoạn `if ($balanceDue > 0) { throw new OutstandingBalanceException(...); }` (ADR-40). Đã xoá hoàn toàn class `OutstandingBalanceException` (không còn nơi throw) cùng 2 catch-block chết ở `StayController`/`RoomOperationsController`.

Chặn thứ hai (độc lập, cùng gốc): `BookingPaymentService::addPayment()` từng chặn MỌI payment vào booking `isTerminal()` (gồm CheckedOut) — đã nới lỏng riêng cho `addPayment()` (không đổi `addDeposit`/`addRefund`/`deletePayment`).

## Checkout Flow (mới)

`finaliseBookingCheckout()`:
- Balance `<= 0` → auto-close Folio (không đổi so với trước).
- Balance `> 0` → **không throw**, Folio giữ `Open`, Booking vẫn chuyển `CheckedOut`, trả về `balanceDue` cho caller ghi audit trail.

UI: nút "Trả phòng"/"Trả tất cả phòng" không còn bị disable khi còn nợ. Dialog xác nhận cuối cùng (ADR-55, đã có sẵn — không tạo dialog mới) hiển thị thêm cảnh báo đúng nội dung mục VIII khi `balance_due > 0`, ở cả 2 điểm vào: trang chi tiết Booking (`RoomBoardPanel.vue`) và Sơ đồ thao tác (`CheckoutFlowDialogs.vue`, nhận `balance_due` thật qua flash `final_checkout_balances` mới).

## Outstanding Balance — Cách bảo toàn

Không có cột/bảng mới. `balance_due` luôn là `Folio.total_charges - payments`, tính lại mỗi lần đọc (`BookingService::paymentSummary()`), không snapshot, không zero-hoá tại checkout. Folio ở trạng thái `Open` là tín hiệu duy nhất "còn giao dịch tài chính đang mở" — không có cờ boolean "is_outstanding" nào cần đồng bộ.

## Reconciliation — Cách hiển thị

Tái sử dụng nguyên vẹn `/admin/reconciliation` (`ReconciliationService::outstandingBalances()` + `discrepancies()`, đã tồn tại từ trước, chưa từng kích hoạt được vì checkout luôn bị chặn). Bổ sung duy nhất: cột `checkout_at` (max `Stay.actual_checkout_at`) vào cả bảng UI lẫn CSV export — trường còn thiếu duy nhất so với danh sách yêu cầu mục VI.

## Abnormal Information — Cách cảnh báo

`discrepancies()`'s type `CHECKED_OUT_OUTSTANDING_BALANCE` (đã có sẵn, đã map badge "Đã trả phòng còn nợ" ở `Reconciliation/Index.vue` từ trước) — không tạo module cảnh báo thứ hai, không duplicate.

## Later Payment — Cách thu nợ sau checkout

`BookingPaymentService::addPayment()` cho phép trên booking `CheckedOut`. Sau khi insert payment, nếu booking đã `CheckedOut` và balance mới `<= 0` → gọi `FolioService::autoCloseFolio()` (idempotent, tái dùng nguyên vẹn — hoàn thiện đúng ý định đã ghi trong docblock cũ của hàm này). Không cần reopen stay/room. Partial payment: balance giảm đúng phần đã trả, KHÔNG mark paid sớm, Đối soát vẫn hiển thị phần còn lại.

## Room Lifecycle — Xác nhận độc lập tài chính

Không đổi gì trong `RoomAssignmentService`/`HousekeepingService`. `autoMarkDirtyOnCheckout()` vẫn chạy y hệt luồng cũ tại checkout, không phụ thuộc `balance_due`. Test `test_outstanding_balance_checkout_passes_and_room_is_released` xác nhận phòng không còn `RoomStatus::Occupied` sau checkout dù còn nợ.

## Financial Integrity — Xác nhận không xóa/duplicate

- Không có ledger thứ hai; `getFolioTotal()` trước/sau checkout không đổi (`test_checkout_with_balance_does_not_alter_charges_already_posted`).
- Payment-sign formula (`Deposit/AdditionalDeposit/RoomPayment/ServicePayment/Adjustment: +`, `Refund: -`) không bị nhân bản thêm — tái dùng `BookingService::paymentSummary()`/`FolioService::getFolioTotal()` sẵn có cho mọi điểm hiển thị mới (dialog cảnh báo, Đối soát, audit trail), không viết công thức riêng lần thứ 4.
- Retry checkout: guard `actual_checkout_at !== null` (đã có từ trước) chặn double-checkout → không tạo 2 lần StayEvent/không double financial movement (`test_checkout_retry_does_not_duplicate_financial_movement`).

## Files Changed

**Backend:**
`app/Services/BookingService.php`, `app/Services/StayService.php`, `app/Services/BookingPaymentService.php`, `app/Services/ReconciliationService.php`, `app/Http/Controllers/Admin/Booking/StayController.php`, `app/Http/Controllers/Admin/RoomOperationsController.php`, `app/Http/Controllers/Admin/ReconciliationController.php`, `app/Http/Middleware/HandleInertiaRequests.php`. Xoá `app/Exceptions/OutstandingBalanceException.php`.

**Frontend:**
`resources/js/Pages/Admin/Bookings/Partials/RoomBoardPanel.vue`, `resources/js/Pages/Admin/RoomOperations/Index.vue`, `resources/js/Pages/Admin/RoomOperations/Partials/CheckoutFlowDialogs.vue`, `resources/js/Pages/Admin/Reconciliation/Index.vue`.

**Test:** `tests/Feature/UnpaidCheckoutTest.php` (mới, 10 test theo đúng mục XVII). Sửa 6 file test cũ khẳng định hành vi ADR-40/ADR-53/ADR-56 cũ: `BookingEngineFoundationTest`, `CheckoutInspectionServiceTest`, `CheckoutIntegrationTest`, `RoomChargeHotfixTest`, `CheckoutUiTest`, `RoomOperationsInspectionGuardTest`.

## Tests: **PASS**

`UnpaidCheckoutTest`: 10/10 pass. Toàn bộ file test bị sửa: pass. Xem `unpaid-checkout-regression-review.md` cho kết quả hồi quy toàn bộ.

## Production Actions Required

Không có action nào cần thực hiện trên production ở bước này — thay đổi chưa deploy, chỉ tồn tại trên `phase-3` local/dev.

## Remaining Risks

- **Night Audit / PER_NIGHT charges trước checkout (mục XIII)**: rủi ro đã biết từ trước (Night Audit chỉ chạy thủ công), KHÔNG thuộc phạm vi sửa của sprint này — xem chi tiết `unpaid-checkout-architecture-review.md` mục 3.
- Công thức tính `balance_due` vẫn lặp lại ở nhiều nơi (kỹ thuật nợ đã biết từ trước, không mở rộng thêm, không thu hẹp trong sprint này).
