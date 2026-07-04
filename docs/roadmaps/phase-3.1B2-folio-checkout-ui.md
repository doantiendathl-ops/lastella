# Phase 3.1B2 — Folio UI & Checkout UI Architecture

**Status:** Draft v2 — Updated per ChatGPT Architecture Review  
**Date:** 2026-07-01  
**Branch:** phase-3  
**Prerequisites:** Phase 3.1A (Folio Ledger Backend) + Phase 3.1B1 (Checkout Integration) — both committed

---

## Context

Phase 3.1A implemented the full service layer: `FolioService`, `BookingPaymentService`, `calculateGuardedFolioTotal()`, `autoPostRoomCharge()`, folio lifecycle (Open → Closed/Voided), and ADR-1 through ADR-37.

Phase 3.1B1 implemented `finaliseBookingCheckout()`, `OutstandingBalanceException`, `BookingTerminalException`, canonical lock order enforcement (ADR-38 through ADR-49), and the stay check-in/check-out service layer.

**All backend service logic is complete.** Phase 3.1B2 builds the UI layer on top of the existing services and controllers. No new service methods, no new migrations, no new models.

The controllers (`FolioController`, `FolioEntryController`, `BookingPaymentController`, `StayController`) and all routes already exist. Phase 3.1B2 completes their payload data, adds missing validation, and implements the Vue component layer.

---

## 1. Functional Requirements

### 1.1 Folio Ledger Tab

| # | Requirement |
|---|---|
| F-01 | Display all folio entries for the booking, sorted by entry_date ASC then created_at ASC |
| F-02 | Each entry shows: charge_type badge, description, quantity × unit_price, total amount, entry_date, posted_by, and void status |
| F-03 | Active entries show a "Void" button. Voided entries show void_reason and voided_by in a muted style |
| F-04 | Add Charge form: charge_type (dropdown), description, quantity, unit_price, entry_date. Amount computed and previewed client-side but validated server-side |
| F-05 | Void entry: requires void_reason (min 5 chars) via inline confirmation dialog |
| F-06 | Folio status badge: Open / Closed / Voided with appropriate colour coding |
| F-07 | Close Folio button (permission: folio.close, only when Open). Confirmation required |
| F-08 | Reopen Folio button (permission: ADMIN role, only when Closed). Confirmation required |
| F-09 | When folio is Closed or Voided, "Add Charge" and "Void" buttons are hidden |
| F-10 | System-posted room charge entry (posting_key = ROOM_CHARGE_*) is displayed without a Void button. This is a display convenience; the authoritative guard is in `FolioService::voidEntry()` (see ADR-50) |

### 1.2 Payment Summary Section

| # | Requirement |
|---|---|
| P-01 | Display payment summary breakdown sourced exclusively from `BookingService::paymentSummary()`. This is the single canonical source for all financial values in the UI — no component derives totals from folio entries or payment rows independently |
| P-02 | Show: Total Charges, Total Deposits, Total Payments, Total Refunds, Total Adjustments, Paid Total, Balance Due — all read from `paymentSummary` prop |
| P-03 | Balance Due is prominently highlighted: green (= 0) or red (> 0) with currency formatting. Value is always `paymentSummary.balance_due` |
| P-04 | Add Payment form: payment_type selector, amount, payment_method, payment_at, note. Conditional fields by payment_type |
| P-05 | For Refund type: show maximum refundable amount. Client-side hint reads `paymentSummary.paid_total` and `paymentSummary.total_charges` directly — never recomputed from other data. Server enforces via `RefundExceedsMaxException` |
| P-06 | Payment transaction history: payment_type badge, amount, payment_method, payment_at, confirmed_by, delete button |
| P-07 | Delete payment: confirmation required. Button visible only when: is Admin OR payment created today (matches BookingPaymentPolicy) |
| P-08 | Payment list sorted by payment_at ASC |

### 1.3 Checkout Readiness

| # | Requirement |
|---|---|
| C-01 | Checkout readiness indicator: shown prominently on both payments and room-map tabs |
| C-02 | When balance_due = 0 AND at least one stay is CheckedIn: "Ready to checkout" |
| C-03 | When balance_due > 0: "Balance outstanding: {amount}. Settle before checkout" |
| C-04 | Checkout button (per stay, in room-map tab) disabled with tooltip when balance_due > 0 AND this stay is the last active stay (Active = Reserved OR CheckedIn per ADR-49). **This is a UX gate only — a direct HTTP request bypassing the button will still be rejected by `OutstandingBalanceException` in `finaliseBookingCheckout()`.** |
| C-05 | Confirmation modal before checking out the last active stay: shows balance summary and the fact that checkout will finalise the booking |
| C-06 | After checkout success: booking status → CheckedOut, folio status → Closed. Page refreshes reactively |
| C-07 | If `OutstandingBalanceException` fires (server-side), display flash error with the outstanding amount |

### 1.4 Audit & Display

| # | Requirement |
|---|---|
| A-01 | History tab shows: booking status changes, payment additions, folio entry postings, void events, checkout timestamp |
| A-02 | All timestamps displayed in Asia/Bangkok timezone |
| A-03 | Currency amounts formatted as Vietnamese Dong (e.g., 1.200.000 đ) |

---

## 2. UI Workflow

### 2.1 Normal Checkout Flow

