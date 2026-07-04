# Phase 3 Architecture Baseline
## Lastella PMS — Official Architecture Lock Before Phase 4

---

## 1. Overview

### Purpose

This document is the official architecture baseline for Phase 3 of Lastella PMS. It locks the Phase 3 financial architecture before Phase 4 implementation begins. Phase 4 teams must read this document before proposing any extension. All Phase 4 integration points must be reviewed against this baseline.

### Scope

Phase 3 delivered the complete **financial foundation** of Lastella PMS:

| Domain | Capability |
|--------|-----------|
| Folio Ledger | Single-sided charge ledger, atomic checkout gate, idempotent posting, canonical lock order |
| Payment Operations | Refund with cap, signed Adjustment (ADMIN-only), delete with terminal guard, 9-key summary |
| Night Audit Pipeline | Extensible PostingJob interface; 7 job implementations; idempotency via posting keys |
| Financial Operations | Operations center, revenue dashboard, reconciliation report, posting timeline |
| Package Enrollment | Per-night service charges (breakfast, extra person, extra bed, city tax) with enrollment UI |
| Service Rate Catalog | Temporal rate versioning with `effective_from`; admin-configurable |

### Completion Date

**2026-07-04**

### Branch

`phase-3`

### Commit / Tag Baseline

| Milestone | Commit | Tag |
|-----------|--------|-----|
| Phase 3.1A — Payment Foundation | `0352641` | `phase-3.1A` |
| Phase 3.1B1 — Folio Backend | `8da1b67` | `phase-3.1B1` |
| Phase 3.1 — Folio UI | `1590674` | `phase-3.1` |
| Phase 3.1.1 — Hotfix | `869e2b3` | `phase-3.1.1` |
| Phase 3.1.2 — Checkout Gate | `1917bf9` | `phase-3.1.2` |
| Phase 3.2 — Night Audit Foundation | `3a06631` | *(none — included in Phase 3.3.x)* |
| Phase 3.3.4 — Reconciliation | `55fc4d5` | `phase-3.3.4` |
| Phase 3.3.5 — Posting Timeline | `2f29914` | `phase-3.3.5` |
| **Phase 3.3.6.3 — Extra Charges Backend** | **`9935423`** | **`phase-3.3.6.3`** |
| Phase 3.3.6.4 — Package Enrollment UI | `194dcc5` | `phase-3.3.6.4` |
| Phase 3.3.6.5 — Night Audit Show | `8117275` | `phase-3.3.6.5` |

**HEAD backend bundle:** `9935423` (tag: `phase-3.3.6.3`)  
**Final UI commit:** `8117275` (tag: `phase-3.3.6.5`)  
**Base master commit:** `95cd254`

### Status

```
Phase 3: COMPLETE
Backend:    PASS — committed, pushed, tagged
Frontend:   PASS — committed, pushed, tagged
Regression: PASS — 0 new failures
Build:      PASS — no build-breaking changes reported
```

---

## 2. Architecture Principles

The following principles were established and enforced across all Phase 3 development. These are non-negotiable constraints for Phase 4.

### P1 — Single-sided Folio Ledger

`folio_entries` records what the guest owes. `booking_payments` records what the guest has paid. These are two separate, one-sided ledgers. There is no double-entry accounting. Every charge posted to the folio is a debit; every payment received is tracked separately.

### P2 — Guarded Balance Calculation

`FolioService::calculateGuardedFolioTotal()` is the **only** method that computes the folio total used in balance decisions. No controller, no other service, and no Phase 4 code may compute `balance_due` by reading `folio_entries` directly. All balance decisions must route through this single method.

VOIDED folio short-circuit: when a folio status is VOIDED, `calculateGuardedFolioTotal()` returns `0` unconditionally, regardless of entry values.

### P3 — Canonical Lock Order

All multi-row DB transactions must acquire row locks in this exact order:

```
Booking → Requirements (id ASC) → Folio → FolioEntries (id ASC) → BookingPayments (id ASC)
```

Deviation from this order causes deadlock risk under concurrent requests. Phase 4 must follow this order in any new multi-row transaction that touches folio or payment tables.

### P4 — Idempotent Posting

Every Night Audit posting job writes a `posting_key` to `folio_entries`. The posting key format is:

```
{JOB_PREFIX}_{stay_id}_{YYYY-MM-DD}    (per-night jobs)
{JOB_PREFIX}_{stay_id}                  (lifecycle jobs)
```

