# Phase 3.3 — Financial Operations: Architecture Design

**Date:** 2026-07-02
**Status:** Design — Pending Architecture Review
**Author:** Claude Code / Tien Dat Doan
**Prerequisite:** Phase 3.2 complete (commit `3a06631`)
**Branch target:** `phase-3`
**Do NOT implement until this document is approved.**

---

## Table of Contents

1. [Goals](#1-goals)
2. [Scope](#2-scope)
3. [Out of Scope](#3-out-of-scope)
4. [Domain Model](#4-domain-model)
5. [Database Impact](#5-database-impact)
6. [Services](#6-services)
7. [UI](#7-ui)
8. [Permissions](#8-permissions)
9. [Audit](#9-audit)
10. [Failure Recovery](#10-failure-recovery)
11. [Monitoring](#11-monitoring)
12. [Reporting](#12-reporting)
13. [Test Strategy](#13-test-strategy)
14. [Regression Risk](#14-regression-risk)
15. [Deployment Strategy](#15-deployment-strategy)
16. [Future Compatibility](#16-future-compatibility)
17. [ADR Registry](#17-adr-registry)

---

## 1. Goals

Phase 3.2 delivered the **financial engine**: the posting pipeline, per-night room charges, business date, Night Audit runner, and service rate catalog. What it did not deliver is **operational visibility** into that engine for hotel management.

Phase 3.3 builds the **operator layer**: dashboards, controls, and reports that let managers and accountants understand, monitor, and act on the financial data produced by Phase 3.2.

### G1 — Night Audit Operations Center
Hotel managers need to see Night Audit run status, trigger manual runs, investigate failures per booking, and retry failed runs — all through a UI, not a CLI. Today this requires database access or developer intervention.

### G2 — Revenue Summary & Daily Revenue Report
The hotel owner and accountant need daily revenue totals broken down by charge type (Room, Food & Beverage, Minibar, etc.) and date range. This data exists in `folio_entries` but has no aggregation layer or presentational surface.

### G3 — Posting Timeline Per Booking
Staff and managers need to see the full posting history for a folio: what was posted, when, by whom, and via which source (`MANUAL` / `SYSTEM_AUTO` / `NIGHT_AUDIT`). This answers "why does this booking show a charge for July 1 at 02:30 AM?"

### G4 — Reconciliation & Outstanding Balance Report
The accountant needs a list of bookings with outstanding balances — where `balance_due > 0` after checkout — and a summary of discrepancies between expected and actual charges. Currently there is no aggregate view.

### G5 — Additional Night Audit Pipeline Steps
Phase 3.2 designed the pipeline to be extensible (ADR-69). Phase 3.3 registers additional `PostingJob` implementations: per-night breakfast charge and resort fee. This validates the extensibility architecture and closes the gap between the engine design and the operational reality of a full-service hotel.

### G6 — Service Rate Version History UI
Phase 3.2 stores rate versions in `service_rates` via `effective_from` temporal rows but exposes only the current rate in the admin UI. Phase 3.3 adds a version history panel so admins can see the pricing timeline and confirm that past rates are preserved.

---

## 2. Scope

| Sub-Phase | Deliverable |
|-----------|-------------|
| **3.3.1** | Night Audit Operations Center: dashboard, manual trigger UI, per-run booking log, retry failed runs |
| **3.3.2** | Additional Night Audit Pipeline Steps: `BreakfastPostingJob`; booking-level breakfast flag; enrollment API |
| **3.3.3** | Revenue Summary & Daily Revenue Report: `RevenueReportService`; admin revenue dashboard |
| **3.3.4** | Reconciliation Tools: `ReconciliationService`; outstanding balance report; checkout discrepancy log |
| **3.3.5** | Posting Timeline & Service Rate History: per-folio posting timeline UI; rate version history panel |

---

## 3. Out of Scope

| Topic | Reason |
|-------|---------|
| Invoice generation / PDF printing | Phase 3.6 |
| Tax engine (VAT application to charge amounts) | Phase 3.5 — `tax_rate` column exists as placeholder |
| GL account population and accounting export | Phase 3.5 — `gl_account_code` column exists as placeholder |
| Split billing (separate folio per stay/room) | Phase 3.4 — `stay_id` FK exists as placeholder |
| Discount engine / promotional pricing | Phase 3.4 |
| Package pricing (room + breakfast bundles as a unit) | Phase 3.4 |
| Corporate billing / company accounts | Phase 3.5 |
| Multi-currency posting | Not planned in Phase 3 — VND only |
| City tax / occupancy tax pipeline steps | Phase 3.4 — `ChargeType.CityTax` can be added when regulations define the rate |
| POS integration (external restaurant, spa) | Not planned |
| Automated reconciliation with bank statements | Phase 3.6 |
| Revenue forecasting | Not planned |

---

## 4. Domain Model

Phase 3.3 does not introduce a fundamentally new domain entity. It extends existing entities with operational views and adds one new optional entity for breakfast enrollment.

### 4.1 New Entity: BookingPackageFlag (Phase 3.3.2 only)

Represents a per-stay service enrollment — a flag indicating that a specific per-night automatic charge should apply to a booking.

```
BookingPackageFlag
  id              BIGINT PK
  booking_id      FK → bookings (CASCADE)
  package_key     VARCHAR(50)   — e.g., 'BREAKFAST_PER_NIGHT', 'RESORT_FEE_PER_NIGHT'
  value           VARCHAR(100)  — typically 'true', or a quantity/modifier
  created_by      FK → users (SET NULL)
  timestamps

  UNIQUE (booking_id, package_key)
```

**Key invariants:**
- One row per (booking, package_key) pair.
- `package_key` maps to a specific `PostingJob` implementation — `BREAKFAST_PER_NIGHT` maps to `BreakfastPostingJob`.
- Enrollments may be added at any point before the night being processed.
- Enrollments cannot be removed once the corresponding `PostingJob` has executed for that night (idempotency key already exists on the folio).
- If a ServiceRate for `FOOD_BEVERAGE` with `effective_from <= businessDate` and `is_active = true` does not exist, the job is skipped with `skip_reason = 'NO_ACTIVE_RATE'`.

### 4.2 Computed Projections (no new DB tables)

The following are computed at read time from existing tables:

**RevenueSnapshot** — aggregated from `folio_entries`:
```
{
  date:         DATE              — entry_date (business date)
  charge_type:  ChargeType
  total_amount: DECIMAL           — SUM(amount) WHERE voided_at IS NULL
  entry_count:  INT
}
```

**OutstandingBalance** — computed from `folios`, `folio_entries`, `booking_payments`:
```
{
  booking_id:     BIGINT
  folio_id:       BIGINT
  folio_total:    DECIMAL  — SUM(non-voided folio_entries.amount)
  paid_total:     DECIMAL  — net from booking_payments
  balance_due:    DECIMAL  — folio_total - paid_total
  booking_status: BookingStatus
  checkout_at:    DATETIME NULL
}
```

---

## 5. Database Impact

### 5.1 New Table: `booking_package_flags` (3.3.2 only)

```sql
CREATE TABLE booking_package_flags (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id      BIGINT UNSIGNED NOT NULL,
    package_key     VARCHAR(50)    NOT NULL,
    value           VARCHAR(100)   NOT NULL DEFAULT 'true',
    created_by      BIGINT UNSIGNED NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,

    UNIQUE KEY uq_booking_package (booking_id, package_key),
    INDEX idx_package_booking (booking_id),

    CONSTRAINT fk_pkg_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_pkg_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.2 No Other Schema Changes

All other Phase 3.3 features are computed queries over existing tables:
- `folio_entries` — revenue aggregation, posting timeline
- `night_audit_runs` + `night_audit_booking_logs` — operational dashboard
- `folios` + `folio_entries` + `booking_payments` — reconciliation
- `service_rates` — rate version history

Phase 3.3 adds no additional columns to any existing table beyond `booking_package_flags`.

### 5.3 Summary of Schema Changes

| Change | Type | Sub-Phase |
|--------|------|-----------|
| `booking_package_flags` | New table | 3.3.2 |

All other changes are **additive routes, services, and views only**.

---

## 6. Services

### 6.1 NightAuditOperationsService (3.3.1)

Extends `NightAuditService` with operational controls. Operations that modify state require `ADMIN` authorization.

```php
NightAuditOperationsService
  triggerManualRun(Carbon $date, User $triggeredBy): NightAuditRun
    — validates no COMPLETED run exists for date
    — validates date is within configurable window (audit_window_days: default 3)
    — dispatches NightAuditService::run() synchronously (Phase 3.3; async in Phase 3.4)
    — throws ManualRunBlockedException if run already COMPLETED

  retryFailedRun(NightAuditRun $run, User $retriedBy): NightAuditRun
    — validates run status is FAILED (not COMPLETED or RUNNING)
    — re-executes only bookings with status FAILED in night_audit_booking_logs
    — updates run.errors_count and run.entries_posted in place
    — throws RunNotRetryableException if run is COMPLETED

  getRunSummary(NightAuditRun $run): array
    — returns run metadata + aggregated log stats (success, failed, skipped counts)

  getBookingLogs(NightAuditRun $run, ?string $status = null): Collection
    — returns night_audit_booking_logs for the run, optionally filtered by status
    — eager-loads booking.folio for display
```

### 6.2 BreakfastPostingJob (3.3.2)

New `PostingJob` implementation registered with the `NightAuditPipeline`.

```php
BreakfastPostingJob
  jobId(): string            → 'BREAKFAST_PER_NIGHT'
  displayName(): string      → 'Per-Night Breakfast Charge'
  dependsOn(): string[]      → ['ROOM_NIGHT']  — must post room first
  isNightAuditStep(): bool   → true
  isRequired(): bool         → false           — enrollment is opt-in

  shouldProcess(PostingContext $ctx): bool
    — checks BookingPackageFlag exists for (booking_id, 'BREAKFAST_PER_NIGHT')
    — checks ServiceRate with charge_type=FOOD_BEVERAGE active on businessDate

  isAlreadyPosted(PostingContext $ctx): bool
    — checks posting_key = 'BREAKFAST_{stay_id}_{date}' exists and is not voided

  execute(PostingContext $ctx): PostingResult
    — resolves rate via ServiceRateService::resolveFor(FOOD_BEVERAGE, businessDate)
    — posts FolioEntry with posting_key = 'BREAKFAST_{stay_id}_{date}'
    — posting_source = 'NIGHT_AUDIT'
    — returns PostingResult::posted()
```

### 6.3 RevenueReportService (3.3.3)

No new DB table. Aggregates `folio_entries`.

```php
RevenueReportService
  dailySummary(Carbon $date): array
    — returns [charge_type => [total_amount, entry_count]] for entry_date = $date
    — excludes voided entries (voided_at IS NULL)
    — ordered by charge_type display_order

  periodSummary(Carbon $from, Carbon $to): array
    — same structure but across a date range
    — includes daily breakdown and period totals

  revenueBySource(Carbon $date): array
    — groups by posting_source for the given date
    — useful for Night Audit vs Manual charge comparison

  topChargesByAmount(Carbon $from, Carbon $to, int $limit = 10): Collection
    — returns bookings with highest total folio_entries in period
```

### 6.4 ReconciliationService (3.3.4)

No new DB table. Joins `folios`, `folio_entries`, `booking_payments`.

```php
ReconciliationService
  outstandingBalances(?BookingStatus $status = null): Collection
    — returns all bookings where balance_due > 0
    — optionally filter by booking status (CheckedOut, Cancelled, etc.)
    — ordered by balance_due DESC

  discrepancyReport(Carbon $from, Carbon $to): array
    — returns bookings checked out in date range where balance_due != 0
    — includes folio_total, paid_total, balance_due, checkout timestamp
    — flags if folio is CLOSED with non-zero balance (data integrity issue)

  voidedEntriesSummary(Carbon $from, Carbon $to): Collection
    — returns all voided folio_entries in period with void reason
    — groups by charge_type and voided_by user
```

### 6.5 PostingTimelineService (3.3.5)

Builds a structured posting timeline per folio.

```php
PostingTimelineService
  getTimeline(Folio $folio): Collection
    — returns folio_entries ordered by entry_date, then created_at
    — each entry includes: charge_type, amount, posting_source, stay room number, voided_at, posted_by (user or 'System')
    — computed: cumulative_total at each step

  getRateHistory(string $chargeType): Collection
    — returns all service_rates for a charge type ordered by effective_from DESC
    — shows price evolution: date → unit_price timeline
```

---

## 7. UI

### 7.1 Night Audit Operations Center (3.3.1)

**`/admin/night-audit` (index — run history)**
- Table: audit_date, status badge, entries_posted, errors_count, run_by, duration
- Status badge colours: COMPLETED=green, FAILED=red, RUNNING=yellow, PENDING=gray
- Action: "Trigger Run" button (ADMIN only) → opens modal
- Action: "View" → per-run detail page
- Pagination: most recent 30 days default

**`/admin/night-audit/trigger` (modal on index)**
- Date picker (defaults to current business date)
- Warning if run already exists for date: "A run for this date exists with status X."
- Confirm → calls `POST /admin/night-audit/trigger`
- ADMIN only

**`/admin/night-audit/{run}` (run detail)**
- Run summary header: status, date, timing, counts
- Booking log table: booking ref, status, entries_posted, skip_reason / error_message
- Filter by status: SUCCESS / FAILED / SKIPPED
- "Retry Failed" button (ADMIN only, visible only when run status = FAILED)
- Drill-down: click booking row → opens folio view for that booking

### 7.2 Breakfast Enrollment UI (3.3.2)

Integrated into the existing Booking Detail page (`/admin/bookings/{id}`).

- New "Packages" section in booking sidebar or info tab
- Toggle per package type: "Breakfast included (per night)" ON/OFF
- Shows current status: active / inactive
- ADMIN and MANAGER may toggle; RECEPTIONIST read-only

### 7.3 Revenue Dashboard (3.3.3)

**`/admin/revenue` (revenue summary)**
- Date picker: single day or date range
- Bar chart or table: charge type vs amount
- Breakdown table: ChargeType, entries, total amount
- Source breakdown: MANUAL vs SYSTEM_AUTO vs NIGHT_AUDIT
- Export button (CSV, Phase 3.3 only — no PDF until Phase 3.6)

### 7.4 Reconciliation Report (3.3.4)

**`/admin/reconciliation` (outstanding balances)**
- Table: booking ref, guest name, checkout date, folio_total, paid_total, balance_due
- Filter: booking status (CheckedOut, Cancelled, all)
- Sorted by balance_due DESC
- Export: CSV

**`/admin/reconciliation/voids` (voided entries)**
- Table: entry date, booking, charge type, amount, voided_at, voided_by, reason
- Filter: date range

### 7.5 Posting Timeline & Rate History (3.3.5)

**Posting Timeline** — embedded in the existing Folio panel on Booking Detail:
- Timeline view: chronological list of entries
- Each row: date, charge type, amount, source badge, room (if stay-linked), status (Active / Voided)
- Cumulative total at current non-voided entries shown at bottom
- ADMIN may see voided entries (with strikethrough); others see only active entries

**Rate History** — accessible from Service Rates admin:
- "History" link per rate row → side panel showing all versions for that ChargeType
- Columns: effective_from, unit_price, is_active, created_by

---

## 8. Permissions

| Action | ADMIN | MANAGER | RECEPTIONIST |
|--------|-------|---------|--------------|
| View Night Audit run history | ✅ | ✅ | ❌ |
| View Night Audit run detail & booking logs | ✅ | ✅ | ❌ |
| Trigger manual Night Audit run | ✅ | ❌ | ❌ |
| Retry failed Night Audit run | ✅ | ❌ | ❌ |
| View / toggle Breakfast enrollment (Booking) | ✅ | ✅ | ❌ |
| View Revenue dashboard | ✅ | ✅ | ❌ |
| Export revenue CSV | ✅ | ✅ | ❌ |
| View Reconciliation report | ✅ | ✅ | ❌ |
| View voided entries | ✅ | ✅ | ❌ |
| View posting timeline (folio) | ✅ | ✅ | ✅ |
| View service rate history | ✅ | ✅ | ❌ |

---

## 9. Audit

Phase 3.3 adds the following audit events on top of Phase 3.2:

| Event | Logged Where | Detail |
|-------|-------------|--------|
| Manual Night Audit triggered | `night_audit_runs.run_by`, audit log | User + date + timestamp |
| Night Audit retry executed | `night_audit_runs.notes` | Retry timestamp and user appended |
| Breakfast enrollment created/removed | `audit_logs` via `AuditObserver` on `BookingPackageFlag` | Created/Deleted events |
| Revenue report exported | `audit_logs` (manual insert) | User, date range, export format |

The `AuditObserver` already handles `Created`, `Updated`, `Deleted` events automatically for models that register it. `BookingPackageFlag` registers `AuditObserver`.

---

## 10. Failure Recovery

### 10.1 Failed Night Audit Run (enhanced from Phase 3.2)

Phase 3.2 defined partial failure handling (ADR-61): individual booking errors do not abort the run. Phase 3.3 adds the operational path:

1. Run completes with `status = FAILED` and `errors_count > 0`.
2. ADMIN sees the FAILED badge in the Night Audit Operations Center.
3. ADMIN views per-booking error messages in the run detail page.
4. ADMIN clicks "Retry Failed" → `NightAuditOperationsService::retryFailedRun()` is called.
5. Retry reprocesses only FAILED booking logs (idempotency via posting keys prevents double-posting for already-succeeded bookings in the same run).
6. If retry succeeds for all previously-failed bookings, run status transitions to COMPLETED.

### 10.2 Breakfast Posting Failure

`BreakfastPostingJob` follows the same partial failure contract as `RoomChargePostingJob` (ADR-61). A failure for one booking does not stop the pipeline. The specific failure reason is recorded in `night_audit_booking_logs.error_message`.

Recovery: Fix the root cause (e.g., missing ServiceRate) then retry the failed run.

### 10.3 Rate Resolution Failure

If `ServiceRateService::resolveFor(FOOD_BEVERAGE, businessDate)` returns null (no active rate for the date):

- `BreakfastPostingJob::shouldProcess()` returns `false`.
- The booking log records `skip_reason = 'NO_ACTIVE_RATE'`.
- No entry is posted.
- This is logged as SKIPPED, not FAILED (does not increment `errors_count`).

Recovery: Admin creates a ServiceRate for FOOD_BEVERAGE with appropriate `effective_from`. Next night's audit will post correctly.

---

## 11. Monitoring

Phase 3.3 exposes operational metrics through the Night Audit Operations Center. Beyond the dashboard UI, the following data points are available for external monitoring:

| Signal | Source | Alert Threshold |
|--------|--------|----------------|
| Night Audit status | `night_audit_runs.status` | FAILED or no COMPLETED row for current business date by 08:00 |
| Error rate per run | `errors_count / bookings_processed` | > 5% suggests systemic issue |
| Entries posted count | `night_audit_runs.entries_posted` | < expected (active stays × jobs) suggests pipeline gap |
| Outstanding balance spike | `ReconciliationService::outstandingBalances()` | Sudden increase in balance_due = 0 checkouts worth investigating |

These are read-only queries with no new monitoring infrastructure in Phase 3.3. Integration with an external alerting tool (e.g., Sentry, Uptime Robot, Grafana) is Phase 3.4+.

---

## 12. Reporting

### 12.1 Daily Revenue Summary

Produced by `RevenueReportService::dailySummary()`. Data source: `folio_entries` grouped by `entry_date` and `charge_type` (non-voided only).

**Report structure:**
```
Date: 2026-07-02 (Business Date)
-----------------------------------
ROOM              VND 4,200,000     3 entries
FOOD_BEVERAGE     VND   450,000     5 entries
LAUNDRY           VND   180,000     2 entries
MINIBAR           VND    90,000     1 entry
-----------------------------------
TOTAL             VND 4,920,000    11 entries

By Source:
  NIGHT_AUDIT    VND 4,200,000
  MANUAL         VND   720,000
  SYSTEM_AUTO    VND       -
```

### 12.2 Outstanding Balance Report

Produced by `ReconciliationService::outstandingBalances()`. Shows all bookings where `folio_total - paid_total > 0`.

**Priority filter:** CheckedOut status with balance_due > 0 (guests have left without full payment) — these require immediate follow-up.

### 12.3 Export Format (Phase 3.3)

CSV export only. Headers in English for accounting software compatibility. PDF and formatted invoice are Phase 3.6.

---

## 13. Test Strategy

### 13.1 Unit Tests

- `BreakfastPostingJobTest`: enrollment check, rate resolution, idempotency, skip on missing rate, skip on missing enrollment
- `NightAuditOperationsServiceTest`: manual trigger validation, retry eligibility, partial retry logic
- `RevenueReportServiceTest`: daily aggregation, voided entries excluded, source breakdown accuracy
- `ReconciliationServiceTest`: outstanding balance calculation, discrepancy detection, voided entries summary

### 13.2 Feature Tests

- `NightAuditDashboardTest`: dashboard renders runs, trigger modal, retry button visibility
- `BreakfastEnrollmentTest`: enrollment create/remove, guard against removal post-posting
- `RevenueDashboardTest`: revenue page renders, date filter applies
- `ReconciliationReportTest`: outstanding balances appear, CSV export works

### 13.3 Pipeline Integration Tests

- `BreakfastPipelineTest`: full pipeline run with breakfast enrolled bookings; verifies BREAKFAST_* entries created; verifies non-enrolled bookings not charged
- Idempotency: re-running Night Audit for the same date creates no duplicate entries

### 13.4 Coverage Target

≥ 80% line coverage on all new services and PostingJob implementations. Pipeline integration tests count toward this target.

---

## 14. Regression Risk

| Risk | Severity | Mitigation |
|------|----------|-----------|
| `NightAuditOperationsService::retryFailedRun()` double-posts entries | HIGH | Idempotency via posting keys prevents this; unit-tested explicitly |
| Manual trigger creates two concurrent runs for the same date | HIGH | `UNIQUE` constraint on `night_audit_runs.audit_date`; DB-level guard |
| `BreakfastPostingJob` registered globally but skips non-enrolled bookings | MEDIUM | `shouldProcess()` returns false for non-enrolled; unit-tested |
| Revenue aggregation includes voided entries | MEDIUM | All queries filter `WHERE voided_at IS NULL`; unit-tested |
| Reconciliation report queries cause N+1 on large datasets | MEDIUM | Use eager loading + aggregate query; no per-row PHP loops |
| Night Audit retry changes run status from FAILED → COMPLETED for a partial success | LOW | Status updated only when `errors_count = 0` after retry |
| Revenue report misclassifies NIGHT_AUDIT entries as MANUAL for legacy rows | LOW | Legacy `posting_source = NULL` treated as MANUAL in report; documented behavior |

### 14.1 Zero-Regression Invariants

The following Phase 3.2 behaviors must not change:

- `RoomChargePostingJob` continues to post one entry per stay per night with the existing posting key format `ROOM_NIGHT_{stay_id}_{date}`.
- `LateCheckoutFeePostingJob` and `EarlyCheckinFeePostingJob` continue to trigger at lifecycle events.
- `getFolioTotal()` sum logic is unchanged.
- Business date calculation is unchanged.
- All existing ADR-57 through ADR-72 invariants remain enforced.

---

## 15. Deployment Strategy

### 15.1 Sub-Phase Deployment Order

Sub-phases are independently deployable in order:

1. **3.3.1** (Night Audit Dashboard) — safe first; adds routes and views, no schema change
2. **3.3.2** (Breakfast PostingJob) — requires `booking_package_flags` migration before deploy
3. **3.3.3** (Revenue Report) — no schema change; safe any time after 3.3.1
4. **3.3.4** (Reconciliation) — no schema change; safe any time after 3.3.1
5. **3.3.5** (Posting Timeline / Rate History) — no schema change; safe any time after 3.3.1

3.3.3, 3.3.4, and 3.3.5 are **independent** of each other and may be deployed in any order after 3.3.1.

### 15.2 Deployment Prerequisites

- Phase 3.2 production deployment must be complete (including backfill command).
- `booking_package_flags` migration must run before activating `BreakfastPostingJob` (Phase 3.3.2).
- Seed at least one FOOD_BEVERAGE ServiceRate before enrolling any bookings for breakfast.

### 15.3 Rollback

- **3.3.1**: Rollback = remove routes. No data dependencies.
- **3.3.2**: Rollback = remove `BreakfastPostingJob` from pipeline registration. Keep migration (table is harmless). BREAKFAST_* posting keys in folio_entries remain valid.
- **3.3.3–3.3.5**: Rollback = remove routes. Pure read operations; no state written.

---

## 16. Future Compatibility

### 16.1 Phase 4 (Housekeeping Module)

Phase 4.1 adds Room Setup Requests (operational instructions per stay). The `stay_id` FK on `folio_entries` (Phase 3.2) is already the link point. Phase 3.3 does not change or conflict with Phase 4.

The `BookingPackageFlag` entity introduced in Phase 3.3 (`booking_id + package_key`) is extensible: new package_key values (e.g., `TURNDOWN_SERVICE`, `EXTRA_AMENITIES`) can be added in Phase 4 without schema changes.

### 16.2 Phase 3.4 (Policy Pricing Tiers, Split Billing)

Phase 3.4 promotes `ChargeCategory` to a DB table and adds graduated late checkout fee tiers. `BreakfastPostingJob` (Phase 3.3.2) uses the existing `ServiceRateService::resolveFor()` resolver — compatible with whatever rate resolution enhancements Phase 3.4 introduces.

Split billing in Phase 3.4 adds a per-stay folio. The `stay_id` FK on `folio_entries` (Phase 3.2) is the foundation. Phase 3.3 reporting queries already group by `stay_id` where relevant; they will continue to work in a multi-folio environment.

### 16.3 Phase 3.5 (Tax Engine, GL Export)

Phase 3.5 activates `tax_rate` on `service_rates` and `gl_account_code` for GL mapping. The revenue reporting in Phase 3.3 will need a minor update to show pre-tax and tax amounts separately. The `RevenueReportService` should be designed with this extension in mind: the aggregation query should be structured to allow a future `SUM(amount * tax_rate)` column to be added without a full rewrite.

### 16.4 Phase 3.6 (Invoice Generation)

Phase 3.6 generates formatted invoices from folio data. The `PostingTimelineService` (Phase 3.3.5) produces a structured folio line-item list that can be consumed directly by the invoice renderer without re-querying `folio_entries`.

---

## 17. ADR Registry

### ADR-73: Night Audit Manual Trigger Requires ADMIN Role and Date Validation

**Decision:** Manual Night Audit triggers are ADMIN-only and restricted to business dates within `audit_window_days` (default: 3 days) of the current business date. A COMPLETED run for the target date blocks re-triggering.

**Rationale:** Allowing any user to trigger Night Audit or trigger for arbitrary past dates creates double-posting risk. The window constraint is conservative and can be widened via hotel settings.

**Consequence:** MANAGER cannot trigger Night Audit; ADMIN must be available for after-hours escalations. Phase 3.4 may add a MANAGER-with-approval workflow.

---

### ADR-74: Night Audit Retry Re-executes Only FAILED Booking Logs

**Decision:** `retryFailedRun()` processes only bookings whose `night_audit_booking_logs.status = 'FAILED'`. Bookings with status SUCCESS or SKIPPED are not reprocessed.

**Rationale:** Idempotency keys prevent double-posting, but reprocessing successful bookings wastes compute and creates confusing log entries. SKIPPED bookings were intentionally excluded from the original run; retry does not change the skip decision.

**Consequence:** If a booking was SKIPPED due to a now-fixed condition (e.g., folio closed then reopened), it remains SKIPPED after retry. The ADMIN must trigger a new run for the next business date.

---

### ADR-75: BreakfastPostingJob Depends on RoomChargePostingJob

**Decision:** `BreakfastPostingJob::dependsOn()` returns `['ROOM_NIGHT']`. The pipeline will not execute `BREAKFAST_PER_NIGHT` for a stay until `ROOM_NIGHT` has succeeded.

**Rationale:** Breakfast is a secondary charge that contextually follows room occupancy. If room charge fails, breakfast charge is deferred to prevent a partial folio that obscures the root failure.

**Consequence:** If room charge is SKIPPED (e.g., stay checking out today), breakfast is also skipped for that night.

---

### ADR-76: Breakfast Rate Resolution Uses ServiceRate — No Hardcoded Price

**Decision:** `BreakfastPostingJob` calls `ServiceRateService::resolveFor(ChargeType::FoodBeverage, $businessDate)`. No unit price is hardcoded in the job. If no active rate exists for the business date, the job skips with `skip_reason = 'NO_ACTIVE_RATE'`.

**Rationale:** Consistent with ADR-57 (ServiceRate is a display default). Breakfast prices change seasonally; they must be admin-configurable without code changes.

**Consequence:** Admin must seed a FOOD_BEVERAGE ServiceRate before enrolling any booking for breakfast. Missing rate = silent skip, not failure.

---

### ADR-77: Revenue Reporting Excludes Voided Entries

**Decision:** All `RevenueReportService` queries filter `WHERE voided_at IS NULL`. Voided entries are excluded from revenue totals.

**Rationale:** A voided charge has no revenue impact — the service was cancelled or reversed. Including voided entries in revenue totals would overstate income.

**Consequence:** The voids summary in `ReconciliationService::voidedEntriesSummary()` provides a separate view of voided amounts for the accountant's audit trail.

---

### ADR-78: ReconciliationService Balance Formula Is Read-Only

**Decision:** `ReconciliationService` computes `balance_due = folio_total - paid_total` at query time. It does not write to any table or update any cached balance. All computations are authoritative reads from the source of truth.

**Rationale:** A reconciliation tool that writes corrective entries introduces audit complexity and could mask data integrity issues. Phase 3.3 identifies discrepancies; Phase 3.4+ addresses correction workflows.

**Consequence:** The reconciliation report is a diagnostic, not a correction tool. Discrepancies found must be resolved by ADMIN through normal folio operations (add charge, process payment, void entry).
