# Phase 3.2 — Service Charges & Night Audit Foundation
## Architecture Design Document

**Date:** 2026-07-02  
**Status:** Architecture — Pending Review  
**Author:** Claude Code / Tien Dat Doan  
**Prerequisite commits:** Phase 3.1B2 (`1590674`), Phase 3.1B1 (`8da1b67`), Phase 3.1A (`0352641`)  
**Branch target:** `phase-3`  
**Do NOT implement until this document is approved.**

---

## Table of Contents

1. [Goals](#1-goals)
2. [Scope](#2-scope)
3. [Out of Scope](#3-out-of-scope)
4. [Domain Model](#4-domain-model)
5. [Database Design](#5-database-design)
6. [Entity Relationships](#6-entity-relationships)
7. [Service Charge Lifecycle](#7-service-charge-lifecycle)
8. [Posting Rules](#8-posting-rules)
9. [Posting Keys](#9-posting-keys)
10. [Charge Categories](#10-charge-categories)
11. [Tax Strategy](#11-tax-strategy)
12. [Service Pricing Strategy](#12-service-pricing-strategy)
13. [Room-Linked vs Booking-Linked Charges](#13-room-linked-vs-booking-linked-charges)
14. [Manual vs Automatic Charges](#14-manual-vs-automatic-charges)
15. [Night Audit Prerequisites](#15-night-audit-prerequisites)
16. [Audit Invariants](#16-audit-invariants)
17. [Idempotency Rules](#17-idempotency-rules)
18. [Concurrency Considerations](#18-concurrency-considerations)
19. [Transaction Boundaries](#19-transaction-boundaries)
20. [Permission Model](#20-permission-model)
21. [Validation Rules](#21-validation-rules)
22. [ADRs](#22-adrs)
23. [API Design](#23-api-design)
24. [UI Impacts](#24-ui-impacts)
25. [Migration Strategy](#25-migration-strategy)
26. [Rollback Strategy](#26-rollback-strategy)
27. [Risk Assessment](#27-risk-assessment)
28. [Regression Analysis](#28-regression-analysis)
29. [Test Plan](#29-test-plan)

---

## 1. Goals

Phase 3.2 has three primary goals:

### G1 — Service Rate Catalog
Introduce an admin-configurable catalog of services with default unit prices, eliminating reliance on staff memory and ensuring pricing consistency across the hotel.

### G2 — Per-Night Room Charge Posting
Replace the current aggregate room-charge-at-check-in model with per-night entries (one `FolioEntry` per room per night) to support per-night revenue recognition, multi-night variance, and the Night Audit workflow.

### G3 — Night Audit Foundation
Establish the Night Audit as a daily, idempotent, logged process that posts nightly room charges for all in-house guests, validates folio integrity, and produces an audit report. This is the foundation for future automated end-of-day procedures.

**Secondary goals:**
- Add per-stay attribution (`stay_id`) to `folio_entries` to enable future per-room split billing.
- Introduce the quick-charge picker UI (staff selects from catalog instead of typing from memory).
- Enable late-checkout and early-check-in auto-fee posting triggered from the Service Rate Catalog.
- Remove the transition guard in `calculateGuardedFolioTotal()` once per-night posting is stable.

---

## 2. Scope

| Sub-Phase | Deliverable |
|-----------|-------------|
| **3.2.1** | Service Rate Catalog: `service_rates` table, admin CRUD, `ServiceRate` model, `ServiceRatePolicy` |
| **3.2.2** | Per-Stay Attribution: `stay_id` FK on `folio_entries`, `FolioEntry` relationship, payload exposure |
| **3.2.3** | Per-Night Room Charge: replace aggregate posting with per-stay per-night entries; new posting key format; update idempotency guard |
| **3.2.4** | Night Audit Service: `night_audit_runs` table, `NightAuditService`, Artisan command, console schedule |
| **3.2.5** | Late/Early Fee Auto-Posting: triggered at checkout/check-in via `StayService`, uses Service Rate Catalog |
| **3.2.6** | Quick-Charge UI: catalog picker in `AddChargeForm.vue`, per-stay room dropdown |
| **3.2.7** | Transition Guard Removal: backfill command, guard removal from `calculateGuardedFolioTotal()`, test updates |

---

## 3. Out of Scope

| Topic | Reason |
|-------|---------|
| Split billing (separate folio per stay) | Phase 3.4+ — `stay_id` lays the groundwork only |
| Full tax engine (per-service VAT rates, tax exemptions) | Phase 3.5 — Phase 3.2 defines the tax strategy, not the engine |
| Invoice generation / printing | Phase 3.6 |
| Night Audit reporting UI | Phase 3.2 produces a log record; a UI dashboard for audit history is Phase 3.3 |
| Policy pricing tiers (e.g., graduated late checkout: free < 1hr, 50% 1–3hr) | Phase 3.4 — Phase 3.2 supports one flat rate per ChargeType |
| POS integration (restaurant, spa external systems) | Not planned |
| Multi-currency posting | Currently VND-only; `currency_code` column is present on `folios` but multi-currency posting is deferred |
| Discounts and promotional pricing | Phase 3.4 |
| Room upgrades and downgrades mid-stay | Phase 3.4 |

---

## 4. Domain Model

### 4.1 Existing Entities (unchanged structure, extended relationships)

```
Booking ──< BookingRequirement (room_type_id, room_price, quantity)
        ──< RoomAssignment ──< Stay
        ──1  Folio ──< FolioEntry
        ──< BookingPayment
```

### 4.2 New Entities

#### ServiceRate
The catalog of available services with default pricing. Each entry represents one specific service (e.g., "Bữa sáng buffet"), not a category (that is `ChargeType`). Many `ServiceRate` records can share the same `ChargeType`.

```
ServiceRate
  id              BIGINT PK
  name            VARCHAR(100)   — "Bữa sáng buffet", "Bia Heineken"
  charge_type     VARCHAR(40)    — FK value from ChargeType enum
  unit_price      DECIMAL(12,2)  — default price; may be overridden at posting time
  unit_label      VARCHAR(30)    — "người", "giờ", "đêm", "cái", "chai" (display only)
  is_active       BOOLEAN        — soft-disable; does not affect existing folio entries
  display_order   INT            — sort order in picker UI
  created_by      FK → users     — nullable, SET NULL on delete
  timestamps
```

**Key invariants:**
- `unit_price` is a default, not binding. Staff may override when posting.
- Soft-disable (`is_active = false`) preserves the record for historical reference.
- No hard delete — historical folio entries do not reference `service_rate_id`, so deletion would only affect UI lookup.
- At most one `is_active = true` record per `charge_type` of `LATE_CHECKOUT` and `EARLY_CHECKIN` (enforced by application logic, not DB constraint, for simplicity).

#### NightAuditRun
Records each execution of the Night Audit, whether manual or scheduled.

```
NightAuditRun
  id              BIGINT PK
  audit_date      DATE           — the calendar date being audited (e.g., 2026-07-01)
  status          ENUM           — 'PENDING', 'RUNNING', 'COMPLETED', 'FAILED'
  started_at      TIMESTAMP NULL
  completed_at    TIMESTAMP NULL
  run_by          FK → users     — nullable (NULL = scheduled/system)
  entries_posted  INT            — count of FolioEntry records created
  errors_count    INT            — count of bookings that could not be audited
  notes           TEXT NULL      — error details or override notes
  timestamps
```

**Key invariants:**
- Exactly one `NightAuditRun` per `audit_date`. Attempting to create a second for the same date fails at the application layer.
- A COMPLETED run for a given `audit_date` cannot be re-run. Only FAILED or PENDING runs can be retried.
- `audit_date` is always the hotel's current date as of the run start, not the next day.

---

## 5. Database Design

### 5.1 New Tables

#### `service_rates`

```sql
CREATE TABLE service_rates (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(100)  NOT NULL,
    charge_type    VARCHAR(40)   NOT NULL,
    unit_price     DECIMAL(12,2) NOT NULL,
    unit_label     VARCHAR(30)   NOT NULL DEFAULT 'lần',
    is_active      TINYINT(1)    NOT NULL DEFAULT 1,
    display_order  INT           NOT NULL DEFAULT 0,
    created_by     BIGINT UNSIGNED NULL,
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,

    INDEX idx_service_rates_charge_type (charge_type),
    INDEX idx_service_rates_active_order (is_active, display_order),

    CONSTRAINT fk_service_rates_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**No unique constraint** on `(charge_type, is_active)` — multiple active records for the same type is valid (e.g., multiple minibar items). Only `LATE_CHECKOUT` and `EARLY_CHECKIN` types enforce single-active via application logic.

#### `night_audit_runs`

```sql
CREATE TABLE night_audit_runs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audit_date      DATE          NOT NULL,
    status          VARCHAR(20)   NOT NULL DEFAULT 'PENDING',
    started_at      TIMESTAMP     NULL,
    completed_at    TIMESTAMP     NULL,
    run_by          BIGINT UNSIGNED NULL,
    entries_posted  INT           NOT NULL DEFAULT 0,
    errors_count    INT           NOT NULL DEFAULT 0,
    notes           TEXT          NULL,
    created_at      TIMESTAMP     NULL,
    updated_at      TIMESTAMP     NULL,

    UNIQUE KEY uq_night_audit_runs_date (audit_date),

    CONSTRAINT fk_night_audit_runs_run_by
        FOREIGN KEY (run_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.2 Modified Tables

#### `folio_entries` — add `stay_id`

```sql
ALTER TABLE folio_entries
    ADD COLUMN stay_id BIGINT UNSIGNED NULL
        AFTER folio_id,
    ADD CONSTRAINT fk_folio_entries_stay_id
        FOREIGN KEY (stay_id) REFERENCES stays(id) ON DELETE SET NULL,
    ADD INDEX idx_folio_entries_stay_id (stay_id),
    ADD INDEX idx_folio_entries_stay_charge (stay_id, charge_type, voided_at);
```

`ON DELETE SET NULL` — if a Stay is deleted (guarded by policy, but defensible), the charge remains on the folio unattributed. Financial integrity is preserved; attribution is lost.

**Important:** `posting_key` remains `UNIQUE` globally on the `folio_entries` table. The new per-night posting key format (`ROOM_NIGHT_{stay_id}_{YYYY-MM-DD}`) guarantees uniqueness across all bookings and dates without needing a composite index.

---

## 6. Entity Relationships

```
Booking ─────────────────────────────────────────────┐
    │                                                 │
    ├──< BookingRequirement (room_type, price, qty)   │
    │                                                 │
    ├──< RoomAssignment ──< Stay ──────────────────── │──< FolioEntry (stay_id nullable)
    │                         │                       │
    │                         └── room_id             │
    │                                                 │
    └──1 Folio ──────────────────────────────────────>│──< FolioEntry (folio_id)
              │
              └── folio_number (unique, FLO-YYYYMMDD-NNNNNN)


FolioEntry
    ├── folio_id        FK → folios (NOT NULL)
    ├── stay_id         FK → stays  (NULL = booking-level charge)
    ├── posting_key     UNIQUE, NULL = manual entry, NOT NULL = system entry
    ├── charge_type     ChargeType enum value
    ├── amount          = quantity × unit_price (server-computed, bcmath)
    └── voided_at       NULL = active


ServiceRate ← referenced by UI only; no FK on folio_entries
    (unit_price at posting time is captured on the FolioEntry directly)


NightAuditRun
    └── run_by  FK → users (nullable)
    (references no FolioEntry FKs; the audit log is self-contained)
```

**Design note:** `FolioEntry` does NOT store a `service_rate_id` FK. Prices are captured at the time of posting. If a rate changes later, historical entries are unaffected. This simplifies the model and avoids orphan FK concerns.

---

## 7. Service Charge Lifecycle

```
STAGE 1 — RATE LOOKUP
  Staff/System reads active ServiceRate from catalog
  → unit_price, charge_type, description pre-filled

STAGE 2 — CHARGE CREATION
  POST /admin/bookings/{booking}/folio/entries
  → StoreFolioEntryRequest validates input
  → FolioService::addCharge() called with (folio, data + stay_id?)
  → Server computes amount = bcmul(quantity, unit_price, 2)
  → FolioEntry created with posting_key = NULL (manual entries never have a posting_key)

STAGE 3 — ACTIVE STATE
  FolioEntry.voided_at = NULL
  Included in getFolioTotal() sum
  Displayed in FolioEntryTable.vue

STAGE 4A — VOID (staff action)
  PATCH /admin/bookings/{booking}/folio/entries/{entry}
  → FolioService::voidEntry() called
  → ADR-50 guard: posting_key != NULL → reject (system entries cannot be voided)
  → Sets voided_at, voided_by, void_reason
  → Entry excluded from getFolioTotal()

STAGE 4B — FOLIO CLOSE (no more charges allowed)
  PATCH /admin/bookings/{booking}/folio/close
  → FolioService::closeFolio()
  → FolioEntryPolicy::create() returns false for CLOSED folio
  → Future charge attempts → 403

STAGE 5 — FOLIO AUTO-CLOSE (at last checkout)
  StayService::checkOut() → BookingService::finaliseBookingCheckout()
  → FolioService::autoCloseFolio()
  → Folio status = CLOSED
```

**System-generated charges follow the same lifecycle except:**
- They have a `posting_key` set at creation time (immutable).
- They cannot be voided by any user (ADR-50).
- They are created inside locked transactions (never via HTTP).

---

## 8. Posting Rules

### Rule 1 — Server-Side Amount Computation (ADR-16, unchanged)
`amount` is always computed server-side as `bcmul(quantity, unit_price, 2)`. HTTP requests that include `amount` are ignored; the value is recomputed.

### Rule 2 — Folio Must Be Open (ADR-33, unchanged)
`addCharge()` and `voidEntry()` reject operations on CLOSED or VOIDED folios. The service layer enforces this with a lock and re-read of the folio status.

### Rule 3 — Booking Must Not Be Terminal (ADR-44, unchanged)
`addCharge()` rejects posting if `booking.status.isTerminal()` returns true. Terminal states: `CHECKED_OUT`, `CANCELLED`, `NO_SHOW`.

### Rule 4 — Per-Night Room Charges Replace Aggregate (NEW — ADR-56)
`autoPostRoomCharge()` is deprecated. Instead:
- **At check-in**: `doPostFirstNightCharge(Stay)` posts one entry for the first night only, with posting key `ROOM_NIGHT_{stay_id}_{checkin_date}`.
- **Each subsequent night**: Night Audit posts `ROOM_NIGHT_{stay_id}_{audit_date}` for all in-house stays.
- The aggregate key `ROOM_CHARGE_{booking_id}_AGGREGATE` is no longer created for new bookings post-Phase-3.2. Existing entries with the aggregate key remain valid until voided or the folio closes.

### Rule 5 — Night Audit Date Window (NEW — ADR-57)
Night Audit for `audit_date` D posts charges for all stays where:
- `status = CHECKED_IN` as of D 23:59:59 hotel time
- `planned_checkout_at > D` (not checking out on D; that's handled by check-in posting of the first night)
- `actual_checkout_at IS NULL` (not yet checked out)
- No `ROOM_NIGHT_{stay_id}_{D}` entry exists for this stay (idempotency)

**Night Audit does NOT post a charge for the checkout date.** The checkout-night charge is posted at check-in of the stay (first night) for single-night bookings, or during the prior night's audit for multi-night bookings.

Wait — this needs more careful thought. See ADR-58 for the canonical night billing boundary.

### Rule 6 — Negative Amounts (Discounts/Credits)
`amount` may be negative. A FolioEntry with `amount < 0` represents a credit or discount. No separate `credit` concept — negative amounts are first-class entries.

### Rule 7 — No Hard Delete
FolioEntry records are never hard-deleted. Void with reason is the only removal mechanism.

---

## 9. Posting Keys

Posting keys serve two purposes: (1) idempotency — prevent duplicate system charges from concurrent calls; (2) identity — allow the system to check if a specific charge has already been posted.

### Key Namespace Registry

| Key Pattern | Posted By | Meaning |
|-------------|-----------|---------|
| `ROOM_CHARGE_{booking_id}_AGGREGATE` | Phase 3.1A (legacy) | One-time aggregate room charge at first check-in |
| `ROOM_NIGHT_{stay_id}_{YYYY-MM-DD}` | Phase 3.2 | Per-stay per-night room charge |
| `LATE_CHECKOUT_{stay_id}` | Phase 3.2 | Auto-posted late checkout fee |
| `EARLY_CHECKIN_{stay_id}` | Phase 3.2 | Auto-posted early check-in fee |

**Invariants:**
- A posting key is set once at entry creation and is immutable (guarded by `FolioService::voidEntry()` ADR-50 check).
- Posting keys are globally unique on `folio_entries.posting_key` (UNIQUE constraint).
- Manual staff entries (`addCharge()`) always have `posting_key = NULL`.
- The system never accepts a `posting_key` value from an HTTP request (ADR-13).

### Legacy Key Coexistence
Existing entries with `ROOM_CHARGE_{booking_id}_AGGREGATE` remain valid for all bookings created under Phase 3.1A. The Night Audit and Phase 3.2 posting code will NOT attempt to post `ROOM_NIGHT_{stay_id}_{date}` entries for stays that already have an active `ROOM_CHARGE_{booking_id}_AGGREGATE` entry — the legacy aggregate guards are still checked in `calculateGuardedFolioTotal()` until the transition guard is removed in Phase 3.2.7.

**Coexistence rule (during transition):** If a booking has an active `ROOM_CHARGE_{booking_id}_AGGREGATE` entry, it is treated as fully charged; Night Audit skips that booking. If no aggregate entry exists, the per-night model applies. This prevents double-charging during the transition window.

---

## 10. Charge Categories

`ChargeType` enum (current — no new values required for Phase 3.2):

| Value | Label (VN) | Auto-posted | Manual | Night Audit |
|-------|-----------|-------------|--------|-------------|
| `ROOM` | Tiền phòng | YES (check-in, night audit) | NO (reserved for system) | YES |
| `FOOD_BEVERAGE` | Ăn uống | NO | YES | NO |
| `SPA` | Spa | NO | YES | NO |
| `LAUNDRY` | Giặt ủi | NO | YES | NO |
| `MINIBAR` | Minibar | NO | YES | NO |
| `DAMAGE` | Bồi thường | NO | YES | NO |
| `LATE_CHECKOUT` | Trả phòng muộn | YES (at checkout) | YES | NO |
| `EARLY_CHECKIN` | Nhận phòng sớm | YES (at check-in) | YES | NO |
| `TRANSPORT` | Vận chuyển | NO | YES | NO |
| `OTHER` | Khác | NO | YES | NO |

**Enforcement:** `ChargeType::Room` should not be used for manual staff entries. `StoreFolioEntryRequest` should reject `charge_type = ROOM` from HTTP requests. The `ROOM` value is reserved for system-generated charges only.

---

## 11. Tax Strategy

### Current State
No tax is currently modeled. All amounts on `folio_entries` are pre-tax.

### Phase 3.2 Strategy: Tax-Exclusive Posting (Deferred Engine)

**Decision:** Phase 3.2 does not implement a tax engine. All `FolioEntry` amounts remain tax-exclusive (pre-tax). Tax calculation and display is deferred to Phase 3.5.

**Rationale:**
- Vietnamese hotel VAT is 8–10% (currently 8% under reduced rate through 2026, reverting to 10% in 2027). The applicable rate depends on booking date, not service date — getting this right requires a tax rate calendar.
- Tax exemptions apply to foreign guests with VAT invoices — this requires guest VAT information capture (Phase 3.5).
- The folio balance formula (`balance_due = folio_total − paid_total`) is unaffected by whether amounts are pre- or post-tax as long as all entries are consistently one or the other.

**Phase 3.2 deliverable:** Add a `tax_rate` column to `service_rates` (DECIMAL 5,4, default 0.0000) as a **display-only** field. It is stored but not applied to any calculation in Phase 3.2. This ensures the data model is ready for Phase 3.5 without a breaking migration.

**UI implication:** The quick-charge picker displays `unit_price` as entered. No tax line on the folio in Phase 3.2.

### Future (Phase 3.5) — Out of Scope Here
- `folio_entries` gains a `tax_amount` column computed from `amount × tax_rate`.
- Balance formula becomes: `balance_due = (folio_total + total_tax) − paid_total`.
- A `TaxCalculationService` reads the tax rate from `service_rates` and hotel tax settings.
- VAT invoice generation uses guest details.

---

## 12. Service Pricing Strategy

### 12.1 Catalog Pricing (Default Price)
`ServiceRate.unit_price` is the hotel's configured default price for a service. It pre-fills the charge form but is **not binding**. Staff may override it per-transaction.

**Why override is allowed:** Ad-hoc pricing adjustments (VIP discounts, complimentary items, partial charges) are a daily reality in hotel operations. Forcing catalog prices without override would reduce adoption.

### 12.2 Price Capture at Posting
The price charged is captured in `FolioEntry.unit_price` at the moment of posting. If the catalog price changes tomorrow, historical entries are unaffected. There is no retroactive repricing.

### 12.3 Room Rate Pricing (Multi-Night Variance)
For per-night room charges posted by the Night Audit:
- The nightly rate is read from `booking_requirements.room_price` (the contractual rate set at booking time).
- **No rate variance per night** in Phase 3.2. The same `room_price` is posted for every night.
- Phase 3.4 may introduce date-based rate adjustments (seasonal pricing, mid-stay rate changes).

### 12.4 Matching Stay to BookingRequirement
A `Stay` is linked to a `RoomAssignment`, which has a `room_type_id`. The Night Audit matches the stay to a `BookingRequirement` by `room_type_id`.

**Matching algorithm for duplicate room types (e.g., two TWIN rooms):**

```
requirements = booking.bookingRequirements
    WHERE room_type_id = stay.roomAssignment.room_type_id
    ORDER BY id ASC

already_matched = folio_entries.where(posting_key LIKE 'ROOM_NIGHT_*')
    JOIN stays ON stay_id
    WHERE stays.room_assignment.room_type_id = same type

remaining_requirements = requirements minus already_matched (FIFO)
rate = remaining_requirements.first().room_price ?? requirements.first().room_price
```

**Known limitation:** For bookings with 2 TWIN rooms at different prices (different requirements), the FIFO match assigns the first requirement's price to the first stay that posts. This is deterministic and auditable, but not room-specific. Phase 3.4 will add direct `requirement_id` on `stays` for exact matching.

### 12.5 Late/Early Fee Pricing
Fee = `service_rates WHERE charge_type = LATE_CHECKOUT/EARLY_CHECKIN AND is_active = 1 ORDER BY id DESC LIMIT 1`.
If no active rate exists, no auto-charge is posted (silently skipped). Staff can always post manually.

---

## 13. Room-Linked vs Booking-Linked Charges

| Attribute | Room-Linked Charge | Booking-Linked Charge |
|-----------|--------------------|-----------------------|
| `stay_id` | NOT NULL — references the Stay for the specific room | NULL — applies to the booking as a whole |
| Use cases | Per-night room charge, minibar (specific room), late checkout | Deposits, food consumed at hotel restaurant (not room-specific), booking-level discounts |
| Picker UI | Shows "Phòng" dropdown (pre-selected for single-stay bookings) | "Phòng" field absent or set to "Chung" |
| Night Audit | Always room-linked | Never posted by Night Audit |
| Split billing | Required for per-room folio split (Phase 3.4) | Cannot be split — stays on the master folio |
| Reporting | Per-room revenue attribution | Booking-level only |

**Default behavior:** When a booking has exactly one active stay, the quick-charge form always pre-selects that stay (room-linked). When it has zero or multiple active stays, the "Phòng" field defaults to "Chung" (NULL) unless the staff selects one.

---

## 14. Manual vs Automatic Charges

| Attribute | Manual Charge | Automatic Charge |
|-----------|--------------|-----------------|
| Source | Staff via quick-charge form | System: check-in trigger, Night Audit, late checkout |
| `posting_key` | NULL | NOT NULL (namespace-qualified) |
| Can be voided | Yes (by authorized user) | **Never** (ADR-50) |
| `posted_by` | Auth::id() of the staff member | NULL (system) or override user ID |
| Amount validation | Server-computed but user-supplied quantity/price | Fully system-computed, no user input |
| Retry behavior | No retry needed — form resubmission would create duplicate (manual guard via UI state) | Idempotent — retrying produces no duplicate (posting_key UNIQUE) |
| Audit trail | AuditLog via Observer | NightAuditRun log record + AuditLog |

---

## 15. Night Audit Prerequisites

The Night Audit command (`php artisan audit:run-night-audit {date?}`) must validate all prerequisites before posting any entries. If any prerequisite fails, the run is aborted with `status = FAILED` and details logged in `night_audit_runs.notes`.

### Prerequisites Checked Before Run

**P1 — No duplicate run for this date**
`NightAuditRun WHERE audit_date = $date AND status IN ('COMPLETED', 'RUNNING')` must return zero records.

**P2 — Previous day audit completed (if sequential mode)**
If the hotel requires sequential nightly audits (configurable via settings), then `audit_date - 1 day` must have a COMPLETED run. This prevents gaps in per-night revenue records.
- Configurable: `setting('night_audit_require_sequential', true)`
- For initial deployment, this defaults to `false` to allow catch-up runs.

**P3 — System clock is within the valid audit window**
The night audit for date D is only valid if the current server time is between `D 22:00` and `D+1 06:00` (configurable window). Outside this window, the command requires `--force` to proceed.
- Configurable: `setting('night_audit_window_start_hour', 22)` and `setting('night_audit_window_end_hour', 6)`.

**P4 — No RUNNING audit in progress**
`NightAuditRun WHERE status = 'RUNNING'` must return zero records. Prevents two concurrent Night Audit processes.

**Note on P3 and P4:** The Artisan command can be run manually with `--force --date=YYYY-MM-DD` to bypass P2 and P3 for catch-up scenarios. P1 and P4 are never bypassed.

---

## 16. Audit Invariants

These invariants must hold after every Night Audit run:

**I1 — Completeness:** Every in-house stay (status = `CHECKED_IN`, `actual_checkout_at IS NULL`) that was in-house on `audit_date` has exactly one active `ROOM_NIGHT_{stay_id}_{audit_date}` entry.

**I2 — No Duplication:** No `FolioEntry` with `posting_key = 'ROOM_NIGHT_{stay_id}_{audit_date}'` exists more than once (enforced by UNIQUE constraint).

**I3 — Amount Integrity:** Each night entry's `amount = quantity × unit_price` computed by `bcmul`. No externally supplied amounts.

**I4 — Voided Folio Safety:** Night Audit never posts to a VOIDED or CLOSED folio. If a stay's folio is CLOSED or VOIDED (edge case: manual admin close before audit runs), the stay is skipped and counted in `errors_count`.

**I5 — Transaction Atomicity:** Each stay's night charge is posted in its own transaction. A failure on one stay does not block other stays. The total count of successes and failures is recorded in `NightAuditRun`.

**I6 — Audit Date Immutability:** The `audit_date` on a `NightAuditRun` record cannot be changed after creation.

**I7 — Balance Unaffected by Run Failure:** If Night Audit posts 10 of 12 in-house stays before a failure, the 2 failed stays have no night charge for that date. `calculateGuardedFolioTotal()` falls back to the requirements estimate for those bookings (if the transition guard is still active) or shows an undercount (if guard has been removed — hence the transition guard removal is deferred until Night Audit is stable).

---

## 17. Idempotency Rules

### 17.1 Per-Night Room Charge Idempotency

Before posting `ROOM_NIGHT_{stay_id}_{date}`:

```php
$exists = FolioEntry::where('posting_key', "ROOM_NIGHT_{$stay->id}_{$date}")
    ->lockForUpdate()
    ->exists();

if ($exists) {
    return null; // already posted — idempotent no-op
}
```

The `lockForUpdate()` is critical: without it, two concurrent Night Audit processes (e.g., scheduled + manual) could both read `exists = false` before either has committed, resulting in a constraint violation at insert time. The UNIQUE constraint on `posting_key` is the last-resort guard; the `lockForUpdate()` prevents the violation from occurring.

### 17.2 Late Checkout Idempotency

```php
$exists = FolioEntry::where('posting_key', "LATE_CHECKOUT_{$stay->id}")
    ->exists();
```

This is checked inside the same transaction as the checkout update. If `checkOut()` is called twice (retry), the second call is rejected at the `actual_checkout_at !== null` validation check in `StayService` before reaching the late checkout posting.

### 17.3 Night Audit Run Idempotency

The `night_audit_runs.audit_date` UNIQUE constraint prevents a second `NightAuditRun` record for the same date. If a FAILED run is retried, the application updates the existing record's status to `RUNNING` (rather than inserting a new one), then proceeds.

### 17.4 Check-In First-Night Idempotency

```php
// posting_key = "ROOM_NIGHT_{stay_id}_{checkin_date}"
$exists = FolioEntry::where('posting_key', "ROOM_NIGHT_{$stay->id}_{$checkinDate}")
    ->lockForUpdate()
    ->exists();
```

Same pattern as night audit. Double check-in is already guarded by `actual_checkin_at !== null` validation, providing a secondary defense.

---

## 18. Concurrency Considerations

### 18.1 Canonical Lock Order (ADR-12, extended)

The existing lock order is:

```
Booking → Folio → FolioEntry
Booking → Stay → RoomAssignment
```

Phase 3.2 adds no new entities to the lock order. The Night Audit acquires locks in the following sequence per stay:

```
Booking (lockForUpdate) → Folio (lockForUpdate) → FolioEntry (lockForUpdate on posting_key check)
```

The Night Audit never acquires a Stay lock (it reads Stay status without updating the Stay record). This is safe because Stay status transitions (`checkIn`, `checkOut`) acquire a Booking lock first — a Night Audit transaction holding the Booking lock blocks any concurrent `checkOut()` call for that booking, and vice versa.

### 18.2 Night Audit vs Checkout Race

**Scenario:** Night Audit is running for date D. A concurrent `checkOut()` call arrives for a stay that Night Audit is about to post a charge for.

**Resolution:**
1. Night Audit acquires `Booking lockForUpdate`.
2. `checkOut()` tries to acquire `Booking lockForUpdate` → waits.
3. Night Audit posts `ROOM_NIGHT_{stay_id}_{D}`, releases Booking lock.
4. `checkOut()` acquires Booking lock, proceeds.

The checkout proceeds correctly with the night charge already posted. No duplicate charge. The `autoCloseFolio()` at checkout will close the folio with the night charge included.

**Inverse scenario:** `checkOut()` completes first, setting `actual_checkout_at`. Night Audit then reads the stay — `actual_checkout_at IS NOT NULL` → Night Audit skips this stay (it is no longer in-house). Correct.

### 18.3 Concurrent Manual Charge + Night Audit

A staff member adding a manual service charge while Night Audit is running:
- Both acquire the Folio lock.
- They serialize naturally (lockForUpdate).
- No conflict: manual charge creates a `posting_key = NULL` entry; Night Audit creates `posting_key = 'ROOM_NIGHT_...'`.

### 18.4 MySQL InnoDB Gap Locks

The `lockForUpdate` on `FolioEntry WHERE posting_key = '...'` acquires a gap lock if the row does not yet exist (SELECT ... FOR UPDATE on a missing unique key). This prevents a phantom insert between the check and the insert. No additional handling is required in InnoDB.

### 18.5 Night Audit Parallelism

The Night Audit processes one stay at a time (no fan-out to queues in Phase 3.2). This is intentional:
- A hotel with 50 rooms has 50 or fewer in-house stays per night.
- 50 sequential transactions × ~50ms each = ~2.5 seconds total. Acceptable.
- Parallel processing is deferred to Phase 3.6 when the hotel grows beyond 100 rooms.

---

## 19. Transaction Boundaries

### 19.1 Check-In Transaction (modified from Phase 3.1B1)

```
BEGIN TRANSACTION
  LOCK Booking FOR UPDATE
  LOCK Stay FOR UPDATE
  LOCK RoomAssignment FOR UPDATE
  
  Validate: assignment.status = ASSIGNED
  Validate: stay.actual_checkin_at IS NULL
  
  UPDATE stays SET status = CHECKED_IN, actual_checkin_at = now()
  UPDATE room_assignments SET status = CHECKED_IN
  
  -- Phase 3.2: post first-night charge instead of aggregate
  LOCK Folio FOR UPDATE
  CHECK: ROOM_NIGHT_{stay_id}_{today} not exists (lock FolioEntry if needed)
  IF not exists:
    INSERT folio_entries (posting_key = 'ROOM_NIGHT_{stay_id}_{today}', ...)
  
  -- Late/early fee (if applicable)
  IF actual_checkin_at < planned_checkin_at - grace:
    CHECK: EARLY_CHECKIN_{stay_id} not exists
    INSERT folio_entries (posting_key = 'EARLY_CHECKIN_{stay_id}', ...)
  
  UPDATE bookings (status recalculation)
COMMIT
```

**Failure modes:**
- Folio is CLOSED or VOIDED at the time of first-night posting → transaction aborts → check-in fails with an error surfaced via flash.
- `posting_key` UNIQUE violation (duplicate concurrent call) → transaction aborts → no net effect (idempotency holds at DB level).

### 19.2 Checkout Transaction (unchanged structure, adds late-fee posting)

```
BEGIN TRANSACTION
  LOCK Booking FOR UPDATE
  LOCK Stay FOR UPDATE
  LOCK RoomAssignment FOR UPDATE
  
  Validate: assignment.status = CHECKED_IN
  Validate: stay.actual_checkout_at IS NULL
  
  UPDATE stays SET status = CHECKED_OUT, actual_checkout_at = now()
  UPDATE room_assignments SET status = CHECKED_OUT
  
  -- Phase 3.2: late checkout fee (if applicable)
  IF actual_checkout_at > planned_checkout_at + grace_minutes:
    LOCK Folio FOR UPDATE
    CHECK: LATE_CHECKOUT_{stay_id} not exists
    IF not exists:
      INSERT folio_entries (posting_key = 'LATE_CHECKOUT_{stay_id}', ...)
  
  IF no more active stays:
    finaliseBookingCheckout()  → OBE check → auto-close folio
  ELSE:
    updateBookingStayStatus()
COMMIT
```

### 19.3 Night Audit Transaction (per stay)

```
FOR EACH in-house stay on audit_date:

  BEGIN TRANSACTION
    LOCK Booking FOR UPDATE
    
    IF booking.folio is NULL or folio.status != OPEN:
      ROLLBACK
      errors_count++
      CONTINUE
    
    LOCK Folio FOR UPDATE
    CHECK: ROOM_NIGHT_{stay_id}_{audit_date} not exists (lockForUpdate)
    
    IF exists:
      COMMIT (no-op, idempotent)
      CONTINUE
    
    INSERT folio_entries (
      posting_key = 'ROOM_NIGHT_{stay_id}_{audit_date}',
      stay_id = stay.id,
      charge_type = ROOM,
      description = 'Tiền phòng {room_number} - {audit_date}',
      quantity = 1,
      unit_price = requirement.room_price,
      amount = requirement.room_price,
      entry_date = audit_date,
      posted_by = NULL
    )
    entries_posted++
  COMMIT

UPDATE night_audit_runs SET status = COMPLETED, completed_at = now(), ...
```

### 19.4 AddCharge Transaction (unchanged from Phase 3.1B1)

Single transaction: LOCK Folio → validate status + booking terminal → INSERT FolioEntry. No changes in Phase 3.2 except `stay_id` is now an optional field in the data array.

---

## 20. Permission Model

### 20.1 Existing Permissions (unchanged)

| Permission | ADMIN | MANAGER | RECEPTION | ACCOUNTANT | SALES | HOUSEKEEPING |
|-----------|-------|---------|-----------|------------|-------|--------------|
| `folio.view` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| `folio.close` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `charge.create` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `charge.void` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |

### 20.2 New Permissions (Phase 3.2)

| Permission | ADMIN | MANAGER | RECEPTION | ACCOUNTANT | SALES | HOUSEKEEPING |
|-----------|-------|---------|-----------|------------|-------|--------------|
| `service_rates.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `night_audit.run` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `night_audit.view` | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |

### 20.3 System Charges and Permission Bypass

Night Audit posts charges as a system action. It does not go through the HTTP permission layer. The `NightAuditService` must never be invoked from a route without `night_audit.run` authorization.

The Artisan command bypasses HTTP authentication by design. If the command is run manually, the user running it must have ADMIN or MANAGER role (checked via `Auth::id()` or a `--user-id` flag).

### 20.4 ServiceRatePolicy

```php
class ServiceRatePolicy
{
    public function viewAny(User $user): bool {
        return $user->can('service_rates.manage');
    }
    public function create(User $user): bool {
        return $user->can('service_rates.manage');
    }
    public function update(User $user, ServiceRate $rate): bool {
        return $user->can('service_rates.manage');
    }
    public function delete(User $user, ServiceRate $rate): bool {
        // Soft-disable only — no hard delete allowed via UI
        return false;
    }
    public function toggleActive(User $user, ServiceRate $rate): bool {
        return $user->can('service_rates.manage');
    }
}
```

### 20.5 ChargeType::Room Reserved for System

`StoreFolioEntryRequest` must reject `charge_type = 'ROOM'` from HTTP requests. Only the system (Night Audit, check-in trigger) may post ROOM charges. Error message: "Tiền phòng được tính tự động bởi hệ thống, không thể thêm thủ công."

---

## 21. Validation Rules

### 21.1 StoreFolioEntryRequest (modified)

```php
[
    'charge_type' => ['required', Rule::enum(ChargeType::class), Rule::notIn([ChargeType::Room->value])],
    'description' => ['required', 'string', 'max:255'],
    'quantity'    => ['required', 'numeric', 'min:0.01', 'max:9999.99'],
    'unit_price'  => ['required', 'numeric', 'min:0', 'max:99999999.99'],
    'entry_date'  => ['required', 'date', 'before_or_equal:today'],
    'stay_id'     => ['nullable', 'integer', Rule::exists('stays', 'id')->where('booking_id', $this->booking->id)],
]
```

**Note on `stay_id` validation:** The `exists` rule scopes to `booking_id = $booking->id`. This prevents IDOR — staff cannot attribute a charge on Booking A to a Stay from Booking B.

### 21.2 StoreServiceRateRequest

```php
[
    'name'          => ['required', 'string', 'max:100'],
    'charge_type'   => ['required', Rule::enum(ChargeType::class)],
    'unit_price'    => ['required', 'numeric', 'min:0', 'max:99999999.99'],
    'unit_label'    => ['required', 'string', 'max:30'],
    'tax_rate'      => ['nullable', 'numeric', 'min:0', 'max:1'],
    'display_order' => ['nullable', 'integer', 'min:0'],
]
```

### 21.3 Night Audit Command Validation

Before the Artisan command posts any charges, it validates:

```php
// audit_date must be a valid calendar date
// audit_date must not be in the future (except with --force)
// No COMPLETED or RUNNING run exists for audit_date (P1, P4)
// Sequential mode: prior audit date must be COMPLETED (P2)
// Current time must be in the valid audit window (P3, unless --force)
```

### 21.4 Late/Early Fee Calculation Validation

```php
// Late checkout fee:
$minutesLate = now()->diffInMinutes($stay->planned_checkout_at, false); // negative = late
if ($minutesLate > $gracePeriod) {
    $hoursLate = (int) ceil($minutesLate / 60);
    $rate = ServiceRate::where('charge_type', ChargeType::LateCheckout->value)
                       ->where('is_active', true)
                       ->orderBy('id', 'desc')
                       ->first();
    if ($rate === null) {
        return null; // no active rate configured — skip silently
    }
    $amount = bcmul((string) $hoursLate, (string) $rate->unit_price, 2);
}
```

**Edge case:** If `planned_checkout_at` is null (no planned time set), no auto-fee is calculated. Staff must post manually if applicable.

---

## 22. ADRs

### ADR-55: ServiceRate Is a Display Default, Not a Price Contract

**Decision:** `ServiceRate.unit_price` is a display default only. Staff may override it at charge-posting time. No FK links `FolioEntry` to `ServiceRate`. The posted `unit_price` is the authoritative price captured on `FolioEntry` at the moment of posting.

**Rationale:** A rate catalog that constrains prices cannot handle one-off adjustments (VIP discounts, management overrides, error corrections). The catalog exists to reduce typing and ensure baseline consistency, not to enforce prices.

**Consequence:** If a `ServiceRate` price is updated, no retroactive repricing occurs. Historical folio entries remain unaffected.

---

### ADR-56: Per-Night Room Charges Replace Aggregate at Check-In

**Decision:** `FolioService::autoPostRoomCharge()` is deprecated. The check-in flow now calls `doPostFirstNightCharge(Stay)` which posts a single `ROOM_NIGHT_{stay_id}_{checkin_date}` entry for the first night only. Subsequent nights are posted by the Night Audit.

**Rationale:** The aggregate entry `ROOM_CHARGE_{booking_id}_AGGREGATE` was a simplification that conflated revenue recognition (when was the room used) with billing (when was the booking created). Per-night entries allow accurate daily revenue recognition, correct multi-night reporting, and proper Night Audit audit trails.

**Consequence:** Bookings with the legacy aggregate key continue to use `calculateGuardedFolioTotal()` until the transition guard is removed (Phase 3.2.7). New bookings never receive an aggregate entry.

**Migration contract:** A backfill Artisan command (`folio:backfill-per-night-charges`) converts existing aggregate entries to per-night entries for any booking that (a) is still active and (b) has an aggregate entry. This command is idempotent. The transition guard is only removed after the backfill is confirmed.

---

### ADR-57: Night Billing Boundary — Checkout Night Is the Arrival Night

**Decision:** For a stay checking in on date D and planning to check out on date D+N, the Night Audit posts entries for dates D, D+1, ..., D+(N-1). **The checkout date D+N is NOT posted.** The first-night entry for D is posted at check-in, not by Night Audit.

**Rationale:** Hotel industry convention is "nights stayed," not "calendar days occupied." A guest checking in Tuesday and out Wednesday paid for one night (Tuesday night). Posting a charge for Wednesday would double-charge.

**Formalization:**
- Check-in trigger: posts `ROOM_NIGHT_{stay_id}_{checkin_date}` = night of arrival
- Night Audit for date D: posts for all stays where `actual_checkin_at::date < D` AND `actual_checkout_at IS NULL` AND `planned_checkout_at::date > D`
- The Night Audit for D=Tuesday posts for guests who checked in Monday or earlier and are still in-house.

---

### ADR-58: Night Audit Is the Only Subsequent-Night Room Charge Mechanism

**Decision:** No route, controller, or manual process may create `ROOM_NIGHT_*` entries for dates other than the current day's first-night post (at check-in). All subsequent-night room charges must flow through `NightAuditService`.

**Rationale:** Prevents staff from manually posting room charges for arbitrary dates, which would corrupt revenue reporting and audit trails.

**Implementation:** `StoreFolioEntryRequest` rejects `charge_type = ROOM` entirely (§21.1). The `doPostFirstNightCharge` and `NightAuditService` are the only code paths that create ROOM entries. These are `private`/`internal` methods only accessible through well-defined entry points.

---

### ADR-59: Night Audit Failure Is Partial — Individual Stay Errors Do Not Abort the Run

**Decision:** If a Night Audit fails to post a charge for one stay (e.g., folio is CLOSED, calculation error), the audit continues processing remaining stays. The failed stay is counted in `night_audit_runs.errors_count` and logged in `notes`. The run completes with `status = COMPLETED` even if `errors_count > 0`.

**Rationale:** A run that aborts on the first error requires full retry and may not converge if the error is permanent (e.g., a folio that was manually closed by an admin). Partial success with a logged error list is operationally preferable.

**Consequence:** Operations staff must review `errors_count > 0` runs and manually post any missed charges.

---

### ADR-60: Transition Guard Is Removed Only After Full Per-Night Backfill

**Decision:** `FolioService::calculateGuardedFolioTotal()` retains the aggregate-key fallback until `folio:backfill-per-night-charges` has been verified on production. The guard is removed as Phase 3.2.7, the final sub-phase.

**Rationale:** Premature guard removal for a booking with an aggregate entry but no per-night entries would show `total_charges = 0`, potentially allowing checkout without payment.

**Signal to remove guard:** Zero active bookings with aggregate key and no per-night entries. Verified via: `SELECT COUNT(*) FROM folios f JOIN folio_entries fe ON fe.folio_id = f.id WHERE fe.posting_key LIKE 'ROOM_CHARGE_%_AGGREGATE' AND fe.voided_at IS NULL AND f.status = 'OPEN'` returns 0.

---

## 23. API Design

### 23.1 New Routes

```
# Service Rate Catalog
GET    /admin/service-rates                     → ServiceRateController@index
POST   /admin/service-rates                     → ServiceRateController@store
PATCH  /admin/service-rates/{rate}              → ServiceRateController@update
PATCH  /admin/service-rates/{rate}/toggle       → ServiceRateController@toggleActive

# Night Audit
GET    /admin/night-audit                       → NightAuditController@index
POST   /admin/night-audit/run                   → NightAuditController@run
GET    /admin/night-audit/{run}                 → NightAuditController@show
```

### 23.2 Modified Routes

```
# FolioEntry store — now accepts stay_id
POST   /admin/bookings/{booking}/folio/entries  (unchanged URL, extended request body)
```

### 23.3 Request/Response Shapes

#### POST `/admin/service-rates`
```json
// Request
{
  "name": "Bữa sáng buffet",
  "charge_type": "FOOD_BEVERAGE",
  "unit_price": 150000,
  "unit_label": "người",
  "tax_rate": 0,
  "display_order": 1
}

// Response (Inertia redirect with flash success)
```

#### PATCH `/admin/service-rates/{rate}/toggle`
```json
// No request body
// Response (Inertia redirect with flash success/error)
```

#### POST `/admin/night-audit/run`
```json
// Request
{
  "audit_date": "2026-07-01",   // optional; defaults to today
  "force": false
}

// Response (Inertia redirect to /admin/night-audit/{run})
// or error flash if prerequisites not met
```

#### Booking Show payload — extended (BookingController@show)

```json
// options key gains:
"options": {
  "chargeTypes": [...],     // unchanged
  "paymentTypes": [...],    // unchanged
  "paymentMethods": [...],  // unchanged
  "serviceRates": {         // NEW
    "FOOD_BEVERAGE": [
      { "id": 1, "name": "Bữa sáng buffet", "unit_price": 150000, "unit_label": "người" }
    ],
    "MINIBAR": [...]
  },
  "activeStays": [          // NEW — for "Phòng" dropdown in charge form
    { "id": 5, "room_number": "301", "room_type": "TWIN" }
  ]
}

// booking.folio.entries items gain:
{
  "id": 12,
  "stay_id": 5,             // NEW — nullable
  "stay_room_number": "301",// NEW — nullable
  "is_system_entry": true,  // existing
  // ... other existing fields
}
```

---

## 24. UI Impacts

### 24.1 New Page: Service Rate Admin

Route: `/admin/service-rates`  
File: `resources/js/Pages/Admin/ServiceRates/Index.vue`

Features:
- Grouped list by `charge_type` label
- Inline toggle active/inactive (PATCH to `/toggle`)
- Create button → slide-in form or modal
- Edit button → same form pre-filled
- No delete button (soft-disable only)
- ADMIN and MANAGER only (hidden from other roles)

Navigation: Add to the admin sidebar under "Cài đặt" section, after "Giá phòng".

### 24.2 Modified: AddChargeForm.vue

Changes:
1. **Catalog picker panel** (above the manual form): tiles of active service rates grouped by type. Clicking a tile pre-fills `charge_type`, `description`, `unit_price`.
2. **"Phòng" dropdown** (`stay_id`): visible only when `options.activeStays.length > 0`. Options: each active stay as "Phòng {room_number}" + "Chung" (null).
3. **Block `charge_type = ROOM`**: removed from the charge type dropdown options. Cannot be selected manually.

### 24.3 Modified: FolioEntryTable.vue

Changes:
1. **"Phòng" column**: new column showing `entry.stay_room_number ?? '—'`. Hidden with `v-if` when all entries have `stay_id = null`.
2. **System entry label**: unchanged behavior (`is_system_entry` flag).

### 24.4 New Page: Night Audit Dashboard

Route: `/admin/night-audit`  
File: `resources/js/Pages/Admin/NightAudit/Index.vue`

Features:
- List of recent `NightAuditRun` records with status badges
- "Chạy kiểm toán đêm" button → posts to `/admin/night-audit/run`
- Status: PENDING / RUNNING / COMPLETED / FAILED with color coding
- Detail view: entries_posted, errors_count, notes

### 24.5 Flash Notification for Auto-Fees

When a late-checkout fee or early-check-in fee is auto-posted:
- The checkout/check-in response includes a `flash.success` message:  
  `"Đã tự động tính phí trả phòng muộn 2 giờ (400,000 đ)."`
- This is already handled by `HandleInertiaRequests::share()` and AppLayout's flash bar.

---

## 25. Migration Strategy

### 25.1 Migration Order (must be run in this sequence)

```
Step 1: create_service_rates_table
Step 2: add_stay_id_to_folio_entries_table
Step 3: create_night_audit_runs_table
Step 4: add_tax_rate_to_service_rates_table   (if not included in Step 1)
```

All migrations are additive (new table or new nullable column). No existing data is modified by the migrations themselves.

### 25.2 Seeder Updates

```php
// RolePermissionSeeder: add new permissions
'service_rates.manage' → ADMIN, MANAGER
'night_audit.run'      → ADMIN, MANAGER
'night_audit.view'     → ADMIN, MANAGER, ACCOUNTANT

// ServiceRateSeeder (new): seed example rates
// Should NOT be included in production DB seeder — only in development/QA seeders
// Production rates are configured by the hotel manager
```

### 25.3 Backfill Command

`php artisan folio:backfill-per-night-charges [--dry-run] [--booking-id=]`

**Algorithm:**
1. Find all `FolioEntry` records with `posting_key LIKE 'ROOM_CHARGE_%_AGGREGATE'` that are active (`voided_at IS NULL`).
2. For each, resolve the `Booking` and all its `CheckedIn` or `CheckedOut` stays.
3. For each stay: calculate the nights it was occupied and post `ROOM_NIGHT_{stay_id}_{date}` for each night (splitting the aggregate amount proportionally or using `requirement.room_price` directly).
4. Void the aggregate entry with `void_reason = 'Chuyển đổi sang phí theo đêm - Phase 3.2'`.
5. Log each action.

**Idempotent:** Can be run multiple times. Skips stays that already have per-night entries.

**--dry-run flag:** Outputs what would be posted without making any DB changes.

**Risk:** The backfill voids the aggregate entry. If the per-night posting fails mid-booking, the aggregate entry is not voided until all nights succeed. Transaction per booking.

### 25.4 Deployment Sequence

```
1. Deploy migrations (safe — additive only)
2. Deploy application code (service_rates CRUD, night audit command, updated FolioService)
3. Seed new permissions (RolePermissionSeeder)
4. Hotel manager configures Service Rate Catalog via UI
5. Run one test Night Audit for yesterday's date (--force) to validate
6. Enable scheduled Night Audit (e.g., daily at 23:30)
7. Run backfill: php artisan folio:backfill-per-night-charges --dry-run (validate)
8. Run backfill: php artisan folio:backfill-per-night-charges (execute)
9. Verify: zero aggregate entries for open bookings
10. Remove transition guard (Phase 3.2.7)
```

Steps 7–10 are Phase 3.2.7 and can be scheduled after Steps 1–6 are stable in production.

---

## 26. Rollback Strategy

### 26.1 Rollback Triggers

Consider rollback if:
- Night Audit posts incorrect amounts for > 5% of in-house stays.
- The balance formula produces incorrect results after per-night posting.
- The `addCharge()` transaction deadlocks under real load.
- The backfill command corrupts existing folio totals.

### 26.2 Database Rollback

All migrations have `down()` methods:
- `add_stay_id_to_folio_entries_table::down()` — drop the FK and column.
- `create_service_rates_table::down()` — drop the table.
- `create_night_audit_runs_table::down()` — drop the table.

**Warning:** Running `down()` on `add_stay_id_to_folio_entries_table` after per-night charges have been posted (which populate `stay_id`) loses the stay attribution. The amounts remain; the attribution is dropped.

### 26.3 Application Rollback

Since all Phase 3.2 changes are additive (new tables, new nullable column, new endpoints), reverting the application code restores the Phase 3.1B2 behavior. The new columns contain no data that the old code reads.

**Exception:** If the transition guard is removed (Phase 3.2.7) and code is then rolled back, folios that had aggregate entries voided by the backfill will show `total_charges = 0` (the guard is gone and per-night entries are now in place, which the rolled-back code ignores). **Therefore: do not roll back Phase 3.2.7 separately — it must be rolled back as a unit with 3.2.3 (per-night posting).**

### 26.4 Night Audit Rollback

If a Night Audit run posts incorrect charges:
1. Identify the `night_audit_runs.id` for the bad run.
2. Run `php artisan folio:void-night-audit-entries --run-id={id} --reason="Sai đơn giá, chạy lại"` (new Artisan command).
3. This voids all `FolioEntry` records created by that run (identifiable by `posting_key LIKE 'ROOM_NIGHT_%_{audit_date}'` AND `entry_date = audit_date`).
4. Update `night_audit_runs.status = FAILED`.
5. Correct the rate issue.
6. Re-run the Night Audit for that date (`php artisan audit:run-night-audit {date} --force`).

**Partial rollback:** The void-audit-entries command can target individual `stay_id` values if only some entries are incorrect.

---

## 27. Risk Assessment

### R1 — Transition: Aggregate → Per-Night Double Charging
**Probability:** Medium  
**Impact:** Critical — guest charged twice  
**Scenario:** Backfill command voids aggregate entry and posts per-night entries. Night Audit then runs for dates already covered by the per-night entries.  
**Mitigation:** Night Audit checks for existing `ROOM_NIGHT_{stay_id}_{date}` entries before posting (idempotency). Backfill runs before Night Audit is enabled for active bookings.

### R2 — Night Audit Runs Against Wrong Date
**Probability:** Low  
**Impact:** High — charges posted for a date guests were not in-house  
**Scenario:** System clock misconfiguration; manual override with wrong date.  
**Mitigation:** The Artisan command validates that the date is not in the future. The audit window check (P3) limits manual invocation to a narrow time window. `--force` requires explicit flag.

### R3 — Late Checkout Fee Posted for Guest Who Checked Out On Time
**Probability:** Low  
**Impact:** High — guest dispute, reputation risk  
**Scenario:** `planned_checkout_at` is set incorrectly (e.g., 11:00 instead of 12:00). Guest checks out at 11:30, fee is auto-posted.  
**Mitigation:** Grace period (default 30 min) absorbs minor inaccuracies. Staff is notified via flash message and can void the auto-fee immediately. `LATE_CHECKOUT_{stay_id}` idempotency prevents re-posting after void.

### R4 — ROOM ChargeType Blocked from Manual Posting
**Probability:** Low  
**Impact:** Medium — staff needs to post a room correction manually  
**Scenario:** Night Audit misses a night (failure). Staff cannot correct by posting a ROOM charge.  
**Mitigation:** The Night Audit rollback and re-run procedure (§26.4) is the correct correction path. Alternatively, the admin can add a manual OTHER charge with description "Tiền phòng bù" as a workaround. Phase 3.3 may introduce an admin override for ROOM posting.

### R5 — Service Rate Price Drift During Active Form Session
**Probability:** Very Low  
**Impact:** Low — one-off incorrect charge in a specific race window  
**Scenario:** Manager updates rate price. Staff opened the charge form 30 seconds earlier. Staff submits the old price.  
**Mitigation:** The submitted `unit_price` is what the staff typed (not re-validated against the catalog at submit time — ADR-55: catalog is a default, not a contract). The posted amount reflects what staff submitted. Acceptable.

### R6 — NightAuditRun Stuck in RUNNING Status
**Probability:** Low  
**Impact:** Medium — blocks next run (P4 prerequisite)  
**Scenario:** PHP process killed mid-audit. `night_audit_runs` row remains at `RUNNING`.  
**Mitigation:** The Night Audit command sets a `started_at` timestamp. A monitoring cron checks for runs where `status = RUNNING AND started_at < now() - 30 minutes` and resets them to `FAILED`. A second monitor alert notifies operations.

### R7 — Stay-to-Requirement Matching Ambiguity (duplicate room types, different prices)
**Probability:** Medium  
**Impact:** Medium — wrong nightly rate for one room  
**Scenario:** Booking has 2 TWIN rooms: one at 800,000/night, one at 750,000/night. Night Audit assigns FIFO — the first stay that posts gets 800,000.  
**Mitigation:** For Phase 3.2, document this limitation. Log a warning in the audit notes when multiple requirements of the same type have different prices. Phase 3.4 will add `requirement_id` on `stays` for exact matching.

---

## 28. Regression Analysis

### Existing Tests That Phase 3.2 May Break

| Test | Reason | Required Action |
|------|---------|-----------------|
| `CheckoutIntegrationTest::test_checkout_transitions_booking_to_checked_out_when_balance_is_zero` | Relies on aggregate `autoPostRoomCharge()` to populate folio total | Update to use per-night posting flow |
| `CheckoutIntegrationTest::test_folio_auto_closes_at_last_stay_checkout` | Same dependency | Update |
| `CheckoutIntegrationTest::test_checkout_blocked_by_outstanding_balance` | Same | Update |
| `FolioCrudTest::test_folio_is_auto_created_when_booking_is_created` | Unaffected — auto-create on booking creation is unchanged | No change |
| `FolioCrudTest::test_admin_can_add_charge_to_open_folio` | Unaffected | No change |
| `PaymentUiTest::test_booking_show_includes_payment_summary` | `paymentSummary` shape gains new keys (`stay_id`, `stay_room_number` per entry) — assertions must be updated | Update assertions |
| `CheckoutUiTest` (all) | OBE redirect and balance checks depend on folio total correctly reflecting per-night entries | Verify; update if needed |
| Any test that asserts `posting_key LIKE 'ROOM_CHARGE_%_AGGREGATE'` | These tests are documenting Phase 3.1A behavior — they should be updated to assert per-night keys OR wrapped in a `@legacy-aggregate` group | Update |

### Existing Tests Unaffected

- `BookingEngineFoundationTest` — no folio dependency
- `PaymentCrudTest` — no charge posting dependency
- `FolioUiTest::test_non_system_entry_can_be_voided_normally` — uses `posting_key = null`
- `RoomAvailabilityCheckerTest` / `RoomBoardTest` — no folio dependency (already pre-existing failures, unrelated)

### New Tests Required

See §29.

---

## 29. Test Plan

### 29.1 Unit Tests

**`ServiceRatePolicyTest`**
- Admin can viewAny, create, update, toggleActive
- Manager can viewAny, create, update, toggleActive
- Reception cannot create, update, toggleActive
- Policy::delete returns false for all roles

**`NightAuditServiceTest` (unit — mock DB)**
- `buildInHouseStays()` returns only CHECKED_IN stays with no actual_checkout_at
- `resolveNightlyRate(Stay)` returns correct rate for FIFO requirement match
- `resolveNightlyRate(Stay)` logs warning when multiple requirements of same type differ in price
- `postNightCharge(Stay, date)` skips when posting_key already exists (idempotency)
- `postNightCharge(Stay, date)` skips when folio is CLOSED
- `postNightCharge(Stay, date)` skips when folio is VOIDED
- `calculateLateCheckoutFee(Stay)` returns correct amount for given minutes late
- `calculateLateCheckoutFee(Stay)` returns null when grace period not exceeded
- `calculateLateCheckoutFee(Stay)` returns null when no active LATE_CHECKOUT rate

### 29.2 Feature Tests

**`ServiceRateCrudTest`** (≥8 tests)
- Admin can create a service rate → `assertDatabaseHas`
- Admin can update a service rate
- Admin cannot delete a service rate (policy::delete → false)
- Manager can create, update, toggle
- Reception is rejected from create (403)
- Toggle active: `is_active` flips correctly
- Toggling inactive rate that is LATE_CHECKOUT type: second active LATE_CHECKOUT can now be toggled on (no unique constraint)
- Inactive rate is not returned in `options.serviceRates` on booking show page

**`NightAuditFeatureTest`** (≥10 tests)
- Night Audit for date D posts `ROOM_NIGHT_{stay_id}_{D}` for all in-house stays
- Night Audit skips checkout date (stay checking out on D is not charged for D)
- Night Audit skips stays whose folio is CLOSED (error logged)
- Night Audit is idempotent — second run for same date is blocked (NightAuditRun record already COMPLETED)
- Night Audit FAILED run can be retried
- Night Audit posts correct `unit_price` from booking requirement
- Night Audit creates `NightAuditRun` record with correct `entries_posted` count
- Concurrent Night Audit (two processes) — second is blocked by RUNNING status
- `check_in` no longer posts aggregate entry; posts `ROOM_NIGHT_{stay_id}_{checkin_date}` instead
- Balance is correct after check-in + one Night Audit

**`PerNightChargeTest`** (≥6 tests)
- Check-in posts `ROOM_NIGHT_{stay_id}_{today}` not `ROOM_CHARGE_{booking_id}_AGGREGATE`
- Check-in is idempotent — double check-in does not post duplicate night charge
- `stay_id` is set on per-night entry
- `posted_by` is NULL on system entries
- `posting_key` is immutable — void attempt throws SystemEntryVoidException
- Balance formula includes per-night charge in folio total

**`LateCheckoutFeeTest`** (≥5 tests)
- Checkout past planned time + active LATE_CHECKOUT rate → auto-posts `LATE_CHECKOUT_{stay_id}`
- Checkout within grace period → no fee
- No active LATE_CHECKOUT rate → no fee (silent)
- Late checkout fee amount is correct (ceiling hours × unit_price)
- Late checkout fee idempotent — retry checkout after double-click does not double-post

**`PerStayAttributionTest`** (≥4 tests)
- Manual charge with `stay_id` → `stay_id` stored on `FolioEntry`
- Manual charge with `stay_id` belonging to different booking → 422 (validation IDOR guard)
- Booking show payload includes `stay_id` and `stay_room_number` per entry
- Manual charge with `charge_type = ROOM` → 422 (system-reserved type)

**`BackfillCommandTest`** (≥3 tests)
- `--dry-run` outputs changes without writing to DB
- Bookings with aggregate entry are correctly converted to per-night entries
- Command is idempotent — running twice produces no duplicate entries

### 29.3 Coverage Target

≥ 80% on:
- `app/Services/NightAuditService.php`
- `app/Services/FolioService.php` (modified sections)
- `app/Models/ServiceRate.php`
- `app/Http/Controllers/Admin/NightAuditController.php`
- `app/Http/Controllers/Admin/ServiceRateController.php`

### 29.4 Manual QA Checklist

Before marking Phase 3.2 COMPLETE:

- [ ] Create service rate via `/admin/service-rates` → appears in booking charge picker
- [ ] Add charge using catalog tile → `charge_type`, `description`, `unit_price` pre-filled
- [ ] Add charge with `charge_type = ROOM` manually → rejected with error
- [ ] Check in a guest → folio shows per-night entry (not aggregate)
- [ ] Run Night Audit for today → in-house guests receive nightly charge
- [ ] Re-run Night Audit for same date → blocked, no duplicate charges
- [ ] Check out guest 2 hours late → late checkout fee auto-posted, flash message shown
- [ ] View Night Audit history at `/admin/night-audit` → run record with correct counts
- [ ] Void attempt on any system entry → flash error, not voided
- [ ] Multi-stay booking → "Phòng" dropdown present in charge form; charge attributed to selected room

---

## Appendix A: Posting Key Reference Table

| Posting Key | Phase Introduced | Who Creates | Voidable | Notes |
|-------------|-----------------|-------------|---------|-------|
| `ROOM_CHARGE_{booking_id}_AGGREGATE` | 3.1A (legacy) | `autoPostRoomCharge()` | Never | Deprecated in 3.2; retained for pre-3.2 bookings |
| `ROOM_NIGHT_{stay_id}_{YYYY-MM-DD}` | 3.2 | Check-in (first night) + Night Audit (subsequent) | Never | Canonical per-night format |
| `LATE_CHECKOUT_{stay_id}` | 3.2 | `StayService::checkOut()` | Never | Posted when actual > planned + grace |
| `EARLY_CHECKIN_{stay_id}` | 3.2 | `StayService::checkIn()` | Never | Posted when actual < planned - grace |

---

## Appendix B: Balance Formula Evolution

| Phase | Formula | Transition Guard |
|-------|---------|-----------------|
| Pre-3.1A | `balance = requirements_total − paid_total` | N/A |
| 3.1A | `balance = guarded_folio_total − paid_total` | YES: if no aggregate entry, fall back to requirements_total |
| 3.2 (post-backfill) | `balance = folio_total − paid_total` | REMOVED |

`folio_total = SUM(folio_entries.amount WHERE voided_at IS NULL AND folio.status != VOIDED)`

---

## Appendix C: Permission Matrix (Full — Phase 3.2)

| Permission | ADMIN | MANAGER | RECEPTION | ACCOUNTANT | SALES | HOUSEKEEPING |
|-----------|-------|---------|-----------|------------|-------|--------------|
| `folio.view` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| `folio.close` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `charge.create` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `charge.void` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `service_rates.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `night_audit.run` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `night_audit.view` | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
