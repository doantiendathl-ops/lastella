# Phase 3.2 — Folio Foundation: Handover Document

## 1. What Phase 3.1 Delivered

Phase 3.1 hoàn thiện Payment Foundation — tầng ghi nhận tiền thu từ khách:

| Capability | Trạng thái |
|---|---|
| Ghi nhận payment (Deposit, AdditionalDeposit, RoomPayment, ServicePayment, Refund, Adjustment) | ✅ Có sẵn từ Phase 2, hoạt động tốt |
| Xóa payment (ADMIN mọi lúc, MANAGER trong ngày) | ✅ Mới — Phase 3.1 |
| Refund validation (không vượt paid_total) | ✅ Mới — Phase 3.1 |
| Payment summary breakdown (total_deposit, total_payment, total_refund, total_adjustment) | ✅ Mới — Phase 3.1 |
| Fix deposit không downgrade CheckedIn/CheckedOut | ✅ Mới — Phase 3.1 |
| Audit log tự động cho mọi payment operation | ✅ Qua AuditObserver |
| IDOR guard trên DELETE endpoint | ✅ Mới — Phase 3.1 |

---

## 2. Architecture Decisions (Critical for Phase 3.2)

### Payment ≠ Charge

**Payments** (`booking_payments`) = tiền **khách trả**:
- Deposit, AdditionalDeposit, RoomPayment, ServicePayment, Refund, Adjustment
- Luôn có `payment_method` (Cash, BankTransfer, Card, EWallet, Other)
- Gắn với `Booking`, không gắn với phòng cụ thể

**Charges** (sẽ thêm trong Phase 3.2) = chi phí **phát sinh** tính vào khách:
- Tiền phòng, dịch vụ phát sinh, minibar, phí gia hạn, v.v.
- Gắn với `Folio` (hóa đơn), có thể gắn với phòng hoặc booking
- Không có `payment_method`

### Balance Formula (hiện tại — chỉ có payments)

```
paid_total       = total_deposit + total_payment + total_adjustment − total_refund
remaining_balance = expected_total − paid_total
```

`expected_total` hiện được tính từ `bookingRequirements.room_price × quantity`. Đây là **placeholder** — Phase 3.2 phải thay bằng tổng `Folio charges`.

### Balance Formula (sau Phase 3.2)

```
folio_total   = SUM(folio_charges) − SUM(credit_charges)
paid_total    = SUM(payments) − SUM(refunds)
balance_due   = folio_total − paid_total
```

---

## 3. Existing Structures Phase 3.2 Must Know

### Models

**`App\Models\Booking`**
- `hasMany bookingPayments`
- `hasMany bookingRequirements`
- `hasMany roomAssignments`
- `hasMany stays`

**`App\Models\BookingPayment`**
- `fillable`: booking_id, payment_type, amount, payment_method, payment_at, confirmed_by, note
- `casts`: payment_type → PaymentType enum; payment_at → datetime
- `observe(AuditObserver::class)` — tự động log Created/Updated/Deleted

**`App\Models\Stay`**
- Đại diện cho một phòng thực tế khách ở
- Có `planned_checkin_at`, `planned_checkout_at`, `actual_checkin_at`, `actual_checkout_at`
- Phase 3.2 nên gắn Folio vào Stay nếu tính phí theo phòng

### Enums cần dùng

- `App\Enums\PaymentType` — Deposit, AdditionalDeposit, RoomPayment, ServicePayment, Refund, Adjustment
- `App\Enums\PaymentMethod` — Cash, BankTransfer, Card, EWallet, Other
- `App\Enums\BookingStatus` — có `isTerminal()` method, quan trọng cho charge guard
- `App\Enums\AuditAction` — Created, Updated, Deleted, Restored

### Services

**`BookingPaymentService`** — methods hiện có:
- `addDeposit(Booking, array): BookingPayment`
- `addAdditionalDeposit(Booking, array): BookingPayment`
- `addRoomPayment(Booking, array): BookingPayment`
- `addServicePayment(Booking, array): BookingPayment`
- `addRefund(Booking, array): BookingPayment` — có validation không vượt paid_total
- `addAdjustment(Booking, array): BookingPayment`
- `deletePayment(BookingPayment): void`
- `calculatePaidTotal(Booking): float` — private, tính net từ tất cả payments

**`BookingService`** — `paymentSummary(Booking): array` trả:
```php
[
    'expected_total'    => float,   // ← Phase 3.2: replace với folio_total
    'total_deposit'     => float,
    'total_payment'     => float,
    'total_refund'      => float,
    'total_adjustment'  => float,
    'paid_total'        => float,
    'remaining_balance' => float,   // ← Phase 3.2: = folio_total - paid_total
]
```

### Permissions

| Permission | ADMIN | MANAGER | RECEPTION | ACCOUNTANT | SALES | HOUSEKEEPING |
|---|---|---|---|---|---|---|
| `booking.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| `booking.create` | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ |
| `booking.update` | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ |
| `booking.cancel` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `payment.create` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `payment.delete` | ✅ | ✅ (today only) | ❌ | ❌ | ❌ | ❌ |
| `room.assign` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `room.unassign` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `stay.checkin` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `stay.checkout` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |

---

## 4. Phase 3.2 Requirements: Folio Foundation

### Goal

Tạo hệ thống Folio — đại diện cho "hóa đơn" của một lần lưu trú. Folio chứa các charges (khoản tính tiền) và được thanh toán qua Payments.

### Proposed Data Model

```
Folio
  - id
  - booking_id (FK → bookings)
  - stay_id (FK → stays, nullable — folio có thể gắn booking-level)
  - folio_number (unique, auto-generated, ví dụ FLO-202607-0001)
  - status (open | closed | voided)
  - note
  - timestamps