The `posting_key` column has a UNIQUE index. `isAlreadyPosted()` checks this index before executing. A job that finds its posting key already present returns `alreadyPosted()` without re-inserting. This makes Night Audit safe to re-run.

### P5 — PostingJob Pipeline

The Night Audit pipeline is purely additive. Adding a new charge type requires only:
1. A new class implementing the `PostingJob` interface
2. A new entry in `NightAuditService::registerJobs()`
3. A new `ChargeType` enum case (if needed)
4. A service rate entry in the rate catalog

No existing PostingJob implementation needs to be modified.

### P6 — ServiceRateService Rate Resolution

No price is hardcoded in any PostingJob or service. All rates are resolved at runtime through:

```php
ServiceRateService::resolveFor(ChargeType $type, Carbon $date): ?ServiceRate
```

If this returns `null` (no active rate for the date), the job skips with `skip_reason = 'NO_ACTIVE_RATE'`. Phase 4 PostingJobs must follow the same pattern.

### P7 — Atomic Checkout Finalisation

The balance check and folio close during booking checkout happen inside a single DB transaction with all rows locked before the balance is read. No concurrent charge or payment can modify the state between the balance read and the folio close.

### P8 — Business Date Model

The current business date is not the wall-clock date. It is provided by `BusinessDateService`, which applies a hotel-configurable `business_date_cutover_hour`. Before the cutover hour, the business date is `today − 1 day`. Night Audit always uses the business date, not the wall-clock date.

### P9 — No Hardcoded Financial Rates

All financial rates — room charges, breakfast, city tax, extra person, extra bed — are stored in the `service_rates` table and resolved via `ServiceRateService`. No monetary amount is hardcoded anywhere in the application layer.

---

## 3. Financial Architecture Baseline

### 3.1 Data Flow

```
Booking
  └─ Folio (1:1, OPEN → CLOSED → VOIDED)
       └─ FolioEntry (n entries, soft-deleted via voided_at)
            ← posting_source: MANUAL | SYSTEM_AUTO | NIGHT_AUDIT
            ← posting_key: idempotency key (UNIQUE, nullable for MANUAL)
            ← stay_id: optional per-stay attribution (nullable)

BookingPayment (separate ledger, n payments per booking)
  ← payment_type: Deposit | RoomPayment | ServicePayment | Refund | Adjustment

Balance Due = Folio Total − Paid Total
```

### 3.2 Balance Formula

```
folio_total  = SUM(folio_entries.amount) WHERE folio_entries.voided_at IS NULL
paid_total   = SUM(inbound BookingPayments) + SUM(Adjustment.amount) − SUM(Refund.amount)
balance_due  = folio_total − paid_total
```

**VOIDED folio short-circuit:**
```
IF folio.status = VOIDED THEN
    folio_total     = 0
    max_refundable  = paid_total
```

### 3.3 Folio Status Machine

```
OPEN ──→ CLOSED (reversible by ADMIN: CLOSED ──→ OPEN)
OPEN ──→ VOIDED (terminal)
CLOSED ──→ VOIDED (terminal)
```

### 3.4 Night Audit Pipeline

```
NightAuditService::run(NightAuditRun)
  └─ foreach CHECKED_IN stays:
       └─ NightAuditPipeline::execute(PostingContext)
            └─ foreach registered PostingJob (in order):
                 1. RoomChargePostingJob       ← no dependency
                 2. BreakfastPostingJob         ← depends on RoomChargePostingJob
                 3. CityTaxPostingJob           ← depends on RoomChargePostingJob
                 4. ExtraPersonPostingJob       ← depends on RoomChargePostingJob
                 5. ExtraBedPostingJob          ← depends on RoomChargePostingJob
```

Lifecycle-triggered jobs (outside pipeline):
- `LateCheckoutFeePostingJob` — triggered at checkout event
- `EarlyCheckinFeePostingJob` — triggered at check-in event

Partial failure is tolerated: one booking failure does not abort the run.

### 3.5 PostingJob Implementations

