# Phase 3.1 — Payment Foundation Report

## 1. Objective

Implement Payment Foundation cho Lastella PMS: deposit tracking, payment tracking, refund tracking, adjustment tracking, payment history, payment summary, balance due calculation.

Không bao gồm: Folio, Invoice, Accounting ledger, Service charges, Dynamic pricing, Multi-property.

---

## 2. Files Reviewed

### Backend
- `app/Models/BookingPayment.php`
- `app/Models/Booking.php`
- `app/Enums/PaymentType.php`
- `app/Enums/PaymentMethod.php`
- `app/Enums/BookingStatus.php`
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

### Frontend
- `resources/js/Pages/Admin/Bookings/Show.vue`
- `resources/js/Support/vietnameseLabels.js`

---

## 3. Files Changed

> **Chưa có thay đổi.** Step 1 chỉ là phân tích. Implementation chưa bắt đầu.

---

## 4. Database Changes

> Không có migration mới.
> Bảng `booking_payments` đã tồn tại và đủ schema cho Phase 3.1.

---

## 5. Backend Changes

> Chưa implement. Kế hoạch:

| File | Thay đổi dự kiến |
|---|---|
| `BookingPaymentService` | Fix bug addDeposit + refund validation + deletePayment() |
| `BookingService` | Mở rộng paymentSummary() thêm 4 breakdown fields |
| `BookingPaymentPolicy` | Thêm delete() method |
| `BookingPaymentController` | Thêm destroy() + IDOR guard |
| `routes/web.php` | Thêm DELETE route |
| `RolePermissionSeeder` | Thêm payment.delete permission |
| `BookingController` | Thêm can_delete vào payment payload |

---

## 6. Frontend Changes

> Chưa implement. Kế hoạch:

| File | Thay đổi dự kiến |
|---|---|
| `Show.vue` | Nút xóa trong payment table + breakdown summary rows |

---

## 7. Business Logic

> Chưa implement. Kế hoạch:

**Bug fix:** `addDeposit()` không được downgrade booking status khi đang ở operational states (CheckedIn, CheckedOut, PartiallyCheckedIn, PartiallyCheckedOut).

**Refund validation:** Refund amount không được vượt quá current paid_total.

**Delete payment:** ADMIN được xóa mọi lúc. MANAGER chỉ được xóa payment tạo trong ngày.

**Payment Summary breakdown:**
```
total_deposit    = SUM(Deposit + AdditionalDeposit)
total_payment    = SUM(RoomPayment + ServicePayment)
total_refund     = SUM(Refund)            -- số dương để display
total_adjustment = SUM(Adjustment)
paid_total       = total_deposit + total_payment + total_adjustment - total_refund
remaining_balance = expected_total - paid_total
```

---

## 8. Authorization

> Chưa implement. Kế hoạch:

| Permission | Trạng thái | Roles |
|---|---|---|
| `payment.create` | ĐÃ CÓ | ADMIN, MANAGER, RECEPTION, ACCOUNTANT |
| `payment.delete` | **THÊM MỚI** | ADMIN (mọi lúc), MANAGER (same-day only) |

---

## 9. Tests Added

> Chưa có. Kế hoạch:

**`tests/Feature/PaymentCrudTest.php`**
- admin can delete payment
- manager can delete todays payment
- manager cannot delete old payment
- reception cannot delete payment
- cannot delete payment belonging to different booking (IDOR)
- cannot add payment with zero amount
- cannot add payment with invalid type
- refund cannot exceed paid total
- deposit does not downgrade checked-in booking status
- delete payment logs audit entry
- payment summary includes breakdown fields

**`tests/Unit/Policies/PaymentPolicyTest.php`**
- admin can delete any payment
- manager can delete todays payment
- manager cannot delete old payment
- reception cannot delete payment
- accountant cannot delete payment

---

## 10. Test Results

> Trước khi implement:

```
Tests: 61 passed (440 assertions)
Duration: 24.65s
Status: ALL PASS
```

> Sau implement: chưa chạy.

---

## 11. Git Diff Summary

```
git diff --stat
(clean — chưa có thay đổi)
```

---

## 12. Risks / Notes

- **Bug addDeposit**: downgrade booking status về Deposited khi booking đang CheckedIn — phải fix trước.
- **Refund validation**: hiện tại không có guard, có thể hoàn tiền nhiều hơn đã thu.
- **IDOR risk**: DELETE endpoint phải verify payment.booking_id === booking.id.
- **61 tests hiện tại**: phải pass sau mỗi bước implement.
- **Convention**: dùng `payment.delete` (singular) không phải `payments.delete` (plural) — theo convention hiện tại của project.
- **Không tạo bảng payments mới**: dùng `booking_payments` đã có — tạo bảng mới sẽ phá vỡ toàn bộ tests.
- **Không implement Update**: financial records không được edit, chỉ xóa và tạo lại.

---

## 13. Ready For Review

**Status: PENDING IMPLEMENTATION**

Step 1 analysis hoàn tất. Chờ confirmation để bắt đầu implement.
