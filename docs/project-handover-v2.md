# Lastella PMS — Project Handover v2

**Created:** 2026-07-01  
**Covers through:** Phase 3.1B1 (tag `phase-3.1B1`, commit `8da1b67`)  
**Status:** Authoritative bootstrap document for all future Claude sessions

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Repository Information](#2-repository-information)
3. [Architecture Principles](#3-architecture-principles)
4. [Completed Phases](#4-completed-phases)
5. [Current Folio Architecture](#5-current-folio-architecture)
6. [Current Locking Rules](#6-current-locking-rules)
7. [Transaction Ownership Rules](#7-transaction-ownership-rules)
8. [Current Exception Hierarchy](#8-current-exception-hierarchy)
9. [Current Test Status](#9-current-test-status)
10. [Remaining Roadmap](#10-remaining-roadmap)
11. [Development Workflow](#11-development-workflow)
12. [Rules for Future Claude Sessions](#12-rules-for-future-claude-sessions)

---

## 1. Project Overview

**Lastella PMS** is a hotel property management system built for Vietnamese small hotels. The system handles bookings, room assignments, check-in/check-out, a folio charge ledger, and payment collection.

### Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.x / Laravel (Eloquent ORM) |
| Frontend | Inertia.js + Vue 3 (SPA feel, server-rendered data) |
| Database (production) | MySQL |
| Database (tests) | SQLite (in-memory) |
| Testing framework | Pest / PHPUnit |
| Currency | Vietnamese Dong (VND) — integer amounts; no decimals in practice |

### Branch Strategy

| Branch | Purpose |
|---|---|
| `master` | Main / stable — target for PRs |
| `phase-3` | Active development branch for all Phase 3.x work |

### Development Workflow (abbreviated)

Architecture design → ChatGPT architecture review → Claude Code implementation → ChatGPT code review → Commit → Push → Tag → GitHub Release → Next phase

---

## 2. Repository Information

- **Remote:** `https://github.com/doantiendathl-ops/lastella.git`
- **Default branch:** `master`
- **Active development branch:** `phase-3`
- **Latest release tag:** `phase-3.1B1` → commit `8da1b67`

### Commit Map (Phase 3.x)

| Commit | Phase | Type |
|---|---|---|
| `68abffa` | Phase 3.1 — Payment Foundation | Implementation |
| `e1ac762` | Phase 3.2 — Folio Foundation | Roadmap design document |
| `400958b` | Phase 3.3 — Service Charges | Roadmap design document |
| `0352641` | Phase 3.1A — Folio Charge Ledger Backend | Implementation |
| `8da1b67` | Phase 3.1B1 — Checkout Integration | Implementation |

> Phase 3.2 and 3.3 commits contain roadmap design documents only, not implementation code.  
> The numbering reflects planning order, not implementation order. Implementation order is: 3.1 → 3.1A → 3.1B1 → 3.1B2 → future.

---

## 3. Architecture Principles

### Architecture-First

Every phase begins with a written architecture document (`docs/roadmaps/phase-X.md`) that is reviewed by ChatGPT before implementation starts. No code is written until architecture is approved. Architecture decisions are recorded as numbered ADRs.

### ADR Process

Architecture Decision Records (ADRs) are numbered globally across the project. Each new phase continues from the highest existing ADR number. The full ADR history lives in the relevant architecture documents in `docs/roadmaps/`. As of Phase 3.1B1, ADRs are numbered ADR-1 through ADR-49.

### Regression Safety

- Every implementation must leave all pre-existing tests passing.
- New tests are written for every new feature or behaviour change.
- Test suite must be green before any commit is created.

### Canonical Lock Order

The project enforces a strict canonical lock acquisition order across all database transactions to prevent deadlocks. See §6 for the full order and rationale.

### Transaction Ownership

Service methods declare explicit transaction ownership contracts. Some methods own their own transaction; others execute within a caller-provided transaction. This is not implicit — it is an ADR (ADR-48). See §7 for details.

### BCMath for Currency

All balance calculations use BCMath (`bcadd`, `bcsub`, `bcmul`, `bccomp`) at `scale=2` to avoid floating-point precision errors on monetary values. Float arithmetic is used only for intermediate aggregation where precision is acceptable (pre-existing pattern in `calculatePaidTotal`).

---

## 4. Completed Phases

### Phase 2.5 — Booking Operations & Room Management

**Commit:** `118a0e0`  
**Objective:** Core booking and room assignment operations including check-in, check-out (pre-folio), room board, booking cancellation, and stay management.  
**Key features:** `StayService::checkIn/checkOut`, `RoomAssignmentService::assignRooms/releaseAssignment`, room board UI, booking list with filters.  
**Test status at completion:** All tests passing (baseline for Phase 3.x).

---

### Phase 3.1 — Payment Foundation

**Commit:** `68abffa`  
**Tag:** `phase-3.1`  
**Objective:** Fix `addDeposit()` status-downgrade bug; add delete payment (role + time window); add Refund with `max_refundable` cap; add signed Adjustment (ADMIN-only); expand `paymentSummary()` to 9-key breakdown.

**Major architecture decisions:**
- ADR-21: Refund cap = `max(0, paid_total − folio_total)` computed under locks.
- ADR-22: All payment mutations lock Booking → Folio → BookingPayments.
- ADR-24: Adjustment is signed (positive or negative), ADMIN-only, note required.
- ADR-33: `deletePayment()` blocked on all terminal bookings.
- ADR-34: `deletePayment()` lock order includes Folio row.

**Files changed:** `BookingPaymentService.php`, `PaymentCrudTest.php`

---

### Phase 3.1A — Folio Charge Ledger Backend Foundation

**Commit:** `0352641`  
**Tag:** `phase-3.1A`  
**Objective:** Create the folio charge ledger: migrations (`folios`, `folio_entries`, `folio_number_sequences`), enums (`FolioStatus`, `ChargeType`), Eloquent models (`Folio`, `FolioEntry`), and the full `FolioService` with all folio lifecycle methods.

**Major architecture decisions:**
- ADR-1: One aggregate room charge auto-posted at first check-in (posting_key based idempotency).
- ADR-2: `posting_key` is internal-only; rejected at request layer.
- ADR-5: Folio auto-closes only on financial completion (not at physical checkout).
- ADR-11: Transition guard fallback when system room charge is not yet posted.
- ADR-32: `calculateGuardedFolioTotal()` is the single shared balance calculation method for all financial decisions.
- ADR-35: `autoCloseFolio()` is idempotent: OPEN → close; CLOSED → return silently; VOIDED → throw.
- ADR-36: `voidFolioOnCancellation()` is PUBLIC (cross-class boundary); no HTTP route.
- ADR-37: VOIDED folio short-circuits to `folio_total = 0` — no guard applied.

**Key methods in `FolioService`:**

| Method | Visibility | Notes |
|---|---|---|
| `createFolioForBooking(Booking)` | public | Auto-called from `createBooking()` |
| `addCharge(Folio, array)` | public | Locks Folio; server-side amount; posting_key = null |
| `autoPostRoomCharge(Booking, ?User)` | public | Idempotent via posting_key; called at check-in |
| `voidEntry(FolioEntry, User, string)` | public | Folio lock → FolioEntry lock |
| `calculateGuardedFolioTotal(Booking)` | public | **Single source of truth for all balance decisions** |
| `closeFolio(Folio, User)` | public | Staff action; records closed_by |
| `autoCloseFolio(Folio, ?User)` | public | System action; idempotent; no HTTP route |
| `reopenFolio(Folio)` | public | ADMIN only |
| `voidFolioOnCancellation(Folio)` | public | System only; no HTTP route |

**Test file:** `tests/Feature/FolioCrudTest.php`

---

### Phase 3.1B1 — Checkout Integration

**Commit:** `8da1b67`  
**Tag:** `phase-3.1B1`  
**Objective:** Wire the folio building blocks into a fully atomic, concurrency-safe checkout process. Implements ADR-38 through ADR-49.

**Major architecture decisions:**

| ADR | Decision |
|---|---|
| ADR-38 | Booking lock first in both `checkIn()` and `checkOut()` — prevents deadlock with `updateBooking` |
| ADR-39 | `finaliseBookingCheckout()` runs in caller's transaction (no own transaction) |
| ADR-40 | Outstanding balance blocks checkout — throws `OutstandingBalanceException`, full rollback |
| ADR-41 | Folio auto-closes atomically at last-stay checkout |
| ADR-42 | `autoPostRoomCharge()` called as idempotency guard in `finaliseBookingCheckout()` |
| ADR-43 | `paymentSummary()` uses `calculateGuardedFolioTotal()` — replaced `getFolioTotal()` |
| ADR-44 | Single `BookingTerminalException` as the only service-layer terminal guard |
| ADR-45 | `reopenFolio()` blocked on terminal bookings |
| ADR-46 | All payment mutations in `BookingPaymentService` acquire Booking lock first |
| ADR-47 | Active stay count read inside the checkout transaction (under Booking lock) |
| ADR-48 | `finaliseBookingCheckout()` transaction ownership contract: caller opens transaction, caller holds Booking lock |
| ADR-49 | Active stay definition: `Reserved` OR `CheckedIn` (not just `CheckedIn`) |

**New method `BookingService::finaliseBookingCheckout(Booking, ?User)`:**
1. Assert no Active stays remain (defensive).
2. Call `autoPostRoomCharge()` — idempotency guard.
3. Lock Folio (`Folio::lockForUpdate()`).
4. Compute `calculateGuardedFolioTotal()`.
5. Lock all BookingPayments → compute `paid_total`.
6. Compute `balance_due = total_charges − paid_total`.
7. If `balance_due > 0` → throw `OutstandingBalanceException` (full rollback).
8. Call `autoCloseFolio($folio, $user)`.
9. Set `Booking.status = CheckedOut`.

**New exceptions:** `BookingTerminalException`, `OutstandingBalanceException`

**New test file:** `tests/Feature/CheckoutIntegrationTest.php` (11 tests)

**Test result at commit:** 311 passed, 1917 assertions, 0 failures.

---

## 5. Current Folio Architecture

### Design Principle: Payment ≠ Charge

```
PAYMENTS (booking_payments)        CHARGES (folio_entries)
= money the guest PAYS             = money the guest OWES

balance_due = folio_total − paid_total
```

### Single Folio per Booking

One `Folio` per `Booking`. Split billing is deferred to a future phase.

### Key Tables

| Table | Purpose |
|---|---|
| `folios` | One row per booking; tracks status (Open/Closed/Voided), folio_number, closed_at/by |
| `folio_entries` | Individual charge line items; voided entries kept for audit (voided_at IS NOT NULL) |
| `folio_number_sequences` | Daily sequence counter; generates `FLO-YYYYMMDD-######` numbers |
| `booking_payments` | Money received (Deposit, RoomPayment, Refund, Adjustment, etc.) |

### Folio Status Lifecycle

```
Created → OPEN → CLOSED (staff closeFolio or system autoCloseFolio)
         ↓
      VOIDED (cancelled/noshow with no active charges)
```

- `CLOSED`: no further charges; `addCharge` throws `FolioClosedException`.
- `VOIDED`: `calculateGuardedFolioTotal()` returns 0.0; full `paid_total` refundable.
- `reopenFolio()` goes CLOSED → OPEN (ADMIN only; blocked on terminal bookings).

### Posting Key (Idempotency)

System room charges carry a `posting_key` (`ROOM_CHARGE_{booking_id}_AGGREGATE`). Manual charges always have `posting_key = null`. The API layer rejects `posting_key` in any HTTP request body.

### Guarded Folio Total

`calculateGuardedFolioTotal(Booking): float` is the **only** method that feeds `balance_due` decisions:

| Condition | Returns |
|---|---|
| Folio status = VOIDED | `0.0` (short-circuit; no guard or entry sum) |
| System room charge posted (active) | `SUM(all active folio_entries.amount)` |
| System room charge missing or voided | `SUM(requirements.room_price × qty)` + `SUM(non-room active entries)` |

The third case is the **transition guard** — it prevents undercharging during the period between booking creation and first check-in. The guard is removed in Phase 3.3 once `autoPostRoomCharge` is confirmed stable across all paths.

### Auto Room Charge

`autoPostRoomCharge(Booking, ?User)` is called:
1. At first check-in (`StayService::checkIn`).
2. As an idempotency guard in `finaliseBookingCheckout` (handles void-after-check-in edge case).

Both calls are idempotent — if the charge is already posted, `null` is returned.

### Auto Folio Close

`autoCloseFolio(Folio, ?User)` is called atomically inside `finaliseBookingCheckout` when balance_due ≤ 0. It is idempotent on CLOSED (staff may have pre-closed). It throws `FolioVoidedException` on VOIDED (data error).

### Outstanding Balance Enforcement

A booking cannot reach `CheckedOut` with `balance_due > 0`. `OutstandingBalanceException` is thrown and the entire checkout transaction rolls back. The stay remains `CheckedIn`. Staff must record payment before the checkout can proceed.

### Terminal Booking Rules

Once a booking is `CheckedOut`, `Cancelled`, or `NoShow` (`BookingStatus::isTerminal()` returns `true`), all service-layer mutations throw `BookingTerminalException`:

- `FolioService::addCharge()` — blocked.
- `FolioService::reopenFolio()` — blocked (ADR-45).
- `BookingPaymentService::addPayment/addDeposit/addRefund/deletePayment` — blocked.

**Correction path for terminal bookings:** ADMIN uses `reopenFolio()` (only on CheckedOut, not Cancelled/NoShow with VOIDED folio), posts charge/void corrections, then creates an `Adjustment` payment (positive or negative, note required) to settle the payment-side change.

---

## 6. Current Locking Rules

### Canonical Lock Order

```
1. Booking row
2. Room row               (only in updateBooking / validateTimeChange)
3. Stay row(s)            (id ASC when multiple)
4. RoomAssignment row(s)  (id ASC when multiple)
5. Folio row
6. FolioEntry rows        (id ASC when multiple)
7. BookingPayment rows    (id ASC when multiple)
```

**Rule:** No transaction may acquire a lock on a resource lower in this chain without first holding all resources above it that it also touches in the same transaction.

### Per-Operation Lock Sequences

| Operation | Lock sequence |
|---|---|
| `StayService::checkOut()` (last stay) | Booking → Stay → RA → Folio → FolioEntry → BookingPayment |
| `StayService::checkOut()` (non-last stay) | Booking → Stay → RA |
| `StayService::checkIn()` | Booking → Stay → RA → (Folio → FolioEntry via autoPostRoomCharge savepoint) |
| `BookingService::cancelBooking()` | Booking → RA → Stay → Folio |
| `FolioService::addCharge()` | Folio |
| `FolioService::voidEntry()` | Folio → FolioEntry |
| `FolioService::autoPostRoomCharge()` | Booking → Requirements → Folio → FolioEntry (idempotency check) |
| `BookingPaymentService::addPayment/addDeposit` | Booking → BookingPayment |
| `BookingPaymentService::addRefund` | Booking → BookingPayment |
| `BookingPaymentService::deletePayment` | Booking → BookingPayment |
| `BookingService::finaliseBookingCheckout()` | (Booking from caller) → Folio → BookingPayment |

### Why This Matters

Before Phase 3.1B1, `checkOut()` and `checkIn()` acquired Stay before Booking. `updateBooking()` with `validateTimeChange()` acquires Booking (implicit) before Stay. This created a circular wait — a classic deadlock. ADR-38 fixed both methods by prepending `Booking::lockForUpdate()` as the first lock.

---

## 7. Transaction Ownership Rules

### Who Opens Transactions

| Method | Opens own transaction? | Requires caller's transaction? |
|---|---|---|
| `StayService::checkOut()` | ✅ (owner) | ❌ |
| `StayService::checkIn()` | ✅ (owner) | ❌ |
| `BookingService::finaliseBookingCheckout()` | ❌ | **✅ REQUIRED** (ADR-48) |
| `FolioService::autoPostRoomCharge()` | ✅ (savepoint when nested) | ❌ |
| `FolioService::autoCloseFolio()` | ❌ (single UPDATE) | ❌ (but Folio lock must be held by caller) |
| `BookingPaymentService::addPayment/addDeposit/addRefund/deletePayment` | ✅ (owner) | ❌ |

### Critical: `finaliseBookingCheckout()` Contract (ADR-48/ADR-39)

This method **must** be called within an already-open `DB::transaction` with the Booking lock already held. It does NOT open its own transaction. Calling it outside a transaction is a programming error — partial mutations will auto-commit and atomicity is destroyed.

The only valid caller is `StayService::checkOut()`. Nested calls within `finaliseBookingCheckout()` (e.g., `autoPostRoomCharge`) use MySQL savepoints — these are implementation details, not independent transaction boundaries.

### Nested Savepoints

When `autoPostRoomCharge()` is called from within `checkOut()`'s transaction, the inner `DB::transaction()` creates a MySQL savepoint. If `autoPostRoomCharge()` fails internally, only the savepoint rolls back — the outer transaction continues. This is intentional and safe.

---

## 8. Current Exception Hierarchy

```
RuntimeException
├── OutstandingBalanceException          [Phase 3.1B1] — balance_due > 0 at checkout; rolls back entire transaction
├── BookingTerminalException             [Phase 3.1B1] — single terminal guard for all service mutations on terminal bookings
├── FolioClosedException                 [Phase 3.1A] — folio state guard (not a booking terminal guard)
├── FolioVoidedException                 [Phase 3.1A] — folio state guard
├── FolioHasActiveEntriesException       [Phase 3.1A] — thrown by voidFolioOnCancellation when active entries exist
├── FolioNotOpenException                [Phase 3.1A] — closeFolio on non-open folio
├── FolioNotClosedException              [Phase 3.1A] — reopenFolio on non-closed folio
├── AlreadyVoidedException               [Phase 3.1A] — voidEntry called on already-voided entry
├── FolioNumberOverflowException         [Phase 3.1A] — daily sequence > 999,999
├── RefundExceedsMaxException            [Phase 3.1A] — refund > max_refundable
├── NegativeAdjustmentExceedsPaidException [Phase 3.1A] — negative adjustment makes paid_total < 0
└── RequirementLockedAfterRoomChargeException [Phase 3.1A] — room_price/quantity edit blocked after charge posted
```

### Key Distinction

`BookingTerminalException` is the booking lifecycle guard. `FolioClosedException` is the folio state guard. These are **different concerns** — a folio can be Closed without the booking being terminal (staff pre-close). Do not conflate them.

### Removed Exceptions

`CannotDeletePaymentOnTerminalBookingException` (Phase 3.1A stub) was superseded by `BookingTerminalException` in Phase 3.1B1 and removed.

---

## 9. Current Test Status

**As of Phase 3.1B1 commit `8da1b67`:**

```
Tests:    311 passed (1917 assertions)
Duration: ~93s
Failures: 0
```

### Test Files

| File | Coverage |
|---|---|
| `tests/Feature/FolioCrudTest.php` | All folio operations: addCharge, voidEntry, autoPostRoomCharge, closeFolio, autoCloseFolio, calculateGuardedFolioTotal, voidFolioOnCancellation, terminal correction flow |
| `tests/Feature/CheckoutIntegrationTest.php` | ADR-38 → ADR-49 end-to-end: zero-balance checkout, outstanding balance rollback, partial checkout, Reserved-stay gate, all 4 payment types blocked post-checkout, folio reopen blocked, folio charge blocked |
| `tests/Feature/PaymentCrudTest.php` | Delete payment, refund cap, adjustment ADMIN-only, terminal booking blocking, all 9 paymentSummary keys |
| `tests/Feature/BookingEngineFoundationTest.php` | Core booking engine, checkout balance enforcement |
| `tests/Feature/BookingManagementUiTest.php` | UI-level booking management including checkout flows |

### Regression Philosophy

- All pre-existing tests must pass after every implementation phase.
- SQLite is used for tests; MySQL-specific concurrency races (deadlocks, lock waits) cannot be tested in SQLite — those are verified by code review.
- The `travelTo()` helper is required in test setUp when bookings use future `checkin_at` dates, to pass the "not yet check-in time" guard in `checkIn()`.

---

## 10. Remaining Roadmap

### Phase 3.1B2 — UI, Checkout Gate, and Backfill

**Status:** NOT STARTED — awaiting architecture planning session.

**Scope:**
- Folio tab UI (show charges, void button, add manual charge form).
- Correct balance formula live in booking show page (uses `calculateGuardedFolioTotal()`).
- Atomic checkout gate visible in UI — error message when balance_due > 0.
- `php artisan folio:backfill` — creates folios for pre-existing bookings (idempotent, `--dry-run` mode).
- ADMIN override path for folio reopen on CheckedOut booking (explicit; blocked in Phase 3.1B1).
- Explicit terminal guard in `cancelBooking()` (currently an implicit block via `FolioClosedException`).
- `RoomAssignmentService::releaseAssignment()` Booking lock fix (OI-1 from Phase 3.1B1 architecture doc).

---

### Phase 3.2 — Folio Foundation UI (design document at `docs/roadmaps/phase-3.2-folio-foundation.md`)

Extended folio management: folio number display, audit trail view, staff close/reopen UI with permission enforcement.

---

### Phase 3.3 — Service Charges (design document at `docs/roadmaps/phase-3.3-service-charges-design.md`)

**Status:** Design document committed (`400958b`). Not yet reviewed or approved for implementation.

Sub-phases:
1. **3.3.1** — Service Rate Catalog (`service_rates` table, admin CRUD).
2. **3.3.2** — Per-Stay Attribution (`stay_id` on `folio_entries`; per-stay room charge split; replaces aggregate charge strategy).
3. **3.3.3** — Quick-Charge UI + Late/Early Fee Policy.
4. **3.3.4** — Transition guard removal + test cleanup (guard removed after per-stay charge is stable).

---

### Phase 3.4 — Night Audit

Nightly per-stay room charge posting. Uses `posting_key` format `ROOM_CHARGE_{booking_id}_STAY_{stay_id}_NIGHT_{YYYYMMDD}`. Historical folios need ADMIN reopen → void aggregate → post nightly entries.

---

### Phase 3.5 — Payment Screen Refactor

Dedicated payment management UI; ACCOUNTANT role refinements; payment receipt generation.

---

### Phase 4.x — Room Setup Requests

Special requests, room preferences, amenity management. Design document at `docs/roadmaps/phase-4.1-room-setup-requests.md`.

---

## 11. Development Workflow

Each implementation phase follows this exact sequence:

```
1. ARCHITECTURE
   Claude drafts architecture document → saved to docs/roadmaps/phase-X.md
   → ChatGPT architecture review
   → Revise until zero CRITICAL/HIGH issues
   → Approved

2. IMPLEMENTATION
   Claude Code implements per approved architecture
   → All pre-existing tests must remain green
   → New tests written for new behaviour
   → 311+ tests passing before commit is created

3. CODE REVIEW
   ChatGPT reviews implementation code
   → CRITICAL/HIGH issues must be fixed before commit
   → Implementation report saved to docs/reports/phase-X-implementation.md

4. COMMIT
   Single commit per phase with conventional commit message:
   "feat: Phase X.Y Description (ADR-N → ADR-M)"

5. PUSH + TAG
   git push origin phase-3
   git tag phase-X.Y && git push origin phase-X.Y

6. GITHUB RELEASE
   gh release create phase-X.Y --title "..." --notes-file docs/reports/phase-X-implementation.md
   (gh CLI required — if not installed, user creates manually)

7. NEXT PHASE
   Do not start next phase until explicitly authorized.
   Do not start Phase 3.1B2 without an architecture planning session.
```

### After Each Phase: Memory + Report Rule

- Implementation report: `docs/reports/phase-X-implementation.md` (full detail)
- Chat summary: 4 lines only (commit hash, push status, tag, release status)
- Update `C:\Users\Lappro\.claude\projects\C--Projects-lastella\memory\project_phase_state.md`

---

## 12. Rules for Future Claude Sessions

### Must Read First

Before writing any code in a new session:
1. Read this file.
2. Run `git log --oneline -10` to confirm current branch and latest commit.
3. Run `php artisan test --no-coverage` to confirm test baseline.
4. Read the architecture document for the phase being implemented (`docs/roadmaps/phase-X.md`).

---

### Never Bypass the Canonical Lock Order

The lock order (Booking → Stay → RoomAssignment → Folio → FolioEntry → BookingPayment) is not optional. Any new service method that touches multiple of these resources must acquire locks in this order, or it risks deadlock in production. If a proposed design requires a different order, stop and raise it as an ADR before implementing.

---

### Never Call `finaliseBookingCheckout()` Outside a Transaction

`finaliseBookingCheckout()` has an explicit transaction ownership contract (ADR-48): caller opens the transaction, caller holds the Booking lock, then calls this method. The method does NOT open its own transaction. Wrapping it in an extra `DB::transaction()` creates a savepoint but does NOT guarantee atomicity if the outer code does not also have a transaction — never assume.

---

### Never Use `getFolioTotal()` for Financial Decisions

`FolioService::getFolioTotal()` is a raw SUM helper — it has no transition guard and no voided-folio check. It must never be used for `balance_due` decisions, refund caps, or checkout gates. All financial balance decisions must go through `FolioService::calculateGuardedFolioTotal()`. This is ADR-32 / ADR-43.

---

### Never Introduce a New Terminal Guard Exception

`BookingTerminalException` is the **only** service-layer terminal guard (ADR-44). Do not create `CannotAddChargeOnTerminalBookingException`, `CannotRefundTerminalBookingException`, or any variant. If a new service method needs to guard against terminal bookings, call `assertBookingNotTerminal(Booking)` which throws `BookingTerminalException`.

---

### Never Bypass Architecture Review

Any new service method, new exception, new ADR, or new data model change must be designed in an architecture document and reviewed by ChatGPT before implementation. Do not add architectural elements directly in code that were not in the approved architecture document.

---

### `travelTo()` in Test setUp

Any test that creates bookings with a future `checkin_at` date (e.g., `2026-07-01 14:00:00`) must call `$this->travelTo('2026-07-01 14:00:00')` in `setUp()` before creating those bookings. The `checkIn()` method validates that `now() >= planned_checkin_at`. Without `travelTo()`, all checkout-related tests fail with `ValidationException "Chưa đến thời gian nhận phòng dự kiến"`.

---

### No Commit Without ChatGPT Approval

Never commit implementation code without explicit ChatGPT code review approval. The workflow is: implement → test suite green → submit for ChatGPT review → fix CRITICAL/HIGH issues → approved → commit. "The tests pass" is not sufficient authorization to commit.

---

### No Phase Starts Without Architecture Planning

Phase 3.1B2 and all subsequent phases require an architecture planning session before implementation. Do not begin coding a new phase in the same session that closed the previous phase, unless the user explicitly authorizes it with a reviewed architecture document.

---

### Respect `balance_due ≤ 0` Semantics

Negative `balance_due` means overpaid (guest paid more than charged). A checkout succeeds when `balance_due <= 0` — overpayment is allowed. Only positive `balance_due` (guest still owes money) blocks checkout. Do not change this to `balance_due === 0` or `balance_due < 0.01`.

---

### BCMath Precision

Use BCMath (`bcmul`, `bcadd`, `bcsub`, `bccomp`) at `scale=2` for all charge and balance arithmetic. Float is acceptable for read-only aggregation (e.g., summing payment rows via Eloquent `sum()`), but the final balance comparison must use BCMath. The pattern `bcsub((string)$totalCharges, (string)$paidTotal, 2)` followed by `bccomp($balance, '0', 2) > 0` is the canonical comparison.

---

*This document supersedes any previous handover document. It reflects the state of the codebase at commit `8da1b67` (tag `phase-3.1B1`). Update it after each completed phase by incrementing the version and updating the "Covers through" header.*