| Job | Trigger | Posting Key | Charge Type | Gate Condition |
|-----|---------|-------------|-------------|----------------|
| `RoomChargePostingJob` | Night Audit | `ROOM_NIGHT_{stay_id}_{date}` | Room | Always (CHECKED_IN stay) |
| `LateCheckoutFeePostingJob` | Checkout event | `LATE_CHECKOUT_{stay_id}` | LateCheckout | Late checkout detected |
| `EarlyCheckinFeePostingJob` | Check-in event | `EARLY_CHECKIN_{stay_id}` | EarlyCheckin | Early check-in detected |
| `BreakfastPostingJob` | Night Audit | `BREAKFAST_{stay_id}_{date}` | FoodBeverage | `booking_package_flags` enrolled |
| `CityTaxPostingJob` | Night Audit | `CITY_TAX_{stay_id}_{date}` | CityTax | `hotel_settings.city_tax_enabled = true` |
| `ExtraPersonPostingJob` | Night Audit | `EXTRA_PERSON_{stay_id}_{date}` | ExtraPerson | `booking_package_flags` enrolled; qty-aware |
| `ExtraBedPostingJob` | Night Audit | `EXTRA_BED_{stay_id}_{date}` | ExtraBed | `booking_package_flags` enrolled; qty-aware |

---

## 4. Core Services Baseline

| Service | Phase | Responsibility |
|---------|-------|---------------|
| `FolioService` | 3.1B1 | Folio and entry lifecycle: add charge, void, close, reopen, auto-post room charge, guarded balance calculation |
| `BookingPaymentService` | 3.1A | Payment mutations: deposit, room payment, service payment, refund (capped), adjustment (signed, ADMIN), delete, 9-key summary |
| `NightAuditService` | 3.2 | Pipeline runner: iterate CHECKED_IN stays, execute PostingJob pipeline per stay, partial failure tolerance |
| `NightAuditPipeline` | 3.2 | Job registration, ordered execution, dependency resolution between jobs |
| `NightAuditOperationsService` | 3.3.1 | Manual trigger (with date guard), retry failed runs, per-job summary aggregation |
| `BusinessDateService` | 3.2 | Compute current business date from wall-clock time and hotel cutover setting |
| `ServiceRateService` | 3.2 | Temporal rate resolution: `resolveFor(ChargeType, Carbon)` — returns latest active rate or null |
| `HotelSettingsService` | 3.2 | Key-value configuration store: `get()`, `set()`, `getBool()` |
| `PackageEnrollmentService` | 3.3.2 | Per-night service package enrollment: enroll/unenroll with quantity support, audit guard |
| `RevenueReportService` | 3.3.3 | Revenue aggregation by date, charge type, posting source; excludes voided entries |
| `ReconciliationService` | 3.3.4 | Outstanding balance analysis, discrepancy detection, voided entries audit — read-only |
| `PostingTimelineService` | 3.3.5 | Per-booking chronological folio entry timeline with running cumulative total |
| `BookingService` | Pre-Phase 3 | Booking lifecycle: status transitions, cancellation, checkout gate |
| `StayService` | Pre-Phase 3 | Stay lifecycle: create from assignment, check-in, check-out |

---

## 5. Database Baseline

### 5.1 New Tables (Phase 3)

| Table | Introduced | Role |
|-------|-----------|------|
| `folios` | Phase 3.1B1 | One per booking; tracks folio number, status (OPEN/CLOSED/VOIDED), currency |
| `folio_entries` | Phase 3.1B1 | Itemised guest charges; soft-deleted via `voided_at`; never hard-deleted |
| `folio_number_sequences` | Phase 3.1B1 | Daily sequence counter for folio number generation (`FLO-YYYYMMDD-######`) |
| `hotel_settings` | Phase 3.2 | Key-value configuration store for hotel-level operational settings |
| `service_rates` | Phase 3.2 | Temporal rate catalog; one record per `(charge_type, effective_from)` pair |
| `night_audit_runs` | Phase 3.2 | One record per audit date; tracks run status and aggregate counts |
| `night_audit_booking_logs` | Phase 3.2 | Per-booking, per-job execution log within a night audit run |
| `booking_package_flags` | Phase 3.3.2 | Per-night service enrollment flags; stores enrolled packages and quantities |

### 5.2 Key Columns Added to Existing Tables

| Column | Table | Introduced | Purpose |
|--------|-------|-----------|---------|
| `posting_key` | `folio_entries` | Phase 3.2 | Idempotency key; UNIQUE index; nullable for MANUAL entries |
| `stay_id` | `folio_entries` | Phase 3.2 | Optional per-stay attribution; FK to `stays.id`; nullable |
| `posting_source` | `folio_entries` | Phase 3.2 | Origin of entry: `MANUAL`, `SYSTEM_AUTO`, or `NIGHT_AUDIT` |
| `currency_code` | `folios` | Phase 3.2 | ISO 4217 currency; defaults to `VND` |

