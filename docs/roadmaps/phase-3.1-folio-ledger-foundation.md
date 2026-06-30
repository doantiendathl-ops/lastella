# Phase 3.1 — Folio / Ledger Foundation: Architecture Design

**Date:** 2026-06-27 (revised — Codex review pass 7)
**Status:** Design — Pending Final Codex Review
**Branch:** phase-3

> **Revision note (pass 6):** Addresses 5 remaining blockers from the fifth Codex review.
> Changes: shared `calculateGuardedFolioTotal()` used by all balance paths including atomic
> checkout; `autoCloseFolio()` idempotent for already-closed folio; `voidFolioOnCancellation()`
> made public (service-only boundary); `deletePayment()` blocked on terminal bookings;
> `deletePayment()` canonical lock order extended to include Folio row.
>
> **Revision note (pass 7):** Addresses 1 remaining Critical issue from the sixth Codex review.
> Change: `calculateGuardedFolioTotal()` now handles VOIDED folios as a first-class case —
> returns `0.0` immediately without applying the requirements estimate fallback or active entry
> calculation. VOIDED folio means there are no active financial charges; `balance_due = 0 −
> paid_total`; `max_refundable = paid_total`. If a folio has active entries it must not be VOIDED.

---

## Table of Contents

1. [Current Financial Code Status](#1-current-financial-code-status)
2. [Existing Tables / Models / Services](#2-existing-tables--models--services)
3. [Proposed Folio Charge Ledger Architecture](#3-proposed-folio-charge-ledger-architecture)
   - 3.1 Design Principle: Payment ≠ Charge
   - 3.2 Entity Relationships
   - 3.3 Auto-Folio Creation
   - 3.4 ADR-1 — Room Charge Posting Strategy
   - 3.5 ADR-2 — Room Charge Idempotency via Posting Key (Internal Only)
   - 3.6 ADR-3 — Nightly Charge Architecture Readiness
   - 3.7 Entry Date vs Posting Timestamp Semantics
   - 3.8 Canonical Lock Order and Folio Mutation Rules
   - 3.9 Shared Balance Calculation Method
   - 3.10 Payment Mutation Transaction Rules
4. [Data Model](#4-data-model)
5. [Charge Types](#5-charge-types)
6. [Payment Entries](#6-payment-entries)
7. [Balance Calculation](#7-balance-calculation)
8. [Relationship with Booking / Stay / Room Assignment](#8-relationship-with-booking--stay--room-assignment)
9. [Checkout Dependency, Folio Close Timing, and Terminal Booking Rules](#9-checkout-dependency-folio-close-timing-and-terminal-booking-rules)
10. [Risks](#10-risks)
11. [Test Plan](#11-test-plan)
12. [Implementation Steps](#12-implementation-steps)
13. [Ready for Implementation](#13-ready-for-implementation)
14. [Architecture Decision Summary](#14-architecture-decision-summary)

---

## 1. Current Financial Code Status

### What Exists (Pre Phase 3.1)

| Capability | Status |
|---|---|
| Record incoming payments (Deposit, RoomPayment, etc.) | ✅ Exists — `booking_payments` table (Phase 2) |
| Calculate `paid_total` (net sum of payments minus refunds) | ✅ Exists — `BookingPaymentService::calculatePaidTotal()` |
| Estimate expected total from room requirements | ✅ Exists — `BookingService::paymentSummary()` |
| Delete payments with role-based time window | ❌ Missing |
| Refund validation (cannot exceed overpayment) | ❌ Missing |
| Payment summary breakdown (deposit / payment / refund / adjustment) | ❌ Missing |
| Folio / charge ledger (actual itemised charges) | ❌ Missing |
| Itemised charge entries (room, F&B, minibar, damage) | ❌ Missing |
| Void charge with reason and audit trail | ❌ Missing |
| Folio close / reopen lifecycle | ❌ Missing |
| Auto-post room charge on check-in | ❌ Missing |
| Accurate balance formula using actual charges | ❌ Missing |

### The `expected_total` Problem

`paymentSummary()` currently computes `expected_total` as `SUM(bookingRequirements.room_price × quantity)` — a mutable planning estimate, not an accounting record. It can be edited after check-in, has no place for ad-hoc charges, and produces an unreliable checkout gate. Phase 3.1 replaces it with a **folio charge ledger**.

### Scope of Phase 3.1

**Phase 3.1A — Payment Foundation:**
Fix `addDeposit()` downgrade bug; add delete payment (role + time window; blocked on terminal bookings); add refund with `max_refundable` cap; add signed Adjustment (ADMIN-only); expand `paymentSummary()` with 9-key breakdown; all payment mutations use canonical lock order with Folio row.

**Phase 3.1B1 — Folio Backend Ledger:**
`folios`, `folio_entries`, `folio_number_sequences` tables; `FolioService` with shared `calculateGuardedFolioTotal()` method; idempotent `autoCloseFolio()`; public `voidFolioOnCancellation()`; `voidEntry()` requires open folio; atomic checkout finalisation using guarded balance.

**Phase 3.1B2 — UI, Checkout Integration, and Backfill:**
Folio tab; corrected balance formula live; atomic checkout gate; data backfill; UI shows balance for CheckedOut bookings.

---

## 2. Existing Tables / Models / Services

### `bookings` Table (Phase 2)

```
id, booking_code (unique), customer_name, customer_phone, customer_email,
customer_type, booking_type, checkin_at, checkout_at, adults, children,
status (indexed), sales_user_id, created_by, updated_by,
cancelled_at, cancelled_by, cancellation_reason, note, internal_note,
timestamps
```

`BookingStatus::isTerminal()` returns true for Cancelled, NoShow, CheckedOut. Terminal booking charge and payment rules are in §9.

### `booking_requirements` Table (Phase 2)

```
id, booking_id (FK), room_type_id (FK), quantity, adults, children,
room_price (DECIMAL 12,2), price_source (enum), note, timestamps
Index: (booking_id, room_type_id)
```

`room_price` = total agreed price for this room type across the entire stay (not per-night). `room_price` and `quantity` are locked once the system room charge is posted — see ADR-4 and §3.8.

### `booking_payments` Table (Phase 2)

```
id, booking_id (FK ON DELETE CASCADE), payment_type (enum, indexed), amount (DECIMAL 12,2),
payment_method (enum), payment_at (datetime, indexed), confirmed_by (FK users), note, timestamps
```

`amount` is always stored as a positive value **except** for `Adjustment`, which may be positive or negative (see §6 and ADR-24). The CASCADE on `booking_id` is Phase 2 tech debt — not changed in Phase 3.1 (see R10).

### `room_assignments` / `stays` Tables (Phase 2)

Standard occupancy tables — unchanged in Phase 3.1.

### Existing Services (Pre Phase 3.1)

| Service | Relevant Methods |
|---|---|
| `BookingPaymentService` | `addDeposit()`, `addPayment()`, `addRefund()`, `calculatePaidTotal()` |
| `BookingService` | `paymentSummary()`, `updateBookingStayStatus()` |
| `StayService` | `checkIn()`, `checkOut()`, `checkInMany()`, `checkOutMany()` |
| `RoomAssignmentService` | `assignRooms()`, `releaseAssignment()` |

---

## 3. Proposed Folio Charge Ledger Architecture

> **Terminology:** This system implements a **folio charge ledger** (single-sided). Charges are
> recorded in `folio_entries`. Payments are recorded in `booking_payments`. Balance is the
> arithmetic difference. No double-entry journal exists.

### 3.1 Design Principle: Payment ≠ Charge

```
PAYMENTS (booking_payments)        CHARGES (folio_entries)
= money the guest PAYS             = money the guest OWES

Deposit                            Room charge
AdditionalDeposit                  F&B charge
RoomPayment                        Minibar charge
ServicePayment                     Damage charge
Refund (reduces paid_total)        Late checkout fee
Adjustment (signed, ADMIN only)    ...
```

`balance_due = folio_total − paid_total`

### 3.2 Entity Relationships

```
Booking (1) ────────────────────────────── (1) Folio
    │                                              │
    ├── (many) BookingPayment                (many) FolioEntry
    ├── (many) BookingRequirement
    ├── (many) RoomAssignment
    └── (many) Stay
```

One Folio per Booking in Phase 3.1.

### 3.3 Auto-Folio Creation

A Folio is created automatically in `BookingService::createBooking()`. No manual create step. Starts in `OPEN` status.

---

### 3.4 ADR-1 — Room Charge Posting Strategy

**Decision: Option A — one aggregate room charge auto-posted at first check-in.**

One `FolioEntry` of type `room` is posted when the first Stay checks in. Amount = `SUM(requirement.room_price × quantity)` across all freshly-locked requirements. Idempotency enforced via `posting_key`. Option B (per-stay) adds fragile join complexity. Option C (Night Audit) requires Night Audit before checkout can work.

**Trade-off:** Multi-room bookings charge all requirements at first check-in. Date changes require void + re-post. Per-stay breakdown is Phase 3.3.

---

### 3.5 ADR-2 — Room Charge Idempotency via Posting Key (Internal Only)

`posting_key VARCHAR(100) NULL` on `folio_entries`. System entries carry a deterministic key; manual charges are NULL. **`posting_key` is never accepted from HTTP requests** — enforced at three layers.

#### Posting Key Hierarchy

| Phase | Entry | Format |
|---|---|---|
| 3.1 | Aggregate room charge | `ROOM_CHARGE_{booking_id}_AGGREGATE` |
| 3.3 | Per-stay room charge | `ROOM_CHARGE_{booking_id}_STAY_{stay_id}` |
| Night Audit | Nightly per stay | `ROOM_CHARGE_{booking_id}_STAY_{stay_id}_NIGHT_{YYYYMMDD}` |

#### Enforcement Layers

| Layer | Rule |
|---|---|
| `StoreFolioEntryRequest` | `posting_key` in request → 422 |
| `FolioService::addCharge()` | Always sets `posting_key = NULL` |
| `FolioService::postSystemEntry()` | **Private** — only callable by `doPostRoomCharge()` and future system jobs |

#### `autoPostRoomCharge()` — Public Entry Point + Private Implementation

```php
// PUBLIC — called by StayService::checkIn()
public function autoPostRoomCharge(Booking $booking, ?User $postedBy = null): ?FolioEntry
{
    return $this->doPostRoomCharge(
        $booking,
        "ROOM_CHARGE_{$booking->id}_AGGREGATE",
        $postedBy
    );
}

// PRIVATE — handles locking, idempotency, and posting
private function doPostRoomCharge(Booking $booking, string $postingKey, ?User $postedBy): ?FolioEntry
{
    return DB::transaction(function () use ($booking, $postingKey, $postedBy) {
        // Canonical lock order: Booking → Requirements (sorted id) → Folio
        $booking->lockForUpdate()->refresh();

        $requirements = $booking->bookingRequirements()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $folio = $booking->folio()->lockForUpdate()->firstOrFail();

        $alreadyPosted = $folio->folioEntries()
            ->where('posting_key', $postingKey)
            ->whereNull('voided_at')
            ->exists();

        if ($alreadyPosted) {
            return null; // Idempotent — charge already posted; returns null
        }

        $amount = $requirements->sum(fn($r) => $r->room_price * $r->quantity);

        return $this->postSystemEntry($folio, [
            'charge_type' => ChargeType::Room,
            'description' => 'Tiền phòng',
            'quantity'    => 1,
            'unit_price'  => $amount,
            'entry_date'  => now()->toDateString(),
            'posted_by'   => $postedBy?->id,
        ], $postingKey);
    });
}

// PUBLIC — always computes amount server-side; posting_key = NULL
public function addCharge(Folio $folio, array $data): FolioEntry
{
    return DB::transaction(function () use ($folio, $data) {
        $folio->lockForUpdate()->refresh();
        if ($folio->status !== FolioStatus::Open) {
            throw new FolioClosedException();
        }
        return $this->createEntry($folio, $data, postingKey: null);
    });
}

private function postSystemEntry(Folio $folio, array $data, string $postingKey): FolioEntry
{
    // Precondition: caller already holds Folio lock within the same transaction
    if ($folio->status !== FolioStatus::Open) {
        throw new FolioClosedException();
    }
    return $this->createEntry($folio, $data, postingKey: $postingKey);
}

private function createEntry(Folio $folio, array $data, ?string $postingKey): FolioEntry
{
    $amount = bcmul((string) $data['quantity'], (string) $data['unit_price'], 2);

    return FolioEntry::create([
        'folio_id'    => $folio->id,
        'charge_type' => $data['charge_type'],
        'description' => $data['description'],
        'quantity'    => $data['quantity'],
        'unit_price'  => $data['unit_price'],
        'amount'      => $amount,
        'entry_date'  => $data['entry_date'],
        'posting_key' => $postingKey,
        'posted_by'   => $data['posted_by'] ?? null,
    ]);
}
```

**Why no DB UNIQUE constraint on `posting_key`:** Voided entries retain their `posting_key` for audit. A unique index would block re-posting after void. `lockForUpdate()` on Booking + Folio provides equivalent serialisation.

---

### 3.6 ADR-3 — Nightly Charge Architecture Readiness

| Constraint | How Phase 3.1 Satisfies It |
|---|---|
| Night Audit can post multiple room entries | No UNIQUE on `(folio_id, charge_type)` |
| Each nightly entry is dateable | `entry_date DATE` required and indexed |
| Night Audit can void Phase 3.1 aggregate | `voidEntry()` accepts any FolioEntry (on open folio) |
| Night Audit posting keys non-colliding | `_STAY_{y}_NIGHT_{d}` ≠ `_AGGREGATE` |
| Per-stay attribution addable later | `stay_id` nullable FK added in Phase 3.3 |

**Closed folio migration:** Historical folios need ADMIN `reopenFolio()` → void aggregate → post nightly entries → `closeFolio()`. Live Night Audit posts to open folios — no reopening needed.

---

### 3.7 Entry Date vs Posting Timestamp Semantics

| Field | Type | Meaning |
|---|---|---|
| `entry_date` | `DATE` | Service date — when service was consumed. Backdatable with MANAGER+. |
| `created_at` | `TIMESTAMP` | Posting timestamp — when record was inserted. Never editable. |

---

### 3.8 Canonical Lock Order and Folio Mutation Rules

Every folio mutation acquires row locks **before** reading state. The same lock order is used by all operations to prevent deadlocks.

#### Canonical Lock Order

```
1. Booking row                        (when operation spans booking + folio)
2. BookingRequirement rows (id ASC)   (only autoPostRoomCharge and updateRequirement)
3. Folio row                          (always before its entries or payments)
4. FolioEntry rows (id ASC)           (parent Folio locked first)
5. BookingPayment rows (id ASC)       (refund, adjustment, deletePayment, checkout finalisation)
```

All operations acquire only the rows they need from this hierarchy in this order.

#### `addCharge(Folio, array): FolioEntry` — PUBLIC

```
DB::transaction:
  1. Lock Folio row (lockForUpdate)
  2. Re-read folio.status AFTER lock
  3. Throw FolioClosedException if status != 'open'
  4. Call createEntry() — amount computed server-side; posting_key = NULL
```

#### `voidEntry(FolioEntry, User, string): void` — PUBLIC

```
DB::transaction:
  1. Lock Folio row (lockForUpdate)           ← Folio FIRST (canonical order)
  2. Re-read folio.status AFTER lock
  3. Throw FolioClosedException if status == 'closed'
  4. Throw FolioVoidedException if status == 'voided'
     ↳ Corollary: a VOIDED folio has no active entries — there is nothing to void.
  5. Lock FolioEntry row (lockForUpdate)      ← Entry SECOND
  6. Re-read entry.voided_at AFTER lock
  7. Throw AlreadyVoidedException if voided_at IS NOT NULL
  8. Set voided_at, voided_by, void_reason
```

#### `closeFolio(Folio, User): void` — PUBLIC (staff action)

```
DB::transaction:
  1. Lock Folio row (lockForUpdate)
  2. Re-read folio.status AFTER lock
  3. Throw FolioNotOpenException if status != 'open'
  4. Permission check: user must have 'folio.close'
  5. Set status = 'closed', closed_at = now(), closed_by = $user->id
```

#### `autoCloseFolio(Folio): void` — PUBLIC (system-only; no controller route; idempotent)

```
Called by BookingService::updateBookingStayStatus() within the checkout
finalisation transaction. The Folio row is already locked by the caller.

  1. Lock Folio row (lockForUpdate) — safe to re-lock in same MySQL transaction
  2. Re-read folio.status AFTER lock
  3. If status == 'closed':  return   ← IDEMPOTENT — staff already closed it; this is fine
  4. If status == 'voided':  throw FolioVoidedException   ← voided folio cannot represent checkout
  5. (status == 'open')
     Set status = 'closed', closed_at = now(), closed_by = NULL
```

**Idempotency rationale:** Staff may call `closeFolio()` manually before all stays physically check out (e.g., guest paid in advance). When the booking later reaches `CheckedOut` financially, `autoCloseFolio()` is called again. Because it is idempotent on `CLOSED`, this succeeds silently — the system does not fight the staff action. `VOIDED` is rejected because a voided folio cannot represent a financially completed checkout; that indicates a data error.

**Public because:** `BookingService` and `FolioService` are separate classes. PHP cannot call private methods across class boundaries. No HTTP route ever maps to `autoCloseFolio()`. The policy layer must ensure no controller exposes this method.

#### `reopenFolio(Folio): void` — PUBLIC (ADMIN only)

```
DB::transaction:
  1. Lock Folio row (lockForUpdate)
  2. Re-read folio.status AFTER lock
  3. Throw FolioNotClosedException if status != 'closed'
  4. Clear closed_at, closed_by
  5. Set status = 'open'
```

Restricted to ADMIN role.

#### `voidFolioOnCancellation(Folio): void` — PUBLIC (system-only; no controller route)

```
Called by BookingService when booking transitions to Cancelled or NoShow:

DB::transaction:
  1. Lock Folio row (lockForUpdate)
  2. Re-read folio.status AFTER lock
  3. Count active entries (WHERE voided_at IS NULL) AFTER lock
  4. If active entry count > 0:
       throw FolioHasActiveEntriesException
       (caller catches this: leaves folio OPEN for settlement)
  5. Set status = 'voided'
```

**Public because:** `BookingService` must call it across class boundaries. No HTTP route maps to it.

**Caller responsibility:** `BookingService` catches `FolioHasActiveEntriesException` and proceeds — the folio remains OPEN for staff to settle the outstanding fee before closing it manually.

#### Atomic Checkout Finalisation (ADR-28)

`updateBookingStayStatus()` computes balance and conditionally closes the folio inside **one atomic transaction** with all relevant rows locked. No concurrent charge or payment can alter state between the balance read and the close.

```
finaliseBookingCheckout(Booking $booking):

DB::transaction:
  1. Lock Booking row (lockForUpdate)
  2. Re-read booking.status; verify all stays physically checked out from DB
     → If not all out: exit early (no status change)
  3. Lock Folio row (lockForUpdate)
  4. Lock all FolioEntry rows for this folio (sorted id ASC, lockForUpdate)
  5. Lock all BookingPayment rows for this booking (sorted id ASC, lockForUpdate)
  6. folio_total  = FolioService::calculateGuardedFolioTotal($booking)  ← shared guarded method
  7. paid_total   = calculatePaidTotal($booking)                          ← from locked payment rows
  8. balance_due  = folio_total − paid_total
  9. If all out AND balance_due <= 0:
        booking.update(status = CheckedOut)
        autoCloseFolio($folio)    ← idempotent; Folio already locked in this transaction
  10. Else if all out AND balance_due > 0:
        booking.update(status = PartiallyCheckedOut)
        (folio stays OPEN — settlement pending)
```

**Why lock FolioEntries:** `addCharge()` locks only the Folio row. By locking all FolioEntry rows in the finalisation transaction, a concurrent `addCharge()` blocks on the Folio lock. After finalisation commits with folio `CLOSED`, the blocked `addCharge()` wakes, re-reads `folio.status = 'closed'`, and throws `FolioClosedException`.

#### Race Condition Prevention Summary

| Race | Prevention |
|---|---|
| Close-vs-add | Both lock Folio; loser re-reads status → addCharge on closed → FolioClosedException |
| Checkout finalisation vs addCharge | Finalisation locks Folio + FolioEntries + Payments; addCharge blocks on Folio; wakes to closed → FolioClosedException |
| Checkout finalisation vs addPayment | Finalisation locks Booking + Folio + Payments; addPayment blocks on Booking |
| deletePayment vs checkout finalisation | deletePayment blocked on terminal; if race, it locks Booking first; re-reads terminal status → rejected |
| Void-on-closed-folio | voidEntry locks Folio first; closed → FolioClosedException |
| Concurrent double-void | Both lock Folio → FolioEntry; second loser re-reads voided_at → AlreadyVoidedException |
| autoPost duplicate | Both lock Booking → Requirements → Folio; second checks posting_key → null return |
| Requirement edit vs auto-post | Both lock Booking → Requirements (sorted id) → Folio; second reads fresh locked data |
| Void-folio vs concurrent addCharge | voidFolio locks Folio + checks active entries; addCharge blocks on Folio |

#### `updateRequirement()` Lock Sequence

```
DB::transaction:
  1. Lock Booking row (lockForUpdate)
  2. Lock BookingRequirement row (lockForUpdate)
  3. Lock Folio row (lockForUpdate)
  4. Query active system room charge by posting_key (read-only check)
  5. If active system room charge exists AND request changes room_price/quantity
     → throw RequirementLockedAfterRoomChargeException
  6. Update allowed fields
```

---

### 3.9 Shared Balance Calculation Method (ADR-32)

**All balance calculation paths in the system must call a single shared method** to ensure the transition guard is applied consistently everywhere. Direct use of `SUM(active_entries.amount)` is forbidden outside this method.

#### `calculateGuardedFolioTotal(Booking): float` — PUBLIC

```php
/**
 * Single source of truth for folio_total.
 * VOIDED folio short-circuits to 0 — no guard, no entry sum.
 * Applies the transition guard when the system aggregate room charge is absent.
 * Safe to call within a locked transaction — queries use existing row locks.
 */
public function calculateGuardedFolioTotal(Booking $booking): float
{
    // VOIDED folio: no active financial charges exist by definition.
    // Do NOT apply the requirements estimate fallback or the active entry sum.
    // balance_due = 0 − paid_total; max_refundable = paid_total (full refund).
    if ($booking->folio?->status === FolioStatus::Voided) {
        return 0.0;
    }

    $postingKey = "ROOM_CHARGE_{$booking->id}_AGGREGATE";

    $systemRoomChargeExists = $booking->folio
        ?->folioEntries()
        ->where('posting_key', $postingKey)
        ->whereNull('voided_at')
        ->exists() ?? false;

    if (!$systemRoomChargeExists) {
        // Transition guard: system room charge not yet posted
        // requirements_estimate substitutes the missing room charge
        $requirementsEstimate = (float) $booking->bookingRequirements()
            ->selectRaw('SUM(room_price * quantity) as total')
            ->value('total') ?? 0.0;

        // non-room active entries: minibar, damage, F&B, etc.
        // Manual room entries (posting_key=NULL, charge_type='room') are excluded
        // to avoid double-counting with requirements_estimate. See limitation R13.
        $nonRoomActiveTotal = (float) ($booking->folio
            ?->folioEntries()
            ->whereNull('voided_at')
            ->where('charge_type', '!=', ChargeType::Room->value)
            ->sum('amount') ?? 0.0);

        return $requirementsEstimate + $nonRoomActiveTotal;
    }

    // System room charge present: use raw folio sum
    return (float) ($booking->folio
        ?->folioEntries()
        ->whereNull('voided_at')
        ->sum('amount') ?? 0.0);
}
```

#### Guarded Balance Formula Summary

| Condition | folio_total |
|---|---|
| Folio status = **VOIDED** | `0` — short-circuit; do not apply guard or entry sum |
| System room charge posted (active) | `SUM(all active folio_entries.amount)` |
| System room charge missing or voided | `SUM(requirements.room_price × qty)` + `SUM(non-room active entries.amount)` |

**VOIDED folio invariant:** A folio is only VOIDED when `voidFolioOnCancellation()` confirms zero active entries. If any active entry exists, the method throws `FolioHasActiveEntriesException` and the folio stays OPEN. Therefore `calculateGuardedFolioTotal()` returning `0` for a VOIDED folio is always correct — there are no active entries to sum.

**Where this method is called:**
- `paymentSummary()` in `BookingService` — for UI display
- `finaliseBookingCheckout()` in `BookingService` — for checkout gate, after all rows are locked
- `addRefund()` in `BookingPaymentService` — for `max_refundable` calculation, after rows are locked

**Why `addRefund()` also uses it:** The refund cap `max(0, paid_total − folio_total)` must use the same guarded folio_total as the checkout gate. Otherwise a booking could pass a refund that leaves a positive balance_due, which the checkout gate would then reject.

**getFolioTotal() relationship:** `calculateGuardedFolioTotal()` calls the raw sum internally when the system charge is present. `getFolioTotal()` is retained as an internal/display helper only. It must not be called in any path that feeds into balance_due decisions.

---

### 3.10 Payment Mutation Transaction Rules

All payment mutations run inside a DB transaction and acquire row locks before computing totals. **No payment mutation may rely on stale pre-lock totals.**

Canonical lock order for payments: `Booking → Folio → BookingPayment rows (sorted by id ASC)`

#### `addDeposit(Booking, array): BookingPayment`

```
DB::transaction:
  1. Lock Booking row
  2. Re-read booking.status AFTER lock
  3. Throw if terminal (Deposit not allowed on terminal bookings)
  4. Fix: do NOT downgrade booking status if already operational
  5. Create BookingPayment
```

#### `addPayment(Booking, array, PaymentType): BookingPayment`

```
DB::transaction:
  1. Lock Booking row
  2. Re-read booking.status AFTER lock
  3. Throw if terminal and type is inbound (Deposit, RoomPayment, ServicePayment, AdditionalDeposit)
  4. Create BookingPayment
```

#### `addRefund(Booking, array, User): BookingPayment`

```
DB::transaction:
  1. Lock Booking row
  2. Lock Folio row
  3. Lock all BookingPayment rows for booking (sorted by id)
  4. Recalculate folio_total = calculateGuardedFolioTotal($booking)   ← shared guarded method
  5. Recalculate paid_total  = calculatePaidTotal($booking)           ← from locked payment rows
  6. Compute max_refundable  = max(0, paid_total − folio_total)
  7. Throw RefundExceedsMaxException if refund_amount > max_refundable
  8. Create BookingPayment (type=Refund, amount stored as positive)
```

#### `addAdjustment(Booking, array, User): BookingPayment`

```
DB::transaction:
  1. Lock Booking row
  2. Lock Folio row
  3. Lock all BookingPayment rows for booking (sorted by id)
  4. Recalculate paid_total AFTER locks
  5. Validate: amount != 0
  6. Validate: note is provided and non-empty
  7. If amount < 0:
       projected_paid_total = paid_total + amount
       Throw NegativeAdjustmentExceedsPaidException if projected_paid_total < 0
  8. Create BookingPayment (type=Adjustment, amount may be positive or negative)
```

#### `deletePayment(BookingPayment, User): void` — BLOCKED ON TERMINAL BOOKINGS (ADR-33)

```
DB::transaction:
  1. Lock Booking row (lockForUpdate)
  2. Lock Folio row (lockForUpdate)           ← canonical order — must include Folio
  3. Lock target BookingPayment row (lockForUpdate)
  4. Re-read booking.status AFTER locks
  5. If booking.status.isTerminal():          ← re-checked AFTER locks to prevent races
       throw CannotDeletePaymentOnTerminalBookingException
  6. Validate time-window permission:
       ADMIN: always allowed (non-terminal only)
       MANAGER: same-day only (non-terminal only)
  7. Hard delete — no soft delete for payments
```

**Why Folio is locked:** `deletePayment()` changes the settlement state. Including the Folio lock ensures it cannot race with `finaliseBookingCheckout()` — both will contend for the Booking lock first, then the Folio lock. The finalisation will win (it locks first in most scenarios) and once it commits with folio `CLOSED`, `deletePayment()` re-reads terminal status and rejects.

**Why terminal bookings are blocked:** Deleting a payment on a CheckedOut booking with a closed folio would create `balance_due > 0` on a finalised record without reopening the folio. Correction on terminal bookings must use the ADMIN terminal correction flow (Adjustment). Deleted payments cannot be undone; using Adjustment preserves the full audit trail.

**Correction path for terminal bookings:** ADMIN reopens folio if needed (CheckedOut), posts any required charge/void adjustments, then uses a positive or negative Adjustment to settle the payment-side change. `deletePayment()` is never the correction vehicle.

---

## 4. Data Model

### `folio_number_sequences` Table

```sql
CREATE TABLE folio_number_sequences (
    sequence_date  DATE NOT NULL,
    last_sequence  INT  NOT NULL DEFAULT 0,
    PRIMARY KEY    (sequence_date)
);
```

Format: `FLO-YYYYMMDD-######` (6-digit zero-padded). Overflow at 999,999 → `FolioNumberOverflowException` + CRITICAL log.

```php
private function generateFolioNumber(): string
{
    return DB::transaction(function () {
        $date = now()->toDateString();

        DB::statement(
            "INSERT INTO folio_number_sequences (sequence_date, last_sequence)
             VALUES (?, 1)
             ON DUPLICATE KEY UPDATE last_sequence = last_sequence + 1",
            [$date]
        );

        $seq = DB::selectOne(
            "SELECT last_sequence FROM folio_number_sequences WHERE sequence_date = ?",
            [$date]
        )->last_sequence;

        if ($seq > 999_999) {
            Log::critical('Folio number overflow', ['date' => $date, 'seq' => $seq]);
            throw new FolioNumberOverflowException(
                "Daily folio sequence exceeded 999,999 for {$date}. Contact system administrator."
            );
        }

        return 'FLO-' . str_replace('-', '', $date) . '-' . str_pad($seq, 6, '0', STR_PAD_LEFT);
    });
}
```

### `folios` Table

```sql
CREATE TABLE folios (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id      BIGINT UNSIGNED NOT NULL UNIQUE,
    folio_number    VARCHAR(32)     NOT NULL UNIQUE,
    status          VARCHAR(32)     NOT NULL DEFAULT 'open',
    currency_code   CHAR(3)         NOT NULL DEFAULT 'VND',
    note            TEXT            NULL,
    created_by      BIGINT UNSIGNED NULL,
    closed_at       TIMESTAMP       NULL,
    closed_by       BIGINT UNSIGNED NULL,
    created_at      TIMESTAMP       NULL,
    updated_at      TIMESTAMP       NULL,

    CONSTRAINT fk_folio_booking    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
    CONSTRAINT fk_folio_created_by FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL,
    CONSTRAINT fk_folio_closed_by  FOREIGN KEY (closed_by)  REFERENCES users(id)    ON DELETE SET NULL,

    INDEX idx_folio_status (status)
);
```

`closed_by = NULL` = system auto-close. `closed_by = user_id` = staff action.

### `folio_entries` Table

```sql
CREATE TABLE folio_entries (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folio_id      BIGINT UNSIGNED NOT NULL,
    charge_type   VARCHAR(32)     NOT NULL,
    description   VARCHAR(255)    NOT NULL,
    quantity      DECIMAL(12,2)   NOT NULL,
    unit_price    DECIMAL(12,2)   NOT NULL,
    amount        DECIMAL(12,2)   NOT NULL,
    entry_date    DATE            NOT NULL,
    posting_key   VARCHAR(100)    NULL,
    posted_by     BIGINT UNSIGNED NULL,
    voided_at     TIMESTAMP       NULL,
    voided_by     BIGINT UNSIGNED NULL,
    void_reason   TEXT            NULL,
    created_at    TIMESTAMP       NULL,
    updated_at    TIMESTAMP       NULL,

    CONSTRAINT fk_entry_folio     FOREIGN KEY (folio_id)   REFERENCES folios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_entry_posted_by FOREIGN KEY (posted_by)  REFERENCES users(id)  ON DELETE SET NULL,
    CONSTRAINT fk_entry_voided_by FOREIGN KEY (voided_by)  REFERENCES users(id)  ON DELETE SET NULL,

    INDEX idx_entry_folio_void  (folio_id, voided_at),
    INDEX idx_entry_type_date   (charge_type, entry_date),
    INDEX idx_entry_posting_key (folio_id, posting_key)
);
```

`amount` always = `bcmul(quantity, unit_price, 2)` computed server-side. `quantity > 0`; `unit_price >= 0`. Neither `amount` nor `posting_key` accepted from HTTP requests.

### FK Delete Rule Summary

| Table | FK Column | Target | Rule |
|---|---|---|---|
| `folios` | `booking_id` | `bookings` | **RESTRICT** |
| `folio_entries` | `folio_id` | `folios` | **RESTRICT** |
| `booking_payments` | `booking_id` | `bookings` | CASCADE (legacy tech debt — R10) |
| `folio_entries` | `posted_by`, `voided_by` | `users` | SET NULL |
| `folios` | `created_by`, `closed_by` | `users` | SET NULL |

### Eloquent Models

**`Folio`**: fillable incl. `currency_code`; casts: status → FolioStatus, closed_at → datetime; scopes: `scopeOpen()`; AuditObserver.

**`FolioEntry`**: fillable incl. `posting_key` (guarded at API layer); casts: charge_type → ChargeType, amounts → decimal:2, entry_date → date, voided_at → datetime; scopes: `scopeActive()` (WHERE voided_at IS NULL); AuditObserver.

---

## 5. Charge Types

| Value | Label (VI) | Notes |
|---|---|---|
| `room` | Tiền phòng | System auto-post has `posting_key`; manual has `posting_key = NULL` |
| `food_beverage` | Ăn uống | Restaurant, room service |
| `spa` | Spa | |
| `laundry` | Giặt ủi | |
| `minibar` | Minibar | |
| `damage` | Thiệt hại | |
| `late_checkout` | Trả phòng muộn | |
| `early_checkin` | Nhận phòng sớm | |
| `transport` | Vận chuyển | |
| `other` | Khác | |

---

## 6. Payment Entries

### Payment Type Semantics

| Type | Direction | Amount Sign | Notes |
|---|---|---|---|
| `Deposit` | Money in | Always positive | Initial deposit |
| `AdditionalDeposit` | Money in | Always positive | Extra deposit pre-checkin |
| `RoomPayment` | Money in | Always positive | Room settlement |
| `ServicePayment` | Money in | Always positive | Service settlement |
| `Refund` | Money out | Always stored positive, **subtracted** in formula | Returns overpayment |
| `Adjustment` | Money in or out | **Signed** — positive or negative | **ADMIN-only** |

### Adjustment Rules (ADR-24, ADR-30)

- Positive (`amount > 0`): money in, increases `paid_total`.
- Negative (`amount < 0`): money out, decreases `paid_total`.
- `amount != 0`. `note` required. **ADMIN-only — no exceptions, no ACCOUNTANT**.
- Negative cap: `paid_total + amount >= 0`.
- Charge corrections use FolioEntry void/repost, not Adjustment.
- Adjustment is never a substitute for `deletePayment()` on terminal bookings — it is the **only** correction vehicle on terminal bookings because it preserves full audit trail.

### Refund Rules and `max_refundable` Formula (ADR-21)

```
max_refundable = max(0, paid_total − folio_total)
```

where `folio_total = calculateGuardedFolioTotal($booking)` — the shared guarded method.

Computed inside `addRefund()` after acquiring row locks. Never from pre-lock totals.

**Examples:**

| Scenario | Folio state | folio_total | paid_total | max_refundable | Requested Refund | Valid? |
|---|---|---|---|---|---|---|
| Normal overpayment (CheckedOut) | CLOSED | 800,000 | 1,000,000 | 200,000 | 200,000 | ✅ |
| Cancelled, no charges | VOIDED | 0 | 500,000 | 500,000 | 500,000 | ✅ |
| Cancelled, cancellation fee posted | OPEN | 100,000 | 500,000 | 400,000 | 400,000 | ✅ |
| Cancelled, cancellation fee posted | OPEN | 100,000 | 500,000 | 400,000 | 500,000 | ❌ over max |
| NoShow, no-show fee posted | OPEN | 150,000 | 300,000 | 150,000 | 150,000 | ✅ |
| Balanced | CLOSED | 800,000 | 800,000 | 0 | any | ❌ max = 0 |

### `calculatePaidTotal()` Formula

```php
private function calculatePaidTotal(Booking $booking): float
{
    $inbound = $booking->bookingPayments()
        ->whereIn('payment_type', [Deposit, AdditionalDeposit, RoomPayment, ServicePayment])
        ->sum('amount');

    $adjustments = $booking->bookingPayments()
        ->where('payment_type', Adjustment)
        ->sum('amount');  // signed

    $refunds = $booking->bookingPayments()
        ->where('payment_type', Refund)
        ->sum('amount');  // stored positive, subtracted here

    return (float) ($inbound + $adjustments - $refunds);
}
```

### `paymentSummary()` Response (9 keys)

```php
[
    'total_charges'     => float,   // calculateGuardedFolioTotal($booking)
    'balance_due'       => float,   // total_charges − paid_total
    'expected_total'    => float,   // backward-compat alias → same as total_charges
    'total_deposit'     => float,   // Deposit + AdditionalDeposit
    'total_payment'     => float,   // RoomPayment + ServicePayment
    'total_refund'      => float,   // SUM(Refund) — positive display
    'total_adjustment'  => float,   // SUM(Adjustment) — net signed
    'paid_total'        => float,   // net money received
    'remaining_balance' => float,   // backward-compat alias → same as balance_due
]
```

`total_charges` is always `calculateGuardedFolioTotal()` — never a raw entry sum.

---

## 7. Balance Calculation

### Formula

```
folio_total   = calculateGuardedFolioTotal($booking)   ← single shared method; applies transition guard
paid_total    = SUM(inbound) + SUM(Adjustment.amount) − SUM(Refund.amount)
balance_due   = folio_total − paid_total
```

Negative `balance_due` = overpaid. Positive `balance_due` = still owes.

### Transition Guard (Applied Inside `calculateGuardedFolioTotal`)

**VOIDED folio — pre-guard short-circuit:**

```
If folio.status == VOIDED:
    folio_total = 0
    (guard logic and entry sum are NOT applied)
```

A VOIDED folio has no active entries by construction — `voidFolioOnCancellation()` enforces this. Returning `0` is always correct. `max_refundable = paid_total` (full refund available).

**OPEN or CLOSED folio — transition guard:**

When the system aggregate room charge is missing:

```
folio_total_guarded = requirements_estimate + non_room_active_entries_total
```

- `requirements_estimate` = `SUM(room_price × quantity)` from `booking_requirements` — substitutes missing room charge
- `non_room_active_entries_total` = `SUM(folio_entries.amount WHERE voided_at IS NULL AND charge_type != 'room')` — includes minibar, F&B, damage, etc.
- Manual room entries (`charge_type = 'room'`, `posting_key = NULL`) excluded to avoid double-counting with `requirements_estimate` — documented limitation R13

Guard is removed in Phase 3.3 after `autoPostRoomCharge()` is confirmed stable across all check-in paths.

### Atomic Checkout Gate

The balance check and folio close run inside **one atomic transaction**. `calculateGuardedFolioTotal()` is called after all rows are locked, so it reads locked data:

```
finaliseBookingCheckout():
DB::transaction:
  Lock Booking → Folio → FolioEntries (id ASC) → BookingPayments (id ASC)
  folio_total  = calculateGuardedFolioTotal($booking)   ← shared guarded method; reads locked rows
  paid_total   = calculatePaidTotal($booking)
  balance_due  = folio_total − paid_total
  If all out AND balance_due <= 0  → CheckedOut + autoCloseFolio() (idempotent)
  If all out AND balance_due > 0   → PartiallyCheckedOut; folio OPEN
```

**No balance calculation bypasses `calculateGuardedFolioTotal()`.** Any path that computes `balance_due` for a decision (checkout gate, refund cap) must call this method.

---

## 8. Relationship with Booking / Stay / Room Assignment

### Key Constraint Rules

| Constraint | Reason |
|---|---|
| One Folio per Booking | Split-billing is Phase 3.3+ |
| Folio created at Booking creation | Every booking has a ledger from the start |
| `voidEntry()` requires open folio | Prevents silent total changes on finalised folios |
| `closeFolio()` requires User; `autoCloseFolio()` does not | Staff and system paths are separate |
| `autoCloseFolio()` is PUBLIC but has no controller route | Different class must call it; no HTTP access |
| `autoCloseFolio()` is idempotent on CLOSED folio | Staff can pre-close; system close later succeeds silently |
| `voidFolioOnCancellation()` is PUBLIC but has no controller route | BookingService calls it across class boundaries |
| All balance calculations go through `calculateGuardedFolioTotal()` | Single source of truth; transition guard always applied |
| `deletePayment()` blocked on all terminal bookings | Correction on terminal uses Adjustment only |
| `deletePayment()` includes Folio in lock order | Canonical order; prevents race with checkout finalisation |
| All Adjustment requires ADMIN — no exceptions | ACCOUNTANT cannot create Adjustment |

### Lifecycle Flow

```
1. createBooking()
   └── createFolioForBooking() → FLO-YYYYMMDD-######

2. checkIn(Stay, User)
   ├── FolioService::autoPostRoomCharge(booking, user) [public]
   │   └── doPostRoomCharge(): Lock Booking → Requirements → Folio
   │       → idempotency check → postSystemEntry()
   └── updateBookingStayStatus()

3. addCharge(Folio, data) — while folio is OPEN
   └── Lock Folio → check open → createEntry() (amount computed server-side)

4. voidEntry(FolioEntry, User, reason) — while folio is OPEN
   └── Lock Folio (check open/voided) → Lock FolioEntry (check not voided) → set voided

5. addPayment / addRefund / addAdjustment
   └── Lock Booking → Folio → BookingPayments
       addRefund: max_refundable = max(0, calculateGuardedFolioTotal - calculatePaidTotal)
       Adjustment: ADMIN-only always

6. checkOut(Stay)
   └── updateBookingStayStatus() → finaliseBookingCheckout():
       DB::transaction:
         Lock Booking → Folio → FolioEntries (id ASC) → BookingPayments (id ASC)
         folio_total = calculateGuardedFolioTotal($booking)   ← same guarded method
         paid_total  = calculatePaidTotal($booking)
         balance_due = folio_total - paid_total
         ├── All out + balance_due ≤ 0 → CheckedOut → autoCloseFolio() [idempotent]
         └── All out + balance_due > 0 → PartiallyCheckedOut; folio OPEN

7. Post-checkout settlement (balance > 0)
   ├── addCharge() — folio still OPEN
   └── finaliseBookingCheckout() → balance clears → CheckedOut + autoCloseFolio()

8. Terminal correction (CheckedOut)
   ├── ADMIN: reopenFolio()
   ├── ADMIN: voidEntry() / addCharge()
   ├── ADMIN: addAdjustment() (positive) to settle balance
   └── ADMIN: closeFolio(folio, adminUser) when done
   Note: deletePayment() is BLOCKED on CheckedOut — use Adjustment instead

9. Cancellation / NoShow
   ├── No active entries → voidFolioOnCancellation() → VOIDED; full refund available
   └── Active fee exists → FolioHasActiveEntriesException caught → folio stays OPEN
       (staff settles fee then manually closeFolio())
```

---

## 9. Checkout Dependency, Folio Close Timing, and Terminal Booking Rules

### Physical Checkout ≠ Financial Settlement

Booking reaches `CheckedOut` only when `balance_due ≤ 0` AND all stays physically out. `autoCloseFolio()` fires only at that point, inside the atomic checkout finalisation transaction.

### Folio Close Methods

| Method | Visibility | Caller | User Required | `closed_by` | Controller Route? | Idempotent? |
|---|---|---|---|---|---|---|
| `closeFolio(folio, user)` | PUBLIC | Staff via controller | ✅ Yes | `$user->id` | ✅ Yes | No — throws on non-OPEN |
| `autoCloseFolio(folio)` | **PUBLIC** | `BookingService` only | ❌ No | `NULL` | ❌ Never | ✅ Yes — CLOSED → return |

**The two methods must not be conflated.**

`autoCloseFolio()` idempotency contract:
- Status `OPEN` → close it (closed_by = NULL)
- Status `CLOSED` → return silently (staff already closed it)
- Status `VOIDED` → throw `FolioVoidedException` (data error)

### Cancelled / NoShow Folio Lifecycle (ADR-31)

| Scenario | Active entries? | Folio Action | Folio End State | Refund cap |
|---|---|---|---|---|
| Cancelled, no fee posted | None | `voidFolioOnCancellation()` → VOIDED | VOIDED | full `paid_total` |
| Cancelled, fee posted | Yes | catch `FolioHasActiveEntriesException` → leave OPEN | OPEN | `max(0, paid_total − folio_total)` |
| NoShow, no fee posted | None | `voidFolioOnCancellation()` → VOIDED | VOIDED | full `paid_total` |
| NoShow, fee posted | Yes | catch exception → leave OPEN | OPEN | `max(0, paid_total − folio_total)` |

`voidFolioOnCancellation()` is PUBLIC because `BookingService` (different class) calls it. No HTTP route maps to it.

### Terminal Booking — Charge and Payment Rules

#### Charges (folio_entries)

Gated by `folio.status`, not `booking.status`:

| Booking Status | Folio Status | New Charges? |
|---|---|---|
| CheckedIn / PartiallyCheckedOut | OPEN | ✅ Yes |
| CheckedOut (auto-closed) | CLOSED | ❌ No |
| CheckedOut (ADMIN reopened) | OPEN | ✅ Yes |
| Cancelled / NoShow, no fee | VOIDED | ❌ No |
| Cancelled / NoShow, fee active | OPEN | ✅ Yes |
| Cancelled / NoShow, fee settled | CLOSED | ❌ No |

#### Payments (booking_payments)

| Payment Type | CheckedOut | Cancelled | NoShow | Role |
|---|---|---|---|---|
| `Deposit`, `AdditionalDeposit`, `RoomPayment`, `ServicePayment` | ❌ Blocked | ❌ Blocked | ❌ Blocked | — |
| `Refund` | ✅ if max_refundable > 0 | ✅ if max_refundable > 0 | ✅ if max_refundable > 0 | MANAGER+, ACCOUNTANT |
| `Adjustment` | ✅ **ADMIN only** | ✅ **ADMIN only** | ✅ **ADMIN only** | **ADMIN** |
| `deletePayment()` | ❌ **Blocked** | ❌ **Blocked** | ❌ **Blocked** | — |

#### Terminal Correction Flow (CheckedOut)

```
1. ADMIN: reopenFolio(folio)
   → folio.status = 'open'; booking.status remains CheckedOut

2. ADMIN: voidEntry() / addCharge() as needed (folio is now OPEN)

3. If balance_due > 0 after charge correction:
   - ADMIN calls addAdjustment() with positive amount + note
   - deletePayment() is BLOCKED on CheckedOut — do not attempt it

4. ADMIN: closeFolio(folio, adminUser)
   booking.status remains CheckedOut; UI shows balance

5. No automatic re-close. No booking status change.
```

#### `deletePayment()` Terminal Blocking

`deletePayment()` is blocked on **all terminal bookings** (CheckedOut, Cancelled, NoShow) without exception:
- CheckedOut: folio is closed; deleting a payment would open a positive balance on a finalised record
- Cancelled/NoShow: financial records are being settled; arbitrary payment deletion undermines the audit trail

**ADMIN cannot bypass this via the normal `deletePayment()` path.** Correction must use the terminal correction flow (Adjustment). If a payment was entered in error pre-terminal, ADMIN uses a negative Adjustment; if a payment needs to be credited, ADMIN uses a positive Adjustment.

This rule may be revisited in a future phase with explicit ADMIN override tooling and a separate audit log.

### Permissions Matrix

| Permission | ADMIN | MANAGER | RECEPTION | ACCOUNTANT | SALES |
|---|---|---|---|---|---|
| `folio.view` | ✅ | ✅ | ✅ | ✅ | ❌ |
| `folio.close` | ✅ | ✅ | ❌ | ❌ | ❌ |
| `folio.reopen` | ✅ (ADMIN) | ❌ | ❌ | ❌ | ❌ |
| `charge.create` | ✅ | ✅ | ✅ | ❌ | ❌ |
| `charge.void` | ✅ | ✅ (today) | ❌ | ❌ | ❌ |
| `payment.create` (non-terminal) | ✅ | ✅ | ✅ | ❌ | ❌ |
| `payment.refund` (any) | ✅ | ✅ | ❌ | ✅ | ❌ |
| `payment.adjustment` | ✅ | ❌ | ❌ | ❌ | ❌ |
| `payment.delete` (non-terminal only) | ✅ | ✅ (today) | ❌ | ❌ | ❌ |
| `payment.delete` (terminal) | ❌ | ❌ | ❌ | ❌ | ❌ |

---

## 10. Risks

### Accepted Trade-offs

| Risk | Status | Notes |
|---|---|---|
| Aggregate room charge on first check-in | **ACCEPTED** | Phase 3.1 trade-off; per-stay is Phase 3.3 |
| Multi-room partial check-in overstates revenue | **ACCEPTED** | Posting_key makes it identifiable |
| Manual room entry excluded from guard fallback (R13) | **ACCEPTED** | Guard removed in Phase 3.3 |
| Single-currency VND only | **ACCEPTED** | `currency_code` column stored |
| Night Audit migration requires ADMIN reopen per folio | **ACCEPTED** | `reopenFolio()` is the deliberate path |
| `booking_payments.booking_id` CASCADE (legacy) | **ACCEPTED TECH DEBT** | Phase 3.x cleanup |
| ACCOUNTANT cannot create Adjustment | **ACCEPTED** | Revisit in future phase if needed |
| ADMIN cannot deletePayment on terminal bookings | **ACCEPTED** | Adjustment is the correction vehicle |

### Fixed in this Revision (Previously Blockers)

| Risk | Fix |
|---|---|
| Checkout finalisation used raw SUM(entries) bypassing transition guard | All balance paths call `calculateGuardedFolioTotal()` — single shared method |
| Missing system room charge could allow undercharged checkout | `calculateGuardedFolioTotal()` applies guard inside atomic checkout finalisation |
| `autoCloseFolio()` threw when staff had already closed folio | `autoCloseFolio()` is idempotent: CLOSED → return silently; VOIDED → throw |
| `voidFolioOnCancellation()` private but BookingService (different class) must call it | Made PUBLIC; no controller route |
| `deletePayment()` on CheckedOut could create positive balance on closed folio | `deletePayment()` blocked on all terminal bookings; terminal correction uses Adjustment |
| `deletePayment()` did not lock Folio row — could race with checkout finalisation | Folio row added to `deletePayment()` lock order: Booking → Folio → BookingPayment |
| Checkout gate read balance before locking rows | Atomic finalisation: locks all rows first, then calls guarded balance method |
| Adjustment permission conflict (ADMIN/ACCOUNTANT in some sections) | ADMIN-only consolidated everywhere |
| Cancelled/NoShow folio always VOIDED despite active fees | Lifecycle split: no entries → VOIDED; active fee → OPEN |

### Remaining Risks

**R3 — Concurrent double-void:** Folio → FolioEntry lock order; second caller re-reads `voided_at` → AlreadyVoidedException.

**R4 — Folio-less booking (legacy data):** Transition guard fires. Backfill before Phase 3.1B2 deploy.

**R6 — Checkout gate rounding:** `DECIMAL(12,2)` + `decimal:2` cast + `≤ 0` comparison.

**R7 — Settlement-pending folio stays open indefinitely:** UI warning when `balance_due > 0` and all stays out.

**R9 — Concurrent folio number creation:** `ON DUPLICATE KEY UPDATE` atomic upsert.

**R14 — Folio number overflow:** `FolioNumberOverflowException` + CRITICAL log at > 999,999.

**R15 — `autoCloseFolio()` accidentally added to a controller route:** Policy layer must verify no route maps to it. Test: any HTTP attempt → 404/405.

**R16 — `getFolioTotal()` called directly in a balance decision path:** Code review must confirm only `calculateGuardedFolioTotal()` feeds `balance_due` in checkout, refund, or summary paths.

---

## 11. Test Plan

### Phase 3.1A Tests (`tests/Feature/PaymentCrudTest.php`)

| Test | Validates |
|---|---|
| `admin_can_delete_any_payment` | ADMIN deletes non-terminal payment — 200 |
| `manager_can_delete_todays_payment` | MANAGER same-day non-terminal — 200 |
| `manager_cannot_delete_old_payment` | MANAGER yesterday — 403 |
| `reception_cannot_delete_payment` | — 403 |
| `cannot_delete_payment_of_another_booking` | IDOR guard — 404 |
| `deposit_does_not_downgrade_checked_in_booking` | Status unchanged |
| `payment_summary_includes_all_nine_breakdown_fields` | 9 keys |
| `delete_payment_creates_audit_log` | AuditObserver fires |
| `refund_cannot_exceed_paid_total_minus_folio_total` | Over max → 422 |
| `refund_max_is_zero_when_still_owes` | max_refundable = 0 → 422 |
| `refund_allowed_up_to_exact_max_refundable` | Exact max → 200 |
| `concurrent_refunds_cannot_over_refund` | Two simultaneous → only one succeeds |
| `refund_allowed_for_cancelled_booking_with_voided_folio` | VOIDED folio + deposit → full refund |
| `refund_capped_for_cancelled_booking_with_cancellation_charge` | OPEN folio + fee → capped |
| `refund_capped_for_no_show_booking_with_no_show_fee` | OPEN folio + fee → capped |
| `new_deposit_blocked_for_checked_out_booking` | Terminal + Deposit → 422 |
| `new_room_payment_blocked_for_checked_out_booking` | Terminal + RoomPayment → 422 |
| `adjustment_positive_increases_paid_total` | +50k → paid_total += 50k |
| `adjustment_negative_decreases_paid_total` | −30k → paid_total -= 30k |
| `adjustment_negative_cannot_make_paid_total_negative` | Cap violated → 422 |
| `adjustment_zero_amount_rejected` | amount = 0 → 422 |
| `adjustment_requires_note` | note empty → 422 |
| `adjustment_requires_admin_role` | MANAGER → 403 |
| `accountant_cannot_create_adjustment` | ACCOUNTANT → 403 |
| `admin_can_create_positive_adjustment_with_note` | ADMIN + note → 200 |
| `admin_can_create_negative_adjustment_with_note` | ADMIN + note → 200 |
| `terminal_adjustment_requires_admin` | CheckedOut + non-ADMIN → 403 |
| **`cannot_delete_payment_after_checked_out`** | CheckedOut + deletePayment → 422 |
| **`manager_cannot_delete_same_day_payment_after_checked_out`** | MANAGER + same-day + CheckedOut → 422 |
| **`admin_cannot_normal_delete_payment_after_checked_out`** | ADMIN + CheckedOut → 422 |
| **`cannot_delete_payment_after_cancelled`** | Cancelled + deletePayment → 422 |
| **`cannot_delete_payment_after_no_show`** | NoShow + deletePayment → 422 |
| **`terminal_correction_uses_adjustment_not_delete_payment`** | Adjustment on CheckedOut → 200; deletePayment → 422 |
| **`delete_payment_follows_canonical_lock_order_with_folio`** | Folio row locked in deletePayment transaction |
| **`delete_payment_cannot_race_with_checkout_finalisation`** | Concurrent deletePayment vs finalisation → deletePayment re-reads terminal → 422 |

### Phase 3.1B1 Tests (`tests/Feature/FolioCrudTest.php`)

#### Folio Number

| Test | Validates |
|---|---|
| `folio_auto_created_on_booking_creation` | Status open, VND, folio_number set |
| `folio_number_format_matches_six_digit_pattern` | `FLO-\d{8}-\d{6}` |
| `folio_number_generation_is_concurrency_safe` | Parallel — no duplicates |
| `folio_number_sequence_overflow_throws_explicit_error` | seq > 999999 → exception |

#### addCharge

| Test | Validates |
|---|---|
| `add_charge_creates_folio_entry_with_null_posting_key` | posting_key = NULL |
| `addcharge_computes_amount_server_side` | qty=2, price=50k → amount=100k |
| `addcharge_rejects_amount_in_request` | amount in body → 422 |
| `addcharge_rejects_posting_key_in_request` | posting_key in body → 422 |
| `cannot_add_charge_to_closed_folio` | Closed folio → FolioClosedException |
| `close_vs_add_charge_race_close_wins_when_first` | Concurrent; close first → addCharge throws |
| `unit_price_zero_allowed_for_complimentary` | unit_price=0 → amount=0 |
| `quantity_zero_rejected` | qty=0 → 422 |

#### voidEntry

| Test | Validates |
|---|---|
| `void_entry_on_open_folio_succeeds` | voided_at set |
| `voided_entry_excluded_from_folio_total` | calculateGuardedFolioTotal ignores voided |
| `void_entry_rejects_closed_folio` | FolioClosedException |
| `void_entry_rejects_voided_folio` | FolioVoidedException |
| `cannot_void_already_voided_entry` | AlreadyVoidedException |
| `concurrent_double_void_only_one_succeeds` | Exactly one succeeds |
| `manager_cannot_void_old_entry` | MANAGER + yesterday → 403 |

#### calculateGuardedFolioTotal

| Test | Validates |
|---|---|
| **`guarded_folio_total_uses_requirements_estimate_when_system_charge_missing`** | No system charge → requirements_estimate + non-room entries |
| **`guarded_folio_total_includes_minibar_when_system_charge_missing`** | Minibar in non-room total |
| **`guarded_folio_total_excludes_manual_room_entry_during_guard_period`** | Manual room charge not double-counted (R13) |
| **`guarded_folio_total_uses_raw_sum_when_system_charge_present`** | System charge posted → raw SUM |
| **`guarded_folio_total_reactivates_guard_when_system_charge_voided`** | Void system charge → guard returns |
| **`all_balance_paths_produce_same_result_as_guarded_method`** | paymentSummary, refund cap, checkout gate all agree |
| **`voided_folio_returns_folio_total_zero`** | VOIDED folio → calculateGuardedFolioTotal returns 0.0 |
| **`voided_folio_does_not_apply_requirements_estimate_fallback`** | VOIDED + non-zero requirements → still returns 0; guard not applied |
| **`voided_folio_does_not_sum_active_entries`** | VOIDED folio (no active entries by invariant) → 0; no SUM query executed |
| **`cancelled_booking_with_voided_folio_allows_full_refund_of_paid_total`** | VOIDED + deposit 500k → max_refundable = 500k; refund 500k → 200 |
| **`no_show_booking_with_active_fee_does_not_void_folio`** | NoShow + no-show fee entry → FolioHasActiveEntriesException → folio OPEN |
| **`no_show_booking_with_active_fee_refund_capped_by_paid_minus_folio_total`** | OPEN folio + fee 150k + paid 300k → max_refundable = 150k |
| **`calculate_guarded_folio_total_handles_open_closed_and_voided_status`** | OPEN → guard logic; CLOSED → guard logic; VOIDED → 0 (status-dispatch verified) |

#### autoPostRoomCharge

| Test | Validates |
|---|---|
| `auto_post_room_charge_is_public_and_callable_from_stay_service` | No visibility error |
| `auto_post_room_charge_fires_on_first_checkin` | Room entry with posting_key |
| `auto_post_room_charge_returns_null_if_already_posted` | Second call → null |
| `auto_post_room_charge_is_idempotent_on_concurrent_checkin` | Parallel → one entry only |
| `auto_post_room_charge_amount_equals_locked_requirements_sum` | Uses locked values |
| `user_cannot_forge_posting_key_via_api` | posting_key in request → 422 |

#### closeFolio / autoCloseFolio

| Test | Validates |
|---|---|
| `staff_close_folio_requires_user_and_permission` | closeFolio without permission → 403 |
| `staff_close_folio_records_closed_by_user` | closed_by = user.id |
| `auto_close_folio_is_public_and_callable_from_booking_service` | No visibility error |
| `auto_close_folio_has_no_controller_route` | No HTTP route |
| `folio_does_not_auto_close_on_physical_checkout_with_balance_due` | PartiallyCheckedOut; folio OPEN |
| `folio_auto_closes_when_booking_reaches_checked_out` | balance ≤ 0 → CheckedOut + closed |
| `folio_reopen_requires_admin_role` | MANAGER reopen → 403 |
| **`auto_close_folio_closes_open_folio`** | OPEN → sets CLOSED, closed_by NULL |
| **`auto_close_folio_succeeds_on_already_closed_folio`** | CLOSED → returns silently, no exception |
| **`auto_close_folio_rejects_voided_folio`** | VOIDED → FolioVoidedException |
| **`staff_pre_close_then_checkout_finalisation_succeeds_idempotently`** | Staff closes → later finalisation calls autoClose → no error |

#### Atomic Checkout Finalisation

| Test | Validates |
|---|---|
| **`checkout_finalisation_uses_guarded_balance_calculation`** | Uses calculateGuardedFolioTotal not raw SUM |
| **`missing_system_room_charge_with_minibar_prevents_undercharged_checkout`** | Guard applied in finalisation; minibar included; checkout rejected if balance > 0 |
| **`concurrent_add_charge_cannot_slip_between_balance_check_and_auto_close`** | Concurrent charge → FolioClosedException after finalisation commits |
| **`checkout_finalisation_recalculates_balance_after_locks`** | Post-lock balance reflects all data |
| **`booking_cannot_become_checked_out_if_locked_balance_due_is_positive`** | Stays PartiallyCheckedOut |

#### voidFolioOnCancellation

| Test | Validates |
|---|---|
| **`booking_service_can_call_void_folio_on_cancellation`** | Public visibility; no error |
| **`void_folio_on_cancellation_voids_folio_with_zero_entries`** | OPEN + no entries → VOIDED |
| **`void_folio_on_cancellation_leaves_folio_open_when_active_fee_exists`** | Active entry → FolioHasActiveEntriesException → folio stays OPEN |
| **`cancelled_booking_with_no_charges_voids_folio`** | End-to-end: cancel + no entry → VOIDED |
| **`cancelled_booking_with_cancellation_fee_cannot_void_folio`** | End-to-end: cancel + entry → OPEN |
| **`refund_after_cancellation_fee_is_capped_by_max_refundable`** | OPEN folio + fee → refund cap applied |
| **`voided_folio_allows_full_deposit_refund`** | VOIDED + deposit → max = paid_total |

#### Terminal Correction Flow

| Test | Validates |
|---|---|
| `reopened_checked_out_folio_accepts_new_charges` | ADMIN reopen + addCharge → 200 |
| `reopened_checked_out_folio_accepts_void` | ADMIN reopen + voidEntry → 200 |
| `positive_adjustment_settles_reopened_balance` | addAdjustment(+) → balance_due decreases |
| `balance_visible_on_checked_out_booking` | paymentSummary on CheckedOut → has balance_due |

### Phase 3.1B2 Tests (Integration)

| Test | Validates |
|---|---|
| `balance_due_equals_guarded_folio_total_minus_paid_total` | End-to-end arithmetic using guarded method |
| `booking_checkout_blocked_when_balance_due` | balance_due > 0 → PartiallyCheckedOut |
| `booking_reaches_checked_out_when_balance_cleared` | Pay → CheckedOut; folio closed |
| `post_checkout_charge_can_be_added_while_balance_due` | OPEN folio after physical checkout |
| `backfill_creates_folios_for_existing_bookings` | All bookings have folio |
| `backfill_is_idempotent` | Re-run is safe |

### Policy Unit Tests

- `FolioPolicyTest.php` — view, close, reopen per role; autoCloseFolio has no policy/route
- `FolioEntryPolicyTest.php` — create (open folio), void (role + time window)
- `BookingPaymentPolicyTest.php` — delete (blocked on terminal); refund cap; adjustment ADMIN-only

---

## 12. Implementation Steps

### Phase 3.1A — Payment Foundation

- A1: Fix `addDeposit()` status downgrade bug
- A2: Payment mutation canonical lock order — all mutations include Folio row
- A3: `addRefund()` with `max_refundable` cap via `calculateGuardedFolioTotal()` after locks
- A4: `addAdjustment()` — signed, note required, ADMIN-only
- A5: `deletePayment()` — BLOCKED on terminal bookings; Folio row in lock order
- A6: `paymentSummary()` — 9 keys; `total_charges` = `calculateGuardedFolioTotal()`
- A7: Terminal booking payment gate (isTerminal → block inbound + deletePayment)
- A8: Frontend: delete button (hidden on terminal); breakdown display

#### Phase 3.1A Commit Criteria

- [ ] All pre-existing tests pass
- [ ] `PaymentCrudTest.php` — all tests in §11 pass
- [ ] Refund uses `calculateGuardedFolioTotal()` after locks
- [ ] deletePayment blocked on CheckedOut/Cancelled/NoShow → `CannotDeletePaymentOnTerminalBookingException`
- [ ] deletePayment includes Folio lock
- [ ] ADMIN-only Adjustment — ACCOUNTANT → 403
- [ ] Inbound payments blocked on terminal bookings

---

### Phase 3.1B1 — Folio Backend Ledger

**Prerequisite:** Phase 3.1A committed.

**Step B1.1 — Migrations**
- `create_folio_number_sequences_table`
- `create_folios_table` (RESTRICT on booking_id)
- `create_folio_entries_table` (RESTRICT on folio_id; posting_key VARCHAR(100) NULL)

**Step B1.2 — Enums**
- `app/Enums/ChargeType.php` — 10 types
- `app/Enums/FolioStatus.php` — Open, Closed, Voided

**Step B1.3 — Exceptions**

| Exception | Trigger |
|---|---|
| `FolioClosedException` | Operation requires open folio but folio is closed |
| `FolioVoidedException` | Operation rejected on voided folio |
| `FolioNotOpenException` | closeFolio/autoCloseFolio: not in expected state |
| `FolioNotClosedException` | reopenFolio: not in closed state |
| `FolioNumberOverflowException` | Sequence > 999,999 |
| `FolioHasActiveEntriesException` | voidFolioOnCancellation: active entries present |
| `AlreadyVoidedException` | voidEntry: entry already voided |
| `RequirementLockedAfterRoomChargeException` | updateRequirement: price locked |
| `RefundExceedsMaxException` | addRefund: exceeds max_refundable |
| `NegativeAdjustmentExceedsPaidException` | addAdjustment: negative makes paid_total < 0 |
| `CannotDeletePaymentOnTerminalBookingException` | deletePayment: terminal booking |

**Step B1.4 — Models**
- `app/Models/Folio.php`, `app/Models/FolioEntry.php`, update `Booking.php`

**Step B1.5 — FolioService** (`app/Services/FolioService.php`)

| Visibility | Method | Notes |
|---|---|---|
| public | `createFolioForBooking(Booking): Folio` | Transaction; `generateFolioNumber()` |
| public | `addCharge(Folio, array): FolioEntry` | Lock Folio; check open; posting_key = NULL |
| public | `autoPostRoomCharge(Booking, ?User): ?FolioEntry` | Public entry; delegates to `doPostRoomCharge()` |
| public | `voidEntry(FolioEntry, User, string): void` | Folio first → FolioEntry second |
| **public** | **`calculateGuardedFolioTotal(Booking): float`** | **Single source of truth; transition guard applied; safe in locked transaction** |
| public | `getFolioTotal(Booking): float` | Raw sum; internal display only; NOT for balance decisions |
| public | `closeFolio(Folio, User): void` | Staff close; user required; records closed_by |
| public | `autoCloseFolio(Folio): void` | System close; idempotent on CLOSED; throws on VOIDED; no controller route |
| public | `reopenFolio(Folio): void` | ADMIN only |
| public | `voidFolioOnCancellation(Folio): void` | System-only; no controller route; throws FolioHasActiveEntriesException if entries exist |
| private | `doPostRoomCharge(Booking, string, ?User): ?FolioEntry` | Locking, idempotency |
| private | `postSystemEntry(Folio, array, string): FolioEntry` | Sets posting_key |
| private | `createEntry(Folio, array, ?string): FolioEntry` | Server-side amount |
| private | `generateFolioNumber(): string` | 6-digit; overflow guard |

**Step B1.6 — Requirement Edit Lock**
- `BookingService::updateRequirement()`: canonical lock order; reject price change if system charge exists

**Step B1.7 — Atomic Checkout Finalisation**
- `BookingService::finaliseBookingCheckout()`: Lock Booking → Folio → FolioEntries → BookingPayments; call `calculateGuardedFolioTotal()` for balance; conditional CheckedOut + `autoCloseFolio()`

**Step B1.8 — Request Validation**
- `StoreFolioEntryRequest`: allow charge_type, description, quantity (> 0), unit_price (>= 0), entry_date; reject amount, posting_key → 422

**Step B1.9 — Policies, Controllers, Routes**
- `FolioPolicy`: view, close (MANAGER+), reopen (ADMIN); no route for autoCloseFolio or voidFolioOnCancellation
- Routes: `GET|POST /bookings/{booking}/folio/entries`, `POST /folio-entries/{entry}/void`, `POST|DELETE /bookings/{booking}/folio/close|reopen`

**Step B1.10 — Wire auto-create, auto-post, cancellation**
- `BookingService::createBooking()` → `createFolioForBooking()`
- `StayService::checkIn()` → `FolioService::autoPostRoomCharge(booking, user)`
- `BookingService` cancellation/no-show → `FolioService::voidFolioOnCancellation()` — catches `FolioHasActiveEntriesException`, leaves folio OPEN

#### Phase 3.1B1 Commit Criteria

- [ ] Migrations run cleanly
- [ ] `FolioCrudTest.php` all B1 tests pass
- [ ] `calculateGuardedFolioTotal()` is the only method feeding `balance_due` in checkout/refund/summary
- [ ] `getFolioTotal()` not called in any balance decision path
- [ ] `autoCloseFolio()` idempotent: CLOSED → pass; OPEN → close; VOIDED → throw
- [ ] `voidFolioOnCancellation()` public; no controller route; active entries → throws
- [ ] `deletePayment()` terminal gate implemented; Folio lock in order
- [ ] posting_key in request → 422; amount in request → 422
- [ ] 6-digit folio number; overflow → FolioNumberOverflowException
- [ ] ON DELETE RESTRICT verified

---

### Phase 3.1B2 — UI, Checkout Integration, and Backfill

**Prerequisite:** Phase 3.1B1 committed.

- B2.1: `paymentSummary()` uses `calculateGuardedFolioTotal()` for `total_charges`
- B2.2: Atomic checkout gate with `calculateGuardedFolioTotal()` after locks
- B2.3: Frontend folio tab; balance visible for CheckedOut; cancel-with-fee shows settlement UI; delete button hidden on terminal bookings
- B2.4: `php artisan folio:backfill` — idempotent, `--dry-run`

---

## 13. Ready for Implementation

**YES — Phase 3.1 is architecturally complete and ready for implementation.**

All 32 issues across six Codex review rounds resolved. Key guarantees:

- Single shared `calculateGuardedFolioTotal()` used by all balance decisions (checkout gate, refund cap, UI summary) — no path uses raw entry sum for a financial decision
- VOIDED folio short-circuits to `folio_total = 0` before any guard or entry sum logic — full `paid_total` is refundable; no phantom room charges preserved
- Atomic checkout finalisation: locks all rows, calls guarded method, closes inside same transaction
- `autoCloseFolio()` is idempotent on already-closed folio — staff pre-close does not break system close
- `voidFolioOnCancellation()` is public (cross-class boundary); no controller route; preserves active fees
- `deletePayment()` blocked on all terminal bookings; Folio row in canonical lock order
- ADMIN-only Adjustment everywhere; ACCOUNTANT cannot create Adjustment
- Cancelled/NoShow folio lifecycle defined for both with-fee and without-fee cases
- 37 ADRs formalised; three independently committable sub-phases; 98+ named tests

Implementation order: **3.1A → 3.1B1 → 3.1B2**

---

## 14. Architecture Decision Summary

### Round 1 ADRs

**ADR-1:** Aggregate room charge — one FolioEntry at first check-in.
**ADR-2:** `posting_key NULL`; application-level idempotency; no DB unique.
**ADR-3:** No UNIQUE on `(folio_id, charge_type)`; `entry_date` indexed.
**ADR-4:** Lock `room_price`/`quantity` after system room charge posted.
**ADR-5:** No auto-close on physical checkout; only on financial completion.
**ADR-6:** `folio_number_sequences`; `FLO-YYYYMMDD-######`; overflow exception.
**ADR-7:** VND-only; `currency_code DEFAULT 'VND'` stored.
**ADR-8:** New tables `ON DELETE RESTRICT`; `booking_payments CASCADE` tech debt.
**ADR-9:** `FolioEntry.amount` gross; tax deferred.
**ADR-10:** Single-sided folio charge ledger.

### Round 2 ADRs

**ADR-11:** Transition guard fallback = `requirements_estimate + non_room_active_total`.
**ADR-12:** Canonical lock order: Booking → Requirements → Folio → FolioEntry → BookingPayments.
**ADR-13:** `posting_key` internal-only; rejected at request layer.
**ADR-14:** Each mutation has defined transaction + lock rules.
**ADR-15:** Terminal payments: only Refund (capped) and ADMIN Adjustment.
**ADR-16:** Amount server-side (`bcmul`); `unit_price >= 0`; `quantity > 0`.
**ADR-17:** 6-digit folio number; overflow throws + logs CRITICAL.

### Round 3 ADRs

**ADR-21:** Refund cap = `max(0, paid_total − folio_total)` after locks.
**ADR-22:** All payment mutations lock Booking → Folio → BookingPayments.
**ADR-23:** `voidEntry()` requires open folio; locks Folio before FolioEntry.
**ADR-24:** Adjustment signed; ADMIN-only; note required; cap at paid_total >= 0.
**ADR-25:** Terminal correction flow: ADMIN reopen → corrections → Adjustment → close.
**ADR-26:** `closeFolio(user)` staff; `autoCloseFolio()` system.
**ADR-27:** `autoPostRoomCharge()` public; `doPostRoomCharge()` private.

### Round 4 ADRs

**ADR-28:** Atomic checkout finalisation: single transaction locking all rows.
**ADR-29:** `autoCloseFolio()` public (cross-class); no controller route.
**ADR-30:** Adjustment ADMIN-only everywhere — no ACCOUNTANT exception.
**ADR-31:** Cancelled/NoShow lifecycle: no entries → VOIDED; active fee → OPEN.

### Round 5 ADRs

**ADR-32 — Shared Guarded Balance Method:**
`FolioService::calculateGuardedFolioTotal(Booking): float` is the single shared method for all `folio_total` computations used in financial decisions. It applies the transition guard internally: if the system aggregate room charge is absent, it returns `requirements_estimate + non_room_active_total`; if present, it returns the raw active entry sum. Called by `paymentSummary()`, `finaliseBookingCheckout()`, and `addRefund()`. `getFolioTotal()` is retained for display only and must never feed a balance_due decision.

**ADR-33 — deletePayment() Blocked on Terminal Bookings:**
`deletePayment()` is blocked on all terminal bookings (CheckedOut, Cancelled, NoShow) without exception. ADMIN cannot bypass this via normal `deletePayment()`. Correction on terminal bookings uses the Adjustment payment type (positive or negative), which preserves full audit trail. This rule may be revisited in a future phase with explicit ADMIN override tooling and a separate audit log.

**ADR-34 — deletePayment() Canonical Lock Order:**
`deletePayment()` must lock in order: Booking → Folio → BookingPayment. The Folio row is required so that `deletePayment()` contends with `finaliseBookingCheckout()` at the Booking lock level and cannot interleave with the balance-check/close sequence. After the finalisation commits with folio `CLOSED`, a concurrent `deletePayment()` re-reads terminal status and rejects.

**ADR-35 — autoCloseFolio() Idempotency:**
`autoCloseFolio()` is idempotent:
- `OPEN` → close (closed_by = NULL)
- `CLOSED` → return silently (staff may have pre-closed; this is a valid operational pattern)
- `VOIDED` → throw `FolioVoidedException` (voided folio cannot represent completed financial checkout; this is a data error)

Staff closing the folio early before all stays check out is explicitly allowed. When the booking later reaches financial completion, `autoCloseFolio()` succeeds silently.

**ADR-36 — voidFolioOnCancellation() Public Service Boundary:**
`voidFolioOnCancellation(Folio): void` is PUBLIC because `BookingService` (a different class) calls it. No HTTP route maps to it. Behavior: locks Folio, counts active entries under lock. Zero entries → VOIDED. Any active entries → throws `FolioHasActiveEntriesException`. The caller (`BookingService`) catches this exception and leaves the folio OPEN for staff settlement. No active financial record disappears on booking cancellation.

### Round 6 ADR

**ADR-37 — VOIDED Folio Short-Circuit in calculateGuardedFolioTotal():**

`calculateGuardedFolioTotal()` must check `folio.status === VOIDED` **before** applying any other logic. When VOIDED:

```
folio_total    = 0
balance_due    = 0 − paid_total
max_refundable = paid_total   (full refund available)
```

Do NOT apply:
- The requirements estimate fallback (transition guard)
- The system room charge existence check
- Any active entry sum

**Why this is correct:** `voidFolioOnCancellation()` enforces the invariant that a VOIDED folio has zero active entries — it throws `FolioHasActiveEntriesException` if any active entry exists. Therefore a VOIDED folio always has `SUM(active entries) = 0` and `folio_total = 0` by construction. The previous implementation did not check folio status first, meaning it fell through to the transition guard and returned `requirements_estimate + non_room_active_total`, incorrectly preserving phantom room charges and blocking the full refund that a VOIDED folio should permit.

**OPEN and CLOSED folios are unaffected.** The VOIDED check is a pre-guard short-circuit only.

**Refund cap consequence:** For Cancelled/NoShow bookings where `voidFolioOnCancellation()` succeeds (no active entries → VOIDED), `max_refundable = paid_total`. For those where it throws (active fee → folio stays OPEN), `max_refundable = max(0, paid_total − folio_total)` using the guard as before.

---

### Ready for Implementation: YES

All 37 architecture decisions formalised (ADR-1 through ADR-37). Three independently committable sub-phases (3.1A, 3.1B1, 3.1B2) with explicit commit criteria. 98+ named tests covering concurrency, atomicity, idempotency, terminal rules, Cancelled/NoShow lifecycle, and VOIDED folio balance behaviour.