```
1. Booking arrives at CheckedIn status (≥1 stay checked in).
   → Room Board tab: shows assigned rooms, check-in timestamps.
   → Payments tab: balance_due > 0 (room charge auto-posted at first check-in).

2. Receptionist opens Payments tab.
   → Sees outstanding balance.
   → Adds payment: payment_type=RoomPayment, amount=X, method=Cash.
   → Page reloads. Balance Due updates.

3. When balance_due = 0:
   → "Ready to checkout" banner appears on Payments tab.
   → Checkout buttons on Room Board tab become active.

4. Receptionist opens Room Board tab.
   → Clicks "Check Out" on the active stay.
   → Confirmation modal: "This is the last stay. Checkout will finalise the booking."
   → Confirms. POST /stays/{stay}/check-out.
   → Server: StayService::checkOut() → finaliseBookingCheckout() → folio auto-closes.
   → Booking → CheckedOut. Page reloads. Status banner shows "Checked Out".
```

### 2.2 Extra Charges Flow

```
1. Guest requests additional service during stay.
2. Receptionist opens Payments tab → Folio Ledger section.
3. Clicks "Add Charge". Selects ChargeType (e.g., Minibar). Enters description, quantity, unit_price.
4. Client previews amount = quantity × unit_price.
5. Submits. Server: FolioService::addCharge() validates folio is Open.
6. New entry appears in ledger. Balance Due increases.
7. Receptionist adds another payment to cover. Balance → 0.
8. Checkout proceeds as normal.
```

### 2.3 Void Entry Flow

```
1. Receptionist identifies incorrect charge.
2. Clicks "Void" on the entry row.
3. Inline confirmation dialog: text field for void_reason (≥5 chars).
4. Submits. Server: FolioService::voidEntry() marks entry voided.
5. Entry shows as struck-through with void_reason. Balance Due decreases.
```

### 2.4 Partial Payment / Deposit Flow

```
1. Booking arrives with PendingAssignment status.
2. Receptionist adds deposit: payment_type=Deposit.
3. Booking status transitions to Deposited automatically.
4. At checkout: remaining balance = total_charges − total_deposits.
5. Receptionist adds RoomPayment to cover remaining.
6. Balance → 0. Checkout proceeds.
```

### 2.5 Folio Close / Reopen Flow

```
1. Booking is CheckedOut. Folio auto-closed by finaliseBookingCheckout().
2. Admin identifies error (missed charge).
3. Admin clicks "Reopen Folio" (ADMIN-only button).
4. PATCH /folio/reopen. Server: FolioService::reopenFolio(). Validates booking not Terminal.
   → BLOCKS if booking is CheckedOut (Terminal). Cannot reopen folio for terminal booking.
   → This means: folio reopen is only valid for active bookings (e.g., manually closed folio).
5. Admin adds corrective charge. Adds covering payment. Balance → 0.
6. Admin manually clicks "Close Folio".
```

**Note:** The `can_reopen` flag in the controller payload (§3.1) is the sole authority for whether the Reopen button is shown. Vue components must not re-evaluate terminal status, booking status, or role conditions independently — that would duplicate logic that already lives in the service layer (`assertBookingNotTerminal`). The UI simply reads `can.can_reopen` and renders or hides the button accordingly (ADR-54).

---

## 3. Controller Changes

### 3.1 BookingController::show() — Payload Additions

Current payload needs the following additions:

```php
// Add to bookingPayload():
'options' => [
    'charge_types'      => ChargeType::options(),      // [{value, label}]
    'payment_types'     => PaymentType::options(),     // [{value, label}]
    'payment_methods'   => PaymentMethod::options(),   // [{value, label}]
],
'payment_summary'  => $this->bookingService->paymentSummary($booking),
'can' => [
    'add_charge'     => $user->can('charge.create') && $folio?->status === FolioStatus::Open,
    'void_entry'     => $user->can('charge.void'),
    'close_folio'    => $user->can('folio.close') && $folio?->status === FolioStatus::Open,
    // can_reopen is the canonical signal for the UI — computed once here.
    // Vue must not re-evaluate role or terminal conditions independently.
    // Service layer (reopenFolio → assertBookingNotTerminal) is the final authority.
    'reopen_folio'   => $user->hasRole('ADMIN') && $folio?->status === FolioStatus::Closed
                        && !$booking->status->isTerminal(),
    'add_payment'    => $user->can('payment.create') && !$booking->status->isTerminal(),
    'delete_payment' => $user->can('payment.delete') || $user->hasRole('ADMIN'),
    'check_out'      => $user->can('stay.checkout'),
],
'folio' => [
    // existing fields +
    'entries' => $this->formatFolioEntries($folio, $user),
],
```

### 3.2 BookingController::show() — Folio Entry Formatting

Add private method `formatFolioEntries(Folio $folio, User $user): array`:

```php
// Per entry:
[
    'id'               => $entry->id,
    'charge_type'      => $entry->charge_type->value,
    'charge_type_label'=> $entry->charge_type->label(),
    'description'      => $entry->description,
    'quantity'         => $entry->quantity,
    'unit_price'       => $entry->unit_price,
    'amount'           => $entry->amount,
    'entry_date'       => $entry->entry_date->format('Y-m-d'),
    'posted_by'        => $entry->postedBy?->name,
    'is_voided'        => $entry->voided_at !== null,
    'voided_at'        => $entry->voided_at?->format('Y-m-d H:i'),
    'voided_by'        => $entry->voidedBy?->name,
    'void_reason'      => $entry->void_reason,
    'is_system_entry'  => $entry->posting_key !== null,  // display flag only — service is authoritative
    'can_void'         => $entry->voided_at === null
                          && $entry->posting_key === null   // hides button; FolioService::voidEntry() is authoritative
                          && ($user->hasRole('ADMIN') || $entry->created_at->isToday()),
]
```