### 5.3 Key Constraints

| Table | Constraint | Type |
|-------|-----------|------|
| `folio_entries` | `posting_key` | UNIQUE (sparse — NULL values excluded) |
| `booking_package_flags` | `(booking_id, package_key)` | UNIQUE — one enrollment per package type per booking |
| `night_audit_runs` | `audit_date` | UNIQUE — one run per business date |
| `folios` | `booking_id` | UNIQUE — one folio per booking |
| `folios` | `booking_id` FK | RESTRICT — cannot delete booking with active folio |
| `booking_payments` | `booking_id` FK | CASCADE — **known tech debt**, should be RESTRICT |

---

## 6. Permission Baseline

Permissions are managed via Spatie Laravel Permission. Seeded in `database/seeders/RolePermissionSeeder.php`.

| Permission | ADMIN | MANAGER | RECEPTION | ACCOUNTANT | HOUSEKEEPING |
|-----------|:-----:|:-------:|:---------:|:----------:|:------------:|
| `folio.view` | ✅ | ✅ | ✅ | ✅ | — |
| `folio.charge` | ✅ | ✅ | ✅ | — | — |
| `folio.void` | ✅ | ✅ (today only) | — | — | — |
| `folio.close` | ✅ | ✅ | — | — | — |
| `folio.reopen` | ✅ | — | — | — | — |
| `payment.create` | ✅ | ✅ | ✅ | — | — |
| `payment.refund` | ✅ | ✅ | — | ✅ | — |
| `payment.adjust` | ✅ | — | — | — | — |
| `payment.delete` | ✅ | ✅ (today, non-terminal) | — | — | — |
| `night_audit.trigger` | ✅ | ✅ | — | — | — |
| `night_audit.retry` | ✅ | ✅ | — | — | — |
| `night_audit.view` | ✅ | ✅ | — | ✅ | — |
| `revenue.view` | ✅ | ✅ | — | ✅ | — |
| `reconciliation.view` | ✅ | ✅ | — | ✅ | — |
| `package.manage` | ✅ | ✅ | — | — | — |
| `service_rate.manage` | ✅ | ✅ | — | — | — |
| `hotel_settings.manage` | ✅ | — | — | — | — |

**HOUSEKEEPING — Phase 4 prerequisite note:** The HOUSEKEEPING role exists in `RolePermissionSeeder` and currently holds no financial permissions. Phase 4.1 (Room Setup Requests) will assign `special_request.fulfill` to this role. No Phase 3 permission conflicts exist.

---

## 7. Public Integration Points for Phase 4

These are the **approved** entry points where Phase 4 code may integrate with Phase 3 services. Phase 4 must not access Phase 3 internals outside these points without an Architecture Review.

### 7.1 `StayService::createStayFromAssignment()`

**File:** `app/Services/StayService.php` (line 32)  
**Purpose:** Called when a room assignment creates a new Stay record.  
**Phase 4 use:** Auto-link per-stay data (e.g., `stay_id` on pending special requests) after Stay is persisted.  
**Contract:** Add a service call after the Stay model is saved. Do not modify the method signature.

---

### 7.2 `BookingService::cancelBooking()`

**File:** `app/Services/BookingService.php` (line 344)  
**Purpose:** Called when a booking transitions to CANCELLED status.  
**Phase 4 use:** Auto-cancel associated records (e.g., pending/acknowledged special requests) at cancellation time.  
**Contract:** Add a service call before the method returns. Do not alter the booking status transition logic.

---

### 7.3 `FolioService::calculateGuardedFolioTotal(Booking)`

**File:** `app/Services/FolioService.php`  
**Purpose:** The single authoritative method for computing folio total in balance decisions.  
**Phase 4 use:** Read-only. Any Phase 4 feature that needs `balance_due` must call this method — it must not re-query `folio_entries` directly.  
**Contract:** Do not override, wrap, or bypass this method. VOIDED folio always returns 0.

---

### 7.4 `NightAuditPipeline::register(array $jobs)`

**File:** `app/Services/NightAuditPipeline.php`  
**Purpose:** Registers an ordered list of PostingJob implementations for pipeline execution.  
**Phase 4 use:** Adding a new PostingJob to the Night Audit pipeline requires adding its class to `NightAuditService::registerJobs()`, which calls `register()`.  
**Contract:** New jobs must implement the `PostingJob` interface fully. They must define a unique `posting_key` prefix. Registration order determines execution order.

---