FolioEntry (Charge)
  - id
  - folio_id (FK → folios)
  - entry_type (room_charge | service_charge | minibar | late_checkout | early_checkin | discount | tax | other)
  - description
  - quantity
  - unit_price
  - amount (quantity × unit_price, có thể âm cho discount)
  - entry_date
  - posted_by (FK → users)
  - voided_at (nullable — soft-void thay vì xóa)
  - voided_by (nullable FK → users)
  - timestamps
```

### Business Rules

1. Mỗi Booking có ít nhất 1 Folio (main folio, gắn booking-level).
2. Mỗi Stay có thể có Folio riêng nếu split bill cần thiết (Phase 3.3+).
3. Folio `closed` không thể thêm entry mới — chỉ ADMIN mới reopen.
4. Folio `voided` không tính vào balance.
5. `FolioEntry.amount` có thể âm (discount, credit).
6. Không xóa `FolioEntry` — dùng `void` với audit trail.

### New Balance Formula

```
folio_total  = SUM(folio_entries.amount WHERE voided_at IS NULL)
paid_total   = SUM(payments) [từ BookingPaymentService.calculatePaidTotal()]
balance_due  = folio_total − paid_total
```

### API Endpoints cần thêm

```
POST   /admin/bookings/{booking}/folios                    → FolioController@store
GET    /admin/bookings/{booking}/folios/{folio}            → FolioController@show
PATCH  /admin/bookings/{booking}/folios/{folio}/close      → FolioController@close
POST   /admin/bookings/{booking}/folios/{folio}/entries    → FolioEntryController@store
DELETE /admin/bookings/{booking}/folios/{folio}/entries/{entry} → FolioEntryController@void
```

### Permissions cần thêm

```
folio.view
folio.create
folio.close
folio.reopen       ← ADMIN only
charge.create
charge.void
```

### Migration chú ý

- `folios` table — có thể auto-create 1 folio khi tạo Booking mới (observer).
- `folio_entries` table — index trên `(folio_id, voided_at)` cho performance.
- Không bỏ `booking_requirements.room_price` — vẫn cần cho booking form. Phase 3.2 sẽ auto-post room charge từ requirements khi check-in.

---

## 5. Migration Path từ `expected_total` sang `folio_total`

Hiện tại `BookingService::paymentSummary()` dùng:
```php
$expectedTotal = $booking->bookingRequirements->sum(fn ($r) => $r->room_price * $r->quantity);
```

Phase 3.2 phải:
1. Tạo `FolioService::getFolioTotal(Booking $booking): float`
2. Thay `$expectedTotal` trong `paymentSummary()` bằng `$this->folios->getFolioTotal($booking)`
3. Giữ nguyên key `expected_total` trong response array để không phá frontend hiện có (rename trong Phase 3.3 nếu muốn)

---

## 6. Risks để Tránh

| Risk | Mô tả | Cách tránh |
|---|---|---|
| Double-counting | Payments và FolioEntries đều tính vào balance | Tách rõ: Folio = charges (debit), Payments = receipts (credit) |
| Xóa FolioEntry | Xóa hard làm mất audit trail | Dùng `voided_at` — không bao giờ xóa entry |
| Folio không được tạo | Booking tạo xong không có Folio | Dùng observer hoặc `createBooking()` service để auto-create |
| Status guard | Booking `Cancelled`/`NoShow` vẫn cho phép thêm charge | Guard trong `FolioEntryService`: reject nếu booking là terminal |
| `expected_total` stale | Frontend dùng `expected_total` nhưng charges chưa được post | Giai đoạn chuyển tiếp: giữ `expected_total` từ requirements, thêm `folio_total` riêng cho đến khi confirmed |

---

## 7. Handover Checklist cho Phase 3.2

- [ ] Đọc `app/Services/BookingPaymentService.php` — đặc biệt `calculatePaidTotal()` private method
- [ ] Đọc `app/Services/BookingService.php::paymentSummary()` — hiểu shape của response hiện tại
- [ ] Đọc `tests/Feature/PaymentCrudTest.php` — đặc biệt `test_payment_summary_includes_breakdown_fields` để biết assertion pattern
- [ ] Đọc `database/migrations/2026_02_01_000020_create_booking_payments_table.php` — schema tham khảo
- [ ] Đọc `app/Observers/AuditObserver.php` — để wire observer cho Folio và FolioEntry
- [ ] Đọc `database/seeders/RolePermissionSeeder.php` — để thêm folio/charge permissions đúng pattern
- [ ] Không sửa `BookingPaymentService` trong Phase 3.2 — chỉ thêm `FolioService` mới
- [ ] Không xóa `expected_total` khỏi `paymentSummary()` response — backward compat

---

## 8. Recommended Implementation Order

```
Phase 3.2 — Folio Foundation
  Step 1: Migration — create folios + folio_entries tables
  Step 2: Models — Folio, FolioEntry với relationships
  Step 3: Enums — FolioEntryType, FolioStatus
  Step 4: FolioService — create, getFolioTotal, close, reopen
  Step 5: FolioEntryService — addCharge, voidEntry
  Step 6: Auto-create Folio khi Booking được tạo (BookingService hoặc observer)
  Step 7: Permissions — folio.view, folio.create, folio.close, charge.create, charge.void
  Step 8: Controllers + Routes — FolioController, FolioEntryController
  Step 9: Update paymentSummary() — thay expected_total bằng folio_total
  Step 10: Frontend — Tab Payments mở rộng với Folio section
  Step 11: Tests — Unit (FolioPolicy, FolioEntryPolicy) + Feature (FolioCrudTest)

Phase 3.3 — Service Charges
  Auto-post room charge từ BookingRequirement khi check-in
  Post service charges từ Stay
```