### 3.3 FolioEntryController::store() — No Change Required

Already delegates to `FolioService::addCharge()`. Amount is always computed server-side (ADR-16).

### 3.4 FolioEntryController::void() — Response Fix

Must redirect back with success flash, not just 200 OK. Verify redirect target is booking show with payments tab.

### 3.5 BookingPaymentController::store() — Response Consistency

Ensure response is always `redirect()->back()->with('success', '...')`. Verify payment_type routing to correct service method (already done in Phase 3.1B1).

### 3.6 StayController::checkOut() — Error Handling

Must catch `OutstandingBalanceException` and return redirect with flash error:

```php
try {
    $this->stayService->checkOut($stay, $request->actual_checkout_at);
} catch (OutstandingBalanceException $e) {
    return redirect()->back()->withErrors([
        'checkout' => "Không thể trả phòng: còn số dư {$e->getFormattedAmount()}",
    ]);
}
```

This exception is currently unhandled at the controller level — it bubbles up as a 500.

---

## 4. Service Interactions

Phase 3.1B2 adds one business invariant guard to `FolioService::voidEntry()`. All other service methods are used as-is.

| UI Action | Controller | Service Method |
|---|---|---|
| Add charge | FolioEntryController::store | FolioService::addCharge |
| Void entry | FolioEntryController::void | FolioService::voidEntry (**add posting_key invariant guard here — ADR-50**) |
| Close folio | FolioController::close | FolioService::closeFolio |
| Reopen folio | FolioController::reopen | FolioService::reopenFolio |
| Add payment | BookingPaymentController::store | BookingPaymentService::addDeposit / addPayment / addRefund |
| Delete payment | BookingPaymentController::destroy | BookingPaymentService::deletePayment |
| Check in stay | StayController::checkIn | StayService::checkIn |
| Check out stay | StayController::checkOut | StayService::checkOut → (last stay) → BookingService::finaliseBookingCheckout |
| Load payments tab | BookingController::show | BookingService::paymentSummary, FolioService (via payload) |

---

## 5. API Endpoints

All endpoints already exist. No new routes required.

| Method | URI | Controller Method | Phase 3.1B2 Change |
|---|---|---|---|
| `POST` | `/admin/bookings/{booking}/folio/entries` | FolioEntryController::store | None |
| `PATCH` | `/admin/bookings/{booking}/folio/entries/{entry}` | FolioEntryController::void | Response fix (§3.4) |
| `PATCH` | `/admin/bookings/{booking}/folio/close` | FolioController::close | None |
| `PATCH` | `/admin/bookings/{booking}/folio/reopen` | FolioController::reopen | None |
| `POST` | `/admin/bookings/{booking}/payments` | BookingPaymentController::store | None |
| `DELETE` | `/admin/bookings/{booking}/payments/{payment}` | BookingPaymentController::destroy | None |
| `POST` | `/admin/bookings/{booking}/stays/{stay}/check-in` | StayController::checkIn | None |
| `POST` | `/admin/bookings/{booking}/stays/{stay}/check-out` | StayController::checkOut | Add OutstandingBalanceException catch (§3.6) |
| `GET` | `/admin/bookings/{booking}` | BookingController::show | Payload additions (§3.1–3.2) |

---

## 6. Vue / Inertia Component Structure

### 6.1 Component Tree

```
Pages/Admin/Bookings/
└── Show.vue                          [MODIFY — orchestrates all tabs]
    ├── Partials/
    │   ├── BookingInfoPanel.vue      [EXISTING — booking details, requirements]
    │   ├── RoomBoardPanel.vue        [EXISTING — room assignments, check-in/out buttons]
    │   ├── FolioPanel.vue            [NEW — folio ledger + payments section]
    │   │   ├── FolioStatusBadge.vue  [NEW — Open/Closed/Voided badge]
    │   │   ├── FolioEntryTable.vue   [NEW — entries list with void button]
    │   │   ├── AddChargeForm.vue     [NEW — add folio entry form]
    │   │   ├── VoidEntryDialog.vue   [NEW — inline void confirmation dialog]
    │   │   ├── PaymentSummary.vue    [NEW — balance breakdown panel]
    │   │   ├── AddPaymentForm.vue    [NEW — add payment form with conditional fields]
    │   │   └── PaymentTable.vue      [NEW — payment history with delete]
    │   └── HistoryPanel.vue          [EXISTING or STUB — audit trail]
    └── Components/
        └── CheckoutReadinessBanner.vue  [NEW — balance due / ready indicator]
```

### 6.2 Show.vue Changes

Show.vue receives enhanced props from BookingController::show():

```typescript
const props = defineProps<{
  booking: BookingPayload          // full booking with folio + entries
  paymentSummary: PaymentSummary   // from BookingService::paymentSummary()
  activeTab: string
  options: {
    charge_types: Option[]
    payment_types: Option[]
    payment_methods: Option[]
  }
  can: CanPayload
}>()
```

Active tab managed via query param: `?tab=payments`. Inertia `router.reload({ only: ['booking', 'paymentSummary'] })` after each mutation.

