# Phase 3.1 — Payment Foundation Implementation Report

## 1. Objective

Implement Payment Foundation cho Lastella PMS: hoàn thiện delete flow, refund validation, payment summary breakdown, phân quyền delete, và frontend payment tab với nút xóa.

Không bao gồm: Folio, Invoice, Accounting ledger, Dynamic pricing, Multi-property.

---

## 2. Files Reviewed

- `app/Models/BookingPayment.php`
- `app/Models/Booking.php`
- `app/Enums/PaymentType.php`, `PaymentMethod.php`, `BookingStatus.php`, `AuditAction.php`
- `app/Http/Controllers/Admin/Booking/BookingPaymentController.php`
- `app/Http/Controllers/Admin/Booking/BookingController.php`
- `app/Http/Requests/Booking/StoreBookingPaymentRequest.php`
- `app/Policies/BookingPaymentPolicy.php`
- `app/Services/BookingPaymentService.php`
- `app/Services/BookingService.php`
- `app/Providers/AppServiceProvider.php`
- `app/Observers/AuditObserver.php`
- `database/migrations/2026_02_01_000020_create_booking_payments_table.php`
- `database/seeders/RolePermissionSeeder.php`
- `database/factories/BookingPaymentFactory.php`
- `routes/web.php`
- `tests/Feature/BookingManagementUiTest.php`
- `tests/Feature/BookingEngineFoundationTest.php`
- `tests/Unit/Policies/RoomPolicyTest.php`
- `resources/js/Pages/Admin/Bookings/Show.vue`
- `resources/js/Support/vietnameseLabels.js`

---

## 3. Files Changed

### Modified (8 files)

| File | Loại thay đổi |
|---|---|
| `app/Services/BookingPaymentService.php` | Fix bug + refund validation + deletePayment() |
| `app/Services/BookingService.php` | Mở rộng paymentSummary() với 4 breakdown fields |
| `app/Policies/BookingPaymentPolicy.php` | Thêm delete() method |
| `app/Http/Controllers/Admin/Booking/BookingPaymentController.php` | Thêm destroy() + IDOR guard |
| `app/Http/Controllers/Admin/Booking/BookingController.php` | Thêm can_delete vào payment payload + deletePayment permission |
| `database/seeders/RolePermissionSeeder.php` | Thêm payment.delete permission |
| `routes/web.php` | Thêm DELETE route |
| `resources/js/Pages/Admin/Bookings/Show.vue` | Nút xóa + breakdown summary + refund color |

### Created (2 files)

| File | Mô tả |
|---|---|
| `tests/Feature/PaymentCrudTest.php` | 15 feature tests cho payment CRUD |
| `tests/Unit/Policies/PaymentPolicyTest.php` | 5 unit tests cho PaymentPolicy::delete |

### Deleted

Không có file bị xóa.

---

## 4. Database Changes

**Không có migration mới.**

Bảng `booking_payments` đã tồn tại với schema đầy đủ. Không thay đổi schema.

---

## 5. Backend Changes

### `BookingPaymentService`
- **Fix bug `addDeposit()`**: Thêm guard `DEPOSITABLE_STATUSES` — chỉ cập nhật booking status về `Deposited` khi booking đang ở trạng thái trước operational (Draft, PendingAssignment, PartiallyAssigned, FullyAssigned, Held). Trước đây, booking đang `CheckedIn` bị downgrade về `Deposited` khi thêm deposit.
- **`addRefund()` validation**: Thêm kiểm tra `refund.amount <= current_paid_total`. Ném `ValidationException` với message tiếng Việt nếu vi phạm.
- **`deletePayment(BookingPayment $payment)`**: Method mới. Xóa payment record. Audit log tự động qua `AuditObserver` đã có sẵn.
- **`calculatePaidTotal()`**: Private helper để tính net paid total từ tất cả payment records.

### `BookingService`
- **`paymentSummary()`**: Mở rộng thêm 4 fields mới: `total_deposit`, `total_payment`, `total_refund`, `total_adjustment`. Giữ nguyên 3 fields cũ (`expected_total`, `paid_total`, `remaining_balance`) để không phá vỡ tests hiện có.

### `BookingPaymentPolicy`
- **`delete(User $user, BookingPayment $payment)`**: Method mới. ADMIN được xóa mọi lúc. MANAGER với `payment.delete` permission chỉ được xóa payment tạo trong ngày (`isToday()`). Các role khác trả về `false`.

### `BookingPaymentController`
- **`destroy(Booking $booking, BookingPayment $payment)`**: Method mới. `authorize('delete', $payment)` → IDOR guard (`$payment->booking_id !== $booking->id` → abort 403) → `deletePayment()` → redirect với flash success.

### `BookingController`
- **`bookingPayload()`**: Thêm `can_delete` vào mỗi payment item trong payments array.
- **`permissions()`**: Thêm `deletePayment` flag.

### `RolePermissionSeeder`
- Thêm `payment.delete` vào `PERMISSIONS` array.
- Assign cho `ADMIN` (qua syncPermissions tất cả permissions).
- Assign cho `MANAGER` (thêm vào permissions list của MANAGER).
- **Không assign** cho RECEPTION, ACCOUNTANT, SALES, HOUSEKEEPING.