### 7.5 `PackageEnrollmentService::enroll(Booking, string $packageKey, int $qty)`

**File:** `app/Services/PackageEnrollmentService.php`  
**Purpose:** Enrolls a booking in a per-night service package.  
**Phase 4 use:** If Phase 4 introduces new package types, add the new `package_key` constant to `ALLOWED_PACKAGES` in `PackageEnrollmentService` and call `enroll()`.  
**Contract:** New package keys must be added to `ALLOWED_PACKAGES`. The audit guard (unenroll blocked if already posted today) applies to all package types.

---

### 7.6 `ServiceRateService::resolveFor(ChargeType $type, Carbon $date): ?ServiceRate`

**File:** `app/Services/ServiceRateService.php`  
**Purpose:** Resolves the active rate for a charge type on a given date via temporal lookup.  
**Phase 4 use:** Any new PostingJob must call this method to retrieve its posting amount. Null return means no active rate → job must skip with `SKIPPED / NO_ACTIVE_RATE`.  
**Contract:** Do not hardcode amounts in PostingJob. Do not bypass this method. Always handle the null case.

---

## 8. ADR Baseline

Phase 3 formalised approximately **79 ADRs** across 6 decision rounds. The canonical references are:
- `docs/roadmaps/phase-3.1-folio-ledger-foundation.md` (ADR-1 to ADR-54)
- `docs/roadmaps/phase-3.3-financial-operations.md` (ADR-55 to ~ADR-79)

| Group | ADR Range | Key Decisions |
|-------|-----------|---------------|
| Folio design | ADR-1 to ADR-10 | Room charge strategy, posting key format, charge type enum, lock rules, VND-only currency |
| Transition guard | ADR-11 to ADR-17 | Guard removed in Phase 3.2.8; raw folio total used after room charge guaranteed at check-in |
| Payment mutations | ADR-21 to ADR-27 | Refund cap formula, Adjustment signed ADMIN-only, deletePayment terminal booking block |
| Checkout gate | ADR-28 to ADR-37 | Atomic finalisation, canonical lock order, VOIDED folio short-circuit, `autoCloseFolio` idempotency |
| Night Audit pipeline | ADR-55 to ADR-65 | PostingJob interface contract, idempotency via posting key, partial failure tolerance, business date model |
| Service rate catalog | ADR-66 to ADR-72 | Temporal versioning with `effective_from`, null → skip behaviour, no hardcoded prices |
| Financial operations | ADR-73 to ADR-79 | Manual trigger authorization, retry scope, BreakfastJob dependency chain, revenue exclusion rules, reconciliation read-only |

**Phase 4.1 must start ADR numbering from ADR-80. Existing ADR numbers must not be renumbered or reused.**

---

## 9. Regression Baseline

### Full Suite State (as of commit `9935423`)

```
Tests:      553 passed, 26 failed
Assertions: 2802+
Duration:   ~391 seconds
```

### Pre-existing Failures (26) — Not Phase 3 Regressions

| Test Class | Count | Root Cause |
|-----------|:-----:|-----------|
| `BookingManagementUiTest` | 7 | Room board / payment summary UI (Phase 2 gap) |
| `PerStayAttributionTest` | 7 | Per-stay attribution feature incomplete |
| `DashboardTest` | 5 | Dashboard data aggregation |
| `RoomAvailabilityCheckerTest` | 4 | Availability checker |
| Others | 3 | Pre-existing baseline |

None of the 26 failures are in Phase 3 code.

### Phase 3 Regression Result

```
New regressions from Phase 3: 0
Phase 3 focused suite:        311 pass / 1 pre-existing
```

Phase 3 introduced zero regressions across all implementation commits (Phase 3.1 through Phase 3.3.6.5).

---

## 10. Known Technical Debt

The following items are known at Phase 3 close. None are blocking. All are carried forward to Phase 4+ backlog.

| ID | Item | Severity |
|----|------|---------|
| TD-1 | `booking_payments.booking_id` FK has CASCADE DELETE — should be RESTRICT. Legacy from Phase 2; risk of accidental payment deletion if booking is deleted. | LOW |
| TD-2 | 26 pre-existing test failures in `BookingManagementUiTest`, `PerStayAttributionTest`, `DashboardTest`, `RoomAvailabilityCheckerTest` — not Phase 3 regressions; represent unresolved Phase 2 gaps. | MEDIUM |
| TD-3 | `balance_due` / `remaining_balance` alias duplication in `paymentSummary()` — two keys return the same value; causes consumer confusion. | LOW |
| TD-4 | `folio_number_sequences` has no retry on concurrent write collision — two simultaneous folio creations on the same calendar date risk a unique constraint violation under high concurrency. | LOW |
| TD-5 | `NightAuditOperationsService::retryFailedRun()` re-executes the full PostingJob pipeline per stay, not only the FAILED jobs — SKIPPED siblings from the original run may re-execute and post duplicates if idempotency guard keys were not written in the original run. | LOW |