**Canonical data rule:** `paymentSummary` is the single source for all financial values displayed in the UI. No child component (PaymentSummary.vue, AddPaymentForm.vue, CheckoutReadinessBanner.vue) derives balance, totals, or refund caps from the folio entries array or payment history array. All such values are read directly from the `paymentSummary` prop. This prevents drift from `calculateGuardedFolioTotal()` (ADR-43, ADR-51).

### 6.3 FolioPanel.vue

Receives:
```typescript
{
  folio: FolioPayload              // status, entries[], can_close, can_reopen
  paymentSummary: PaymentSummary
  bookingId: number
  can: CanPayload
  options: Options
}
```

Renders in two sections:

**Section 1 — Folio Ledger:**
- Folio number + `FolioStatusBadge`
- `FolioEntryTable` with entries sorted entry_date ASC
- "Add Charge" button → toggles `AddChargeForm` (inline, not modal)
- "Close Folio" button (can_close) → confirmation then PATCH
- "Reopen Folio" button (can_reopen) → confirmation then PATCH

**Section 2 — Payments:**
- `PaymentSummary` panel
- `CheckoutReadinessBanner`
- "Add Payment" button → toggles `AddPaymentForm`
- `PaymentTable`

### 6.4 FolioEntryTable.vue

```typescript
// Per row:
{
  id, charge_type, charge_type_label, description,
  quantity, unit_price, amount, entry_date,
  posted_by, is_voided, voided_at, voided_by,
  void_reason, is_system_entry, can_void
}
```

Row styling:
- Active entry: normal text
- Voided entry: `line-through text-muted`, shows void_reason in secondary row
- System entry (is_system_entry=true): no void button, "Hệ thống" label in posted_by column

### 6.5 AddChargeForm.vue

Fields:
- `charge_type` (select, required) — from `options.charge_types`
- `description` (text, required, max 255)
- `quantity` (number, min 0.01, step 0.01)
- `unit_price` (number, min 0, step 1000)
- `entry_date` (date, required, default today)
- Preview: `amount = quantity × unit_price` (computed, display only — server ignores)

Submit: `router.post('/admin/bookings/{id}/folio/entries', data, { onSuccess: reload })`

### 6.6 VoidEntryDialog.vue

Inline confirmation within the table row (accordion-style, not a modal):
- `void_reason` (textarea, required, min 5 chars)
- "Confirm Void" + "Cancel" buttons
- Submits: `router.patch('/admin/bookings/{id}/folio/entries/{entry}', { void_reason })`

### 6.7 PaymentSummary.vue

Displays grid of payment breakdown fields:

```
Tiền phòng (phí):     1.200.000 đ
Tiền đặt cọc:           400.000 đ
Thanh toán:             800.000 đ
Hoàn trả:                     0 đ
Điều chỉnh:                   0 đ
─────────────────────────────────
Đã thanh toán:        1.200.000 đ
Còn lại:                      0 đ   ← green if 0, red if > 0
```

### 6.8 AddPaymentForm.vue

Fields:
- `payment_type` (select) — from `options.payment_types`
- `amount` (number, min 0.01) — when Refund: max hint = `refundable_max`
- `payment_method` (select, conditional — hidden for Adjustment)
- `payment_at` (datetime, default now)
- `note` (textarea, optional)

When `payment_type === 'REFUND'`:
- Show helper: "Tối đa có thể hoàn: {refundable_max}đ"
- `refundable_max = max(0, paymentSummary.paid_total − paymentSummary.total_charges)` — reads directly from the `paymentSummary` prop; not derived from the payment list or folio entries independently

### 6.9 CheckoutReadinessBanner.vue

```
[READY TO CHECKOUT]  ← green, when balance_due === 0 AND has_active_stays
[BALANCE DUE: 1.200.000 đ — Settle before checkout]  ← red, when balance_due > 0
[BOOKING CHECKED OUT]  ← neutral, when status === 'CHECKED_OUT'
```

Displayed on both Payments tab and Room Board tab.

### 6.10 RoomBoardPanel.vue — Checkout Button Enhancement

Per stay, the checkout button logic:

```typescript
const isLastActiveStay = computed(() =>
  activeStays.value.length === 1 && stay.id === activeStays.value[0].id
)

const checkoutDisabled = computed(() =>
  isLastActiveStay.value && props.paymentSummary.balance_due > 0
)

const checkoutTooltip = computed(() =>
  checkoutDisabled.value
    ? `Còn số dư ${formatCurrency(paymentSummary.balance_due)} — thanh toán trước khi trả phòng`
    : 'Trả phòng'
)
```

If `isLastActiveStay && !checkoutDisabled`: show confirmation modal before submitting.

> **Security note:** `checkoutDisabled` is a UX affordance. A user can bypass the disabled button via a direct HTTP request or browser devtools. The server remains the authoritative enforcement point: `StayService::checkOut()` → `finaliseBookingCheckout()` → `OutstandingBalanceException` will reject any checkout with outstanding balance regardless of how the request was submitted. Both layers must exist: client improves experience, server guarantees correctness.

**Confirmation modal content:**
```
Xác nhận trả phòng cuối
─────────────────────────
Phòng: {room_number}
Số dư: 0 đ  ✓
Trả phòng sẽ hoàn tất booking này và đóng folio.
[Xác nhận] [Hủy]
```

---

## 7. State Transitions

### 7.1 Booking Status (UI-visible)

