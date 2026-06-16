# Phase 3.2 — Folio Foundation: Architecture Design

**Date:** 2026-06-15
**Status:** Pending Review

---

## Table of Contents

1. [Existing Financial Review](#1-existing-financial-review)
2. [Data Model Design](#2-data-model-design)
3. [Charge Types](#3-charge-types)
4. [Balance Calculation](#4-balance-calculation)
5. [UI Design](#5-ui-design)
6. [Future Expansion Strategy](#6-future-expansion-strategy)
7. [Risks](#7-risks)
8. [Implementation Plan](#8-implementation-plan)

---

## 1. Existing Financial Review

### What Already Exists

**`booking_payments` table** (from Phase 3.1):
```
id, booking_id, payment_type, amount, payment_method,
payment_at, confirmed_by, note, timestamps
```
Foreign key: `booking_id → bookings` (cascade delete).
No soft delete — hard delete is the pattern, guarded by policy time window.

**`PaymentType` enum** — Deposit, AdditionalDeposit, RoomPayment, ServicePayment, Refund, Adjustment.

**`BookingPaymentService`** — addDeposit, addPayment, addRefund, deletePayment, `calculatePaidTotal()` (private, net sum).

**`BookingService::paymentSummary()`** — returns:
```php
[
    'expected_total'    => float,  // ← PLACEHOLDER: SUM(requirements.room_price × qty)
    'total_deposit'     => float,
    'total_payment'     => float,
    'total_refund'      => float,
    'total_adjustment'  => float,
    'paid_total'        => float,
    'remaining_balance' => float,  // = expected_total − paid_total
]
```

**Critical dependency on `remaining_balance`** — `BookingService::updateBookingStayStatus()` uses:
```php
$checkedOut === $total && $remainingBalance <= 0 => BookingStatus::CheckedOut
```
Any change to how `remaining_balance` is computed directly affects checkout gate behavior.

**AuditObserver** — generic, already handles all models via `created/updated/deleted/restored`. Just wire new models in `AppServiceProvider`.

**Policy pattern** — `Gate::policy(Model::class, Policy::class)` in `AppServiceProvider`. `noun.verb` permission naming: `booking.create`, `payment.delete`.

### The `expected_total` Problem

`expected_total` currently sums `bookingRequirements.room_price × quantity`. This is a placeholder introduced in Phase 2 — it is NOT the actual folio. Problems:

1. Room requirements can be updated after check-in, changing `expected_total` retroactively.
2. Ad-hoc charges (F&B, damage, late checkout) have nowhere to go.
3. `remaining_balance` is unreliable because it derives from a planning document, not from posted charges.

**Phase 3.2 replaces `expected_total` with `total_charges` from actual folio entries.**

### Integration Constraint

Phase 3.2 MUST NOT break:
- `PaymentType` enum (do not add charge types here — charges belong in `ChargeType`)
- `BookingPaymentService` interface (no changes)
- `paymentSummary()` response shape (keep all existing keys; only update how values are computed)
- The `remaining_balance <= 0` checkout gate (must remain semantically correct)

---

## 2. Data Model Design

### Entity Relationship

```
Booking (1) ──────────────── (1) Folio
                                    │
                              (many) FolioEntry
```

Phase 3.2: one Folio per Booking (main folio). Phase 3.3+ can introduce per-Stay folios or split billing.

---

### `folios` Table

```php
Schema::create('folios', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('booking_id')
          ->unique()                          // 1 folio per booking in Phase 3.2
          ->constrained('bookings')
          ->cascadeOnDelete();
    $table->string('folio_number', 40)->unique();  // FLO-20260701-0001
    $table->string('status', 20)->default('OPEN')->index(); // FolioStatus enum
    $table->text('note')->nullable();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->dateTime('closed_at')->nullable();
    $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
});
```

**Column decisions:**

| Column | Decision | Reason |
|---|---|---|
| `booking_id` UNIQUE | Yes at Phase 3.2 | 1 main folio per booking. Remove UNIQUE if split billing needed in future |
| `status` string not enum | String column | Matches existing pattern in project (BookingStatus, StayStatus all use string columns) |
| `closed_at` / `closed_by` | Yes | Needed for audit — when and by whom folio was closed |
| Soft delete | NO | Use `status = VOIDED` instead. Soft delete adds complexity without benefit for a financial document |
| `stay_id` | NO at Phase 3.2 | Per-stay folio is Phase 3.3+ scope |

**Indexes:**
- `booking_id` — UNIQUE (FK + uniqueness)
- `status` — index (filter open/closed folios)
- `folio_number` — UNIQUE

---

### `folio_entries` Table

```php
Schema::create('folio_entries', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('folio_id')
          ->constrained('folios')
          ->cascadeOnDelete();
    $table->string('charge_type', 40)->index();    // ChargeType enum
    $table->string('description', 255);
    $table->decimal('quantity', 8, 2)->default(1);
    $table->decimal('unit_price', 12, 2);
    $table->decimal('amount', 12, 2);              // stored, not computed
    $table->date('entry_date')->index();
    $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
    $table->dateTime('voided_at')->nullable();
    $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
    $table->text('void_reason')->nullable();
    $table->timestamps();

    $table->index(['folio_id', 'voided_at']);      // critical for total_charges query
    $table->index(['charge_type', 'entry_date']);  // for future reporting
});
```

**Column decisions:**

| Column | Decision | Reason |
|---|---|---|
| `amount` stored separately | YES | Not computed as `quantity × unit_price`. Allows fractional/special rates, rounded amounts, and partial charges without float arithmetic at query time |
| `amount` can be negative | YES | Discounts and credit entries. Example: late checkout fee waived = `amount = -200000` |
| Soft delete | NO | Use `voided_at` pattern. Richer than `deleted_at`: captures who, when, and why |
| Hard delete | NEVER | Financial records must never be deleted. Only void |
| `void_reason` | YES | Required for audit — manager/admin must state reason |
| `description` free text | YES | Covers all charge types; structured description is module concern, not folio concern |

**Void vs Delete:**
- Voided entries remain visible in the audit trail and entry list (greyed out in UI)
- `voided_at IS NULL` = active for `total_charges` calculation
- `AuditObserver::updated()` automatically captures the void action (sets `voided_at`)

---

### Migration Order

```
2026_06_01_000000_create_folios_table.php
2026_06_01_000010_create_folio_entries_table.php
```

Numbers `000000` / `000010` follow existing project convention (gap of 10 between migrations in same batch).

---

## 3. Charge Types

### Proposed `ChargeType` Enum

```php
namespace App\Enums;

enum ChargeType: string
{
    case Room           = 'ROOM';
    case FoodBeverage   = 'FOOD_BEVERAGE';
    case Spa            = 'SPA';
    case Laundry        = 'LAUNDRY';
    case Minibar        = 'MINIBAR';
    case Damage         = 'DAMAGE';
    case LateCheckout   = 'LATE_CHECKOUT';
    case EarlyCheckin   = 'EARLY_CHECKIN';
    case Transport      = 'TRANSPORT';
    case Other          = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Room          => 'Tiền phòng',
            self::FoodBeverage  => 'Ăn uống',
            self::Spa           => 'Spa',
            self::Laundry       => 'Giặt ủi',
            self::Minibar       => 'Minibar',
            self::Damage        => 'Bồi thường',
            self::LateCheckout  => 'Trả phòng muộn',
            self::EarlyCheckin  => 'Nhận phòng sớm',
            self::Transport     => 'Vận chuyển',
            self::Other         => 'Khác',
        };
    }
}
```

### Enum vs Config Decision

**Use PHP backed enum for charge types.** Rationale:

| Criterion | Enum | Config |
|---|---|---|
| Type safety | ✅ Compile-time | ❌ String validation only |
| Logic branching | ✅ `match($type)` is exhaustive | ❌ Risk of missing cases |
| Auto-post logic | ✅ `ChargeType::Room` auto-posted at check-in | ❌ No clean hook |
| Adding new type | Requires code + migration (intentional gate) | Can be done without deployment (risky) |
| Frontend dropdown | Same effort either way | Same effort |

The intentional gate when adding a new charge type is a **feature, not a bug** — it forces the team to consider backend implications (does this type need special handling?) before exposing it to users.

**Config is appropriate for**: charge rates per type (e.g., default late checkout fee amount), tax rates, minibar menu items. These can change at runtime and are out of Phase 3.2 scope.

---

## 4. Balance Calculation

### New Formula

```
total_charges = SUM(folio_entries.amount WHERE voided_at IS NULL)
paid_total    = total_deposit + total_payment + total_adjustment − total_refund
balance_due   = total_charges − paid_total
```

### Updated `paymentSummary()` Response

Phase 3.2 updates `BookingService::paymentSummary()` to:

```php
return [
    // ── NEW in Phase 3.2 ──────────────────────────────────
    'total_charges'    => $totalCharges,      // SUM(active folio entries)
    'balance_due'      => $balanceDue,        // total_charges − paid_total

    // ── KEPT for backward compat (key names unchanged) ────
    'expected_total'   => $totalCharges,      // now aliases total_charges
    'paid_total'       => $paidTotal,         // unchanged
    'remaining_balance'=> $balanceDue,        // now aliases balance_due

    // ── Payment breakdown (Phase 3.1, unchanged) ──────────
    'total_deposit'    => $totalDeposit,
    'total_payment'    => $totalPayment,
    'total_refund'     => $totalRefund,
    'total_adjustment' => $totalAdjustment,
];
```

**Key decisions:**
- `expected_total` and `remaining_balance` keep their key names — frontend and tests continue to work without changes.
- `total_charges` and `balance_due` are added as semantic aliases.
- `expected_total` value now equals `total_charges` (folio sum) instead of requirements sum.
- `remaining_balance` value now equals `balance_due` (correct formula).

### Transition Guard for Empty Folio

When a Folio exists but has no active entries (newly created booking, no check-in yet), `total_charges = 0`, which would make `balance_due = 0 − paid_total` (negative). This would incorrectly allow checkout for a booking with deposits but no room charges posted yet.

**Solution:** Fall back to `expected_total` from requirements when `total_charges = 0`.

```php
$folioTotal = $this->folios->getFolioTotal($booking);
$totalCharges = $folioTotal > 0.0
    ? $folioTotal
    : $requirements->sum(fn ($r) => $r->room_price * $r->quantity);
```

This is a **temporary bridge**. Once Phase 3.2.4 auto-posts room charges at check-in, `total_charges` will always be > 0 for checked-in bookings, and the fallback becomes irrelevant. Remove the fallback in Phase 3.3 when auto-posting is confirmed stable.

### `updateBookingStayStatus()` — No Change Needed

The method already calls `$this->paymentSummary($booking)['remaining_balance']`. Because we alias `remaining_balance = balance_due`, and the transition guard ensures `balance_due` is semantically correct, this method requires no modification.

---

## 5. UI Design

### Current Booking Detail Tabs

```
[ Thông tin Booking ] [ Sơ đồ phòng ] [ Thanh toán ] [ Lịch sử ]
```

### Proposed Structure for Phase 3.2

Merge charges and payments into a single "Tài chính" tab. This is standard PMS UX — front desk staff need to see the full financial picture in one view, not switch between tabs to understand balance.

```
[ Thông tin Booking ] [ Sơ đồ phòng ] [ Tài chính ] [ Lịch sử ]
```

The current "Thanh toán" tab is renamed to "Tài chính" and expanded.

### Finance Tab Layout

```
┌──────────────────────────────────────────────────────────────────┐
│  TỔNG KẾT TÀI CHÍNH                                             │
│                                                                  │
│  ┌────────────────┐  ┌────────────────┐  ┌────────────────┐     │
│  │ Tổng phí       │  │ Đã thu         │  │ Còn lại        │     │
│  │ 2,500,000 đ    │  │ 1,000,000 đ    │  │ 1,500,000 đ ↑  │     │
│  └────────────────┘  └────────────────┘  └────────────────┘     │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ Breakdown phí: Phòng 800k | Ăn uống 200k | Khác 150k    │   │
│  └──────────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│  PHÍ PHÁT SINH          [ + Thêm phí ]                          │
│                                                                  │
│  Ngày   │ Loại            │ Mô tả            │ SL │ Đơn giá │ Tổng │ │
│  07-01  │ Tiền phòng      │ TWIN - 1 đêm     │ 1  │ 800,000 │ 800,000│ │
│  07-01  │ Minibar         │ Nước ngọt Pepsi   │ 2  │  25,000 │  50,000│[Void]│
│  07-02  │ Trả phòng muộn  │ Late checkout 2h │ 1  │ 200,000 │ 200,000│[Void]│
│  ~~07-01~~ │ ~~Ăn uống~~ │ ~~Đã hủy~~       │~~1~~│~~150k~~ │~~150k~~│ VOIDED │
│                                                                  │
│  Folio #FLO-20260701-0001 · Trạng thái: OPEN                   │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│  THANH TOÁN             [ + Thêm thanh toán ]                   │
│                                                                  │
│  Ngày   │ Loại      │ Phương thức │    Số tiền │ Actions        │
│  06-30  │ Đặt cọc   │ Tiền mặt    │    500,000 │ [Delete]       │
│  07-02  │ Thanh toán│ Chuyển khoản│  1,000,000 │ [Delete]       │
└──────────────────────────────────────────────────────────────────┘
```

**Layout decisions:**

1. **Financial Summary at top** — 3 cards (Total Charges, Paid Total, Balance Due). Balance Due shows red/green color based on sign.
2. **Breakdown row** — horizontal chip-style breakdown of charges by type (Room, F&B, etc.).
3. **Charges section** — separate from Payments. Voided entries stay visible but visually struck through (accessibility: don't rely on color alone; use strikethrough + VOIDED badge).
4. **Folio metadata** — folio number and status shown below charges table. Close/Reopen button for ADMIN/MANAGER.
5. **Payments section** — current payment table, unchanged visually.

### Add Charge Form

Inline form (same pattern as current add payment form) with fields:
- `charge_type` (select — ChargeType options)
- `description` (text)
- `quantity` (number, default 1)
- `unit_price` (number)
- `entry_date` (date, default today)

Amount is auto-calculated in frontend (`quantity × unit_price`) and sent as `amount` in request. Backend validates that `amount = quantity × unit_price` within tolerance.

---

## 6. Future Expansion Strategy

### The Folio as a Financial Hub

The Folio's `folio_entries` table is the central financial hub. Every future module that charges guests writes to `folio_entries`. The Folio schema itself never changes when new modules are added.

```
Restaurant Module ──→ FolioEntry (charge_type: FOOD_BEVERAGE)
Spa Module        ──→ FolioEntry (charge_type: SPA)
Laundry Module    ──→ FolioEntry (charge_type: LAUNDRY)
Damage Reports    ──→ FolioEntry (charge_type: DAMAGE)
Mini Bar (auto)   ──→ FolioEntry (charge_type: MINIBAR)
```

### Integration Pattern for Future Modules

Each future module integrates via `FolioService::addCharge()`:

```php
// Restaurant module posting a charge:
$this->folioService->addCharge($booking->folio, [
    'charge_type' => ChargeType::FoodBeverage,
    'description' => 'Bữa sáng x2',
    'quantity'    => 2,
    'unit_price'  => 150000,
    'amount'      => 300000,
    'entry_date'  => today(),
]);
```

The Folio service does not need to know what a "Restaurant" is. The restaurant module does not need to know how the Folio calculates balances.

### Adding a New Charge Type

When a new module needs a new charge type:

1. Add a new case to `ChargeType` enum
2. Add `label()` mapping in Vietnamese
3. Run migration if needed (schema is already flexible — `charge_type` is just a string)
4. No changes to `folios`, `folio_entries` schema
5. No changes to `FolioService` calculation logic
6. Add the new type to the UI dropdown options

### Split Billing (Phase 3.3+)

When the hotel needs to split charges across guests or rooms:
1. Remove `UNIQUE` constraint on `folios.booking_id`
2. Add `stay_id` nullable FK to `folios`
3. `Booking` becomes `hasMany Folios`
4. `FolioService::getFolioTotal()` sums across all non-voided folios for the booking

No changes to `folio_entries` schema. This is a non-breaking extension.

### Invoice Generation (Phase 3.4+)

Invoice is a snapshot of a closed Folio:
- `invoices` table references `folio_id`
- Invoice reads `folio_entries` at generation time
- Folio must be CLOSED before invoice can be generated
- Invoice PDF is a separate concern (does not affect Folio schema)

---

## 7. Risks

### R1 — Duplicate Room Charges
**Risk:** Auto-posting room charge at check-in runs twice (e.g., double check-in event, retry).
**Impact:** Guest is double-charged. Hard to detect without explicit dedup.
**Mitigation:**
- Check `folio_entries.charge_type = 'ROOM'` existence before auto-posting.
- Or use database unique constraint on `(folio_id, charge_type)` for ROOM type only (use partial unique index).
- Manual charges (F&B etc.) are allowed to have duplicates.
**Decision:** Add a service-level guard in `FolioService::autoPostRoomCharge()`: skip if room charge already exists for the folio.

### R2 — Charge Deletion (No Hard Delete)
**Risk:** Developer or future requirement introduces a DELETE endpoint for charges.
**Impact:** Breaks audit trail, creates unexplained gaps in financial records.
**Mitigation:**
- Expose ONLY a `void` endpoint, never a delete endpoint.
- `FolioEntryPolicy` explicitly has no `delete` method.
- Document this in a code comment in the policy class.
- Policy `before()` hook returns `false` for delete to make it explicit.

### R3 — Audit Integrity of Void
**Risk:** Voiding sets `voided_at` — this triggers `AuditObserver::updated()` automatically, which captures old/new `voided_at` values. But if someone updates `voided_at` directly via `DB::table()`, it bypasses the observer.
**Mitigation:**
- All voids MUST go through `FolioService::voidEntry()` which uses Eloquent `$entry->update(...)`.
- Never bypass with `DB::table()` in the void flow.
- The `void_reason` being required by validation ensures the action is intentional.

### R4 — Payment/Charge Conceptual Confusion
**Risk:** Future developers add charge types to `PaymentType` enum, or use `booking_payments` for room charges.
**Impact:** Balance formula breaks. Mixed data in wrong tables.
**Mitigation:**
- Clear naming: `BookingPayment` = money received from guest. `FolioEntry` = money owed by guest.
- Controller-level separation: `BookingPaymentController` and `FolioEntryController` are distinct.
- Add PHPDoc comments to `BookingPayment` and `FolioEntry` models stating their role.
- Never add `ROOM_CHARGE` or similar to `PaymentType`.

### R5 — Future Reporting Gaps
**Risk:** Reporting on charges by type, by date, by staff requires efficient queries. Without proper indexes, this becomes slow at scale.
**Impact:** Report generation slow, potentially inaccurate if queries time out.
**Mitigation:**
- Index `(folio_id, voided_at)` — for all charge sum queries.
- Index `(charge_type, entry_date)` — for reporting by type/date range.
- Add `posted_by` index — for staff productivity reports.
- These are already in the proposed schema above.

### R6 — `remaining_balance` Transition Gap
**Risk:** During the period between Phase 3.2 deployment and Phase 3.2's auto-posting of room charges being active, folios will exist but have zero entries. The transition guard (fall back to requirements sum) bridges this, but if a booking is checked in with no room charge yet posted, the checkout gate might pass incorrectly.
**Impact:** Guest could be marked CheckedOut while still having unpaid room charges.
**Mitigation:**
- Auto-post room charge at check-in time (Phase 3.2.2 backend step).
- The fallback is only needed for the brief window between deployment and first check-in.
- Write a test: `test_checkout_blocked_when_room_charge_exceeds_paid_total`.

### R7 — Folio Created Before Requirements Exist
**Risk:** Auto-creating folio in `BookingService::createBooking()` works, but if a booking is created with no requirements, the folio exists with no initial charges.
**Impact:** Balance shows 0 until charges are posted. No functional problem — just cosmetically shows 0/0/0 in UI.
**Mitigation:** This is acceptable behavior. Room charge is posted at check-in, not at booking creation.

---

## 8. Implementation Plan

### Phase 3.2.1 — Database

- [ ] **Migration:** `create_folios_table`
  - Columns: id, booking_id (unique FK), folio_number (unique), status, note, created_by, closed_at, closed_by, timestamps
  - Indexes: status, folio_number (unique), booking_id (unique)

- [ ] **Migration:** `create_folio_entries_table`
  - Columns: id, folio_id (FK cascade), charge_type, description, quantity, unit_price, amount, entry_date, posted_by, voided_at, voided_by, void_reason, timestamps
  - Indexes: (folio_id, voided_at), (charge_type, entry_date), posted_by

- [ ] **Enum:** `App\Enums\ChargeType` — Room, FoodBeverage, Spa, Laundry, Minibar, Damage, LateCheckout, EarlyCheckin, Transport, Other. With `label()` Vietnamese.

- [ ] **Enum:** `App\Enums\FolioStatus` — Open, Closed, Voided. With `isEditable()` returning `$this === self::Open`.

- [ ] **Factories:** `FolioFactory`, `FolioEntryFactory` for tests.

---

### Phase 3.2.2 — Backend

- [ ] **Model:** `App\Models\Folio`
  - `fillable`: booking_id, folio_number, status, note, created_by, closed_at, closed_by
  - `casts`: status → FolioStatus, closed_at → datetime
  - `belongsTo Booking`
  - `hasMany FolioEntry`
  - `belongsTo User as createdBy`
  - `belongsTo User as closedBy`
  - Scope: `scopeOpen()` — where status = OPEN

- [ ] **Model:** `App\Models\FolioEntry`
  - `fillable`: folio_id, charge_type, description, quantity, unit_price, amount, entry_date, posted_by, voided_at, voided_by, void_reason
  - `casts`: charge_type → ChargeType, amount → 'decimal:2', unit_price → 'decimal:2', quantity → 'decimal:2', entry_date → 'date', voided_at → 'datetime'
  - `belongsTo Folio`
  - `belongsTo User as postedBy`
  - `belongsTo User as voidedBy`
  - Scope: `scopeActive()` — where voided_at IS NULL

- [ ] **Update `Booking` model:** Add `hasOne Folio` (or `hasMany` for future-readiness — use `hasOne` now)

- [ ] **Service:** `App\Services\FolioService`
  - `createFolioForBooking(Booking $booking): Folio` — create folio, generate folio_number
  - `addCharge(Folio $folio, array $data): FolioEntry` — validate folio is OPEN, create entry
  - `autoPostRoomCharge(Booking $booking): ?FolioEntry` — check if ROOM charge exists, post if not
  - `voidEntry(FolioEntry $entry, string $reason): void` — set voided_at/voided_by/void_reason via Eloquent
  - `getFolioTotal(Booking $booking): float` — SUM(active entries)
  - `closeFolio(Folio $folio): void` — set status CLOSED, closed_at, closed_by
  - `reopenFolio(Folio $folio): void` — set status OPEN, clear closed_at/closed_by

- [ ] **Update `BookingService::createBooking()`:** Call `$this->folios->createFolioForBooking($booking)` inside transaction after booking creation.

- [ ] **Update `BookingService::paymentSummary()`:** Integrate `FolioService::getFolioTotal()`. Add `total_charges` and `balance_due` keys. Keep `expected_total` and `remaining_balance` as semantic aliases with transition guard.

- [ ] **Update `RoomAssignmentService` or `StayService` check-in:** After stay check-in, call `$this->folios->autoPostRoomCharge($booking)`.

- [ ] **Policy:** `App\Policies\FolioPolicy`
  - `viewAny(User)`: has `folio.view`
  - `view(User, Folio)`: has `folio.view`
  - `close(User, Folio)`: has `folio.close` AND folio is OPEN
  - `reopen(User, Folio)`: user has role ADMIN (no permission check — ADMIN only)

- [ ] **Policy:** `App\Policies\FolioEntryPolicy`
  - `create(User, Folio)`: has `charge.create` AND folio is OPEN
  - `void(User, FolioEntry)`: ADMIN always; MANAGER with `charge.void` for today's entries

- [ ] **Controller:** `App\Http\Controllers\Admin\Booking\FolioController`
  - `show(Booking, Folio)`: return folio with entries for booking detail payload
  - `close(Booking, Folio): RedirectResponse`
  - `reopen(Booking, Folio): RedirectResponse`

- [ ] **Controller:** `App\Http\Controllers\Admin\Booking\FolioEntryController`
  - `store(Booking, Folio): RedirectResponse` — validate, addCharge, redirect
  - `void(Booking, Folio, FolioEntry): RedirectResponse` — validate reason, voidEntry, redirect

- [ ] **Request:** `App\Http\Requests\Folio\StoreFolioEntryRequest`
  - Required: charge_type (in ChargeType values), description (min:1, max:255), quantity (numeric, min:0.01), unit_price (numeric, min:0), amount (numeric, min:0), entry_date (date)

- [ ] **Request:** `App\Http\Requests\Folio\VoidFolioEntryRequest`
  - Required: void_reason (string, min:5, max:500)

- [ ] **Update `RolePermissionSeeder`:** Add new permissions:
  ```
  folio.view   → ADMIN, MANAGER, RECEPTION, ACCOUNTANT
  charge.create → ADMIN, MANAGER, RECEPTION
  charge.void  → ADMIN, MANAGER
  folio.close  → ADMIN, MANAGER
  ```
  Note: `folio.reopen` is ADMIN-only and handled via role check in policy, no permission needed.

- [ ] **Update `AppServiceProvider`:** Register policies + observers:
  ```php
  Gate::policy(Folio::class, FolioPolicy::class);
  Gate::policy(FolioEntry::class, FolioEntryPolicy::class);
  Folio::observe(AuditObserver::class);
  FolioEntry::observe(AuditObserver::class);
  ```

- [ ] **Routes:** Inside existing admin middleware group:
  ```php
  Route::get('bookings/{booking}/folio', [FolioController::class, 'show'])->name('bookings.folio.show');
  Route::patch('bookings/{booking}/folio/close', [FolioController::class, 'close'])->name('bookings.folio.close');
  Route::patch('bookings/{booking}/folio/reopen', [FolioController::class, 'reopen'])->name('bookings.folio.reopen');
  Route::post('bookings/{booking}/folio/entries', [FolioEntryController::class, 'store'])->name('bookings.folio.entries.store');
  Route::patch('bookings/{booking}/folio/entries/{entry}/void', [FolioEntryController::class, 'void'])->name('bookings.folio.entries.void');
  ```
  Note: `PATCH` for void (not `DELETE`) — semantically correct, no data is removed.

- [ ] **Update `BookingController::bookingPayload()`:** Include folio data (folio_number, status, entries array, totals).

- [ ] **Update `BookingController::permissions()`:** Add `createCharge`, `voidCharge`, `closeFolio`, `reopenFolio` flags.

---

### Phase 3.2.3 — Frontend

- [ ] **Rename tab** "Thanh toán" → "Tài chính" in `BookingController::detailTabs()` and `normalizeDetailTab()`.

- [ ] **Update `Show.vue`** — Finance tab:
  - Financial summary section: 3 cards using `payment_summary.total_charges`, `paid_total`, `balance_due`
  - Charge type breakdown row (horizontal chips)
  - Charges table: charge_type label, description, quantity, unit_price, amount. Voided rows visually struck through with VOIDED badge
  - Add Charge inline form (collapsible, `v-if="can.createCharge"`)
  - Void button per entry (`v-if="entry.can_void"`)
  - Void reason modal/prompt before submitting void
  - Folio status badge + Close/Reopen button
  - Payments section (existing, no visual changes — just moved inside Finance tab)

- [ ] **ChargeType labels** — add to `resources/js/Support/vietnameseLabels.js` (or equivalent support file if it exists)

- [ ] **`router.patch()`** for void and folio close/reopen actions

---

### Phase 3.2.4 — Tests

- [ ] **`tests/Unit/Policies/FolioPolicyTest.php`** (6 tests):
  - admin can view folio
  - manager can view folio
  - accountant can view folio
  - housekeeping cannot view folio
  - manager can close open folio
  - only admin can reopen closed folio

- [ ] **`tests/Unit/Policies/FolioEntryPolicyTest.php`** (5 tests):
  - admin can void any entry
  - manager can void today's entry
  - manager cannot void old entry
  - reception cannot void entry
  - cannot create charge on closed folio

- [ ] **`tests/Feature/FolioCrudTest.php`** (15 tests):
  - folio is auto-created when booking is created
  - admin can add charge to open folio
  - charge appears in total_charges
  - voided entry excluded from total_charges
  - cannot void already-voided entry
  - void requires reason (validation)
  - charge type must be valid
  - amount must be positive (validation)
  - cannot add charge to closed folio (403)
  - manager can close folio
  - admin can reopen closed folio
  - manager cannot reopen closed folio (403)
  - charge audit log created on post
  - void captured in audit log (updated event)
  - booking controller exposes folio with entries and totals
  - balance_due = total_charges − paid_total formula correct

---

## Summary

| Sub-phase | Scope | Est. Effort |
|---|---|---|
| 3.2.1 Database | 2 migrations, 2 enums, 2 factories | 2–3 hours |
| 3.2.2 Backend | 2 models, 1 service, 2 policies, 2 controllers, 2 requests, seeder, routes, observer wiring | 5–7 hours |
| 3.2.3 Frontend | Finance tab redesign, charge table, add-charge form, void flow | 4–5 hours |
| 3.2.4 Tests | 6 unit + 5 unit + 16 feature | 3–4 hours |
| **Total** | | **14–19 hours** |

**Recommended implementation order:** 3.2.1 → 3.2.2 → 3.2.4 (tests alongside backend) → 3.2.3

The database layer must exist before models; models before policies; policies before controllers; all backend before frontend. Tests should be written in parallel with backend (TDD style where possible) — write the test, implement the feature, confirm green.