### `routes/web.php`
- Thêm: `DELETE /admin/bookings/{booking}/payments/{payment}` → `BookingPaymentController@destroy` → `admin.bookings.payments.destroy`

---

## 6. Frontend Changes

### `resources/js/Pages/Admin/Bookings/Show.vue`

**Script:**
- Thêm `deletePayment(payment)` function: `window.confirm` → `router.delete(...)` với `preserveScroll: true`.

**Template — Tab Payments:**
- **Summary cards**: `remaining_balance` hiển thị màu đỏ (`text-coral`) nếu > 0, màu xanh (`text-pine`) nếu = 0.
- **Breakdown section**: Grid 4 cột mới bên dưới 3 summary cards — hiển thị `total_deposit`, `total_payment`, `total_refund` (màu đỏ + dấu `−`), `total_adjustment`.
- **Payment table header**: Thêm cột `Thao tác` (chỉ render nếu `can.deletePayment`).
- **Payment table rows**: Refund amount hiển thị màu đỏ + dấu `−`. Nút xóa `Trash2` icon (chỉ hiện nếu `payment.can_delete`).
- **Empty state**: `colspan` cập nhật theo `can.deletePayment`.

---

## 7. Business Logic

### Fix: Deposit không downgrade booking status
Trước đây `addDeposit()` sẽ set status → `Deposited` cho mọi booking không bị Cancel, kể cả booking đang `CheckedIn`. Giờ chỉ update khi status thuộc `{Draft, PendingAssignment, PartiallyAssigned, FullyAssigned, Held}`.

### Refund validation
`addRefund()` tính `currentPaidTotal` bằng cách sum tất cả payment records hiện tại (deposit + payment + adjustment − refund). Nếu refund amount > paid_total, ném `ValidationException`.

### Payment Summary breakdown
```
total_deposit    = SUM(Deposit + AdditionalDeposit)
total_payment    = SUM(RoomPayment + ServicePayment)
total_refund     = SUM(Refund)            -- số dương để display
total_adjustment = SUM(Adjustment)
paid_total       = total_deposit + total_payment + total_adjustment - total_refund
remaining_balance = expected_total - paid_total
```

### Delete payment
IDOR guard: controller verify `payment.booking_id === booking.id` trước khi xóa. Audit log tự động qua `AuditObserver` đã observe `BookingPayment`.

---

## 8. Authorization

### Permissions

| Permission | Trước | Sau |
|---|---|---|
| `payment.create` | ĐÃ CÓ | Không thay đổi |
| `payment.delete` | Không có | **THÊM MỚI** |

### Role Matrix

| Role | `payment.delete` | Policy delete() |
|---|---|---|
| ADMIN | ✅ | Được xóa mọi payment mọi lúc |
| MANAGER | ✅ | Được xóa payment tạo trong ngày |
| RECEPTION | ❌ | Không được xóa |
| ACCOUNTANT | ❌ | Không được xóa |
| SALES | ❌ | Không được xóa |
| HOUSEKEEPING | ❌ | Không được xóa |

---

## 9. Tests Added

### `tests/Unit/Policies/PaymentPolicyTest.php` (5 tests)
- `test_admin_can_delete_any_payment`
- `test_manager_can_delete_todays_payment`
- `test_manager_cannot_delete_old_payment`
- `test_reception_cannot_delete_payment`
- `test_accountant_cannot_delete_payment`

### `tests/Feature/PaymentCrudTest.php` (15 tests)
- `test_admin_can_delete_payment`
- `test_manager_can_delete_todays_payment`
- `test_manager_cannot_delete_old_payment`
- `test_reception_cannot_delete_payment`
- `test_cannot_delete_payment_belonging_to_different_booking` (IDOR guard)
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

## 10. Test Results

```
Tests:    81 passed (516 assertions)
Duration: 34.47s
Status:   ALL PASS
```

Breakdown:
- Tests cũ: 61 passed (giữ nguyên 100%)
- Tests mới: 20 passed (15 Feature + 5 Unit)

---

## 11. Git Diff Summary

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

## 12. Risks / Notes

- **LF/CRLF warning**: Git báo line-ending mismatch trên 8 files. Không ảnh hưởng functionality — đây là Git config của môi trường Windows, không phải lỗi.
- **Không có migration**: Schema `booking_payments` đã đủ, không cần migration mới.
- **`payment.delete` seeder**: Seeder dùng `findOrCreate` — chạy lại trên DB production an toàn, không tạo duplicate.
- **Float vs Integer JSON**: `paymentSummary` trả về PHP float, JSON encoder serialize thành integer nếu không có phần thập phân — behavior bình thường trong PHP, tests đã được viết để match integer values.

### TODOs cho Phase tiếp theo
- Phase 3.2: Folio Foundation — Charges system
- Phase 3.3: Service Charges
- Cân nhắc thêm export payment history (PDF/Excel) nếu có yêu cầu
- Có thể thêm filter theo payment_type và date range trong payment history tab

---

## 13. Ready For Review

**Status: READY FOR REVIEW**

- ✅ 81/81 tests pass
- ✅ 0 regressions
- ✅ No migration needed
- ✅ Backward compatible
- ✅ Audit log hoạt động
- ✅ IDOR guard implemented
- ✅ Chờ approval trước khi commit
