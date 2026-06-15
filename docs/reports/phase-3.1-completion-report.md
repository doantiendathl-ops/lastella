# Phase 3.1 — Payment Foundation: Completion Report

## Objective

Hoàn thiện Payment Foundation cho Lastella PMS: delete flow, refund validation, payment summary breakdown, phân quyền `payment.delete`, và frontend payment tab với nút xóa theo role.

---

## Files Changed

### Modified (8 files)

| File | Loại thay đổi |
|---|---|
| `app/Services/BookingPaymentService.php` | Fix deposit downgrade bug + refund validation + `deletePayment()` + `calculatePaidTotal()` |
| `app/Services/BookingService.php` | Mở rộng `paymentSummary()` với 4 breakdown fields |
| `app/Policies/BookingPaymentPolicy.php` | Thêm `delete()` method với time-based rule cho MANAGER |
| `app/Http/Controllers/Admin/Booking/BookingPaymentController.php` | Thêm `destroy()` + IDOR guard |
| `app/Http/Controllers/Admin/Booking/BookingController.php` | Thêm `can_delete` vào payment payload + `deletePayment` permission flag |
| `database/seeders/RolePermissionSeeder.php` | Thêm `payment.delete` permission, assign cho ADMIN + MANAGER |
| `routes/web.php` | Thêm `DELETE /admin/bookings/{booking}/payments/{payment}` |
| `resources/js/Pages/Admin/Bookings/Show.vue` | Nút xóa, breakdown summary, refund color, conditional columns |

### Created (2 test files)

| File | Mô tả |
|---|---|
| `tests/Feature/PaymentCrudTest.php` | 15 feature tests cho payment CRUD flow |
| `tests/Unit/Policies/PaymentPolicyTest.php` | 5 unit tests cho `BookingPaymentPolicy::delete` |

---

## Tests Added

### Unit (5)
- `test_admin_can_delete_any_payment`
- `test_manager_can_delete_todays_payment`
- `test_manager_cannot_delete_old_payment`
- `test_reception_cannot_delete_payment`
- `test_accountant_cannot_delete_payment`

### Feature (15)
- `test_admin_can_delete_payment`
- `test_manager_can_delete_todays_payment`
- `test_manager_cannot_delete_old_payment`
- `test_reception_cannot_delete_payment`
- `test_cannot_delete_payment_belonging_to_different_booking`
- `test_cannot_add_payment_with_zero_amount`
- `test_cannot_add_payment_with_invalid_payment_type`
- `test_cannot_add_payment_with_invalid_payment_method`
- `test_refund_cannot_exceed_paid_total`
- `test_refund_within_paid_total_succeeds`
- `test_deposit_does_not_downgrade_checked_in_booking_status`
- `test_deposit_does_not_downgrade_checked_out_booking_status`
- `test_delete_payment_logs_audit_entry`
- `test_payment_summary_includes_breakdown_fields`
- `test_booking_show_exposes_can_delete_payment_flag`

---

## Final Test Results

```
Tests:    81 passed (516 assertions)
Duration: 45.97s
Status:   ALL PASS

Breakdown:
- Pre-existing tests:  61 passed (0 regressions)
- New tests (Phase 3.1): 20 passed (15 Feature + 5 Unit)
```

---

## Git Diff Summary

```
8 files changed, 142 insertions(+), 12 deletions(-)

app/Http/Controllers/Admin/Booking/BookingController.php        |   2 ++
app/Http/Controllers/Admin/Booking/BookingPaymentController.php |  13 +++++++++++++
app/Policies/BookingPaymentPolicy.php                           |  13 +++++++++++++
app/Services/BookingPaymentService.php                          |  42 ++++++++++++++++++++++++++++++++++++++++++-
app/Services/BookingService.php                                 |  25 +++++++++++++++++-------
database/seeders/RolePermissionSeeder.php                       |   2 ++
resources/js/Pages/Admin/Bookings/Show.vue                      |  56 ++++++++++++++++++++++++++++++++++++++++++++++++++++----
routes/web.php                                                  |   1 +
```

---

## Business Logic Delivered

### 1. Fix: Deposit không downgrade booking status
Trước: `addDeposit()` set `status → Deposited` cho mọi booking không bị Cancel, kể cả `CheckedIn`.
Sau: Chỉ update status khi booking đang ở một trong `{Draft, PendingAssignment, PartiallyAssigned, FullyAssigned, Held}`.

### 2. Refund validation
`addRefund()` tính `currentPaidTotal` từ tất cả payment records trước khi thêm refund. Nếu `refund.amount > paid_total` → ném `ValidationException` với message tiếng Việt.

### 3. Payment delete flow
- IDOR guard: controller verify `payment.booking_id === booking.id` sau khi policy authorize.
- Audit log tự động qua `AuditObserver` đã observe `BookingPayment`.
- Flash success redirect về tab payments.

### 4. Payment summary breakdown
```
total_deposit    = SUM(Deposit + AdditionalDeposit)
total_payment    = SUM(RoomPayment + ServicePayment)
total_refund     = SUM(Refund)              -- số dương, display với dấu −
total_adjustment = SUM(Adjustment)
paid_total       = total_deposit + total_payment + total_adjustment − total_refund
remaining_balance = expected_total − paid_total
```
Backward compatible: `expected_total`, `paid_total`, `remaining_balance` giữ nguyên.

---

## Authorization Changes

### Permission mới
| Permission | ADMIN | MANAGER | RECEPTION | ACCOUNTANT | SALES | HOUSEKEEPING |
|---|---|---|---|---|---|---|
| `payment.delete` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |

### Policy rule
| Role | Điều kiện |
|---|---|
| ADMIN | Được xóa mọi payment, mọi lúc |
| MANAGER | Chỉ xóa payment được tạo trong ngày (`isToday()`) |
| Các role khác | Không được xóa |

---

## Frontend Changes

**`resources/js/Pages/Admin/Bookings/Show.vue`:**
- `deletePayment(payment)`: `window.confirm` tiếng Việt → `router.delete()` với `preserveScroll: true`.
- `remaining_balance` card: màu đỏ (`text-coral`) nếu > 0, màu xanh (`text-pine`) nếu = 0.
- Breakdown grid 4 cột: `total_deposit`, `total_payment`, `total_refund` (đỏ + `−`), `total_adjustment`.
- Cột `Thao tác` trong bảng payment (chỉ render nếu `can.deletePayment`).
- Nút `Trash2` icon (chỉ hiện nếu `payment.can_delete`).
- Empty state `colspan` cập nhật theo `can.deletePayment`.

---

## Risks Remaining

- **No Update endpoint**: Thiết kế append-only — xóa và tạo lại nếu sai. Phù hợp với audit trail.
- **LF/CRLF warning**: Git báo line-ending mismatch trên 8 files — không ảnh hưởng, do môi trường Windows.
- **Manager time-window**: Chỉ xóa trong ngày — nếu cần window rộng hơn (ví dụ 24h thực), cần sửa policy.
- **Float/integer JSON**: `paymentSummary` trả PHP float, serialize thành integer nếu không có phần thập phân — behavior chuẩn PHP, tests đã được viết để match.

---

## Ready For Production

- ✅ 81/81 tests pass, 516 assertions, 0 regressions
- ✅ Backward compatible (no breaking changes to existing API shape)
- ✅ No new migration needed
- ✅ IDOR guard implemented
- ✅ Audit log hoạt động tự động
- ✅ Role-based delete with time constraint
- ✅ Committed to `master`