```
PendingAssignment ──add deposit──→ Deposited
FullyAssigned     ──check-in──→    CheckedIn / PartiallyCheckedIn
CheckedIn         ──add charge──→  CheckedIn (folio updates, status unchanged)
CheckedIn         ──add payment──→ CheckedIn (balance_due decreases)
CheckedIn (last)  ──check-out──→   CheckedOut [TERMINAL] (folio auto-closes)
CheckedIn (not last) ──check-out→  PartiallyCheckedOut
```

### 7.2 Folio Status (UI-visible)

```
Open   ──add charge──→ Open (balance increases)
Open   ──void entry──→ Open (balance decreases)
Open   ──close──→      Closed (manual)
Closed ──reopen──→     Open (ADMIN only, non-terminal booking)
Open   ──last checkout──→ Closed (auto, atomic via autoCloseFolio)
Open   ──cancel booking──→ Voided (via voidFolioOnCancellation)
```

### 7.3 FolioEntry States (UI-visible)

```
Active (voided_at IS NULL) → Void button visible
Voided (voided_at IS NOT NULL) → Strike-through, void_reason displayed, no Void button
System entry (posting_key IS NOT NULL) → No Void button regardless of void state
```

---

## 8. Permission Model

| Action | Gate | Role Override |
|---|---|---|
| View folio | `folio.view` | Admin always |
| Add charge | `charge.create` + folio Open | Admin always |
| Void own entry (today) | `charge.void` + created today | Admin: any entry |
| Void old entry | ADMIN only | — |
| Close folio | `folio.close` + folio Open | Admin always |
| Reopen folio | `can.can_reopen` from server (computed in BookingController::show) | Service (`assertBookingNotTerminal`) is final authority |
| Add payment | `payment.create` + booking not terminal | Admin always |
| Delete payment (today) | `payment.delete` + created today | Admin: any payment |
| Check in stay | `stay.checkin` | — |
| Check out stay | `stay.checkout` | — |

**UI enforcement:**
- Buttons are conditionally rendered (not just disabled) based on `can.*` props from server
- `can.*` flags are computed once in BookingController::show() and are the canonical source — Vue components must not re-derive or duplicate these conditions
- Server-side services (authorization + business invariants) are the final authority; UI gates are UX convenience only
- All `can.*` flags computed server-side in BookingController::show() (see §3.1)

---

## 9. Validation Rules

### 9.1 Add Charge (StoreFolioEntryRequest — already exists)

| Field | Rule |
|---|---|
| `charge_type` | required, in ChargeType::values() |
| `description` | required, string, max:255 |
| `quantity` | required, numeric, min:0.01 |
| `unit_price` | required, numeric, min:0 |
| `entry_date` | required, date |
| `posting_key` | prohibited (ADR-13) |
| `amount` | prohibited (ADR-16) |

### 9.2 Void Entry (VoidFolioEntryRequest — already exists)

| Field | Rule |
|---|---|
| `void_reason` | required, string, min:5, max:500 |

### 9.3 Add Payment (StoreBookingPaymentRequest — already exists)

| Field | Rule |
|---|---|
| `payment_type` | required, in PaymentType::values() |
| `amount` | required, numeric, min:0.01 |
| `payment_method` | required_unless:payment_type,ADJUSTMENT, in PaymentMethod::values() |
| `payment_at` | required, date |
| `note` | nullable, string, max:500 |

**Additional server-side rule for Refund (in BookingPaymentService::addRefund):**
- `amount` ≤ `calculatePaidTotal(booking)` — enforced via `RefundExceedsMaxException`

### 9.4 Check Out (CheckOutStayRequest — already exists)

| Field | Rule |
|---|---|
| `actual_checkout_at` | nullable, date |

**Additional server-side rules (in StayService::checkOut):**
- Stay must be CheckedIn
- `actual_checkout_at` ≥ `actual_checkin_at` (if provided)
- **Last stay: balance_due = 0** (enforced via `OutstandingBalanceException` in `finaliseBookingCheckout`)

---

## 10. Error Handling

### 10.1 Exception → HTTP → UI Mapping

| Exception | Service | HTTP Response | Vue Display |
|---|---|---|---|
| `OutstandingBalanceException` | finaliseBookingCheckout | Redirect + errors['checkout'] | Red flash message with amount |
| `FolioClosedException` | addCharge, voidEntry | Redirect + errors['folio'] | Yellow flash: "Folio đã đóng" |
| `FolioVoidedException` | addCharge, voidEntry | Redirect + errors['folio'] | Yellow flash: "Folio đã huỷ" |
| `BookingTerminalException` | all mutations | Redirect + errors['booking'] | Yellow flash: "Booking đã kết thúc" |
| `AlreadyVoidedException` | voidEntry | Redirect + errors['entry'] | Yellow flash: "Mục đã được huỷ" |
| `FolioHasActiveEntriesException` | cancelBooking | Redirect + errors['folio'] | Red flash: "Vui lòng huỷ các mục folio trước" |
| `RefundExceedsMaxException` | addRefund | Redirect + errors['amount'] | Red field error below amount input |
| `ValidationException` (StayService) | checkIn | Redirect + errors['stay'] | Red flash with Vietnamese message |

### 10.2 Exception Rendering

All domain exceptions in `app/Exceptions/` must implement `render()` to return a redirect response. **Currently OutstandingBalanceException lacks a controller-level catch** — this is the critical gap identified in §3.6.

For Inertia, the standard pattern is:
```php
// In Handler::register() or per-controller try/catch:
$exception->render(fn () => back()->withErrors(['...']));
```