---

## 11. Phase 4 Constraints

The following rules apply to **all Phase 4 work**. Violating any of these requires a formal Architecture Review before proceeding.

**Phase 4 MUST NOT:**

1. **Bypass `FolioService::calculateGuardedFolioTotal()`** for balance decisions. Direct queries on `folio_entries` to compute totals are forbidden outside this method.

2. **Modify the PostingJob interface contract** (`execute`, `shouldProcess`, `isAlreadyPosted`, `dependsOn`) without an Architecture Review. The interface is the contract between the pipeline and all job implementations.

3. **Hardcode financial rates** (room price, service charge, tax amounts) in any service, job, or controller. All rates must come from `ServiceRateService::resolveFor()`.

4. **Write directly to `folios` or `folio_entries`** outside the approved service layer. All folio mutations must go through `FolioService`.

5. **Break the booking or stay lifecycle** — do not add status transitions, skip lifecycle hooks, or alter `BookingService` or `StayService` status machines without Architecture Review.

6. **Bypass `ServiceRateService`** for rate resolution. A PostingJob that hardcodes an amount or queries `service_rates` directly bypasses the temporal resolution logic.

7. **Change existing financial permissions** (folio, payment, night audit, revenue, reconciliation, package.manage) or remove them from existing roles without Architecture Review.

8. **Update this baseline document based on assumptions** — only update after a change is committed to git. The baseline must always reflect actual git state, not planned state.

---

## 12. Extension Points

The following extension patterns are **approved** for Phase 4 without requiring Architecture Review, provided they follow the established conventions.

| Extension | How to Extend | Convention |
|-----------|--------------|-----------|
| **New PostingJob** | Implement `PostingJob`, define unique posting key prefix, register in `NightAuditService::registerJobs()` | Must resolve rate via `ServiceRateService::resolveFor()`, handle null, follow posting key format |
| **New ChargeType** | Add enum case to `ChargeType` | Add corresponding service rate via `ServiceRateSeeder` or admin UI |
| **New package enrollment type** | Add `package_key` constant to `PackageEnrollmentService::ALLOWED_PACKAGES` | Requires corresponding PostingJob; value stored as string in `booking_package_flags.value` |
| **New financial report** | Add service class following `RevenueReportService` pattern | Must filter `WHERE voided_at IS NULL`; read-only; no corrective writes |
| **New booking detail tab** | Add tab to `resources/js/Pages/Admin/Booking/Show.vue` | Follow existing Inertia tab pattern; route under `/bookings/{booking}/` prefix |
| **New housekeeping module** | Add permissions to `RolePermissionSeeder` under `HOUSEKEEPING` role | HOUSEKEEPING currently has no financial permissions — no conflict risk |
| **New dashboard widget** | Add widget to admin dashboard | Must not recalculate folio balance outside `FolioService` |

---

## 13. Official Closure Statement

This section formally closes Phase 3 and records the verified outcome of each review dimension.

```
Phase 3 Architecture:  PASS
Phase 3 Backend:       PASS — all code committed (latest: 9935423 / phase-3.3.6.3)
Phase 3 Frontend:      PASS — all UI committed (latest: 8117275 / phase-3.3.6.5)
Phase 3 Integration:   PASS — all services wired, all routes registered, all permissions seeded
Phase 3 Build:         PASS — no build-breaking changes
Phase 3 Regression:    PASS — 553 tests passed, 26 pre-existing failures unchanged, 0 new regressions
```

**Phase 3 is officially closed after this baseline.**

Phase 4 status:

```
Phase 4 Implementation:   NOT STARTED
Phase 4 Architecture:     PENDING REVIEW
Phase 4 Readiness:        READY for Architecture Review
```

Phase 4 may proceed to **Architecture Review**. Direct implementation of Phase 4 must not begin until the Architecture Review is approved. The first Architecture Review document for Phase 4.1 (Room Setup Requests) must reference this baseline.

---

*Signed off: 2026-07-04 — Branch: phase-3 — Baseline commit: `9935423`*
