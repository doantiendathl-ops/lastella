# Phase 3.1B1 — Checkout Integration Architecture

**Date:** 2026-06-30  
**Status:** Architecture — Revised v2 (Addressing ChatGPT Round 1 Review)  
**Branch:** phase-3  
**Author:** Lead Software Architect  
**Prerequisite:** Phase 3.1A (tag: `phase-3.1A`) committed and approved

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Objectives](#2-objectives)
3. [Scope](#3-scope)
4. [Out of Scope](#4-out-of-scope)
5. [Current Architecture](#5-current-architecture)
6. [Proposed Architecture](#6-proposed-architecture)
7. [Service Responsibilities](#7-service-responsibilities)
8. [Checkout Flow Diagram](#8-checkout-flow-diagram)
9. [Transaction Boundary](#9-transaction-boundary)
10. [Canonical Lock Order](#10-canonical-lock-order)
11. [Concurrency Analysis](#11-concurrency-analysis)
12. [State Transition Matrix](#12-state-transition-matrix)
13. [Exception Design](#13-exception-design)
14. [Regression Risk Assessment](#14-regression-risk-assessment)
15. [ADR List](#15-adr-list)
16. [Database Impact](#16-database-impact)
17. [Test Strategy](#17-test-strategy)
18. [Acceptance Criteria](#18-acceptance-criteria)

---

## 1. Executive Summary

Phase 3.1A delivered the folio charge ledger backend: `FolioService`, `autoPostRoomCharge`, `autoCloseFolio`, `calculateGuardedFolioTotal`, and the canonical lock order (Booking → Folio → FolioEntry). These building blocks exist but are not yet wired into the checkout workflow.

Phase 3.1B1 integrates those building blocks into a **fully atomic, concurrency-safe checkout process**. It does not change the data model or add UI. It changes how checkout is *orchestrated*.

The primary deliverables are:

1. A new `BookingService::finaliseBookingCheckout()` method — the single, atomic orchestrator for the last-stay checkout, called only within an existing `DB::transaction` (see ADR-48).
2. A corrected `StayService::checkOut()` that acquires a Booking lock first (fixing a pre-existing deadlock risk vs `updateBooking`) and delegates final checkout orchestration to `finaliseBookingCheckout`.
3. A corrected `StayService::checkIn()` that acquires a Booking lock first (same deadlock root cause as checkOut — brought into Phase 3.1B1 scope).
4. A corrected `BookingService::paymentSummary()` that uses `calculateGuardedFolioTotal()` instead of `getFolioTotal()`.
5. A corrected `BookingService::updateBookingStayStatus()` that reads balance under the Booking lock already held by the caller.
6. Booking-first lock ordering for all payment mutations (`addPayment`, `addDeposit`, `addRefund`, `deletePayment`).
7. Wire-up of terminal-booking guards using a single `BookingTerminalException`.
8. A new `OutstandingBalanceException` that blocks checkout when balance > 0.
9. An explicit Active Stay definition governing checkout finalisation (see ADR-49).

**No migrations are required.** No UI changes are required. No new tables are introduced.

---

## 2. Objectives

| # | Objective |
|---|---|
| O1 | Checkout must be atomic: stay finalisation + room charge guarantee + balance validation + folio close + booking terminal transition must succeed or fail together |
| O2 | Checkout must be concurrency-safe: two concurrent requests for the same booking must not produce inconsistent state |
| O3 | Outstanding balance must block checkout — a booking with unpaid charges cannot transition to CheckedOut |
| O4 | Folio must auto-close atomically with the last-stay checkout — no manual close step required |
| O5 | Room charge must be guaranteed before folio closes — if it was already posted (at check-in), no second post; if it was voided, it must be re-posted |
| O6 | Terminal booking (CheckedOut) must be protected: charges and payments cannot be added or deleted post-checkout |
| O7 | The pre-existing Booking→Stay deadlock risk in `StayService::checkOut` AND `StayService::checkIn` must be eliminated |
| O8 | `paymentSummary()` must use the accurate `calculateGuardedFolioTotal()` to produce correct balance values for checkout gate and display |
| O9 | All payment mutations must participate in Booking-first lock ordering to prevent balance serialisation races |
| O10 | Checkout finalisation must trigger ONLY when no Active stays remain — Reserved stays are Active and prevent finalisation |

---

## 3. Scope

Architecture for:

- Checkout workflow (per-stay and final-stay)
- Checkout transaction boundary and atomicity
- **Transaction ownership contract for `finaliseBookingCheckout()` (Critical — see §6.7 and ADR-48)**
- **Active Stay definition governing checkout finalisation gate (Critical — see §6.8 and ADR-49)**
- Checkout locking strategy and canonical lock order extension
- Room charge guarantee before folio close
- Balance validation gate before checkout transition
- Folio auto-close at checkout
- Booking terminal transition to `CheckedOut`
- Stay finalisation (`actual_checkout_at`, status → CheckedOut)
- Room release sequence (assignment status → CheckedOut)
- Rollback behaviour on failure
- Exception hierarchy for checkout failures — single `BookingTerminalException` as service-layer terminal guard
- `paymentSummary()` correction using `calculateGuardedFolioTotal()`
- Terminal booking protection (charges, payments, folio reopen)
- **`StayService::checkIn()` Booking lock fix (same deadlock root cause as checkOut)**
- **Booking-first lock ordering for all payment mutations**
- `BookingPaymentService` refund validation wire-up (architecture only)
- Audit events
- Regression impact analysis
- Full test strategy

---

## 4. Out of Scope

| Topic | Phase |
|---|---|
| Invoice generation | Future |
| Tax calculation | Future |
| Frontend checkout UI | Phase 3.1B2 |
| Payment screen / payment CRUD refactor | Phase 3.2 |
| Service charges | Phase 3.3 |
| Night audit | Future |
| Reports | Future |
| Electronic invoice | Future |
| Special requests | Future |
| Per-stay room charge breakdown | Phase 3.3 |
| `autoCloseFolio` triggered by payment reaching zero balance | Phase 3.2 |
| `RoomAssignmentService::releaseAssignment()` Booking lock | Phase 3.1B2 |
| ADMIN override for post-checkout folio reopen | Phase 3.1B2 |

---

## 5. Current Architecture

### 5.1 What `StayService::checkOut()` Does Today

```
StayService::checkOut(Stay $stay, $actualCheckoutAt):
  DB::transaction {
    lockForUpdate Stay       ← (1) first lock acquired
    lockForUpdate RA         ← (2) second lock acquired
    Validate: RA must be CheckedIn
    Validate: Stay must have actual_checkin_at
    Validate: Stay must not already have actual_checkout_at
    Update Stay: actual_checkout_at, status=CheckedOut, checked_out_by
    Update RA: status=CheckedOut
    Call: BookingService::updateBookingStayStatus()   ← (3) reads payments, folio — no locks
  }
```

### 5.2 What `updateBookingStayStatus()` Does Today

```
BookingService::updateBookingStayStatus(Booking $booking):
  Load stays (no lock)
  Count: total, checkedIn, checkedOut
  Read: paymentSummary()['remaining_balance']  ← uses getFolioTotal() — display only
  if checkedOut == total AND remaining_balance <= 0:
    Booking.status = CheckedOut    ← UPDATE Booking (implicit lock AFTER balance read)
  elif checkedOut > 0:
    Booking.status = PartiallyCheckedOut
  ...
```

### 5.3 Identified Gaps

| # | Gap | Severity | Impact |
|---|---|---|---|
| G1 | No Booking lock before Stay/RA lock in **`checkOut()` and `checkIn()`** | **CRITICAL** | Deadlock risk vs `updateBooking`/`validateTimeChange` (which does Booking→Stay order) — affects both methods |
| G2 | Balance read in `updateBookingStayStatus()` is not under any lock | **HIGH** | Race condition: concurrent payment can slip between balance read and Booking UPDATE, producing incorrect CheckedOut status |
| G3 | `paymentSummary()` uses `getFolioTotal()` (display-only sum) not `calculateGuardedFolioTotal()` | **HIGH** | Produces incorrect balance when system room charge is not yet posted (transition guard bypass) |
| G4 | `autoCloseFolio()` is not called at checkout | **HIGH** | Folio stays Open after checkout; staff must close manually; Open folio on terminal booking is inconsistent |
| G5 | No outstanding balance validation blocks checkout | **HIGH** | Guest can check out without paying; booking transitions to CheckedOut with unpaid balance |
| G6 | No terminal booking protection on `addCharge()` or `addPayment()` | **MEDIUM** | Charges and payments can be added to a CheckedOut or Cancelled booking |
| G7 | `reopenFolio()` has no terminal booking guard | **MEDIUM** | Staff can reopen folio on a CheckedOut booking and post additional charges |
| G8 | `BookingPaymentService::deletePayment()` has no terminal booking guard | **MEDIUM** | Payment can be deleted from a terminal booking, distorting financial records |
| G9 | Refund validation in `addRefund()` reads payments without a lock | **MEDIUM** | Concurrent refund requests can both pass the `amount > paidTotal` check |
| G10 | `autoPostRoomCharge()` is not called in the checkout finalise flow | **LOW** | If room charge was voided after check-in, it will not be re-posted before folio closes |
| G11 | "Last stay" detection uses only CheckedIn count — Reserved stays are ignored | **CRITICAL** | A booking with Reserved stays that have not yet checked in could be incorrectly finalised |
| G12 | Payment mutations (`addPayment`, `addDeposit`, `addRefund`, `deletePayment`) do not acquire Booking lock | **HIGH** | Concurrent payment and checkout can produce stale balance reads — true serialisability requires Booking-first ordering for all payment mutations |

### 5.4 Deadlock Risk Analysis (G1)

`validateTimeChange()` (inside `updateBooking`'s transaction) acquires locks in this order:

```
T_updateBooking: Booking (implicit UPDATE) → Room → Stay → RoomAssignment
```

`StayService::checkOut()` acquires:

```
T_checkOut: Stay → RoomAssignment → [Booking UPDATE via updateBookingStayStatus]
```

`StayService::checkIn()` acquires:

```
T_checkIn: Stay → RoomAssignment → Folio → FolioEntry → [Booking UPDATE via updateBookingStayStatus]
```

If any of these run concurrently on the same booking with `T_updateBooking`:
- T_updateBooking holds Booking (implicit), waits on Stay
- T_checkOut / T_checkIn holds Stay, waits on Booking (via UPDATE in `updateBookingStayStatus`)
- **Deadlock.**

MySQL's deadlock detector will kill one transaction (typically the one that holds fewer locks), returning a `Deadlock found when trying to get lock; try restarting transaction` error. Under low concurrency this rarely fires, but it is a correctness bug that will manifest in production.

**Fix (both methods):** Acquire an explicit `Booking::lockForUpdate()` at the START of `checkOut()` and `checkIn()`, before any Stay lock. This enforces Booking-first ordering and eliminates the circular wait in both code paths.

---

## 6. Proposed Architecture

### 6.1 Design Decisions

**Decision 1 — Where does finalise live?**

`finaliseBookingCheckout()` belongs in `BookingService`. Rationale:
- Checkout finalisation is a booking lifecycle event, not a room/stay event
- `BookingService` already owns `updateBookingStayStatus()`, `cancelBooking()`, and the booking status machine
- Adding it to `StayService` would couple a lower-level service with high-level booking lifecycle orchestration
- A new `CheckoutService` class would be justified only if checkout grew to multi-method scope; for Phase 3.1B1 a single method does not warrant a new class

`StayService::checkOut()` detects "no remaining Active stays" and calls `BookingService::finaliseBookingCheckout()` inside its own transaction. This creates a savepoint in MySQL, which is acceptable — both commits or both roll back atomically.

**Decision 2 — Single transaction or two-phase?**

Single transaction. The stay checkout and the finalise step must be atomic. If balance validation fails, the stay must NOT be marked as CheckedOut. Two-phase would leave the system in an inconsistent intermediate state (stay=CheckedOut, booking=PartiallyCheckedOut, folio=Open, balance > 0).

**Decision 3 — Balance check: hard block or soft warning?**

Hard block. A booking must not reach `CheckedOut` with an unpaid balance. Staff must record payment before the last room is checked out. `OutstandingBalanceException` rolls back the entire transaction — the stay remains `CheckedIn`.

Rationale: The folio system exists to track charges. Allowing checkout with outstanding balance creates unrecoverable accounting state on a terminal booking.

**Decision 4 — When is room charge guaranteed?**

`autoPostRoomCharge()` was called at check-in. For Phase 3.1B1, `finaliseBookingCheckout()` calls it again as an idempotency-safe guard. This handles the edge case where the room charge was voided after check-in. The second call is a no-op if already posted. This adds one extra SELECT per finalise path — acceptable overhead.

**Decision 5 — Partial checkout stays (non-last stay)?**

For stays that are NOT the last Active stay to check out, the flow is identical to today: Stay → CheckedOut, RA → CheckedOut, Booking → PartiallyCheckedOut. No balance check, no folio close. Booking lock is still acquired first (fixes G1 regardless of last-stay status).

**Decision 6 — `paymentSummary()` correction**

`BookingService::paymentSummary()` currently uses `$this->folios->getFolioTotal($booking)` as the primary charge figure. This must be replaced with `$this->folios->calculateGuardedFolioTotal($booking)`. `getFolioTotal()` is a raw SUM with no transition guard and no voided-folio handling. `calculateGuardedFolioTotal()` implements ADR-11 and ADR-37. This correction propagates correctly to `updateBookingStayStatus()` since it reads `paymentSummary()`.

**Decision 7 — `updateBookingStayStatus()` called inside finalise transaction**

After the finalise path validates balance and closes the folio, it sets `Booking.status = CheckedOut` directly (holding the Booking lock). It does NOT call `updateBookingStayStatus()` for the terminal transition. Rationale: `updateBookingStayStatus()` reads stays and payments without locks and recalculates balance — calling it after the validated balance check would re-read stale data. The finalise method sets status directly under the lock.

For non-last-stay checkouts, `updateBookingStayStatus()` continues to be called as today (with the Booking lock now held, which makes its subsequent Booking UPDATE non-racy).

**Decision 8 — Single `BookingTerminalException` as service-layer terminal guard**

Option A is selected: `BookingTerminalException` is the single exception thrown by all service-layer methods when they detect a terminal booking (`CheckedOut`, `Cancelled`, `NoShow`). `CannotDeletePaymentOnTerminalBookingException` (Phase 3.1A stub) is superseded and removed. `FolioClosedException` is retained for folio state transitions (a folio-level concern, not a booking lifecycle concern) — it is not a terminal booking guard.

**Decision 9 — `checkIn()` Booking lock brought into Phase 3.1B1 scope**

The deadlock in `checkIn()` is the same root cause as checkOut (G1) and the fix is identical (one line). Deferring it after fixing checkOut would leave the system in an inconsistent state — checkOut is safe, checkIn is not, for the same concurrent `updateBooking` scenario. Phase 3.1B1 fixes both.

**Decision 10 — Booking-first lock ordering for all payment mutations**

`addPayment()`, `addDeposit()`, `addRefund()`, and `deletePayment()` in `BookingPaymentService` all acquire `Booking::lockForUpdate()` as their first lock. This:
- Enforces the canonical lock order (Booking before BookingPayment)
- Serialises payment mutations against `finaliseBookingCheckout()` (which holds the Booking lock)
- Makes the terminal booking guard read-then-check under lock (safe pattern)
- Eliminates the payment-INSERT-during-checkout balance race (OI-7 resolved)

### 6.2 New Method: `BookingService::finaliseBookingCheckout(Booking, User)`

Called inside `StayService::checkOut()`'s transaction when no Active stays remain.

**Transaction contract (ADR-48):** This method MUST be called within an already-open `DB::transaction`. It does NOT open its own transaction. If called outside a transaction, the partial mutations (room charge post, folio close, booking update) will commit independently and atomicity is broken. Callers must ensure an outer transaction exists. Nested `DB::transaction()` calls inside this method (e.g., in `autoPostRoomCharge`) use MySQL savepoints — they are implementation details, not independent transaction boundaries.

**Lock preconditions:** The caller MUST hold `Booking::lockForUpdate()` before calling this method. `autoPostRoomCharge()` will acquire and release a Folio lock internally (savepoint). `autoCloseFolio()` requires the Folio lock to already be held by the caller — this method re-acquires Folio lock at step 3 before calling `autoCloseFolio()`.

**Precondition:** Caller holds Booking lockForUpdate (acquired at the start of the outer `checkOut` transaction).

**Steps:**
1. Confirm no Active stays remain (status in [Reserved, CheckedIn]) — defensive assertion; caller has already verified
2. Call `FolioService::autoPostRoomCharge($booking)` — idempotent; ensures room charge exists; internally acquires Folio lock via savepoint
3. Acquire `$booking->folio` via `Folio::lockForUpdate()` — canonical Booking→Folio order maintained since Booking lock is already held
4. Compute `calculateGuardedFolioTotal($booking)` — the authoritative charge total (no additional lock needed; reads only)
5. Acquire `BookingPayment::where('booking_id', $booking->id)->lockForUpdate()->get()` → compute `paid_total`
6. Compute `balance_due = total_charges - paid_total`
7. If `balance_due > 0`: throw `OutstandingBalanceException($balance_due)` — outer transaction rolls back
8. Call `FolioService::autoCloseFolio($folio, $user)` — Folio lock already held (step 3); idempotent
9. Update `Booking.status = CheckedOut`, `Booking.updated_by = $user->id` — Booking lock already held (from caller)

### 6.3 Modified Method: `StayService::checkOut()`

New structure:

```
StayService::checkOut(Stay $stay, $actualCheckoutAt, User $user):
  DB::transaction {                              ← OWNS the transaction
    lockForUpdate Booking    ← NEW: first lock (fixes G1 deadlock)
    lockForUpdate Stay
    lockForUpdate RA
    Validate: RA must be CheckedIn
    Validate: Stay must have actual_checkin_at
    Validate: Stay must not already have actual_checkout_at
    Update Stay: actual_checkout_at, status=CheckedOut, checked_out_by
    Update RA: status=CheckedOut

    $remainingActiveStays = Stay::where(booking_id)
                                 .whereIn(status, [Reserved, CheckedIn])
                                 .count()       ← ADR-49: Active = Reserved OR CheckedIn

    if $remainingActiveStays === 0:
      $this->bookings->finaliseBookingCheckout($booking, $user)
      // ADR-48: finalise runs within THIS transaction (savepoint)
    else:
      $this->bookings->updateBookingStayStatus($booking)
      // Booking lock held — no deadlock risk on the subsequent UPDATE
  }                                              ← COMMITS or ROLLS BACK atomically
```

**Note on `$user` parameter:** Currently `checkOut(Stay, $actualCheckoutAt)` does not receive a User — it uses `Auth::id()`. For `finaliseBookingCheckout` to call `autoCloseFolio(Folio, User)`, the acting user must be available. The signature adds `?User $user = null` with `$user ?? Auth::guard()->user()` fallback. This is a backward-compatible change.

### 6.4 Modified Method: `StayService::checkIn()`

New structure (minimal change — Booking lock prepended):

```
StayService::checkIn(Stay $stay, $actualCheckinAt, User $user):
  DB::transaction {
    lockForUpdate Booking    ← NEW: first lock (fixes G1 deadlock — same as checkOut fix)
    lockForUpdate Stay
    lockForUpdate RA
    Validate: RA must be Assigned
    Validate: Stay must be Reserved
    Validate: Stay must not already have actual_checkin_at
    Update Stay: actual_checkin_at, status=CheckedIn, checked_in_by
    Update RA: status=CheckedIn
    FolioService::autoPostRoomCharge($booking)  ← acquires Folio/FolioEntry locks (canonical order maintained: Booking already held)
    $this->bookings->updateBookingStayStatus($booking)
    // Booking lock held — no deadlock risk on the subsequent UPDATE
  }
```

### 6.5 Terminal Booking Protection

A private helper `assertBookingNotTerminal(Booking $booking): void` is added to `BookingService` and `BookingPaymentService`. It throws `BookingTerminalException` if `$booking->status->isTerminal()`. It is called under the Booking lock in all payment mutations, and before folio mutations.

**Single exception rule (Decision 8):** `BookingTerminalException` is the only service-layer terminal guard. `CannotDeletePaymentOnTerminalBookingException` is removed. `FolioClosedException` is NOT a terminal guard — it is thrown when a folio-level state check fails (e.g., attempting to add a charge to a Closed folio), regardless of booking status.

It will be called at:
- `FolioService::addCharge()`: assert booking not terminal (resolved via `$folio->booking`, under Folio lock)
- `BookingPaymentService::addPayment()` / `addDeposit()`: assert booking not terminal (under Booking lock — see §6.6)
- `BookingPaymentService::addRefund()`: assert booking not terminal (under Booking lock — see §6.6)
- `BookingPaymentService::deletePayment()`: assert booking not terminal (under Booking lock — see §6.6)
- `FolioService::reopenFolio()`: assert booking not terminal (under Folio lock already held by caller)

**Post-checkout refund policy:** `addRefund()` is blocked on terminal bookings by default (ADR-44). If post-checkout refunds are needed, a separate `postCheckoutRefund()` method with explicit admin-only gate is the correct design — not an exception to ADR-44.

### 6.6 `BookingPaymentService` — Booking-First Lock Ordering

All four payment mutation methods wrap their operation in `DB::transaction` and acquire `Booking::lockForUpdate()` as the first lock:

```
addPayment(Booking $booking, array $data):
  DB::transaction {
    lockForUpdate Booking              ← canonical order position 1
    assertBookingNotTerminal($booking) ← read under lock — safe pattern
    INSERT booking_payments            ← position 7 in canonical order
  }

addDeposit(Booking $booking, array $data):
  DB::transaction {
    lockForUpdate Booking
    assertBookingNotTerminal($booking)
    INSERT booking_payments
  }

addRefund(Booking $booking, array $data):
  DB::transaction {
    lockForUpdate Booking
    assertBookingNotTerminal($booking)
    $currentPaidTotal = calculatePaidTotal($booking)   ← read under Booking lock — serialised
    if $data['amount'] > $currentPaidTotal: throw RefundExceedsMaxException
    INSERT booking_payments (negative amount)
  }

deletePayment(Booking $booking, BookingPayment $payment):
  DB::transaction {
    lockForUpdate Booking
    assertBookingNotTerminal($booking)
    lockForUpdate BookingPayment       ← canonical order: Booking before BookingPayment
    DELETE booking_payments
  }
```

This eliminates the payment-INSERT-during-checkout race (G12 / OI-7): `finaliseBookingCheckout()` holds the Booking lock, so any concurrent payment mutation waits until checkout completes (or is blocked if checkout transitions booking to terminal before the payment acquires the lock).

### 6.7 Transaction Ownership Contract

This section is the authoritative reference for transaction boundaries in the checkout flow. It answers the question: who opens, who nests, who commits?

**Rule 1 — `StayService::checkOut()` owns the checkout transaction.**
It calls `DB::transaction(function() {...})`. All operations within the closure (Stay update, RA update, active stay count, `finaliseBookingCheckout`) execute within this single transaction boundary.

**Rule 2 — `finaliseBookingCheckout()` MUST be called within an existing transaction.**
It does NOT call `DB::transaction()`. It does NOT open a savepoint explicitly. It operates on the connection's current transaction state. Calling it without an outer transaction is a programming error — mutations will auto-commit individually and atomicity is destroyed.

**Rule 3 — `autoPostRoomCharge()` uses a nested `DB::transaction()` (savepoint) internally.**
When called from within `checkOut()`'s outer transaction, this nested call creates a MySQL savepoint. If `autoPostRoomCharge()` throws internally, only the savepoint is rolled back — the outer transaction survives. This is an implementation detail, not a separate transaction boundary.

**Rule 4 — `autoCloseFolio()` does NOT use `DB::transaction()`.**
It performs a single `$folio->update(...)`. It relies on the Folio lock held by the caller (acquired in step 3 of `finaliseBookingCheckout`). It must be called under the Folio lock.

**Rule 5 — Payment mutations own their own transactions.**
`addPayment`, `addDeposit`, `addRefund`, and `deletePayment` each open their own `DB::transaction`. They are never called from within `finaliseBookingCheckout()`. If a payment is attempted concurrently with checkout, the Booking lock serialises them.

**Summary table:**

| Method | Opens own transaction? | Requires existing transaction? | Requires Booking lock from caller? | Requires Folio lock from caller? |
|---|---|---|---|---|
| `StayService::checkOut()` | ✓ (owner) | ✗ | ✗ | ✗ |
| `StayService::checkIn()` | ✓ (owner) | ✗ | ✗ | ✗ |
| `BookingService::finaliseBookingCheckout()` | ✗ | **✓ REQUIRED** | **✓ REQUIRED** | ✗ (acquires internally) |
| `FolioService::autoPostRoomCharge()` | ✓ (savepoint when nested) | ✗ | ✗ (but caller must hold if nested) | ✗ (acquires internally) |
| `FolioService::autoCloseFolio()` | ✗ | ✗ | ✗ | **✓ REQUIRED** |
| `BookingPaymentService::addPayment()` | ✓ (owner) | ✗ | ✗ (acquires internally) | ✗ |
| `BookingPaymentService::addRefund()` | ✓ (owner) | ✗ | ✗ (acquires internally) | ✗ |
| `BookingPaymentService::deletePayment()` | ✓ (owner) | ✗ | ✗ (acquires internally) | ✗ |

See **ADR-48** for the formal decision record.

### 6.8 Active Stay Definition

**Definition:** An **Active Stay** is any Stay record in a state that indicates the guest has a current or future obligation to occupy a room for this booking. Active Stays prevent checkout finalisation.

**Active Stay states:**
- `Reserved` — stay is assigned and awaiting check-in
- `CheckedIn` — stay is currently occupied

**Non-active Stay states (do not block finalisation):**
- `CheckedOut` — stay has been completed
- `Cancelled` — stay was cancelled before check-in
- `NoShow` — guest did not arrive for this stay

**Finalisation gate rule (ADR-49):** `finaliseBookingCheckout()` is called if and only if:

```
Stay::where('booking_id', $booking->id)
     ->whereIn('status', [StayStatus::Reserved, StayStatus::CheckedIn])
     ->count() === 0
```

**Why `CheckedIn == 0` is insufficient:** A booking may have multiple stays. If stay A is checked out and stay B is still Reserved (guest checking in tomorrow), the old condition `CheckedIn == 0` would incorrectly trigger finalisation — closing the folio and marking the booking CheckedOut while stay B is still open. The Active Stay definition prevents this.

**Why Cancelled and NoShow do not block finalisation:** These states represent stays that will never consume a room. A booking where all remaining stays are Cancelled or NoShow is fully concluded from an occupancy perspective — finalisation is correct.

See **ADR-49** for the formal decision record.

### 6.9 `paymentSummary()` Correction

```
// Before:
$folioTotal = $this->folios->getFolioTotal($booking);
$totalCharges = $folioTotal > 0.0 ? $folioTotal : $requirementsTotal;

// After:
$totalCharges = $this->folios->calculateGuardedFolioTotal($booking);
```

The `$requirementsTotal` local calculation can be removed; `calculateGuardedFolioTotal()` already implements the ADR-11 transition guard. This simplifies `paymentSummary()`.

---

## 7. Service Responsibilities

| Service | Method | Responsibility | Changed? |
|---|---|---|---|
| `StayService` | `checkOut()` | Acquire Booking lock first; detect no remaining Active stays; delegate to `finaliseBookingCheckout` or `updateBookingStayStatus` | **MODIFIED** |
| `StayService` | `checkIn()` | Acquire Booking lock first (fixes G1 deadlock — same root cause as checkOut) | **MODIFIED** |
| `BookingService` | `finaliseBookingCheckout()` | Orchestrate atomic last-stay checkout: charge guarantee, balance validation, folio close, terminal transition. Requires existing transaction + Booking lock from caller. | **NEW** |
| `BookingService` | `updateBookingStayStatus()` | Update booking status for partial checkout; no longer responsible for final CheckedOut transition | **UNCHANGED** (caller now holds Booking lock) |
| `BookingService` | `paymentSummary()` | Replace `getFolioTotal` with `calculateGuardedFolioTotal` | **MODIFIED** |
| `FolioService` | `autoPostRoomCharge()` | Called as idempotency guard in finalise; uses savepoint internally | **UNCHANGED** |
| `FolioService` | `autoCloseFolio()` | Called atomically in finalise under Folio lock held by caller; already idempotent | **UNCHANGED** |
| `FolioService` | `calculateGuardedFolioTotal()` | Authoritative balance source for all checkout logic | **UNCHANGED** |
| `FolioService` | `addCharge()` | Add terminal booking guard (BookingTerminalException) | **MODIFIED** |
| `FolioService` | `reopenFolio()` | Add terminal booking guard (BookingTerminalException) | **MODIFIED** |
| `BookingPaymentService` | `addPayment()` / `addDeposit()` | Add Booking lock (first) + terminal booking guard | **MODIFIED** |
| `BookingPaymentService` | `addRefund()` | Add Booking lock (first) + terminal booking guard + locked paid_total read | **MODIFIED** |
| `BookingPaymentService` | `deletePayment()` | Add Booking lock (first) + terminal booking guard | **MODIFIED** |
| `StayController` | `checkOut()` | Pass authenticated user to service | **MODIFIED** (minor) |

---

## 8. Checkout Flow Diagram

### 8.1 Single-Stay Checkout (Last and Only Stay)

```
HTTP POST /bookings/{booking}/stays/{stay}/check-out
    │
    ▼
StayController::checkOut()
    │  authorize('checkOut', $stay)
    │  abort_unless stay.booking_id == booking.id
    │
    ▼
StayService::checkOut($stay, $actualCheckoutAt, $user)   ← TRANSACTION OWNER
    │
    ▼
DB::transaction {                                         ← opens transaction
    │
    ├─ [1] Booking::lockForUpdate()  ← Booking lock acquired FIRST (ADR-38)
    ├─ [2] Stay::lockForUpdate()
    ├─ [3] RoomAssignment::lockForUpdate()
    │
    ├─ Validate: RA.status == CheckedIn
    ├─ Validate: Stay.actual_checkin_at != null
    ├─ Validate: Stay.actual_checkout_at == null
    │
    ├─ Stay.update(actual_checkout_at, status=CheckedOut, checked_out_by)
    ├─ RA.update(status=CheckedOut)
    │
    ├─ COUNT remaining Active stays (Reserved OR CheckedIn) → 0  ← ADR-49
    │
    ├─ BookingService::finaliseBookingCheckout($booking, $user)   ← ADR-48: runs in caller's transaction
    │       │
    │       ├─ Assert: no Active stays (defensive)
    │       │
    │       ├─ [4] FolioService::autoPostRoomCharge($booking)
    │       │         │
    │       │         └─ DB::transaction (savepoint — implementation detail) {
    │       │               [5] Folio::lockForUpdate()
    │       │               [6] FolioEntry::lockForUpdate() (idempotency check)
    │       │               if not yet posted: INSERT FolioEntry
    │       │            }  ← savepoint released; outer transaction continues
    │       │
    │       ├─ [7] Folio::lockForUpdate()   ← fresh Folio lock (Booking lock already held → canonical order ✓)
    │       │
    │       ├─ calculateGuardedFolioTotal($booking) → total_charges  (read-only)
    │       ├─ [8] BookingPayment::lockForUpdate()->get() → paid_total
    │       ├─ balance_due = total_charges - paid_total
    │       │
    │       ├─ if balance_due > 0:
    │       │       throw OutstandingBalanceException($balance_due)
    │       │       ← ROLLBACK entire transaction (ADR-48: single atomic unit)
    │       │       ← Stay remains CheckedIn
    │       │       ← RA remains CheckedIn
    │       │       ← Booking unchanged
    │       │
    │       ├─ FolioService::autoCloseFolio($folio, $user)
    │       │       ← Folio lock already held (step 7); no-op if already Closed
    │       │       Folio.status = Closed
    │       │
    │       └─ Booking.update(status=CheckedOut, updated_by)
    │               ← Booking lock already held (step 1)
    │
} ← COMMIT (all steps succeeded)
    │
    ▼
StayController: redirect → booking show
```

### 8.2 Partial Checkout (Active Stays Remain)

```
DB::transaction {
    [1] Booking::lockForUpdate()
    [2] Stay::lockForUpdate()
    [3] RoomAssignment::lockForUpdate()
    Validate, Update Stay + RA
    COUNT remaining Active stays (Reserved OR CheckedIn) → > 0   ← ADR-49
    BookingService::updateBookingStayStatus($booking)
      → Booking.status = PartiallyCheckedOut
} COMMIT
```

### 8.3 Check-In (Corrected)

```
DB::transaction {
    [1] Booking::lockForUpdate()   ← NEW: first lock (fixes G1 — ADR-38 extended)
    [2] Stay::lockForUpdate()
    [3] RoomAssignment::lockForUpdate()
    Validate, Update Stay + RA
    FolioService::autoPostRoomCharge($booking)   ← savepoint; Booking→Folio order maintained
    BookingService::updateBookingStayStatus($booking)
      → Booking.status = CheckedIn or PartiallyCheckedIn
} COMMIT
```

### 8.4 Room Release (Implicit)

A room is considered "released" when `RoomAssignment.status = CheckedOut`. The `RoomAvailabilityRuleService` query excludes CheckedOut assignments from conflict detection. No explicit "release" action is needed — checkout implies release.

---

## 9. Transaction Boundary

### 9.1 Single Atomic Unit (Last-Stay Checkout)

| Step | Atomic? | Lock Held | Transaction role |
|---|---|---|---|
| Booking lock acquisition | ✓ | Booking X | checkOut() owns |
| Stay lock acquisition | ✓ | Stay X | checkOut() owns |
| RoomAssignment lock acquisition | ✓ | RA X | checkOut() owns |
| Stay validation | ✓ | All above | checkOut() owns |
| Stay status update | ✓ | All above | checkOut() owns |
| RA status update | ✓ | All above | checkOut() owns |
| Active stays count | ✓ | Booking X (serialises concurrent checkouts) | checkOut() owns |
| autoPostRoomCharge savepoint | ✓ | Booking X + Folio X + FolioEntry gap | savepoint (impl. detail) |
| Folio lock (fresh) | ✓ | Folio X | finaliseBookingCheckout() |
| Balance read (payments, locked) | ✓ | BookingPayment X | finaliseBookingCheckout() |
| Balance validation | ✓ | All above | finaliseBookingCheckout() |
| autoCloseFolio | ✓ | Folio X (already held) | finaliseBookingCheckout() |
| Booking terminal transition | ✓ | Booking X (already held) | finaliseBookingCheckout() |
| **COMMIT / ROLLBACK** | ✓ | All released | checkOut() owns |

### 9.2 What Can Occur Outside the Transaction

| Activity | Outside transaction? | Rationale |
|---|---|---|
| HTTP request authentication | ✓ | Stateless; no DB state involved |
| Policy / authorization check | ✓ | Read-only; no mutation |
| Redirect response construction | ✓ | Post-commit |
| Audit event fired by AuditObserver | ✓ | Eloquent model event fires synchronously after save; if inside transaction, observer runs within it — this is fine for audit |

### 9.3 Failure and Rollback

| Failure point | Effect |
|---|---|
| `OutstandingBalanceException` | Full rollback (ADR-48 — single atomic unit). Stay = CheckedIn. RA = CheckedIn. Folio = Open. Booking unchanged. User sees error with balance_due amount. |
| `FolioVoidedException` (during autoCloseFolio) | Full rollback. Indicates folio was voided concurrently — should not happen if canonical lock order is maintained, but guard is in place. |
| InnoDB deadlock (should not occur after G1 fix) | InnoDB kills the losing transaction; Laravel propagates `QueryException`; application shows generic error. Retry at application layer not designed for Phase 3.1B1. |
| General exception | Full rollback. Laravel's `DB::transaction` wraps the closure; any thrown exception triggers rollback. |

---

## 10. Canonical Lock Order

### 10.1 Extended Order for Phase 3.1B1

The Phase 3.1A canonical order:
```
Booking → Folio → FolioEntry
```

Phase 3.1B1 extends this with Stay, RoomAssignment, and BookingPayment:

```
Booking
  ↓
Room (when acquired — time change only)
  ↓
Stay (sorted by id ASC when acquiring multiple)
  ↓
RoomAssignment (sorted by id ASC when acquiring multiple)
  ↓
Folio
  ↓
FolioEntry
  ↓
BookingPayment
```

**Rule:** No transaction may acquire a lock on a resource lower in this chain without first acquiring the lock on all resources above it that it also touches in the same transaction.

### 10.2 Per-Operation Compliance

| Operation | Lock sequence | Compliant? |
|---|---|---|
| `StayService::checkOut()` (last stay) | Booking → Stay → RA → Folio → FolioEntry → BookingPayment | ✓ |
| `StayService::checkOut()` (non-last) | Booking → Stay → RA | ✓ |
| `StayService::checkIn()` | Booking → Stay → RA → Folio → FolioEntry (via autoPostRoomCharge) | ✓ (fixed in Phase 3.1B1) |
| `BookingService::updateBooking()` + `validateTimeChange()` | Booking (implicit) → Room → Stay → RA | ✓ |
| `FolioService::addCharge()` | Folio | ✓ (single resource) |
| `FolioService::voidEntry()` | Folio → FolioEntry | ✓ |
| `FolioService::closeFolio()` | Folio | ✓ (single resource) |
| `FolioService::voidFolioOnCancellation()` | Folio (within Booking lock held by caller) | ✓ |
| `BookingService::cancelBooking()` | Booking → RA → Stay → Folio | ✓ |
| `BookingPaymentService::addPayment()` / `addDeposit()` | Booking → BookingPayment | ✓ (after fix — ADR-46 extended) |
| `BookingPaymentService::addRefund()` | Booking → BookingPayment | ✓ (after fix — ADR-46) |
| `BookingPaymentService::deletePayment()` | Booking → BookingPayment | ✓ (after fix) |

### 10.3 BookingPayment Locking in finalise

`finaliseBookingCheckout()` reads `booking_payments` to compute `paid_total`. Locking is acquired via `BookingPayment::where('booking_id', $booking->id)->lockForUpdate()->get()` after acquiring the Folio lock (step 3 of finalise). Canonical order: Booking (step 1 in checkOut) → Folio (step 3 in finalise) → BookingPayment (step 5 in finalise). ✓

With payment mutations now also acquiring Booking first, all actors touching BookingPayment rows go through the Booking lock. No new circular dependencies exist.

---

## 11. Concurrency Analysis

### 11.1 Concurrent Checkouts on the Same Booking

**Scenario:** Two staff members simultaneously click "Check Out" on two different stays of the same booking.

```
T1 (checkout stay A):  Booking lock → Stay A lock → RA A lock → ...
T2 (checkout stay B):  Booking lock (waits on T1) → ...
```

T2 waits on the Booking lock. When T1 commits, T2 acquires Booking lock, then proceeds to lock Stay B and RA B. T2 sees the updated count of remaining Active stays (which now reflects T1's commit). Sequential execution guaranteed. ✓

### 11.2 Concurrent Checkout vs Add Payment

**Scenario:** Staff initiates checkout while another staff member records a final payment simultaneously.

```
T1 (checkout):   Booking lock (acquired) → Stay → RA → Folio → BookingPayment (lock read)
T2 (addPayment): Booking lock (WAITS on T1)
```

With Booking-first lock on `addPayment()` (Decision 10, §6.6), T2 cannot insert a payment until T1 releases the Booking lock. The race is fully eliminated:

- If T1 commits first (balance outstanding → rollback, or checkout completes): T2 acquires Booking lock and proceeds. If T1 completed checkout (booking is now terminal), T2's `assertBookingNotTerminal` throws `BookingTerminalException`. If T1 rolled back, T2 inserts payment normally.
- If T2 commits first: T2 inserts payment. T1 acquires Booking lock, reads updated paid_total under BookingPayment lockForUpdate (sees T2's committed payment), balance is now correct. ✓

**Conclusion:** Payment-during-checkout race (G12 / previously OI-7) is fully resolved by Booking-first lock ordering on all payment mutations. ✓

### 11.3 Concurrent Checkout vs Add Charge

```
T1 (checkout/finalise): Booking → Stay → RA → Folio → FolioEntry → BookingPayment
T2 (addCharge):         Folio → FolioEntry
```

T1 will acquire Folio lock. T2 waits. When T1 commits (Folio is now Closed), T2 gets the Folio lock, finds status=Closed, throws `FolioClosedException`. ✓

### 11.4 Concurrent Checkout vs Close Folio (Manual)

```
T1 (checkout/finalise): ... → Folio lock → autoCloseFolio
T2 (closeFolio):         Folio lock (waits)
```

T1 wins, closes folio, commits. T2 gets Folio lock, finds status=Closed, `autoCloseFolio`/`closeFolio` is idempotent — returns without error. ✓ (or T2 wins: folio is Closed; T1's `autoCloseFolio` finds it already Closed — idempotent, continues to Booking UPDATE). ✓

### 11.5 Concurrent Checkout vs Void Entry

```
T1 (checkout/finalise): Booking → Stay → RA → Folio → FolioEntry (balance calc)
T2 (voidEntry):          Folio → FolioEntry
```

T1 holds Folio lock. T2 waits. T1 commits (Folio Closed). T2 gets Folio lock, sees status=Closed, throws `FolioClosedException`. ✓

### 11.6 Concurrent Checkout vs Cancel Booking

```
T1 (checkout): Booking (lock) → Stay → RA → Folio
T2 (cancel):   Booking (lock, waits)
```

T1 wins: checkout completes, Booking=CheckedOut (terminal). T2 gets Booking lock, checks `canCancelNormally()` (no CheckedIn stays — true), proceeds. But folio is Closed and Booking is CheckedOut. `voidFolioOnCancellation` throws `FolioClosedException` — cancel rolls back. ✓ Staff cannot cancel a checked-out booking via the normal cancel path.

**Note:** An explicit `isTerminal()` guard in `cancelBooking()` is deferred to Phase 3.1B2 (OI-4 below). For now, the `FolioClosedException` provides the implicit block.

### 11.7 Concurrent Checkout vs Reopen Folio

```
T1 (checkout): Booking → Stay → RA → Folio (lock → Close)
T2 (reopenFolio): Folio (waits)
```

T1 commits: Folio=Closed, Booking=CheckedOut. T2 gets Folio lock, but Phase 3.1B1 adds terminal booking guard to `reopenFolio`. T2 throws `BookingTerminalException`. ✓

### 11.8 Concurrent Check-In vs Update Booking

```
T1 (checkIn):      Booking (lock) → Stay → RA → Folio → FolioEntry
T2 (updateBooking): Booking (implicit UPDATE, waits on T1)
```

T2 now waits on the Booking lock acquired by T1. Sequential execution guaranteed. Deadlock eliminated (G1 fix in checkIn). ✓

---

## 12. State Transition Matrix

### 12.1 Booking Status

| From | Event | To | Guard |
|---|---|---|---|
| Any non-terminal | Create booking | Draft | — |
| Draft / PendingAssignment / PartiallyAssigned / FullyAssigned | addDeposit | Deposited | — |
| Any pre-checkin | First check-in | PartiallyCheckedIn or CheckedIn | All stays must be Reserved |
| PartiallyCheckedIn / CheckedIn | Second+ check-in | CheckedIn | — |
| CheckedIn | First check-out (Active stays remain) | PartiallyCheckedOut | — |
| PartiallyCheckedOut | Another check-out (Active stays remain) | PartiallyCheckedOut | — |
| CheckedIn / PartiallyCheckedOut | Last check-out (no Active stays remain) | CheckedOut | balance_due ≤ 0; folio auto-closed; ADR-49 |
| Any non-terminal | Cancel | Cancelled | No CheckedIn stays |
| Cancelled | Restore | PendingAssignment or Draft | — |
| **CheckedOut** | **[TERMINAL]** | No transition | Immutable |
| **Cancelled** | **[TERMINAL]** | No transition except Restore | — |
| **NoShow** | **[TERMINAL]** | No transition | — |

**Phase 3.1B1 enforces:** CheckedOut → no further charge/payment mutations (BookingTerminalException).

### 12.2 Folio Status

| From | Event | To | Guard |
|---|---|---|---|
| — | Booking created | Open | Auto-created |
| Open | addCharge / voidEntry / autoPostRoomCharge | Open | Booking not terminal (BookingTerminalException) |
| Open | closeFolio (manual) | Closed | Folio must be Open |
| Open | autoCloseFolio (at last checkout) | Closed | Booking lock held; balance ≤ 0; Folio lock held |
| Closed | reopenFolio | Open | Booking NOT terminal (BookingTerminalException if terminal) |
| Open | voidFolioOnCancellation | Voided | No active entries |
| **Closed** | **[no write operations]** | — | addCharge, voidEntry throw FolioClosedException |
| **Voided** | **[terminal]** | — | All mutations throw FolioVoidedException |

### 12.3 Stay Status

| From | Event | To | Guard | Active? |
|---|---|---|---|---|
| Reserved | checkIn | CheckedIn | RA.status == Assigned; no early check-in | ✓ Active |
| CheckedIn | checkOut | CheckedOut | RA.status == CheckedIn; actual_checkin_at not null | ✗ Not active |
| Reserved | cancelBooking | Cancelled | — | ✗ Not active |
| Reserved | noShow recorded | NoShow | — | ✗ Not active |
| **CheckedOut** | **[terminal for stay]** | — | — | ✗ |
| **Cancelled** | **[terminal for stay]** | — | — | ✗ |
| **NoShow** | **[terminal for stay]** | — | — | ✗ |

**Active Stay definition (ADR-49):** A stay is Active if and only if its status is `Reserved` or `CheckedIn`. Only when all stays are non-active does `finaliseBookingCheckout()` trigger.

### 12.4 RoomAssignment Status

| From | Event | To | Guard |
|---|---|---|---|
| Assigned | checkIn | CheckedIn | Matching stay must be Reserved |
| CheckedIn | checkOut | CheckedOut | Matching stay must be CheckedIn |
| Assigned / CheckedIn | releaseAssignment | Released | Matching stay must not be CheckedIn (for Assigned) |
| Assigned / CheckedIn | cancelBooking | Released | — |
| **CheckedOut** | **[terminal for RA]** | — | — |
| **Released** | **[terminal for RA]** | — | — |

### 12.5 BookingPayment (Payments)

| Operation | Guard | Lock order |
|---|---|---|
| addDeposit | Booking not terminal (BookingTerminalException) | Booking → BookingPayment |
| addPayment | Booking not terminal (BookingTerminalException) | Booking → BookingPayment |
| addRefund | Booking not terminal; amount ≤ paid_total (under Booking lock) | Booking → BookingPayment |
| deletePayment | Booking not terminal; role + time window check | Booking → BookingPayment |
| **[no mutations on terminal booking]** | `BookingTerminalException` thrown | — |

---

## 13. Exception Design

### 13.1 New Exceptions for Phase 3.1B1

#### `OutstandingBalanceException`

```
Namespace: App\Exceptions
Extends: RuntimeException
```

| Property | Value |
|---|---|
| When thrown | `finaliseBookingCheckout()` detects `balance_due > 0` |
| Who catches | Laravel exception handler via `render()` |
| Rollback? | Yes — entire checkout transaction rolls back (ADR-48) |
| User visible? | Yes — render() returns `back()->withErrors(['checkout' => ...])` |
| Message | `"Booking còn số dư chưa thanh toán: {amount}. Vui lòng thanh toán trước khi trả phòng."` |
| Constructor | `__construct(float $balanceDue)` |

#### `BookingTerminalException`

```
Namespace: App\Exceptions
Extends: RuntimeException
```

| Property | Value |
|---|---|
| When thrown | Any service-layer mutation on a terminal booking (`CheckedOut`, `Cancelled`, `NoShow`) |
| Who catches | Laravel exception handler via `render()` |
| Rollback? | Yes (operation is aborted; if inside a transaction, it rolls back) |
| User visible? | Yes — render() returns `back()->withErrors(['booking' => ...])` |
| Message | `"Booking đã hoàn tất hoặc đã hủy. Không thể thực hiện thao tác này."` |
| Scope | **Single service-layer terminal guard (Decision 8). Replaces `CannotDeletePaymentOnTerminalBookingException`.** |

### 13.2 Exception Responsibilities (Single Exception Rule)

| Exception | Layer | Condition | Booking terminal? |
|---|---|---|---|
| `BookingTerminalException` | Service | Booking status is CheckedOut, Cancelled, or NoShow | ✓ Yes — this is the ONLY terminal booking guard |
| `FolioClosedException` | Service | Folio status is Closed (not a booking lifecycle check) | ✗ No — folio state only |
| `FolioVoidedException` | Service | Folio status is Voided | ✗ No — folio state only |
| `OutstandingBalanceException` | Service | balance_due > 0 at checkout | ✗ No — balance validation only |
| `FolioHasActiveEntriesException` | Service | Non-voided entries exist during void attempt | ✗ No — entry state only |
| `AlreadyVoidedException` | Service | Entry already voided | ✗ No — entry state only |

**Rule:** `FolioClosedException` is NOT a terminal booking guard. It is thrown when folio state prevents a folio-level mutation. A folio can be Closed without the booking being terminal (manual `closeFolio()` before checkout). Code must not use `FolioClosedException` to infer booking terminal status.

### 13.3 Existing Exceptions — Interaction with Checkout

| Exception | Checkout scenario | Effect |
|---|---|---|
| `FolioClosedException` | `addCharge` after checkout | Blocked by `BookingTerminalException` first (terminal guard fires before folio check) |
| `FolioVoidedException` | `autoCloseFolio` finds Voided folio | Checkout rolls back; corrupted state — should not occur if canonical lock order is maintained |
| `FolioHasActiveEntriesException` | `voidFolioOnCancellation` during concurrent cancel | Cancel blocked; unrelated to checkout flow |
| `AlreadyVoidedException` | `voidEntry` called twice | Unrelated to checkout |
| `RequirementLockedAfterRoomChargeException` | `updateRequirement` after room charge posted | Unrelated to checkout |

### 13.4 Exception Hierarchy

```
RuntimeException
├── OutstandingBalanceException                    [NEW — Phase 3.1B1]
├── BookingTerminalException                       [NEW — Phase 3.1B1; single terminal guard]
├── FolioClosedException                           [Phase 3.1A — folio state only]
├── FolioVoidedException                           [Phase 3.1A — folio state only]
├── FolioHasActiveEntriesException                 [Phase 3.1A]
├── FolioNotOpenException                          [Phase 3.1A]
├── FolioNotClosedException                        [Phase 3.1A]
├── AlreadyVoidedException                         [Phase 3.1A]
├── FolioNumberOverflowException                   [Phase 3.1A]
├── RefundExceedsMaxException                      [Phase 3.1A stub — wire-up Phase 3.1B1]
├── NegativeAdjustmentExceedsPaidException         [Phase 3.1A stub — wire-up Phase 3.1B1]
└── RequirementLockedAfterRoomChargeException      [Phase 3.1A]

REMOVED:
└── CannotDeletePaymentOnTerminalBookingException  [Phase 3.1A stub — superseded by BookingTerminalException]
```

---

## 14. Regression Risk Assessment

### 14.1 Booking

| Area | Risk | Severity | Mitigation |
|---|---|---|---|
| `paymentSummary()` output changes | `calculateGuardedFolioTotal()` returns different value than `getFolioTotal()` for bookings where system room charge is not yet posted | **HIGH** | New calculation is more correct (ADR-11 guard). Existing tests must be updated to reflect correct expected values. Add targeted tests for transition guard behaviour. |
| `updateBookingStayStatus()` now requires Booking lock | If called outside of a Booking-locked context, it is safe (just a read + UPDATE); the lock just prevents races | **LOW** | No regression — caller (checkOut, checkIn) now holds lock; the method itself is unchanged |
| `cancelBooking()` on a CheckedOut booking | `FolioClosedException` from `voidFolioOnCancellation` — implicit block; explicit guard deferred to Phase 3.1B2 | **LOW** | Expected; document as correct terminal behaviour |
| Finalisation gate change (Active stays, not just CheckedIn) | Bookings with Reserved future stays will no longer incorrectly reach CheckedOut | **LOW** (positive — corrects a bug) | Existing tests that assumed CheckedIn==0 triggers finalise must be updated |

### 14.2 Room Assignment

| Area | Risk | Severity |
|---|---|---|
| `checkOut()` now acquires Booking lock first | Any concurrent `updateBooking` on same booking is now serialised, not deadlocked | **LOW** (positive change) |
| `checkIn()` now acquires Booking lock first | Any concurrent `updateBooking` on same booking is now serialised, not deadlocked | **LOW** (positive change) |
| `releaseAssignment()` does not acquire Booking lock | Potential deadlock with `updateBooking` still exists for release path — out of scope for Phase 3.1B1, flagged for Phase 3.1B2 | **MEDIUM** |

### 14.3 Stay

| Area | Risk | Severity |
|---|---|---|
| `checkOut()` signature change (add User param) | Backward-compatible: `?User $user = null` with Auth fallback | **LOW** |
| `checkIn()` now acquires Booking lock first | Lock order change is transparent to tests (SQLite serialises); no behavioral regression | **LOW** (positive change) |

### 14.4 Payment

| Area | Risk | Severity |
|---|---|---|
| `addPayment()` / `addDeposit()` now acquire Booking lock first | Higher lock contention; operations serialised against checkout | **LOW** — correct behaviour; no test regression |
| `addPayment()` / `addDeposit()` now blocked on terminal booking | Any controller path that adds a payment to a CheckedOut/Cancelled booking will now throw | **LOW** — correct behaviour; tests must be updated |
| `addRefund()` now locked + terminal guard | Slightly higher lock contention; concurrent refunds serialised | **LOW** |
| `deletePayment()` now blocked on terminal booking | Any test that deletes a payment on a terminal booking must expect BookingTerminalException | **LOW** |
| `CannotDeletePaymentOnTerminalBookingException` removed | Any code catching this specific exception must be updated to catch `BookingTerminalException` | **LOW** — search codebase for catch clauses |

### 14.5 Room Board

| Area | Risk | Severity |
|---|---|---|
| Room board reads assignment status | `AssignmentStatus::CheckedOut` is already handled by room board display | **LOW** |
| No new query changes | Room board not modified in Phase 3.1B1 | — |

### 14.6 Dashboard

| Area | Risk | Severity |
|---|---|---|
| `paymentSummary()` change | If dashboard uses `paymentSummary()`, displayed balance values may change for bookings without posted room charge | **MEDIUM** — audit dashboard consumers |
| Dashboard does not trigger checkout | No regression in checkout paths | — |

### 14.7 Reservation / Booking List

| Area | Risk | Severity |
|---|---|---|
| `BookingStatus::CheckedOut` now requires balance = 0 AND no Active stays | Previously reachable with balance > 0 or Reserved stays via `updateBookingStayStatus`; now blocked | **LOW** — correct behaviour |
| Booking list UI shows `can_cancel` based on CheckedIn stays | No change to `canCancelNormally()` logic | — |

---

## 15. ADR List

| ADR | Decision |
|---|---|
| ADR-38 | **Booking lock is required at the start of `StayService::checkOut()` AND `StayService::checkIn()`** before any Stay or RoomAssignment lock. Rationale: prevents circular lock with `BookingService::updateBooking()` + `validateTimeChange()` which acquires Booking (implicit UPDATE) before Stay. Both methods have the same deadlock root cause; both are fixed in Phase 3.1B1. |
| ADR-39 | **`finaliseBookingCheckout()` executes atomically within the caller's transaction.** It is called by `StayService::checkOut()` and inherits the outer `DB::transaction`. No new transaction boundary is created; a MySQL savepoint may be used by sub-calls (e.g., `autoPostRoomCharge`). The entire checkout (stay update + finalise) is one atomic unit. |
| ADR-40 | **Outstanding balance blocks checkout.** `balance_due > 0` at last-stay checkout throws `OutstandingBalanceException` and rolls back. There is no soft-warning path. A booking cannot reach `BookingStatus::CheckedOut` with unpaid charges. |
| ADR-41 | **Folio is auto-closed atomically at last-stay checkout.** `autoCloseFolio()` is called within `finaliseBookingCheckout()`. Staff do not need to manually close the folio as part of checkout. Manual `closeFolio()` remains available for early close scenarios. |
| ADR-42 | **`autoPostRoomCharge()` is called as an idempotency guard in `finaliseBookingCheckout()`.** If the room charge is already posted (normal case — posted at check-in), this is a no-op. If it was voided after check-in, it will be re-posted. This ensures the folio always reflects the full charge before closing. |
| ADR-43 | **`paymentSummary()` must use `calculateGuardedFolioTotal()`** as the authoritative total charges figure. `getFolioTotal()` is a display-only helper (no transition guard, no voided-folio check) and must not be used in balance calculations. |
| ADR-44 | **Terminal booking status (`CheckedOut`, `Cancelled`, `NoShow`) blocks all financial mutations.** Charges, payments, and folio reopens on terminal bookings throw `BookingTerminalException`. This is enforced at the service layer, not the controller layer. `BookingTerminalException` is the single terminal guard — `CannotDeletePaymentOnTerminalBookingException` is removed. |
| ADR-45 | **`reopenFolio()` is blocked on terminal bookings.** A Folio on a CheckedOut booking is closed at checkout and must not be reopened (it would allow post-checkout charges with no checkout gate). ADMIN override path is deferred to Phase 3.1B2. |
| ADR-46 | **All `BookingPaymentService` mutation methods (`addPayment`, `addDeposit`, `addRefund`, `deletePayment`) must acquire `Booking::lockForUpdate()` as their first lock.** Rationale: (1) enforces canonical Booking→BookingPayment lock order; (2) serialises payment mutations against `finaliseBookingCheckout()` which holds the Booking lock; (3) makes terminal booking check read-then-assert under lock (safe pattern). This fully resolves the payment-insertion-during-checkout balance race. |
| ADR-47 | **The count of remaining Active stays is determined inside the checkout transaction** (under Booking lock), not before it. A pre-lock count could be stale if a concurrent checkout commits before the lock is acquired. |
| ADR-48 | **Transaction Ownership Contract for `finaliseBookingCheckout()`.** This method MUST be called within an already-open `DB::transaction`. It does NOT open its own transaction. Calling it outside a transaction is a programming error. The contract is: (a) caller opens transaction; (b) caller acquires `Booking::lockForUpdate()`; (c) caller invokes `finaliseBookingCheckout()`; (d) caller's transaction commits or rolls back atomically. Sub-calls within `finaliseBookingCheckout()` (e.g., `autoPostRoomCharge`) may use savepoints — these are implementation details, not independent transaction boundaries. `autoCloseFolio()` requires the caller to hold the Folio lock before calling it. |
| ADR-49 | **Active Stay Definition for Checkout Finalisation Gate.** An Active Stay is a Stay with status `Reserved` or `CheckedIn`. Checkout finalisation (`finaliseBookingCheckout()`) is triggered if and only if the count of Active stays for the booking is zero. Cancelled and NoShow stays are not Active and do not block finalisation. This replaces the previous incorrect gate of `CheckedIn count == 0`, which would incorrectly finalise bookings with Reserved future stays. |

---

## 16. Database Impact

### 16.1 No Schema Changes

Phase 3.1B1 requires zero migrations. All tables and columns required by this phase already exist:

| Table | Status |
|---|---|
| `bookings` | Existing — `status`, `updated_by` columns sufficient |
| `folios` | Existing — `status`, `closed_at`, `closed_by` columns sufficient |
| `folio_entries` | Existing — no new columns needed |
| `booking_payments` | Existing — no new columns needed |
| `stays` | Existing — `actual_checkout_at`, `status`, `checked_out_by` sufficient |
| `room_assignments` | Existing — `status` sufficient |

### 16.2 Query Changes

| Query | Change |
|---|---|
| `booking_payments` read in `finaliseBookingCheckout` | New `lockForUpdate()` SELECT |
| `stays` active count in `checkOut` | Changed from `COUNT WHERE status = CheckedIn` to `COUNT WHERE status IN (Reserved, CheckedIn)` |
| `paymentSummary` balance calculation | Replaces `FolioEntry SUM` with `calculateGuardedFolioTotal()` |
| `addPayment` / `addDeposit` / `addRefund` / `deletePayment` | New `Booking::lockForUpdate()` SELECT before mutation |

### 16.3 Performance Considerations

- The checkout transaction acquires more locks (Booking, Stay, RA, Folio, FolioEntry, BookingPayment) than the pre-Phase-3.1B1 transaction (Stay, RA). Lock window is longer.
- For a typical single-room booking with one stay, the transaction touches ≤ 10 rows total. Lock contention at this granularity is negligible in a hotel PMS with O(100) concurrent users.
- `autoPostRoomCharge()` within `finaliseBookingCheckout()` creates a nested savepoint. In MySQL, savepoints are lightweight — no concern.
- The `COUNT(*)` of remaining Active stays is a simple indexed query on `(booking_id, status)`. If this index does not exist, it should be added. **Open item:** verify index on `stays(booking_id, status)`.
- Payment mutations now acquire an additional Booking lock per operation — this is an extra row lock on a single row. Lock overhead is negligible; correctness improvement is material.

---

## 17. Test Strategy

### 17.1 Unit Tests

| Test class | Test | Purpose |
|---|---|---|
| `BookingServiceTest` | `test_payment_summary_uses_guarded_folio_total` | `paymentSummary()` calls `calculateGuardedFolioTotal()` |
| `BookingServiceTest` | `test_payment_summary_uses_requirements_estimate_when_no_charge_posted` | ADR-11 transition guard in paymentSummary |
| `BookingServiceTest` | `test_payment_summary_returns_zero_for_voided_folio` | ADR-37 |
| `BookingServiceTest` | `test_finalise_throws_when_balance_outstanding` | ADR-40 |
| `BookingServiceTest` | `test_finalise_closes_folio_when_balance_zero` | ADR-41 |
| `BookingServiceTest` | `test_finalise_auto_posts_room_charge_if_not_yet_posted` | ADR-42 |
| `BookingServiceTest` | `test_finalise_is_no_op_on_room_charge_if_already_posted` | ADR-42 idempotency |
| `BookingServiceTest` | `test_finalise_sets_booking_to_checked_out` | terminal transition |
| `BookingServiceTest` | `test_finalise_requires_no_active_stays` | ADR-49: finalise must not trigger with Reserved stays present |
| `BookingPaymentServiceTest` | `test_add_refund_blocked_on_terminal_booking` | ADR-44 |
| `BookingPaymentServiceTest` | `test_add_payment_blocked_on_terminal_booking` | ADR-44 |
| `BookingPaymentServiceTest` | `test_delete_payment_blocked_on_terminal_booking` | ADR-44 |
| `BookingPaymentServiceTest` | `test_add_payment_acquires_booking_lock` | ADR-46 |
| `BookingPaymentServiceTest` | `test_add_refund_reads_paid_total_under_booking_lock` | ADR-46 concurrent refund prevention |
| `FolioServiceTest` | `test_add_charge_blocked_on_terminal_booking` | ADR-44 |
| `FolioServiceTest` | `test_reopen_folio_blocked_on_terminal_booking` | ADR-45 |

### 17.2 Feature Tests (`CheckoutIntegrationTest`)

| Test | Expected outcome |
|---|---|
| `test_single_stay_checkout_with_zero_balance_completes` | Stay=CheckedOut; RA=CheckedOut; Folio=Closed; Booking=CheckedOut |
| `test_single_stay_checkout_with_outstanding_balance_is_blocked` | Stay remains CheckedIn; Booking unchanged; error returned |
| `test_partial_checkout_sets_booking_partially_checked_out` | Stay=CheckedOut; Booking=PartiallyCheckedOut; Folio=Open |
| `test_checkout_with_reserved_stay_still_pending_does_not_finalise` | Stay A=CheckedOut; Stay B=Reserved; Booking=PartiallyCheckedOut; Folio=Open (ADR-49) |
| `test_last_active_stay_checkout_triggers_finalisation` | All non-cancelled stays=CheckedOut; Folio=Closed; Booking=CheckedOut |
| `test_checkout_when_only_remaining_stays_are_cancelled` | Active count=0; finalise triggers; Booking=CheckedOut |
| `test_last_stay_checkout_auto_closes_folio` | Folio=Closed after last checkout |
| `test_last_stay_checkout_posts_room_charge_if_not_already_posted` | FolioEntry created; Folio=Closed; Booking=CheckedOut |
| `test_checkout_rolls_back_if_outstanding_balance` | All rows unchanged after rollback |
| `test_checkout_with_voided_and_reposted_room_charge` | New room charge posted; Folio=Closed; Booking=CheckedOut |
| `test_add_charge_blocked_after_checkout` | BookingTerminalException; FolioEntry not created |
| `test_add_payment_blocked_after_checkout` | BookingTerminalException; payment not created |
| `test_reopen_folio_blocked_after_checkout` | BookingTerminalException; Folio remains Closed |
| `test_delete_payment_blocked_after_checkout` | BookingTerminalException; payment not deleted |
| `test_add_refund_blocked_after_checkout` | BookingTerminalException; refund not created |
| `test_payment_summary_reflects_guarded_folio_total` | Balance uses calculateGuardedFolioTotal |
| `test_overpaid_checkout_succeeds` | balance_due < 0 allowed; Booking=CheckedOut |
| `test_check_in_does_not_deadlock_with_update_booking` | Both complete without deadlock (serialised by Booking lock) |

### 17.3 Concurrency Tests

> Note: SQLite uses file-level write locking; these tests require MySQL or explicit Pest parallel test setup. Mark with `@requires extension pdo_mysql` or run against a MySQL test database.

| Test | Setup | Expected outcome |
|---|---|---|
| `test_concurrent_checkout_two_stays_same_booking` | Two transactions try to check out stays A and B simultaneously | One serialises after the other; both succeed; Booking=CheckedOut |
| `test_concurrent_checkout_same_stay_twice` | Two transactions try to check out the same stay | One succeeds; other gets "already checked out" validation error |
| `test_checkout_blocks_concurrent_charge` | T1=checkout, T2=addCharge on same booking | T1 closes folio; T2 throws FolioClosedException |
| `test_concurrent_refund_requests_respect_paid_total` | Two concurrent refunds each at 90% of paid_total | One succeeds; other throws refund-exceeds-max error |
| `test_concurrent_payment_and_checkout_serialised_by_booking_lock` | T1=checkout, T2=addPayment on same booking | T2 waits on Booking lock; T1 sees T2's payment if T2 commits first, or T2 is blocked if T1 completes first |

### 17.4 Rollback Tests

| Test | Expected outcome |
|---|---|
| `test_checkout_rollback_on_outstanding_balance` | DB state identical to pre-request state |
| `test_checkout_rollback_leaves_folio_open` | Folio.status = Open after rollback |
| `test_checkout_rollback_leaves_stay_checked_in` | Stay.status = CheckedIn after rollback |
| `test_checkout_rollback_leaves_booking_unchanged` | Booking.status unchanged after rollback |

### 17.5 Terminal Booking Regression Tests

| Test | Expected outcome |
|---|---|
| `test_cancelled_booking_blocks_add_charge` | BookingTerminalException |
| `test_checked_out_booking_blocks_add_charge` | BookingTerminalException |
| `test_no_show_booking_blocks_add_charge` | BookingTerminalException |
| `test_non_terminal_booking_allows_add_charge` | FolioEntry created |
| `test_cancel_after_checkout_is_blocked_by_closed_folio` | FolioClosedException propagated from voidFolioOnCancellation |

### 17.6 Existing Tests — Expected Impact

| Existing test suite | Expected change |
|---|---|
| `FolioCrudTest` | `paymentSummary` output tests must be updated for `calculateGuardedFolioTotal` values |
| `BookingManagementUiTest` | Checkout-related tests must reflect new OutstandingBalanceException path |
| `StayService` tests (if any) | Booking lock acquisition in checkOut and checkIn is transparent to existing tests (SQLite serialises) |
| Tests catching `CannotDeletePaymentOnTerminalBookingException` | Update to catch `BookingTerminalException` |

---

## 18. Acceptance Criteria

| # | Criterion | Verifiable by |
|---|---|---|
| AC1 | Checking out the last Active stay with zero balance atomically sets: Stay=CheckedOut, RA=CheckedOut, Folio=Closed, Booking=CheckedOut | Feature test |
| AC2 | Checking out the last Active stay with outstanding balance rolls back: Stay=CheckedIn, RA=CheckedIn, Folio=Open, Booking unchanged | Feature test |
| AC3 | Checking out a non-last Active stay sets: Stay=CheckedOut, RA=CheckedOut, Booking=PartiallyCheckedOut, Folio=Open | Feature test |
| AC4 | `paymentSummary()` uses `calculateGuardedFolioTotal()` as the charge basis | Unit test |
| AC5 | Adding a charge to a CheckedOut booking throws `BookingTerminalException` | Unit + feature test |
| AC6 | Adding a payment to a CheckedOut booking throws `BookingTerminalException` | Unit + feature test |
| AC7 | Reopening a Folio on a CheckedOut booking throws `BookingTerminalException` | Unit + feature test |
| AC8 | Concurrent checkout of the same stay results in exactly one success and one validation error | Concurrency test |
| AC9 | If the room charge was voided after check-in, checkout re-posts it before closing the folio | Feature test |
| AC10 | All 298 existing tests continue to pass after Phase 3.1B1 implementation | Test suite |
| AC11 | No new CRITICAL or HIGH issues remain unresolved after implementation | Code review |
| AC12 | A checkout that leaves a Reserved stay pending does NOT finalise the booking — Folio remains Open, Booking remains PartiallyCheckedOut (ADR-49) | Feature test |
| AC13 | A checkout where all remaining stays are Cancelled or NoShow DOES finalise the booking (Active count = 0, ADR-49) | Feature test |
| AC14 | `StayService::checkIn()` acquires Booking lock before Stay lock — no deadlock with concurrent `updateBooking` | Concurrency test + code review |
| AC15 | `finaliseBookingCheckout()` cannot be called without an active outer transaction — any attempt results in non-atomic commits (enforced by contract, verified by code review per ADR-48) | Code review |
| AC16 | All payment mutations (`addPayment`, `addDeposit`, `addRefund`, `deletePayment`) acquire Booking lock as their first lock (ADR-46) | Unit test + code review |

---

## Open Issues

| # | Issue | Severity | Resolution |
|---|---|---|---|
| OI-1 | **`RoomAssignmentService::releaseAssignment()` missing Booking lock** — potential deadlock with `validateTimeChange`. Out of current scope. | MEDIUM | Defer to Phase 3.1B2 |
| OI-2 | **Post-checkout refund policy** — should `addRefund()` be allowed on a `CheckedOut` booking? Current design blocks it (ADR-44). If post-checkout refunds are a business requirement, a separate ADMIN-gated `postCheckoutRefund()` method is the correct approach. | MEDIUM | Business decision required |
| OI-3 | **`stays(booking_id, status)` index** — the Active stay `COUNT(*)` runs on this column pair inside a locked transaction. Verify that an index covering `(booking_id, status)` exists on the `stays` table. If not, this is a performance risk. | LOW | Verify schema before implementation |
| OI-4 | **Cancellation of a CheckedOut booking** — currently blocked by `FolioClosedException` from `voidFolioOnCancellation`. This is a secondary effect, not an explicit business rule. Should `cancelBooking` explicitly guard against CheckedOut status before attempting folio void? | LOW | Deferred to Phase 3.1B2 |

---

**Critical Issues: 0**  
**High Issues: 0**  
**Medium Issues: 2** (OI-1: releaseAssignment lock; OI-2: post-checkout refund policy)  
**Low Issues: 2** (OI-3: stays index; OI-4: cancel-after-checkout guard)

**READY FOR CHATGPT ARCHITECTURE REVIEW**