Recommendation: Handle per-controller (not globally) to preserve context-specific messages.

### 10.3 Vue Flash Messages

Flash messages follow a single shared path. No page component handles flash independently.

```
Server:          redirect()->back()->with('success', '...')
                 redirect()->back()->withErrors([...])
                        │
                        ▼
Inertia middleware: HandleInertiaRequests::share()
                 → $page.props.flash = { success, error }
                        │
                        ▼
AppLayout.vue:   reads $page.props.flash once
                 → renders <FlashMessage> component
                        │
                        ▼
All pages:       inherit flash display via AppLayout automatically.
                 No per-page flash handling.
```

```typescript
// HandleInertiaRequests::share():
'flash' => [
    'success' => session('success'),
    'error'   => session('error'),
],

// AppLayout.vue:
const flash = usePage().props.flash as { success?: string; error?: string }
// Renders <FlashMessage :success="flash.success" :error="flash.error" />
```

`FlashMessage.vue` is a single shared component. All error handling paths (OutstandingBalanceException, FolioClosedException, etc.) surface through this infrastructure — no page builds its own toast or alert independently.

---

## 11. Regression Risks

| Risk | Severity | Mitigation |
|---|---|---|
| R-01: `calculateGuardedFolioTotal()` used inconsistently | CRITICAL | `paymentSummary()` already uses it as the sole financial source. `paymentSummary` prop is the canonical source in the UI — no Vue component derives totals, balances, or refund caps from other data sources (folio entries array, payment rows). Any independent derivation bypasses the transition guard in ADR-11 and the bcmath precision of ADR-45. |
| R-02: Checkout triggered without Booking lock by UI bypass | CRITICAL | Controller must always route through StayController → StayService → canonical lock order. No shortcut checkout endpoint. |
| R-03: `posting_key` or `amount` accepted from HTTP form | HIGH | StoreFolioEntryRequest already prohibits these. Verify no bypass via custom controllers. |
| R-04: Folio reopen allowed on terminal booking | HIGH | `can_reopen` flag in §3.1 is false when booking.status.isTerminal(). Server-side: `reopenFolio()` calls `assertBookingNotTerminal()`. |
| R-05: Delete payment on terminal booking | HIGH | `BookingPaymentPolicy::delete()` checks created_at→isToday() but does NOT check terminal. Must verify `BookingTerminalException` is thrown by `deletePayment()` service method. |
| R-06: Folio entry void for system entries (room charge) | MEDIUM | `is_system_entry` flag hides Void button (display convenience). Authoritative guard: `FolioService::voidEntry()` throws a domain exception when `posting_key !== null`. This is a business invariant, not an authorization rule — must NOT be placed in FolioEntryPolicy (ADR-50). |
| R-07: Multi-stay checkout race: two stays checked out concurrently | MEDIUM | `finaliseBookingCheckout()` uses Booking lockForUpdate — only one will win. The other will find booking in CheckedOut state and abort. Covered by ADR-38. |
| R-08: refundable_max client hint diverges from server cap | LOW | Max is a hint only; server enforces via `RefundExceedsMaxException`. Display is advisory. |
| R-09: Balance_due display out of sync after Inertia partial reload | LOW | Use `router.reload({ only: ['booking', 'paymentSummary'] })` after every mutation. |

---

## 12. Test Plan

### 12.1 Feature Tests (PHP / Pest)

**FolioUiTest:**
- [ ] Admin can view folio entries on booking show page
- [ ] Non-admin with charge.create can add charge to open folio
- [ ] Cannot add charge when folio is Closed (FolioClosedException → redirect with error)
- [ ] Cannot add charge when folio is Voided (FolioVoidedException → redirect with error)
- [ ] Cannot add charge to terminal booking (BookingTerminalException → redirect with error)
- [ ] System room charge entry is not voidable via void endpoint — `FolioService::voidEntry()` throws domain exception when posting_key != null (business invariant, not policy)
- [ ] Void entry with valid reason succeeds
- [ ] Void entry with reason < 5 chars fails validation
- [ ] Cannot void already-voided entry (AlreadyVoidedException → redirect with error)
- [ ] Admin can close open folio
- [ ] Non-admin without folio.close cannot close folio
- [ ] Admin can reopen closed folio (non-terminal booking)
- [ ] Admin cannot reopen folio for terminal booking
- [ ] Non-admin cannot reopen folio

**PaymentUiTest:**
- [ ] Admin can add deposit; booking transitions to Deposited
- [ ] Admin can add room payment
- [ ] Admin can add refund up to paid_total
- [ ] Refund exceeding paid_total is rejected (RefundExceedsMaxException)
- [ ] Terminal booking blocks all payment mutations
- [ ] Admin can delete payment
- [ ] Non-admin can delete own payment (created today)
- [ ] Non-admin cannot delete old payment

**CheckoutUiTest:**
- [ ] Check out with zero balance succeeds; booking → CheckedOut, folio → Closed
- [ ] Check out last stay with balance > 0 redirects with OutstandingBalanceException error
- [ ] Check out non-last stay succeeds regardless of balance (intermediate checkout)
- [ ] After checkout: payment summary shows balance_due = 0 and status = CheckedOut
- [ ] Flash error message shown after failed checkout

### 12.2 Browser / E2E Tests (Playwright)

Critical user flows:

