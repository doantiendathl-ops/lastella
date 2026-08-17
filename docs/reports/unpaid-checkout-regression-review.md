# Unpaid Checkout — Regression Review

**Ngày:** 2026-08-17 · **Nhánh:** `phase-3`

## Test mới (mục XVII) — `tests/Feature/UnpaidCheckoutTest.php`

10/10 PASS: Zero Balance · Outstanding Balance PASS + room released · Folio outstanding giữ 500k · Reconciliation liệt kê đúng field (booking_code, customer_name, status, checkout_at, balance_due) · Abnormal Information (`CHECKED_OUT_OUTSTANDING_BALANCE`) · Later Full Payment (500k → balance=0, rơi khỏi cả outstanding lẫn discrepancies) · Later Partial Payment (200k → còn 300k, Folio vẫn Open) · Retry idempotent (ValidationException, không duplicate StayEvent) · Permission RECEPTION · Audit trail (`outstanding_balance_at_checkout` trong StayEvent metadata).

## Test cũ đã audit + sửa lại (khẳng định hành vi ADR-40/53/56 cũ)

| File | Test | Trước | Sau |
|---|---|---|---|
| `BookingEngineFoundationTest` | `test_booking_does_not_finalize_checkout_when_balance_remains` | expect `OutstandingBalanceException` | đổi tên `..._finalizes_checkout_with_outstanding_balance_preserved`, assert checkout thành công + Folio Open + balance giữ nguyên |
| `CheckoutInspectionServiceTest` | `test_checkout_rolls_back_inspection_charge_when_a_later_guard_throws` | expect rollback qua OBE | đổi tên `..._commits_inspection_charge_together_with_outstanding_balance`, assert charge + checkout COMMIT cùng nhau |
| `CheckoutIntegrationTest` | `test_checkout_blocked_by_outstanding_balance`, `..._with_partial_payment`, `..._leaves_booking_and_folio_unchanged` | expect OBE / rollback | đổi thành `test_checkout_succeeds_with_full_outstanding_balance`, `..._with_partial_payment_remaining_balance`, `..._does_not_alter_charges_already_posted` |
| `CheckoutIntegrationTest` | `test_add_payment_blocked_on_terminal_booking` | expect `BookingTerminalException` cho CheckedOut | đổi tên `test_add_payment_allowed_on_checked_out_booking`, assert payment thành công; thêm `test_add_payment_still_blocked_on_cancelled_booking` + `test_add_refund_still_blocked_on_checked_out_booking` khoá phạm vi (addDeposit/addRefund/deletePayment KHÔNG đổi) |
| `RoomChargeHotfixTest` | `test_checkout_all_last_stay_blocked_by_obe_when_balance_outstanding` | expect OBE | đổi tên `..._succeeds_with_balance_outstanding`, assert checkout thành công, balance 1.600.000 giữ nguyên |
| `RoomChargeHotfixTest` | `test_checkout_all_http_blocked_when_balance_outstanding` | expect redirect tab=payments + flash error | đổi tên `..._succeeds_when_balance_outstanding`, assert redirect tab=room_map + flash success |
| `CheckoutUiTest` | `test_checkout_with_outstanding_balance_redirects_to_payments_tab_with_flash_error`, `..._does_not_change_booking_status` | expect chặn | đổi thành `..._redirects_to_room_map_and_succeeds`, `..._finalizes_booking_status` |
| `RoomOperationsInspectionGuardTest` | `test_forged_confirmed_true_does_not_bypass_outstanding_balance_guard` | expect vẫn chặn dù forge `confirmed=true` | đổi tên `test_confirmed_true_succeeds_with_outstanding_balance` — guard này không còn tồn tại theo thiết kế, không phải hồi quy |

Toàn bộ được tìm bằng `grep -r OutstandingBalanceException tests/` (4 file gốc) + phát hiện thêm qua chạy full suite (`CheckoutUiTest`, `RoomOperationsInspectionGuardTest` — không match tên exception trực tiếp nhưng khẳng định hành vi chặn qua flash/HTTP status).

## Kết quả hồi quy toàn bộ (`php artisan test`)

**28 failed / 1343 passed (5154 assertions)** — khớp chính xác baseline có sẵn từ trước phiên làm việc này, **0 lỗi mới**:

| Nhóm | Số lỗi | Ghi chú |
|---|---|---|
| `RoomAvailabilityCheckerTest` | 13 | Có sẵn từ trước, không liên quan checkout |
| `BookingManagementUiTest` (room-board) | 11 | Có sẵn từ trước, không liên quan checkout |
| `DashboardTest` | 3 | Có sẵn từ trước (`ValidationException`) |
| `ReleaseBatchSchemaTest` | 1 | Có sẵn từ trước |

(`LateCheckoutFeeTest`, `PerStayAttributionTest` — 2 flaky test đã biết từ trước, không xuất hiện lần chạy này, đúng với đặc tính không ổn định đã ghi nhận.)

`npm run build`: sạch, không lỗi biên dịch, 2387 module.

## Danh sách hồi quy bắt buộc theo mục II — xác nhận KHÔNG hỏng

- **Booking/Check-in/Check-out**: `CheckoutIntegrationTest`, `CheckoutConfirmationGateTest`, `CheckoutUiTest`, `RoomOperationsInspectionGuardTest` — pass.
- **Folio/Payments**: `CheckoutIntegrationTest` (ADR-44 terminal guard cho charge/addDeposit/addRefund/deletePayment vẫn nguyên vẹn) — pass.
- **Night Audit**: không đổi code, không có test nào trong phạm vi sửa đổi.
- **Reconciliation**: `UnpaidCheckoutTest` xác nhận trực tiếp qua `ReconciliationService`.
- **Unified Services, Room Map, Customer Display, KDS**: không đổi code liên quan; baseline không phát sinh lỗi mới ở các khu vực này.

## Code Review

Dispatch `code-reviewer` subagent trước khi commit (do đây là logic tài chính/checkout — bắt buộc theo chính sách review). Kết quả: **APPROVE**, 0 CRITICAL/HIGH/MEDIUM, 1 LOW (cosmetic): `outstandingBalances()`'s `checkout_at` có thể hiển thị ngày trả phòng của MỘT phòng trong booking `PartiallyCheckedOut` (khách vẫn còn ở phòng khác) — không phải lỗi tài chính/bảo mật. Đã sửa: chỉ trả `checkout_at` khi `booking.status === CheckedOut`; thêm test khoá hành vi này (`test_reconciliation_hides_checkout_date_for_partially_checked_out_booking`). Reviewer cũng tự chạy lại toàn bộ test liên quan (95/95 pass) độc lập với báo cáo của kỹ sư trước, xác nhận: lock order Booking→Folio→BookingPayment không đổi, không có race condition khi 2 payment đồng thời settle cùng balance, ranh giới addPayment/addDeposit/addRefund/deletePayment được enforce đúng trong code (không chỉ trong comment), số dư audit trail lấy từ đúng 1 lần tính có lock (không tính lại lần 2), UI chỉ mang tính advisory còn gate thật nằm ở server.

## READY FOR COMMIT = YES

## READY FOR PRODUCTION MIGRATION = NO

(Không deploy production theo đúng chỉ dẫn mục XX/XXI — cần review thủ công + kế hoạch rollout riêng, đặc biệt vì đây là thay đổi đảo ngược một business rule tài chính đã có ADR ghi nhận từ trước.)