- [ ] **Normal checkout flow:** Add charge → add payment → balance = 0 → check out → booking CheckedOut
- [ ] **Blocked checkout:** Try checkout with balance → see error → add payment → retry → succeed
- [ ] **Void entry:** Add charge → void it → balance updates
- [ ] **Refund flow:** Add payment → add refund → verify max cap enforced
- [ ] **Folio close/reopen:** Close folio → verify add charge blocked → reopen → add charge

### 12.3 Existing Tests to Protect

All tests from Phase 3.1B1 (`CheckoutIntegrationTest`, `FolioCrudTest`, `PaymentCrudTest`) must continue to pass. Run full suite after each UI change.

---

## 13. Implementation Scope

### Phase 3.1B2 includes:

| # | Item | Type |
|---|---|---|
| S-01 | BookingController::show() payload additions (§3.1–3.2) | PHP — controller |
| S-02 | StayController::checkOut() OutstandingBalanceException catch (§3.6) | PHP — controller |
| S-03 | FolioService::voidEntry() — add `posting_key !== null` business invariant guard; throws `SystemEntryVoidException` before any void proceeds. Not encoded in FolioEntryPolicy (ADR-50) | PHP — service |
| S-04 | FolioPanel.vue — new component (folio ledger + payments, §6.3) | Vue |
| S-05 | FolioStatusBadge.vue — status badge | Vue |
| S-06 | FolioEntryTable.vue — entries list with void | Vue |
| S-07 | AddChargeForm.vue — inline add charge form | Vue |
| S-08 | VoidEntryDialog.vue — inline void confirmation | Vue |
| S-09 | PaymentSummary.vue — breakdown panel | Vue |
| S-10 | AddPaymentForm.vue — add payment with conditional fields | Vue |
| S-11 | PaymentTable.vue — payment history with delete | Vue |
| S-12 | CheckoutReadinessBanner.vue — balance status indicator | Vue |
| S-13 | RoomBoardPanel.vue — enhance checkout button with balance gate + confirmation modal | Vue |
| S-14 | Show.vue — wire new components, handle tab routing | Vue |
| S-15 | AppLayout.vue — add shared `FlashMessage` component wired to Inertia `$page.props.flash`. All pages inherit flash display via AppLayout. No per-page flash handling (see §10.3) | Vue |
| S-16 | Feature tests: FolioUiTest, PaymentUiTest, CheckoutUiTest | Pest |
| S-17 | E2E tests: normal checkout, blocked checkout, void, refund, close/reopen | Playwright |

### Implementation order (dependency-aware):

```
1. S-01 (controller payload) — enables all Vue to receive data
2. S-02 (exception catch) — enables clean UI error flow
3. S-03 (service invariant guard) — closes system-entry void gap
4. S-15 (FlashMessage) — enables all error display
5. S-05 (FolioStatusBadge)
6. S-06 (FolioEntryTable) + S-07 (AddChargeForm) + S-08 (VoidEntryDialog)
7. S-09 (PaymentSummary) + S-10 (AddPaymentForm) + S-11 (PaymentTable)
8. S-12 (CheckoutReadinessBanner)
9. S-04 (FolioPanel — assembles above components)
10. S-13 (RoomBoardPanel — checkout gate)
11. S-14 (Show.vue — wire everything)
12. S-16 (feature tests)
13. S-17 (E2E tests)
```

---

## 14. Out-of-Scope Items

These are explicitly excluded from Phase 3.1B2:

| Item | Reason |
|---|---|
| Service charge module (Phase 3.3 design exists) | Separate phase; ChargeType enum supports it but billing logic not yet designed |
| Group billing / split folio | Future phase |
| PDF folio/invoice generation | Future phase |
| Email receipt on checkout | Future phase |
| Refund workflow with approval gate | Simplified refund is in scope; approval gate is not |
| Room charges by night (nightly breakdown) | Current design: aggregate charge per booking |
| FolioEntry pagination | Out of scope; ~50 entries max per booking per current design |
| Currency conversion | Out of scope; VND only |
| External payment gateway integration | Out of scope |
| Real-time balance push (WebSocket) | Out of scope; Inertia reload is sufficient |

---

## 15. Open Questions

| # | Question | Impact | Recommended Default |
|---|---|---|---|
| OQ-01 | Should system room charge (posting_key) be visible to non-ADMIN users? | Low — informational only | Yes, visible to all with folio.view |
| OQ-02 | Should the Add Charge form be a modal or inline accordion? | UX only | Inline accordion (consistent with existing forms in Show.vue) |
| OQ-03 | Should Adjustments require a reason field? | Medium — audit trail | Yes, treat reason as required for Adjustment type (add to StoreBookingPaymentRequest conditionally) |
| OQ-04 | Is FolioEntryPolicy::void() today-window applied per entry created_at or entry_date? | Medium — correctness | `created_at` (system posting time, not the charge date). Current policy uses created_at. |
| OQ-05 | Can non-admin staff reopen folio? | Security — HIGH | **RESOLVED.** Reopen is ADMIN-only. `can_reopen` in BookingController::show() enforces this centrally. UI renders the button only when `can.can_reopen === true`. No Vue component evaluates role conditions independently (ADR-54). |
| OQ-06 | Should checkout confirmation modal show full payment breakdown or just balance_due? | UX only | Show balance_due only (full breakdown visible on Payments tab) |
| OQ-07 | When balance_due > 0 and checkout is attempted: should the system redirect to Payments tab? | UX — medium | Yes. Redirect to `?tab=payments` with error flash |
| OQ-08 | Should partial payments be tracked as "paid" toward balance? | Backend — currently YES via paid_total in paymentSummary | Confirmed — all payment types except Refund reduce balance |
| OQ-09 | Should Add Charge form default entry_date to today or to booking check-in date? | UX only | Today (receptionist charges typically current date) |
| OQ-10 | What happens if folio is null (booking never got a folio)? | Edge case — should never happen per Phase 3.1A | Log error, show "Folio chưa được tạo" with ADMIN-only repair button |

---

## 16. ADR Impacts

Phase 3.1B2 introduces no new architectural decisions at the service layer. All existing ADRs are preserved.

The following UI-layer decisions should be recorded:

| ADR (proposed) | Decision |
|---|---|
| ADR-50 | Voiding a system-posted folio entry (posting_key != null) is a business invariant violation, not an authorization failure. `FolioService::voidEntry()` throws a domain exception when posting_key is not null, before any write proceeds. This guard must NOT be placed in FolioEntryPolicy — the policy handles access control (who can act), the service handles domain rules (what is allowed). The UI hides the Void button as a display convenience only; the service guard is the authoritative enforcement point. |
| ADR-51 | `paymentSummary` (from `BookingService::paymentSummary()`) is the single canonical source for all payment-related values displayed in the UI: `total_charges`, `balance_due`, `paid_total`, `total_deposit`, `total_refund`, `total_adjustment`, `total_payment`. Vue components read from this prop directly. They must never derive, aggregate, or mix these values from other sources (folio entries array, payment history array, booking requirements). Any independent derivation bypasses the `calculateGuardedFolioTotal()` transition guard (ADR-11, ADR-43) and bcmath precision (ADR-45). |
| ADR-52 | The checkout button is disabled client-side when `balance_due > 0` AND the stay is the last active stay. This is a UX affordance only — it can be bypassed via a direct HTTP request. The authoritative enforcement is `OutstandingBalanceException` thrown by `finaliseBookingCheckout()` within the server-side transaction, which rejects any checkout with an outstanding balance regardless of request origin. Both layers must exist and must not be collapsed: the client gate improves experience, the server gate guarantees correctness. |
| ADR-53 | `OutstandingBalanceException` is caught per-controller in StayController::checkOut() and returned as a redirect with error flash; it does not propagate to the global exception handler |
| ADR-54 | `can_reopen` is computed once in `BookingController::show()` and passed to the UI as the canonical signal. Vue components render or hide the Reopen button solely based on `can.can_reopen` — they must not re-evaluate role conditions or booking terminal status. The service layer (`reopenFolio → assertBookingNotTerminal`) is the authoritative final guard. Duplicating the terminal check in Vue would create drift risk when booking status transitions evolve. |

---

## Summary for ChatGPT Architecture Review

**Critical Issues:**

1. **Missing `OutstandingBalanceException` handler in `StayController::checkOut()`** — currently propagates as HTTP 500. Must be caught per-controller and returned as a redirect with flash error. Without this, checkout failure produces a fatal error rather than a recoverable UI state.

2. **`FolioService::voidEntry()` does not guard against voiding system entries** — `posting_key !== null` entries (the aggregate room charge) can be voided via a direct API call, silently corrupting `calculateGuardedFolioTotal()`. Must add a domain exception throw in `FolioService::voidEntry()` when `posting_key` is not null. This is a business invariant, not an authorization concern — it must NOT be placed in `FolioEntryPolicy` (ADR-50).

**High Issues:**

3. **`BookingController::show()` does not include `payment_summary` in payload** — `PaymentSummary.vue` and all financial display components cannot render without it. Must add `$this->bookingService->paymentSummary($booking)` to the show payload. This is also the prerequisite for ADR-51 (canonical financial source).

4. **No FlashMessage infrastructure in `AppLayout.vue`** — server-side redirect flash data (`session('success')`, `session('error')`) is not surfaced to any page. All exception handling paths (OutstandingBalanceException, FolioClosedException, etc.) depend on this infrastructure. Must wire `HandleInertiaRequests::share()` → `AppLayout` → `FlashMessage` component before any error handling path is functional.

5. **Checkout button has no balance gate** — `RoomBoardPanel` has no awareness of `balance_due`. Staff attempting last-stay checkout with unpaid balance hit a server error instead of a clear UI warning. Must add client-side gate (UX only) with server remaining authoritative via `OutstandingBalanceException` (ADR-52).

**Medium Issues:**

6. **Refund max cap hint not shown** — When `payment_type === REFUND` is selected, no hint displays the maximum refundable amount. Must read `paymentSummary.paid_total` and `paymentSummary.total_charges` to show the cap advisory. Server enforces the hard limit via `RefundExceedsMaxException` regardless.

7. **Client-side amount preview uses JS floating-point arithmetic** — `quantity × unit_price` in the browser may diverge from `bcmul($quantity, $unitPrice, 2)` on the server for certain decimal values. Preview is advisory only and clearly labelled as such; server value is authoritative (ADR-16). No fix required, but must be documented so future developers do not treat the client preview as a validation gate.

**Ready For ChatGPT Architecture Review:** Yes — all 16 sections complete and updated per review feedback. 2 critical + 3 high + 2 medium issues. Five architectural clarifications applied: service-layer invariant enforcement, canonical `can_reopen` flag, single financial source (`paymentSummary`), shared FlashMessage infrastructure, and explicit checkout security layering.
