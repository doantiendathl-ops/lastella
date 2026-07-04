# Phase 3.2 — Service Charges & Night Audit Foundation
## Architecture Design Document

**Date:** 2026-07-02 (Revised 2026-07-02 post Architecture Review)
**Status:** Architecture — Revised, Pending Second Review
**Author:** Claude Code / Tien Dat Doan
**Prerequisite commit:** Phase 3.1.2 (`1917bf9`) — Final Checkout Confirmation Gate
**Branch target:** `phase-3`
**Do NOT implement until this document is approved.**

> **ADR Note:** ADR-55 and ADR-56 were consumed by Phase 3.1.2 (Final Checkout Gate and
> Charge Lock). Phase 3.2 original ADRs run ADR-57 through ADR-65.
> Architecture Review additions run **ADR-66 through ADR-72**.

---

## Table of Contents

1. [Goals](#1-goals)
2. [Scope](#2-scope)
3. [Out of Scope](#3-out-of-scope)
4. [Domain Model](#4-domain-model)
5. [Database Design](#5-database-design)
6. [Entity Relationships](#6-entity-relationships)
7. [Charge Categories & ChargeType Catalog](#7-charge-categories--chargetype-catalog)
8. [PostingSource Model](#8-postingsource-model)
9. [Service Rate Versioning](#9-service-rate-versioning)
10. [Business Date Model](#10-business-date-model)
11. [Hotel Settings](#11-hotel-settings)
12. [Service Charge Lifecycle](#12-service-charge-lifecycle)
13. [Posting Rules](#13-posting-rules)
14. [Posting Keys](#14-posting-keys)
15. [Tax Strategy](#15-tax-strategy)
16. [Service Pricing Strategy](#16-service-pricing-strategy)
17. [Room-Linked vs Booking-Linked Charges](#17-room-linked-vs-booking-linked-charges)
18. [Manual vs Automatic Charges](#18-manual-vs-automatic-charges)
19. [Night Audit Foundation — Pipeline Design](#19-night-audit-foundation--pipeline-design)
20. [PostingJob Abstraction](#20-postingjob-abstraction)
21. [Idempotency Strategy](#21-idempotency-strategy)
22. [Concurrency Strategy](#22-concurrency-strategy)
23. [Transaction Boundaries](#23-transaction-boundaries)
24. [Rollback Strategy](#24-rollback-strategy)
25. [Failure Recovery](#25-failure-recovery)
26. [Audit Strategy](#26-audit-strategy)
27. [Permission Rules](#27-permission-rules)
28. [Validation Rules](#28-validation-rules)
29. [API Design](#29-api-design)
30. [UI Impact](#30-ui-impact)
31. [Security Considerations](#31-security-considerations)
32. [Performance Considerations](#32-performance-considerations)
33. [Migration Strategy](#33-migration-strategy)
34. [Risk Assessment](#34-risk-assessment)
35. [Regression Analysis](#35-regression-analysis)
36. [ADR Registry](#36-adr-registry)
37. [Test Plan](#37-test-plan)

---

## 1. Goals

### G1 — Service Rate Catalog
Introduce an admin-configurable catalog of services with default unit prices, replacing reliance on staff memory and ensuring pricing consistency. The catalog covers all manual charge types: minibar, laundry, restaurant, airport transfer, extra bed, extra person, late checkout, early check-in, and other services.

### G2 — Per-Night Room Charge Posting
Replace the current aggregate room-charge-at-check-in model (`ROOM_CHARGE_{booking_id}_AGGREGATE`) with per-night entries — one `FolioEntry` per stay per night — to support daily revenue recognition, multi-night variance reporting, and the Night Audit workflow.

### G3 — Night Audit Foundation (Pipeline)
Establish the Night Audit as a generic, extensible posting pipeline that processes registered `PostingJob` implementations in dependency order for every in-house guest each night. In Phase 3.2, one job runs: room charge. Future phases register additional jobs (breakfast, resort fee, city tax) without redesigning the pipeline. The run is daily, idempotent, fully logged, and produces a per-booking audit trail.

### G4 — Business Date as Canonical Accounting Date
Introduce `BusinessDateService` so all night audit dates, posting keys, and revenue-recognition `entry_date` values use the hotel's business date rather than the calendar date. A hotel operating past midnight must not assign 2 AM transactions to the next calendar day.

### G5 — Hotel Settings Infrastructure
Centralise all configurable hotel parameters (grace minutes, business-date offset, audit window, currency precision) in a `hotel_settings` key-value table backed by `HotelSettingsService`. No more hardcoded defaults scattered across services.

### G6 — Service Rate Versioning Slot
Add `effective_from` to `service_rates` so price changes create a new row rather than overwriting history. Historical folio entries are unaffected (price captured at posting). Future reporting can answer "what was the Extra Bed rate on 2026-07-01?"

**Secondary goals:**
- Add `stay_id` attribution on `folio_entries` for future per-room split billing.
- Add `posting_source` on `folio_entries` for operational reporting and audit.
- Add `gl_account_code` slot on `service_rates` for future accounting integration.
- Introduce the quick-charge picker UI (staff selects from catalog instead of entering prices from memory).
- Enable late-checkout and early-check-in auto-fee posting via Service Rate Catalog.
- Remove the transition guard in `calculateGuardedFolioTotal()` after per-night posting is stable and backfilled.

---

## 2. Scope

| Sub-Phase | Deliverable |
|-----------|-------------|
| **3.2.1** | Service Rate Catalog: `service_rates` table (with `effective_from`, `gl_account_code`), admin CRUD, `ServiceRate` model, `ServiceRatePolicy` |
| **3.2.2** | ChargeType enum expansion: add `ExtraBed`, `ExtraPerson`, `AirportTransfer`; explicit `ChargeCategory` PHP class |
| **3.2.3** | Per-Stay & Source Attribution: `stay_id` + `posting_source` on `folio_entries`; update `FolioEntry` model and all callers |
| **3.2.4** | Per-Night Room Charge: `RoomChargePostingJob`; replace aggregate posting; new posting key format; first-night at check-in |
| **3.2.5** | Night Audit Pipeline: `hotel_settings` + `night_audit_runs` + `night_audit_booking_logs` tables; `NightAuditPipeline`; `NightAuditService`; Artisan command; console schedule |
| **3.2.6** | Late/Early Fee Auto-Posting: `LateCheckoutFeePostingJob`, `EarlyCheckinFeePostingJob`; triggered at lifecycle events via `StayService` |
| **3.2.7** | Quick-Charge UI: catalog picker in `AddChargeForm.vue`; per-stay room dropdown; `charge_type = ROOM` blocked from UI |
| **3.2.8** | Transition Guard Removal: backfill Artisan command; guard removal from `calculateGuardedFolioTotal()`; test updates |
| **3.2.9** | Hotel Settings: `hotel_settings` table; `HotelSettingsService`; `BusinessDateService`; admin settings UI |

---

## 3. Out of Scope

| Topic | Reason |
|-------|---------|
| Split billing (separate folio per stay) | Phase 3.4 — `stay_id` lays the groundwork only |
| Full tax engine (per-service VAT, exemptions) | Phase 3.5 — Phase 3.2 defines tax strategy only; see §15 |
| Invoice generation / printing | Phase 3.6 |
| Night Audit reporting UI | Phase 3.2 produces log records; reporting dashboard is Phase 3.3 |
| Policy pricing tiers (graduated late checkout fees) | Phase 3.4 |
| POS integration (external restaurant, spa systems) | Not planned |
| Multi-currency posting | VND only; `currency_code` in hotel_settings, but multi-currency deferred |
| Discounts and promotional pricing | Phase 3.4 |
| Corporate billing / company account | Phase 3.5 |
| Package pricing (room + breakfast bundles) | Phase 3.4 |
| Breakfast / resort fee / city tax pipeline steps | Phase 3.3 — pipeline architecture designed here; steps added next phase |
| GL account population and accounting integration | Phase 3.5 — `gl_account_code` column slot defined in Phase 3.2 only |

---

## 4. Domain Model

### 4.1 Existing Entities (structure unchanged, relationships extended)

```
Booking ──< BookingRequirement (room_type_id, room_price, quantity)
        ──< RoomAssignment ──< Stay
        ──1 Folio ──< FolioEntry   ← stay_id + posting_source added
        ──< BookingPayment
```

### 4.2 New Entity: ServiceRate

The catalog of available services with default pricing. A `ServiceRate` represents one specific service offering at a specific price point from a specific date forward. Multiple rows can represent the same service at different prices over time (versioning via `effective_from` — see §9).

```
ServiceRate
  id                BIGINT PK
  name              VARCHAR(100)   — "Bữa sáng buffet", "Bia Heineken", "Giường phụ"
  charge_type       VARCHAR(40)    — FK value from ChargeType enum
  unit_price        DECIMAL(12,2)  — default; staff may override at posting
  effective_from    DATE NOT NULL  — rate is valid from this business date forward (ADR-66)
  unit_label        VARCHAR(30)    — "người", "đêm", "cái", "chai" (display only)
  tax_rate          DECIMAL(5,4)   — stored for future Phase 3.5 tax engine; not applied in 3.2
  gl_account_code   VARCHAR(50)    — future GL integration slot (ADR-72); NULL in 3.2
  is_active         TINYINT(1)     — soft-disable; historical folio entries unaffected
  display_order     INT            — sort order in UI picker
  created_by        FK → users (nullable, SET NULL)
  timestamps
```

**Key invariants:**
- `unit_price` is a default, not binding. Staff overrides are accepted at posting time (ADR-57).
- No hard delete — deactivation preserves the record for historical audit.
- `tax_rate` is stored but ignored in all calculations until Phase 3.5.
- `gl_account_code` is stored but ignored in all calculations until Phase 3.5.
- `effective_from` defaults to `'2000-01-01'` for rates created before versioning is enforced, making them always valid. New price changes INSERT a new row with the future date.

### 4.3 New Entity: NightAuditRun

Records each execution of the Night Audit pipeline — scheduled or manual.

```
NightAuditRun
  id              BIGINT PK
  audit_date      DATE           — the BUSINESS DATE being audited (UNIQUE) (ADR-67)
  status          VARCHAR(20)    — 'PENDING', 'RUNNING', 'COMPLETED', 'FAILED'
  started_at      TIMESTAMP NULL
  completed_at    TIMESTAMP NULL
  run_by          FK → users (nullable — NULL = scheduled/system)
  entries_posted  INT DEFAULT 0  — total FolioEntry records created
  errors_count    INT DEFAULT 0  — bookings that could not be processed
  notes           TEXT NULL      — error summary or override notes
  timestamps
```

**Key invariants:**
- One `NightAuditRun` per `audit_date` (UNIQUE constraint).
- `audit_date` is the hotel business date, not necessarily the calendar date.
- A COMPLETED run cannot be re-triggered. Only FAILED or PENDING runs are eligible for retry.
- `audit_date` is immutable after creation (ADR-65).

### 4.4 New Entity: NightAuditBookingLog

Per-booking audit trail for each Night Audit run. Enables granular error diagnosis.

```
NightAuditBookingLog
  id                  BIGINT PK
  night_audit_run_id  FK → night_audit_runs (CASCADE)
  booking_id          FK → bookings (CASCADE)
  status              VARCHAR(20)  — 'SUCCESS', 'SKIPPED', 'FAILED'
  entries_posted      INT DEFAULT 0
  skip_reason         VARCHAR(100) NULL  — e.g., 'FOLIO_CLOSED', 'AGGREGATE_ENTRY_PRESENT'
  error_message       TEXT NULL
  processed_at        TIMESTAMP NULL
  timestamps
```

**Key invariants:**
- One log entry per (run, booking) pair — UNIQUE on `(night_audit_run_id, booking_id)`.
- SKIPPED bookings do not increment `errors_count` on the parent run; FAILED bookings do.

### 4.5 New Entity: HotelSettings

Key-value store for all configurable hotel parameters. Replaces hardcoded defaults throughout services. See §11 for full key registry.

```
HotelSettings (DB table: hotel_settings)
  id            BIGINT PK
  key           VARCHAR(100) UNIQUE NOT NULL
  value         TEXT NOT NULL
  value_type    VARCHAR(20)  — 'string' | 'int' | 'decimal' | 'bool' | 'time'
  description   VARCHAR(255) NULL
  updated_by    FK → users (nullable, SET NULL)
  updated_at    TIMESTAMP NULL
```

### 4.6 Architecture Interfaces (PHP — no migration required)

These PHP interfaces define the PostingJob abstraction and Night Audit Pipeline contract. Implementation is in Phase 3.2.4–3.2.5. See §19 and §20 for full specification.

```
PostingJob (interface)
  jobId(): string
  displayName(): string
  dependsOn(): string[]         — other jobIds that must succeed before this one
  isNightAuditStep(): bool      — true = pipeline includes this job each night
  shouldProcess(PostingContext): bool
  isAlreadyPosted(PostingContext): bool
  execute(PostingContext): PostingResult
  rollback(PostingContext): void

PostingContext (final class)
  booking: Booking
  folio: Folio
  stay: Stay
  businessDate: Carbon
  run: ?NightAuditRun           — null for lifecycle PostingJobs

PostingResult (final class)
  success: bool
  status: 'POSTED' | 'SKIPPED' | 'FAILED'
  message: string
  entriesCreated: int

NightAuditPipeline (service)
  register(PostingJob): void
  executeForStay(Stay, NightAuditRun): PipelineResult
  executeRun(NightAuditRun): RunResult

BusinessDateService (service)
  currentBusinessDate(): Carbon
  businessDateFor(Carbon $realTime): Carbon
  fromNightAuditDate(string $date): Carbon

HotelSettingsService (service)
  get(string $key, mixed $default = null): mixed
  set(string $key, mixed $value, string $updatedBy): void
  getInt(string $key, int $default): int
  getBool(string $key, bool $default): bool
```

---

## 5. Database Design

### 5.1 New Table: `service_rates`

```sql
CREATE TABLE service_rates (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(100)   NOT NULL,
    charge_type      VARCHAR(40)    NOT NULL,
    unit_price       DECIMAL(12,2)  NOT NULL,
    effective_from   DATE           NOT NULL DEFAULT '2000-01-01',
    unit_label       VARCHAR(30)    NOT NULL DEFAULT 'lần',
    tax_rate         DECIMAL(5,4)   NOT NULL DEFAULT 0.0000,
    gl_account_code  VARCHAR(50)    NULL,
    is_active        TINYINT(1)     NOT NULL DEFAULT 1,
    display_order    INT            NOT NULL DEFAULT 0,
    created_by       BIGINT UNSIGNED NULL,
    created_at       TIMESTAMP NULL,
    updated_at       TIMESTAMP NULL,

    INDEX idx_service_rates_charge_type (charge_type),
    INDEX idx_service_rates_effective (charge_type, is_active, effective_from),
    INDEX idx_service_rates_active_order (is_active, display_order),

    CONSTRAINT fk_service_rates_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Key index: `idx_service_rates_effective (charge_type, is_active, effective_from)`** — optimises the canonical rate resolution query: `WHERE charge_type = X AND is_active = 1 AND effective_from <= :business_date ORDER BY effective_from DESC LIMIT 1`.

### 5.2 New Table: `hotel_settings`

```sql
CREATE TABLE hotel_settings (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`        VARCHAR(100)   NOT NULL,
    `value`      TEXT           NOT NULL,
    value_type   VARCHAR(20)    NOT NULL DEFAULT 'string',
    description  VARCHAR(255)   NULL,
    updated_by   BIGINT UNSIGNED NULL,
    updated_at   TIMESTAMP      NULL,

    UNIQUE KEY uq_hotel_settings_key (`key`),

    CONSTRAINT fk_hotel_settings_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.3 New Table: `night_audit_runs`

```sql
CREATE TABLE night_audit_runs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audit_date      DATE           NOT NULL,
    status          VARCHAR(20)    NOT NULL DEFAULT 'PENDING',
    started_at      TIMESTAMP      NULL,
    completed_at    TIMESTAMP      NULL,
    run_by          BIGINT UNSIGNED NULL,
    entries_posted  INT            NOT NULL DEFAULT 0,
    errors_count    INT            NOT NULL DEFAULT 0,
    notes           TEXT           NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,

    UNIQUE KEY uq_night_audit_runs_date (audit_date),
    INDEX idx_night_audit_runs_status (status),

    CONSTRAINT fk_night_audit_runs_run_by
        FOREIGN KEY (run_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.4 New Table: `night_audit_booking_logs`

```sql
CREATE TABLE night_audit_booking_logs (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    night_audit_run_id   BIGINT UNSIGNED NOT NULL,
    booking_id           BIGINT UNSIGNED NOT NULL,
    status               VARCHAR(20)    NOT NULL DEFAULT 'PENDING',
    entries_posted       INT            NOT NULL DEFAULT 0,
    skip_reason          VARCHAR(100)   NULL,
    error_message        TEXT           NULL,
    processed_at         TIMESTAMP      NULL,
    created_at           TIMESTAMP NULL,
    updated_at           TIMESTAMP NULL,

    UNIQUE KEY uq_audit_log_run_booking (night_audit_run_id, booking_id),
    INDEX idx_audit_log_booking (booking_id),
    INDEX idx_audit_log_status (status),

    CONSTRAINT fk_audit_log_run
        FOREIGN KEY (night_audit_run_id) REFERENCES night_audit_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_audit_log_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.5 Modified Table: `folio_entries` — add `stay_id` and `posting_source`

```sql
ALTER TABLE folio_entries
    ADD COLUMN stay_id BIGINT UNSIGNED NULL
        AFTER folio_id,
    ADD COLUMN posting_source VARCHAR(20) NULL
        AFTER stay_id,

    ADD CONSTRAINT fk_folio_entries_stay_id
        FOREIGN KEY (stay_id) REFERENCES stays(id) ON DELETE SET NULL,

    ADD INDEX idx_folio_entries_stay_id (stay_id),
    ADD INDEX idx_folio_entries_stay_charge (stay_id, charge_type, voided_at),
    ADD INDEX idx_folio_entries_source (posting_source);
```

**`posting_source` values:** `'MANUAL'`, `'SYSTEM_AUTO'`, `'NIGHT_AUDIT'`. NULL on legacy rows — treated as MANUAL where relevant.

### 5.6 Summary of Schema Changes

| Change | Type | Migration |
|--------|------|-----------|
| `hotel_settings` | New table | `create_hotel_settings_table` |
| `service_rates` | New table (with `effective_from`, `gl_account_code`) | `create_service_rates_table` |
| `night_audit_runs` | New table | `create_night_audit_runs_table` |
| `night_audit_booking_logs` | New table | `create_night_audit_booking_logs_table` |
| `folio_entries.stay_id` | New nullable FK column | `add_stay_id_to_folio_entries` |
| `folio_entries.posting_source` | New nullable VARCHAR column | `add_posting_source_to_folio_entries` |

All changes are **purely additive**. No existing column is modified, removed, or renamed.

---

## 6. Entity Relationships

```
Booking ──────────────────────────────────────────────────────────┐
    │                                                              │
    ├──< BookingRequirement (room_type_id, room_price, qty)        │
    │                                                              │
    ├──< RoomAssignment ──< Stay ──────────────────────────────────│──< FolioEntry.stay_id (nullable)
    │                         │                                    │
    │                         └── room_id → Room → RoomType        │
    │                                                              │
    └──1 Folio ────────────────────────────────────────────────────┘──< FolioEntry
              │
              └── folio_number (unique, FLO-YYYYMMDD-NNNNNN)

FolioEntry
    ├── folio_id         FK → folios (NOT NULL)
    ├── stay_id          FK → stays  (NULL = booking-level charge)
    ├── posting_key      UNIQUE nullable (NULL = manual, NOT NULL = system)
    ├── posting_source   'MANUAL' | 'SYSTEM_AUTO' | 'NIGHT_AUDIT' | NULL (legacy)
    ├── charge_type      ChargeType enum value
    ├── amount           = bcmul(quantity, unit_price, 2) — server-computed
    └── voided_at        NULL = active

ServiceRate (versioned catalog — no FK on folio_entries)
    └── price captured at posting time in FolioEntry.unit_price

NightAuditRun ──< NightAuditBookingLog (night_audit_run_id, booking_id)
    └── run_by FK → users (nullable)

HotelSettings (standalone key-value, no FK to domain entities)
```

**Design rationale — no `service_rate_id` FK on `folio_entries`:** Prices are captured at posting time. Historical entries are unaffected by rate changes. Avoids orphan FK concerns and simplifies rollback reasoning.

---

## 7. Charge Categories & ChargeType Catalog

### 7.1 Three-Layer Separation (ADR-71)

| Layer | Purpose | Location |
|-------|---------|---------|
| `ChargeType` | Accounting/reporting dimension — the GL bucket | PHP enum, backed by VARCHAR(40) in DB |
| `ChargeCategory` | UI grouping — organises the picker | PHP class with constants (no DB table in 3.2) |
| `ServiceRate` | Catalog item — a specific priced offering | DB table, many per ChargeType |

Keeping `ChargeType` as a PHP enum (not a DB table) is correct for Phase 3.2: the set of accounting dimensions is small (≤15), type-checked at compile time, and stable across hotels. `ServiceRate` handles hotel-specific naming and pricing without requiring ChargeType changes. See ADR-71 for full justification.

### 7.2 Existing ChargeType Values (unchanged behavior)

| Value | Label (VN) | Auto-posted | Manual | Night Audit |
|-------|-----------|-------------|--------|-------------|
| `ROOM` | Tiền phòng | YES — check-in (first night) + Night Audit (subsequent) | BLOCKED from HTTP | YES |
| `FOOD_BEVERAGE` | Ăn uống | NO | YES | NO |
| `SPA` | Spa | NO | YES | NO |
| `LAUNDRY` | Giặt ủi | NO | YES | NO |
| `MINIBAR` | Minibar | NO | YES | NO |
| `DAMAGE` | Bồi thường | NO | YES | NO |
| `LATE_CHECKOUT` | Trả phòng muộn | YES — at checkout | YES | NO |
| `EARLY_CHECKIN` | Nhận phòng sớm | YES — at check-in | YES | NO |
| `TRANSPORT` | Vận chuyển | NO | YES | NO |
| `OTHER` | Khác | NO | YES | NO |

### 7.3 New ChargeType Values (Phase 3.2.2)

| Value | Label (VN) | Rationale |
|-------|-----------|-----------|
| `EXTRA_BED` | Giường phụ | Distinct revenue category; common charge in Vietnamese hotels |
| `EXTRA_PERSON` | Người thêm | Per-person surcharge; frequent in group/family bookings |
| `AIRPORT_TRANSFER` | Đưa đón sân bay | Distinct from general transport; needed for rate catalog grouping |

**Implementation:** ChargeType stored as `VARCHAR(40)` in MySQL (not a MySQL ENUM), so adding PHP enum cases requires no migration.

### 7.4 ChargeCategory Class (explicit, Phase 3.2.2)

A PHP class `ChargeCategory` defines UI groupings as named constants. Not persisted in DB — evaluated at runtime.

```php
class ChargeCategory
{
    const ROOM_SERVICES  = ['ROOM', 'EXTRA_BED', 'EXTRA_PERSON'];
    const FOOD_BEVERAGE  = ['FOOD_BEVERAGE', 'MINIBAR'];
    const GUEST_SERVICES = ['LAUNDRY', 'SPA', 'TRANSPORT', 'AIRPORT_TRANSFER'];
    const SURCHARGES     = ['LATE_CHECKOUT', 'EARLY_CHECKIN', 'DAMAGE', 'OTHER'];

    const LABEL_MAP = [
        'ROOM_SERVICES'  => 'Phòng',
        'FOOD_BEVERAGE'  => 'Ăn uống',
        'GUEST_SERVICES' => 'Dịch vụ khách',
        'SURCHARGES'     => 'Phụ phí',
    ];
}
```

**Phase 3.4 path:** If hotels need custom categories, a `charge_categories` DB table replaces this class. The `ChargeType` enum remains unchanged — only the grouping layer is promoted to DB.

### 7.5 System-Reserved ChargeType Enforcement

`ChargeType::Room` is reserved for system-generated entries only. Enforced at:
1. `StoreFolioEntryRequest` validation — rejects `charge_type = ROOM` with 422.
2. `ServiceRateController::store()` — rejects creating a service rate with `charge_type = ROOM`.

---

## 8. PostingSource Model

A new `PostingSource` PHP enum (backed by `VARCHAR(20)`) tracks the origin of every `FolioEntry`.

```php
enum PostingSource: string
{
    case Manual      = 'MANUAL';      // Staff action via AddChargeForm
    case SystemAuto  = 'SYSTEM_AUTO'; // Lifecycle trigger: checkIn, checkOut
    case NightAudit  = 'NIGHT_AUDIT'; // Night Audit pipeline run
}
```

### Source → Posting Key → Voidability Matrix

| Source | posting_key | Voidable | Examples |
|--------|------------|----------|---------|
| `MANUAL` | NULL | YES (authorized user) | Minibar, laundry, restaurant charge |
| `SYSTEM_AUTO` | NOT NULL | NEVER (ADR-50) | First-night room charge, late checkout fee, early check-in fee |
| `NIGHT_AUDIT` | NOT NULL | NEVER (ADR-50) | Per-night room charges for nights 2+ |

**Legacy rows** (pre-3.2 `folio_entries`) have `posting_source = NULL`. Treated as `MANUAL` for voidability if `posting_key IS NULL`; treated as `SYSTEM_AUTO` if `posting_key IS NOT NULL`. No backfill required.

---

## 9. Service Rate Versioning

### 9.1 Problem

Hotels change prices monthly or seasonally. Without versioning, updating `unit_price` on an existing `ServiceRate` row would change what the UI shows as the "catalog price" without any history of what the rate used to be. Reporting queries such as "what was the Extra Bed rate last month?" would have no answer.

### 9.2 Decision — Temporal Rows on `service_rates` (ADR-66)

Each price change **inserts a new row** with a new `effective_from` date. The previous row remains in the table unchanged. No `effective_to` column — it is implicitly computed as the `effective_from` of the next row for the same `(name, charge_type)` pair.

**Canonical rate resolution query** (used by `ServiceRateService::resolveFor(string $chargeType, Carbon $businessDate)`):

```sql
SELECT * FROM service_rates
WHERE charge_type = :charge_type
  AND is_active   = 1
  AND effective_from <= :business_date
ORDER BY effective_from DESC, id DESC
LIMIT 1
```

### 9.3 Why This Approach

| Approach | Regression risk | Schema change | Query complexity |
|----------|----------------|---------------|-----------------|
| `effective_from` on same table | **Lowest** — additive only | One new NOT NULL column (default `'2000-01-01'`) | Minimal |
| `service_rate_versions` separate table | Medium — FK joins everywhere | New table + FK on service_rates | Higher |
| Overwrite `unit_price` | None now, **Critical later** — no history | None | None |

Temporal rows on the same table has the lowest regression risk. All existing rows keep `effective_from = '2000-01-01'`, making them valid from before any business date query.

### 9.4 Price Capture at Posting (unchanged)

`FolioEntry.unit_price` captures the price at posting time (ADR-57). Rate versioning is for **catalog audit and future reporting only** — it does not retroactively affect posted charges.

### 9.5 Late/Early Fee Rate Resolution

Late checkout and early check-in fees use the same versioned resolution:

```sql
SELECT * FROM service_rates
WHERE charge_type = 'LATE_CHECKOUT'
  AND is_active   = 1
  AND effective_from <= :business_date
ORDER BY effective_from DESC, id DESC
LIMIT 1
```

This ensures the correct rate for the business date of the checkout, not today's rate.

### 9.6 Night Audit Rate Resolution

Room charges use `booking_requirements.room_price` (the contractual rate), not the service rate catalog. Rate versioning does not affect room charge amounts in Phase 3.2.

### 9.7 UI Admin Workflow

When the hotel changes a price:
1. Manager opens `/admin/service-rates` → finds "Extra Bed".
2. Clicks "Thêm phiên bản giá" → form pre-fills name and charge_type.
3. Sets new `unit_price` and `effective_from` date.
4. Saves → INSERT new row. Old row remains for history.

**Phase 3.2 scope:** The versioning history view (showing all rows per service name) is a Phase 3.3 enhancement. Phase 3.2 UI shows only the currently effective rate per charge type in the picker.

---

## 10. Business Date Model

### 10.1 Problem

Hotels operate past midnight. A Night Audit triggered at 02:30 AM on July 2 must audit **July 1** (the business date still in progress), not July 2. Posting keys, `entry_date` values, and revenue recognition must all use the business date, not the real clock date.

### 10.2 Decision — BusinessDateService as Canonical Source (ADR-67)

All code that needs "today's date" for financial purposes calls `BusinessDateService::currentBusinessDate()` instead of `Carbon::today()` or `now()->toDateString()`.

```
business_date = floor_to_date(real_time − offset_hours)
```

With `business_date_offset_hours = 6` (default):
- 00:00 – 05:59 real time → business date = **yesterday**
- 06:00 – 23:59 real time → business date = **today**

### 10.3 Implementation

```
BusinessDateService
  __construct(HotelSettingsService $settings)

  currentBusinessDate(): Carbon
    → now()->subHours($settings->getInt('business_date_offset_hours', 6))
              ->startOfDay()

  businessDateFor(Carbon $realTime): Carbon
    → $realTime->subHours(offset)->startOfDay()
```

### 10.4 Impact on Each Subsystem

| Subsystem | Before | After |
|-----------|--------|-------|
| `NightAuditRun.audit_date` | `today()` | `BusinessDateService::currentBusinessDate()` |
| `ROOM_NIGHT_{stay_id}_{date}` posting key | `today()` | business date |
| `FolioEntry.entry_date` (system entries) | `today()` | business date |
| `FolioEntry.entry_date` (manual entries) | staff-selected, default today | staff-selected, default **business date** |
| Rate resolution (`effective_from <= :date`) | calendar date | business date |
| Night Audit window check (P3) | compare real clock to window | compare **business date** advancement to expected |

### 10.5 Manual Entry Date Override

Manual entries allow staff to select `entry_date` freely (validated as `before_or_equal:today_calendar`). The default pre-fill changes from `Carbon::today()` to `BusinessDateService::currentBusinessDate()`. Staff may still select a past date. The `before_or_equal:today` validation applies to the **calendar** date (prevents true future entries).

### 10.6 Business Date and Posting Keys

`ROOM_NIGHT_{stay_id}_{YYYY-MM-DD}` uses the business date. A Night Audit triggered at 02:30 AM on July 2 for business date July 1 creates `ROOM_NIGHT_42_2026-07-01`, not `ROOM_NIGHT_42_2026-07-02`. This is the correct behaviour — the night being charged is July 1.

---

## 11. Hotel Settings

### 11.1 Purpose (ADR-68)

Eliminate all hardcoded configuration values from services. Every tunable parameter lives in `hotel_settings` and is read via `HotelSettingsService`.

### 11.2 Default Settings (seeded on first deploy)

| Key | Value | Type | Description |
|-----|-------|------|-------------|
| `business_date_offset_hours` | `6` | int | Hours after midnight at which business date advances |
| `night_audit_window_start` | `22:00` | time | Earliest time Night Audit may run (local) |
| `night_audit_window_end` | `06:00` | time | Latest time Night Audit may run (next morning) |
| `night_audit_require_sequential` | `true` | bool | Prior business date must be COMPLETED before running |
| `late_checkout_grace_minutes` | `30` | int | Minutes after planned checkout before fee triggers |
| `early_checkin_grace_minutes` | `30` | int | Minutes before planned check-in within which no fee is charged |
| `currency_code` | `VND` | string | ISO 4217 currency code |
| `currency_precision` | `0` | int | Decimal places for display (VND = 0) |

### 11.3 HotelSettingsService

```
HotelSettingsService
  get(key, default)      → mixed   (reads from cache, falls through to DB)
  getInt(key, default)   → int
  getBool(key, default)  → bool
  set(key, value, userId) → void   (writes to DB, invalidates cache)
  all()                  → array   (for admin settings page)
```

**Cache:** Settings are cached in Laravel's default cache for 10 minutes, invalidated on any `set()` call. This prevents a DB query on every service rate resolution or grace check. No distributed cache needed — settings change rarely.

### 11.4 Service Integration

All formerly-hardcoded values are now fetched from `HotelSettingsService`:

```
StayService::checkOut()
  grace = $settings->getInt('late_checkout_grace_minutes', 30)

NightAuditService
  window_start = $settings->get('night_audit_window_start', '22:00')
  require_seq  = $settings->getBool('night_audit_require_sequential', true)

BusinessDateService
  offset       = $settings->getInt('business_date_offset_hours', 6)
```

### 11.5 Permission

`hotel_settings.manage` — ADMIN only (not MANAGER). Settings affect the financial behaviour of the entire property and must be restricted to the highest privilege level.

### 11.6 Audit

Every `HotelSettings` update records `updated_by` and `updated_at`. No separate audit log entry required — the `updated_by` FK is sufficient for this low-frequency operation.

---

## 12. Service Charge Lifecycle

```
STAGE 1 — RATE LOOKUP (Manual path)
  Staff opens Add Charge form
  System loads active ServiceRates effective on current business date, grouped by ChargeCategory
  Staff selects a tile → unit_price, description, charge_type pre-filled
  Staff optionally selects stay (room attribution)
  Staff may override unit_price and quantity

STAGE 2 — CHARGE POSTING
  POST /admin/bookings/{booking}/folio/entries
  → StoreFolioEntryRequest: validates charge_type ≠ ROOM, stay_id scoped to booking
  → FolioService::addCharge(Folio, array $data): FolioEntry
      ├── Acquires Folio lockForUpdate
      ├── Validates: folio.status = OPEN (FolioClosedException / FolioVoidedException)
      ├── Validates: booking not terminal (BookingTerminalException — ADR-44)
      ├── Computes: amount = bcmul(quantity, unit_price, 2)  [ADR-16]
      └── Inserts FolioEntry with posting_source = MANUAL, posting_key = NULL, stay_id = ?

STAGE 3 — ACTIVE STATE
  FolioEntry.voided_at = NULL
  Included in FolioService::getFolioTotal() sum
  Displayed in FolioEntryTable with "Phòng" column showing stay attribution

STAGE 4A — VOID (staff action)
  PATCH /admin/bookings/{booking}/folio/entries/{entry}/void
  → FolioService::voidEntry()
      ├── ADR-50 guard: posting_key IS NOT NULL → SystemEntryVoidException
      ├── Folio must be OPEN (FolioClosedException)
      └── Sets voided_at, voided_by, void_reason

STAGE 4B — SYSTEM AUTO-CHARGE (PostingJob path)
  checkIn()  → RoomChargePostingJob->execute()    (first night)
             → EarlyCheckinFeePostingJob->execute() (if early)
  checkOut() → LateCheckoutFeePostingJob->execute() (if late)
  NightAuditPipeline → RoomChargePostingJob->execute() (night 2+)
  All post with posting_source = SYSTEM_AUTO or NIGHT_AUDIT, posting_key = NOT NULL
  Immutable — ADR-50 prevents void

STAGE 5 — FOLIO CLOSE (at final checkout)
  BookingService::finaliseBookingCheckout()
  → FolioService::autoCloseFolio()
  → All future addCharge() calls → FolioClosedException
```

---

## 13. Posting Rules

### Rule 1 — Server-Side Amount Computation (ADR-16, unchanged)
`amount = bcmul(quantity, unit_price, 2)`. HTTP requests supplying `amount` are ignored.

### Rule 2 — Folio Must Be Open (ADR-33, unchanged)
`addCharge()` and `voidEntry()` reject operations on CLOSED or VOIDED folios.

### Rule 3 — Booking Must Not Be Terminal (ADR-44, unchanged)
`addCharge()` rejects posting if `booking.status.isTerminal()` is true.

### Rule 4 — Per-Night Room Charges Replace Aggregate (ADR-58)
`FolioService::autoPostRoomCharge()` is deprecated. The check-in flow executes `RoomChargePostingJob` for the first night. Subsequent nights are posted by `NightAuditPipeline`.

### Rule 5 — Night Audit Date Window (ADR-59)
Night Audit for business date D posts for stays where:
- `status = CHECKED_IN`
- `actual_checkin_at::business_date < D`
- `actual_checkout_at IS NULL`
- `planned_checkout_at::business_date > D`
- No `ROOM_NIGHT_{stay_id}_{D}` entry exists
- The stay's folio is `OPEN`

**The checkout night is never charged by Night Audit.**

### Rule 6 — Legacy Aggregate Coexistence (ADR-62)
If a booking has an active `ROOM_CHARGE_{booking_id}_AGGREGATE` entry, Night Audit skips that booking entirely.

### Rule 7 — Negative Amounts Allowed
`FolioEntry.amount < 0` represents a credit or discount. No separate credit entity.

### Rule 8 — No Hard Delete (ADR-50, unchanged)
FolioEntry records are never hard-deleted.

### Rule 9 — ChargeType::Room Blocked from HTTP
`StoreFolioEntryRequest` rejects `charge_type = ROOM` with 422.

### Rule 10 — Business Date as Canonical Date (ADR-67)
All system-generated `FolioEntry.entry_date` values use `BusinessDateService::currentBusinessDate()`, not `Carbon::today()`.

---

## 14. Posting Keys

Posting keys serve dual purposes: (1) idempotency via UNIQUE constraint; (2) identity for auditing.

**All date components in posting keys use the business date, not the calendar date (ADR-67).**

### Key Namespace Registry

| Key Pattern | Introduced | Who Creates | Source | Voidable | Notes |
|-------------|-----------|-------------|--------|---------|-------|
| `ROOM_CHARGE_{booking_id}_AGGREGATE` | Phase 3.1A (legacy) | `autoPostRoomCharge()` | SYSTEM_AUTO | NEVER | Deprecated in 3.2; retained for pre-3.2 bookings |
| `ROOM_NIGHT_{stay_id}_{YYYY-MM-DD}` | Phase 3.2 | `RoomChargePostingJob` | SYSTEM_AUTO / NIGHT_AUDIT | NEVER | Date = **business date** |
| `LATE_CHECKOUT_{stay_id}` | Phase 3.2 | `LateCheckoutFeePostingJob` | SYSTEM_AUTO | NEVER | No date component — per-stay, one-time |
| `EARLY_CHECKIN_{stay_id}` | Phase 3.2 | `EarlyCheckinFeePostingJob` | SYSTEM_AUTO | NEVER | No date component — per-stay, one-time |

**Invariants:**
- Posting keys are immutable after creation.
- The system never reads or accepts `posting_key` from an HTTP request (ADR-13).
- Manual entries always have `posting_key = NULL`.

### Legacy Key Coexistence
Existing aggregate entries remain valid until Phase 3.2.8 backfill.

---

## 15. Tax Strategy

### Current State
No tax is modeled. All `folio_entries` amounts are pre-tax.

### Phase 3.2 Decision: Tax-Exclusive Posting with Future Slot
All `FolioEntry.amount` values remain tax-exclusive in Phase 3.2. The balance formula is unaffected.

**Deliverables in Phase 3.2:**
- `service_rates.tax_rate` column (DECIMAL 5,4, default 0.0000) — stored, not applied.
- `hotel_settings`: `currency_code` and `currency_precision` keys defined.

**Phase 3.5 (Out of Scope):**
- `folio_entries` gains a `tax_amount` column.
- Balance formula: `balance_due = (folio_total + total_tax) − paid_total`.
- `TaxCalculationService` reads `service_rates.tax_rate` and hotel tax config.

---

## 16. Service Pricing Strategy

### 16.1 Catalog Pricing (Default Price, Versioned)
`ServiceRateService::resolveFor(ChargeType, Carbon $businessDate)` returns the effective `ServiceRate` for the given charge type and business date. The resolved `unit_price` pre-fills the charge form. Staff may override per transaction (ADR-57).

### 16.2 Price Capture at Posting
The price posted is captured in `FolioEntry.unit_price` at the moment of posting. Subsequent rate versions do not retroactively affect existing entries (ADR-57, ADR-66).

### 16.3 Room Rate Pricing
Nightly room charge rate is read from `booking_requirements.room_price` (the contractual rate set at booking time). Not from `service_rates`. This rate is immutable for the booking and does not participate in service rate versioning.

### 16.4 Stay-to-Requirement Matching (Multi-Room Bookings)

**FIFO algorithm for duplicate room types:**
```
requirements = booking.bookingRequirements
    WHERE room_type_id = stay.roomAssignment.room_type_id
    ORDER BY id ASC

already_posted_for_type = folio_entries
    WHERE posting_key LIKE 'ROOM_NIGHT_{stay_X}_{D}'
    JOIN stays ON stays.id = folio_entries.stay_id
    WHERE stays.roomAssignment.room_type_id = same_type

remaining = requirements minus already_matched (FIFO)
rate = remaining.first().room_price ?? requirements.first().room_price
```

**Known limitation:** If two TWIN rooms have different requirement prices, FIFO may assign the wrong rate. Phase 3.4 adds `requirement_id` on `stays`. Night Audit logs a warning when multiple requirements of the same type have different prices.

### 16.5 Late/Early Fee Pricing
```
Rate = ServiceRateService::resolveFor(ChargeType::LateCheckout, $businessDate)
```
If no effective rate exists on `$businessDate`, the auto-charge is silently skipped. Staff may post manually.

Late checkout fee amount:
```
minutes_late = actual_checkout_at - planned_checkout_at (in minutes)
grace        = HotelSettingsService::getInt('late_checkout_grace_minutes', 30)
if minutes_late > grace:
    hours_late = ceil(minutes_late / 60)
    amount = bcmul(hours_late, rate.unit_price, 2)
```

---

## 17. Room-Linked vs Booking-Linked Charges

| Attribute | Room-Linked (stay_id set) | Booking-Linked (stay_id null) |
|-----------|--------------------------|-------------------------------|
| Attribution | Specific room/stay | Entire booking |
| Use cases | Nightly room charge, minibar, laundry (room-specific), late checkout | Admin fees, discounts, restaurant charge (not room-specific) |
| Quick-charge UI | "Phòng" dropdown pre-selected (single-stay) or required (multi-stay) | "Chung" default |
| Night Audit | Always room-linked | Never posted by Night Audit |
| Future split billing | Required for per-room folio split | Stays on master folio |
| Balance formula | No distinction — all active entries summed | Same |

---

## 18. Manual vs Automatic Charges

| Attribute | Manual | System Auto | Night Audit |
|-----------|--------|-------------|-------------|
| `posting_source` | `MANUAL` | `SYSTEM_AUTO` | `NIGHT_AUDIT` |
| `posting_key` | NULL | NOT NULL | NOT NULL |
| Voidable | YES (authorized user) | NEVER (ADR-50) | NEVER (ADR-50) |
| `posted_by` | Auth::id() | NULL | NULL |
| Amount origin | Staff-entered (server recomputes) | Fully system-computed | Fully system-computed |
| Retry behavior | UI prevents double-submit | Idempotent (posting_key UNIQUE) | Idempotent (posting_key UNIQUE) |
| Audit trail | AuditLog observer | AuditLog observer | NightAuditBookingLog + AuditLog |
| Mechanism | `FolioService::addCharge()` | `PostingJob::execute()` | `NightAuditPipeline::executeForStay()` |

---

## 19. Night Audit Foundation — Pipeline Design

### 19.1 Design: Generic Posting Pipeline (ADR-69)

The Night Audit is redesigned as a pipeline that discovers and executes registered `PostingJob` implementations. In Phase 3.2, one job is registered: `RoomChargePostingJob`. In Phase 3.3, additional jobs (Breakfast, ResortFee, CityTax) are registered without any changes to the pipeline infrastructure.

```
NightAuditPipeline
  ├── register(PostingJob): void
  ├── resolveExecutionOrder(): PostingJob[]  — topological sort by dependsOn()
  ├── executeForStay(Stay, NightAuditRun): StayPipelineResult
  └── executeRun(NightAuditRun): RunResult

Phase 3.2 registered jobs:
  RoomChargePostingJob   (jobId: 'room_charge', dependsOn: [], isNightAuditStep: true)

Phase 3.3+ jobs (future — registered without pipeline changes):
  BreakfastPostingJob    (jobId: 'breakfast',   dependsOn: ['room_charge'], isNightAuditStep: true)
  ResortFeePostingJob    (jobId: 'resort_fee',  dependsOn: [],              isNightAuditStep: true)
  CityTaxPostingJob      (jobId: 'city_tax',    dependsOn: ['room_charge'], isNightAuditStep: true)
```

### 19.2 Entry Point

`php artisan audit:night-audit {date?}` — runs the Night Audit for the given business date. Defaults to `BusinessDateService::currentBusinessDate()`.

Optional flags:
- `--force` — bypass time-window (P3) and sequential-mode (P2) checks
- `--dry-run` — compute and log what would be posted; no DB writes
- `--booking-id=` — run for a single booking (diagnostic use)

### 19.3 Prerequisites

**P1 — No completed/running run for this business date** (never bypassed)
`NightAuditRun WHERE audit_date = $businessDate AND status IN ('COMPLETED', 'RUNNING')` must be empty.

**P2 — Sequential mode: prior business date must be COMPLETED** (bypassed by `--force`)
If `hotel_settings.night_audit_require_sequential = true`.

**P3 — Audit window** (bypassed by `--force`)
Real time must be within `[hotel_settings.night_audit_window_start, hotel_settings.night_audit_window_end]`.

**P4 — No RUNNING audit in progress** (never bypassed)
Stuck runs auto-reset after 30 minutes by `audit:cleanup-stale-runs`.

### 19.4 Processing Flow

```
1. businessDate = BusinessDateService::currentBusinessDate() (or --date flag)
2. Validate prerequisites P1–P4 → abort if any fail
3. Upsert NightAuditRun (audit_date = businessDate, status = RUNNING, started_at = now())
4. Resolve pipeline: jobs = NightAuditPipeline::resolveExecutionOrder()
   → topological sort of isNightAuditStep=true jobs by dependsOn()
   → throw PipelineCycleException if cycle detected
5. Query eligible stays:
       stays WHERE status = CHECKED_IN
              AND BusinessDate(actual_checkin_at) < businessDate
              AND actual_checkout_at IS NULL
              AND BusinessDate(planned_checkout_at) > businessDate
6. FOR EACH stay (sequential):
       result = NightAuditPipeline::executeForStay(stay, run)
       Upsert NightAuditBookingLog from result
7. UPDATE NightAuditRun: status = COMPLETED/FAILED, completed_at, entries_posted, errors_count
```

### 19.5 Per-Stay Pipeline Execution

```
NightAuditPipeline::executeForStay(Stay $stay, NightAuditRun $run):

  context = new PostingContext(
      booking      = stay.booking (eager loaded),
      folio        = stay.booking.folio (eager loaded),
      stay         = stay,
      businessDate = run.audit_date (as Carbon),
      run          = run,
  )

  FOR EACH job in ordered_jobs:
      IF NOT job->shouldProcess(context):
          log job SKIPPED (shouldProcess=false)
          IF job->isRequired(): abort stay → FAILED
          ELSE: continue to next job

      IF job->isAlreadyPosted(context):
          log job SKIPPED (already_posted)
          continue

      BEGIN TRANSACTION
        (locks acquired inside job->execute())
        result = job->execute(context)
      COMMIT (or ROLLBACK on exception)

      IF result.success:
          log job SUCCESS, entries_created = result.entriesCreated
      ELSE:
          log job FAILED (result.message)
          IF job->isRequired(): abort stay → FAILED
          ELSE: continue

  return StayPipelineResult (aggregated status)
```

### 19.6 Dependency Ordering Example

Phase 3.3 scenario (CityTax depends on RoomCharge succeeding first):

```
Registered jobs:
  RoomChargePostingJob   dependsOn: []
  CityTaxPostingJob      dependsOn: ['room_charge']
  BreakfastPostingJob    dependsOn: ['room_charge']
  ResortFeePostingJob    dependsOn: []

Resolved order (topological):
  [RoomChargePostingJob, ResortFeePostingJob, BreakfastPostingJob, CityTaxPostingJob]
  (ResortFee and RoomCharge can swap — both have no dependencies)
```

If `RoomChargePostingJob` is marked `isRequired = true` and fails, the stay is marked FAILED and dependent jobs (CityTax, Breakfast) are not attempted.

### 19.7 Audit Invariants

**I1 — Completeness:** Every eligible in-house stay has exactly one active `ROOM_NIGHT_{stay_id}_{businessDate}` entry after a COMPLETED run.

**I2 — No Duplication:** `posting_key` UNIQUE constraint enforces at DB level. `lockForUpdate` prevents phantom inserts.

**I3 — Amount Integrity:** Night entry amount = `bcmul(1, requirement.room_price, 2)`. No externally supplied amounts.

**I4 — Folio Safety:** Pipeline never posts to a CLOSED or VOIDED folio.

**I5 — Per-Booking Atomicity (ADR-64):** Each booking's processing is an independent transaction.

**I6 — Audit Date Immutability (ADR-65):** `NightAuditRun.audit_date` cannot change after creation.

**I7 — Business Date Consistency (ADR-67):** `audit_date` = business date, `entry_date` on created entries = business date.

---

## 20. PostingJob Abstraction

### 20.1 Interface Contract (ADR-70)

Every system-generated charge — whether lifecycle-triggered or pipeline-executed — is modelled as a `PostingJob`. This unifies the charge posting contract and makes all posting logic independently testable.

```php
interface PostingJob
{
    // Unique identifier used for dependency resolution and logging
    public function jobId(): string;

    // Human-readable name for logs and Night Audit dashboard
    public function displayName(): string;

    // IDs of jobs that must succeed before this one runs (pipeline only)
    public function dependsOn(): array;

    // true = NightAuditPipeline includes this job every night
    // false = lifecycle-only (checkIn/checkOut triggers directly)
    public function isNightAuditStep(): bool;

    // true = if this job fails, abort the entire stay's pipeline
    public function isRequired(): bool;

    // Guard: should this job even attempt for this context?
    // (e.g., EarlyCheckinFeeJob returns false if check-in is on time)
    public function shouldProcess(PostingContext $context): bool;

    // Idempotency check — true if charge already posted
    public function isAlreadyPosted(PostingContext $context): bool;

    // Post the charge; must be called inside a DB transaction
    // Acquires Folio lockForUpdate internally
    public function execute(PostingContext $context): PostingResult;

    // Void the charge created by this job for this context
    // Used by the rollback command (audit:void-night-audit-entries)
    public function rollback(PostingContext $context): void;
}
```

### 20.2 PostingContext

```php
final class PostingContext
{
    public function __construct(
        public readonly Booking $booking,
        public readonly Folio $folio,
        public readonly Stay $stay,
        public readonly Carbon $businessDate,
        public readonly ?NightAuditRun $run = null,
    ) {}
}
```

`run` is `null` for lifecycle-triggered jobs (check-in, check-out). Present for Night Audit pipeline jobs.

### 20.3 PostingResult

```php
final class PostingResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,    // 'POSTED' | 'SKIPPED' | 'FAILED'
        public readonly string $message,
        public readonly int $entriesCreated = 0,
    ) {}
}
```

### 20.4 Phase 3.2 Concrete Implementations

| Class | jobId | isNightAuditStep | isRequired | dependsOn |
|-------|-------|-----------------|------------|-----------|
| `RoomChargePostingJob` | `room_charge` | true | true | `[]` |
| `LateCheckoutFeePostingJob` | `late_checkout_fee` | false | false | `[]` |
| `EarlyCheckinFeePostingJob` | `early_checkin_fee` | false | false | `[]` |

### 20.5 Lifecycle vs Pipeline Execution

Lifecycle jobs are called directly by `StayService`, not through the pipeline:

```
checkIn():
  RoomChargePostingJob->execute(context)      // first night
  EarlyCheckinFeePostingJob->execute(context) // if early

checkOut():
  LateCheckoutFeePostingJob->execute(context) // if late
```

`RoomChargePostingJob` serves dual duty: it is called at check-in (for the first night) and by the Night Audit pipeline (for subsequent nights). The same `isAlreadyPosted()` check (`ROOM_NIGHT_{stay_id}_{businessDate}` exists?) handles both paths idempotently.

### 20.6 Rollback Contract

`rollback(PostingContext $context)` voids the specific charge this job created for the given context. It is called by `audit:void-night-audit-entries`, never by the pipeline itself. Each job only knows how to roll back its own entries — the command determines which jobs to invoke.

### 20.7 Extension Mechanism

To add a new posting step (Phase 3.3+):
1. Implement `PostingJob` with the new `jobId` and appropriate `dependsOn`.
2. Register via `NightAuditPipeline::register()` in the service provider.
3. No changes to `NightAuditPipeline`, `NightAuditService`, or any existing job.

The pipeline discovery ensures the new job runs automatically for all in-house stays on the next audit night.

---

## 21. Idempotency Strategy

### 21.1 Per-Night Room Charge (`RoomChargePostingJob`)

```php
// Inside PostingJob::isAlreadyPosted() — called before acquiring any lock
$exists = FolioEntry::where('posting_key', "ROOM_NIGHT_{$stay->id}_{$businessDate}")
    ->exists();

// Inside PostingJob::execute() — inside DB::transaction with Folio lock held
$exists = FolioEntry::where('posting_key', "ROOM_NIGHT_{$stay->id}_{$businessDate}")
    ->lockForUpdate()
    ->exists();

if ($exists) {
    return new PostingResult(true, 'SKIPPED', 'Already posted');
}
```

`lockForUpdate` acquires a next-key (gap) lock in InnoDB, preventing a phantom insert from a concurrent call (§22.4).

### 21.2 Late Checkout / Early Check-in Fee

```php
// Inside transaction with Folio lock
$exists = FolioEntry::where('posting_key', "LATE_CHECKOUT_{$stay->id}")->lockForUpdate()->exists();
```

Secondary guard: `actual_checkout_at !== null` in `StayService::checkOut()`.

### 21.3 Night Audit Run
`UNIQUE KEY uq_night_audit_runs_date` prevents two run records for the same business date. A FAILED run is retried by updating the existing record's status, not inserting a new one.

### 21.4 Check-In First-Night Charge
Same pattern as §21.1. Secondary guard: `actual_checkin_at !== null` in `StayService::checkIn()`.

### 21.5 Two-Layer Defense Summary

| Layer | Mechanism | Handles |
|-------|-----------|---------|
| Application | `isAlreadyPosted()` pre-check + `lockForUpdate` inside transaction | Concurrent same-process calls |
| Database | `posting_key UNIQUE` constraint | Any race not caught at application layer |

---

## 22. Concurrency Strategy

### 22.1 Canonical Lock Order (ADR-12, extended)

```
Booking → Folio → FolioEntry (key check)
Booking → Stay  → RoomAssignment
```

Night Audit acquires: `Booking (lockForUpdate) → Folio (lockForUpdate) → FolioEntry (lockForUpdate on key check)` — inside `PostingJob::execute()`.

Night Audit never locks a Stay record directly. Stay status transitions (checkIn, checkOut) acquire Booking lock first, serialising them with Night Audit naturally.

### 22.2 Night Audit vs Checkout Race

**Scenario A:** Night Audit holds Booking lock → `checkOut()` waits → Night Audit commits → `checkOut()` proceeds. Correct.

**Scenario B:** `checkOut()` completes first → `actual_checkout_at` set → Night Audit query excludes stay (not null). No duplicate charge.

### 22.3 Concurrent Manual Charge + Night Audit

Both acquire Folio lock and serialise. No conflict: manual entry has `posting_key = NULL`; Night Audit entry has `posting_key = 'ROOM_NIGHT_...'`.

### 22.4 InnoDB Gap Locks

`SELECT ... FOR UPDATE` on a missing `posting_key` value acquires a next-key lock (gap lock), preventing phantom inserts. MySQL InnoDB handles this correctly.

### 22.5 Night Audit Parallelism

Phase 3.2 processes stays sequentially. For 50 rooms: ~2.5s. For 100 rooms: ~5s. Acceptable for a nightly background job. Parallel fan-out deferred to Phase 3.6.

### 22.6 Concurrent Night Audit Runs

Two simultaneous start attempts: one INSERT succeeds; the other hits `UNIQUE KEY uq_night_audit_runs_date` and aborts. No partial state.

### 22.7 Pipeline Step Concurrency

Each step within a stay runs sequentially (steps within a booking are not parallelised). Steps across different stays are independent — each stay's steps run within their own transaction.

---

## 23. Transaction Boundaries

### 23.1 Check-In Transaction (modified)

```
BEGIN TRANSACTION
  LOCK Booking FOR UPDATE
  LOCK Stay FOR UPDATE
  LOCK RoomAssignment FOR UPDATE

  Validate: assignment.status = ASSIGNED
  Validate: stay.actual_checkin_at IS NULL

  UPDATE stays SET status = CHECKED_IN, actual_checkin_at = now()
  UPDATE room_assignments SET status = CHECKED_IN

  -- Phase 3.2: post first-night charge via PostingJob
  context = PostingContext(booking, folio, stay, businessDate=BusinessDateService::currentBusinessDate())
  RoomChargePostingJob->execute(context)  -- acquires Folio lock internally

  -- Early check-in fee (if applicable)
  EarlyCheckinFeePostingJob->execute(context)  -- no-op if within grace

  UPDATE bookings (status recalculation)
COMMIT
```

**Note:** `PostingJob::execute()` acquires `Folio lockForUpdate` internally, inside this outer transaction. Lock order is maintained: Booking is locked before Folio.

### 23.2 Checkout Transaction (modified)

```
BEGIN TRANSACTION
  LOCK Booking FOR UPDATE
  LOCK Stay FOR UPDATE
  LOCK RoomAssignment FOR UPDATE

  Validate: assignment.status = CHECKED_IN
  Validate: stay.actual_checkout_at IS NULL
  Validate: confirmed = true if last stay (ADR-55)

  UPDATE stays SET status = CHECKED_OUT, actual_checkout_at = now()
  UPDATE room_assignments SET status = CHECKED_OUT

  context = PostingContext(booking, folio, stay, businessDate=BusinessDateService::currentBusinessDate())
  LateCheckoutFeePostingJob->execute(context)  -- no-op if within grace

  IF no more active stays:
      finaliseBookingCheckout()  -- OBE check → auto-close folio
  ELSE:
      updateBookingStayStatus()
COMMIT
```

### 23.3 Night Audit Transaction (per booking, inside pipeline)

```
FOR EACH stay:

  context = PostingContext(booking, folio, stay, businessDate, run)

  FOR EACH job in pipeline:
    IF job->isAlreadyPosted(context): log SKIPPED; continue

    BEGIN TRANSACTION
      result = job->execute(context)
      -- (execute acquires Folio lockForUpdate, checks idempotency again, inserts)
    COMMIT  (or ROLLBACK on exception)

    IF failure AND job->isRequired():
        log stay FAILED; break job loop
```

### 23.4 AddCharge Transaction (unchanged structure, `stay_id` now accepted)

```
BEGIN TRANSACTION
  LOCK Folio FOR UPDATE
  Validate: folio.status = OPEN
  Validate: booking not terminal
  Compute: amount = bcmul(quantity, unit_price, 2)
  INSERT folio_entries (posting_source='MANUAL', posting_key=NULL, stay_id=?,
                        entry_date=BusinessDateService::currentBusinessDate())
COMMIT
```

---

## 24. Rollback Strategy

### 24.1 Rollback Triggers

Consider rollback if:
- Night Audit posts incorrect amounts for > 5% of in-house stays.
- Balance formula produces wrong results after per-night posting.
- Backfill command corrupts folio totals.
- `addCharge()` transaction deadlocks under real concurrent load.

### 24.2 Database Rollback

All migrations have `down()` methods. All changes are additive. Rollback drops new tables and columns cleanly. No existing data is modified.

**Warning:** Rolling back `add_stay_id_to_folio_entries` after per-night charges have populated `stay_id` drops attribution data. Amounts preserved; attribution lost.

**Critical (ADR-62):** Do NOT roll back Phase 3.2.8 independently. Roll back atomically with Phase 3.2.4.

### 24.3 Night Audit Entry Rollback

```
php artisan audit:void-night-audit-entries --run-id={id} [--stay-id={id}] --reason="..."
```

Calls `PostingJob::rollback()` for each affected job and stay, voiding all entries created in that run. Sets `night_audit_runs.status = FAILED`. Date can then be re-run with `--force`.

### 24.4 Backfill Rollback

`folio:backfill-per-night-charges` processes bookings in individual transactions. Failure mid-run leaves unconverted bookings with aggregate entries intact. Re-running is idempotent — already-converted bookings are skipped.

---

## 25. Failure Recovery

### 25.1 Night Audit Partial Failure (ADR-61)

If a job fails for one booking, Night Audit continues to the next booking. Run completes with `errors_count > 0`. Operations staff review `NightAuditBookingLog` and either retry with `--booking-id=` or post manually.

### 25.2 Stuck RUNNING Run

`audit:cleanup-stale-runs` (scheduled every 15 minutes) resets runs where `status = RUNNING AND started_at < now() - 30 minutes` to `FAILED`.

### 25.3 Service Rate Unavailable

If `ServiceRateService::resolveFor(ChargeType::LateCheckout, $businessDate)` returns null, the fee is silently skipped. `Log::warning()` emitted. Checkout proceeds. Staff may post manually.

### 25.4 Check-In Folio Failure

If `RoomChargePostingJob::execute()` fails (e.g., folio closed), the entire check-in transaction rolls back. Stay remains `Reserved`. Flash error returned to UI.

### 25.5 HotelSettings Cache Miss

If the settings cache is cold (restart, cache flush), `HotelSettingsService` falls through to a DB query with the configured default. The default values are safe and conservative. No downtime risk.

### 25.6 Pipeline Cycle Detection

If a `PostingJob` dependency graph contains a cycle (Phase 3.3+ misconfiguration), `NightAuditPipeline::resolveExecutionOrder()` throws `PipelineCycleException` before any posting occurs. The run fails at prerequisite check with a clear error message naming the cycle.

---

## 26. Audit Strategy

### 26.1 FolioEntry Immutability

Every `FolioEntry` is write-once. The only mutation is `voided_at`, `voided_by`, `void_reason`. `posting_key`, `amount`, `charge_type`, `stay_id`, `posting_source`, `entry_date` are immutable after creation.

### 26.2 Existing AuditLog Observer

Captures `created` and `updated` events on `FolioEntry`. No changes needed — new columns are automatically included in `new_data`.

### 26.3 Night Audit Log

`NightAuditBookingLog` provides per-booking audit trail per run. `NightAuditRun` provides run-level summary.

### 26.4 Void Trail

Existing void audit on `FolioEntry` (`voided_at`, `voided_by`, `void_reason`) is unchanged.

### 26.5 Balance Audit

`BookingService::paymentSummary()` formula is deterministic. Transition guard retained until Phase 3.2.8.

### 26.6 Hotel Settings Audit

`hotel_settings.updated_by` and `updated_at` record every settings change. Low frequency, no separate AuditLog needed.

---

## 27. Permission Rules

### 27.1 Existing Permissions (unchanged)

| Permission | ADMIN | MANAGER | RECEPTION | ACCOUNTANT | SALES | HOUSEKEEPING |
|-----------|-------|---------|-----------|------------|-------|--------------|
| `folio.view` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| `folio.close` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `charge.create` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `charge.void` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |

### 27.2 New Permissions (Phase 3.2)

| Permission | ADMIN | MANAGER | RECEPTION | ACCOUNTANT | SALES | HOUSEKEEPING |
|-----------|-------|---------|-----------|------------|-------|--------------|
| `service_rates.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `night_audit.run` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `night_audit.view` | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| `hotel_settings.manage` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

### 27.3 System Charge Permission Bypass

Night Audit posts charges as a system process. Artisan command validates the `--user-id` flag against `night_audit.run` when provided. When scheduled, no user validation is applied.

### 27.4 ServiceRatePolicy

```php
viewAny(User $user): $user->can('service_rates.manage')
create(User $user): $user->can('service_rates.manage')
update(User $user, ServiceRate $rate): $user->can('service_rates.manage')
delete(User $user, ServiceRate $rate): false  // no hard delete
toggleActive(User $user, ServiceRate $rate): $user->can('service_rates.manage')
```

### 27.5 ChargeType::Room Reserved for System

`StoreFolioEntryRequest` rejects `charge_type = 'ROOM'` with 422. Prevents contamination of the per-night audit trail.

---

## 28. Validation Rules

### 28.1 StoreFolioEntryRequest (modified)

```php
[
    'charge_type' => ['required', Rule::enum(ChargeType::class),
                      Rule::notIn([ChargeType::Room->value])],
    'description' => ['required', 'string', 'max:255'],
    'quantity'    => ['required', 'numeric', 'min:0.01', 'max:9999.99'],
    'unit_price'  => ['required', 'numeric', 'min:0', 'max:99999999.99'],
    'entry_date'  => ['required', 'date', 'before_or_equal:today'],
    'stay_id'     => ['nullable', 'integer',
                      Rule::exists('stays', 'id')
                          ->where('booking_id', $this->booking->id)],
]
```

`entry_date` `before_or_equal:today` uses the calendar date (prevents truly future entries). The default pre-fill is the business date. Staff may select past calendar dates.

### 28.2 StoreServiceRateRequest

```php
[
    'name'             => ['required', 'string', 'max:100'],
    'charge_type'      => ['required', Rule::enum(ChargeType::class),
                           Rule::notIn([ChargeType::Room->value])],
    'unit_price'       => ['required', 'numeric', 'min:0', 'max:99999999.99'],
    'effective_from'   => ['required', 'date'],
    'unit_label'       => ['required', 'string', 'max:30'],
    'tax_rate'         => ['nullable', 'numeric', 'min:0', 'max:1'],
    'gl_account_code'  => ['nullable', 'string', 'max:50'],
    'display_order'    => ['nullable', 'integer', 'min:0'],
]
```

### 28.3 UpdateHotelSettingsRequest

```php
[
    'settings'          => ['required', 'array'],
    'settings.*.key'    => ['required', 'string', Rule::exists('hotel_settings', 'key')],
    'settings.*.value'  => ['required'],
]
```

Individual key validation is performed in `HotelSettingsService::set()` based on `value_type`.

### 28.4 NightAuditRunRequest (manual trigger)

```php
[
    'audit_date' => ['required', 'date', 'before_or_equal:today'],
    'force'      => ['nullable', 'boolean'],
]
```

### 28.5 Late/Early Fee Calculation Guard

```
IF planned_checkout_at IS NULL → skip fee silently
IF actual_checkout_at IS NULL  → not applicable
IF minutes ≤ hotel_settings.late_checkout_grace_minutes → within grace; no fee
IF no effective LATE_CHECKOUT ServiceRate on businessDate → skip silently; log warning
```

---

## 29. API Design

### 29.1 New Routes

```
# Service Rate Catalog
GET    /admin/service-rates               → ServiceRateController@index
POST   /admin/service-rates               → ServiceRateController@store
PATCH  /admin/service-rates/{rate}        → ServiceRateController@update
PATCH  /admin/service-rates/{rate}/toggle → ServiceRateController@toggleActive

# Night Audit
GET    /admin/night-audit                 → NightAuditController@index
POST   /admin/night-audit/run             → NightAuditController@run
GET    /admin/night-audit/{run}           → NightAuditController@show

# Hotel Settings
GET    /admin/settings                    → HotelSettingsController@index
PATCH  /admin/settings                    → HotelSettingsController@update
```

### 29.2 Modified Route

`POST /admin/bookings/{booking}/folio/entries` — URL unchanged. Request body gains optional `stay_id` and uses business date as default `entry_date`.

### 29.3 Key Request/Response Shapes

#### POST `/admin/service-rates`
```json
{
  "name": "Giường phụ",
  "charge_type": "EXTRA_BED",
  "unit_price": 300000,
  "effective_from": "2026-07-01",
  "unit_label": "đêm",
  "tax_rate": 0,
  "gl_account_code": null,
  "display_order": 10
}
```

#### PATCH `/admin/settings`
```json
{
  "settings": [
    { "key": "late_checkout_grace_minutes", "value": "30" },
    { "key": "business_date_offset_hours",  "value": "6"  }
  ]
}
```

#### BookingController@show — extended payload

```json
"options": {
  "serviceRates": {
    "EXTRA_BED": [{ "id": 1, "name": "Giường phụ", "unit_price": 300000, "unit_label": "đêm" }]
  },
  "checkableStays": [{ "id": 5, "room_number": "301", "room_type_name": "TWIN" }],
  "currentBusinessDate": "2026-07-01"
}
```

### 29.4 Error Responses

| Scenario | HTTP Status | Error key |
|---------|------------|-----------|
| `charge_type = ROOM` from HTTP | 422 | `charge_type` |
| `stay_id` belongs to different booking | 422 | `stay_id` |
| Folio is CLOSED | 403 | flash error |
| Booking is terminal | 403 | flash error |
| Night Audit prerequisite fails | 422 | flash error with reason |
| Night Audit date in future | 422 | `audit_date` |
| Settings key does not exist | 422 | `settings.*.key` |

---

## 30. UI Impact

### 30.1 New Page: Service Rate Admin

**Route:** `/admin/service-rates`
**File:** `resources/js/Pages/Admin/ServiceRates/Index.vue`

Features: rates grouped by `ChargeCategory`, per-rate name/price/unit_label/toggle. "Thêm dịch vụ" creates a new row with `effective_from`. No delete button. ADMIN and MANAGER only.

### 30.2 Modified: AddChargeForm.vue

1. **Catalog picker** — tiles from `options.serviceRates` effective on `options.currentBusinessDate`.
2. **"Phòng" dropdown** (`stay_id`) — visible when `options.checkableStays.length > 0`.
3. **`entry_date` default** — pre-filled from `options.currentBusinessDate` (business date).
4. **`charge_type = ROOM` removed** from manual dropdown.

### 30.3 Modified: FolioEntryTable.vue

1. **"Phòng" column** — shows `entry.stay_room_number ?? '—'`.
2. **System badge** — unchanged; `posting_key IS NOT NULL` is the criterion.

### 30.4 New Page: Night Audit Dashboard

**Route:** `/admin/night-audit` — list of `NightAuditRun` records with status badges. "Chạy kiểm toán đêm" opens date picker → POST. Click a run → detail view with per-booking `NightAuditBookingLog` table.

### 30.5 New Page: Hotel Settings

**Route:** `/admin/settings`
**File:** `resources/js/Pages/Admin/Settings/Index.vue`

Features: form fields for each `hotel_settings` key, grouped by category (Night Audit, Fees, General). ADMIN only. Shows current value, description, last-updated-by and timestamp.

### 30.6 Flash Notification for Auto-Fees

Existing flash bar used for auto-fee notifications:
```
"Đã tự động tính phí trả phòng muộn 2 giờ (400,000 đ)."
"Đã tự động tính phí nhận phòng sớm (200,000 đ)."
```

---

## 31. Security Considerations

### 31.1 IDOR on stay_id
`StoreFolioEntryRequest` scopes `stay_id` exists check to `booking_id = $this->booking->id`. Cross-booking attribution fails with 422.

### 31.2 Manual Room Charge Injection
`StoreFolioEntryRequest` rejects `charge_type = ROOM` with 422. Excluded from UI picker. Double-layer defense.

### 31.3 Night Audit Command Privilege
Command validates `--user-id` against `night_audit.run` permission when provided. SSH access restricted to ADMIN users — operational control.

### 31.4 Mass Assignment on ServiceRate and HotelSettings
Both models use explicit `$fillable`. Controllers call `->update($request->validated())` only.

### 31.5 Price Injection via Override
`unit_price` validated `min:0, max:99999999.99`. Values of 0 allowed (complimentary). AuditLog captures posting staff identity.

### 31.6 Night Audit Date Injection
`NightAuditRunRequest` validates `audit_date` as `before_or_equal:today` (calendar date). `--force` does not bypass this check.

### 31.7 posting_key Injection via HTTP
`StoreFolioEntryRequest` does not include `posting_key`. `FolioService::addCharge()` sets `posting_key = null` unconditionally. ADR-13 enforces at service layer.

### 31.8 HotelSettings Tampering
`hotel_settings.manage` restricted to ADMIN only. Every change records `updated_by`. Malicious settings changes (e.g., setting `business_date_offset_hours = 999`) would not produce valid charges — all posting guards (folio status, booking terminal status) still apply.

---

## 32. Performance Considerations

### 32.1 Night Audit Execution Time
50 rooms × 1 job each × ~50ms = ~2.5s per run. Acceptable. Alert if > 60s. Phase 3.3 with 3 jobs: ~7.5s (still sequential). Parallel fan-out at Phase 3.6 if > 100 rooms.

### 32.2 Service Rate Catalog Queries
Rate resolution: single indexed query on `(charge_type, is_active, effective_from)`. With 20–50 rates and 1–3 price versions each, the index lookup is O(log n). No caching in Phase 3.2.

If catalog exceeds 200 rows: cache grouped results with 5-minute TTL, invalidated on write.

### 32.3 HotelSettings Cache
`HotelSettingsService` caches all settings in Laravel's default cache with 10-minute TTL. On every Night Audit run (~3 lookups) and every checkout (~2 lookups): zero DB queries after first call. Cache invalidated on `set()`. On cold start: one `SELECT *` query, then cached. Performance impact: negligible.

### 32.4 Folio Entry Queries
`idx_folio_entries_stay_charge (stay_id, charge_type, voided_at)` optimises the idempotency check inside `PostingJob::isAlreadyPosted()` and `PostingJob::execute()`.

### 32.5 Pipeline Dependency Resolution
Topological sort is computed once per Night Audit run, not per stay. O(V + E) where V = number of registered jobs (≤10 in foreseeable future). Negligible overhead.

### 32.6 Night Audit Run Log Queries
365 runs × 200 bookings = 73,000 rows. Well within MySQL range with existing indexes.

### 32.7 Backfill Command
500 bookings × ~100ms = ~50s. Run during off-peak hours.

---

## 33. Migration Strategy

### 33.1 Migration Order (must run in sequence)

```
Step 1:  create_hotel_settings_table
Step 2:  create_service_rates_table           (includes effective_from, gl_account_code)
Step 3:  create_night_audit_runs_table
Step 4:  create_night_audit_booking_logs_table
Step 5:  add_stay_id_to_folio_entries_table
Step 6:  add_posting_source_to_folio_entries_table
```

All migrations are additive. No existing data is modified.

`hotel_settings` created first (Step 1) because `NightAuditService` and `BusinessDateService` depend on it. Steps 5 and 6 may be combined if independent rollback is not required.

### 33.2 Seeder Updates

```
HotelSettingsSeeder:    seeds all default settings (runs always, uses updateOrInsert)
RolePermissionSeeder:   add new permissions incl. hotel_settings.manage
ServiceRateSeeder:      dev/QA only; NOT production seeder
```

### 33.3 Backfill Command (Phase 3.2.8)

`php artisan folio:backfill-per-night-charges [--dry-run] [--booking-id=]`

Uses `BusinessDateService` to compute business dates for historical nights. All generated posting keys use the business-date convention.

**Algorithm:**
1. Find all active `ROOM_CHARGE_%_AGGREGATE` entries.
2. For each booking: resolve stays, calculate occupied nights using business date.
3. For each night: post `ROOM_NIGHT_{stay_id}_{business_date}` using `requirement.room_price`.
4. Void the aggregate entry.

**Idempotent:** Skips stays with existing per-night entries.

### 33.4 Deployment Sequence

```
1.  Deploy migrations
2.  Deploy application code
3.  Seed: HotelSettingsSeeder, RolePermissionSeeder
4.  ADMIN configures hotel settings via /admin/settings
5.  Hotel manager configures Service Rate Catalog via /admin/service-rates
6.  Test: POST a manual charge → verify stay_id, posting_source, entry_date = business date
7.  Test: Check in → verify ROOM_NIGHT_* key uses business date
8.  Run test Night Audit: php artisan audit:night-audit --dry-run
9.  Enable scheduled Night Audit (daily at 23:30 — within default window)
10. Run backfill dry-run: php artisan folio:backfill-per-night-charges --dry-run
11. Run backfill: php artisan folio:backfill-per-night-charges
12. Verify: SELECT COUNT(*) where aggregate + open folio = 0
13. Deploy transition guard removal (Phase 3.2.8)
```

---

## 34. Risk Assessment

### R1 — Double Charging During Aggregate → Per-Night Transition
**Probability:** Medium | **Impact:** Critical
**Mitigation:** Posting key UNIQUE prevents duplicates. ADR-62 skips aggregate bookings in Night Audit. Backfill runs before Night Audit is enabled.

### R2 — Night Audit Runs Against Wrong Business Date
**Probability:** Low | **Impact:** High
**Mitigation:** `audit_date` validated as `before_or_equal:today` (calendar). Business date derivation is deterministic from `business_date_offset_hours`. Date displayed in UI for confirmation before manual run.

### R3 — Late Checkout Fee on On-Time Guest
**Probability:** Low | **Impact:** High
**Mitigation:** `hotel_settings.late_checkout_grace_minutes` absorbs inaccuracies. Staff notified via flash. `LATE_CHECKOUT_{stay_id}` idempotency prevents re-posting after void.

### R4 — Room Charge Blocked from Manual Correction
**Mitigation:** Night Audit retry command. Admin may post as `OTHER`. Phase 3.3 may add admin override.

### R5 — Stuck RUNNING Status Blocks Next Run
**Probability:** Low | **Impact:** Medium
**Mitigation:** `audit:cleanup-stale-runs` (every 15 min).

### R6 — Stay-to-Requirement FIFO Mismatch
**Probability:** Medium | **Impact:** Medium
**Mitigation:** Log warning. Phase 3.4 adds `requirement_id` on `stays`.

### R7 — Transition Guard Removal Too Early
**Probability:** Low | **Impact:** Critical
**Mitigation:** Blocked until Step 12 verification query confirms zero aggregate+open folio rows.

### R8 — folio_entries Table Growth
**Probability:** Certain (long-term) | **Impact:** Low
**Mitigation:** Existing indexes. Archiving in Phase 3.6.

### R9 — Business Date Misconfiguration
**Probability:** Low | **Impact:** High
**Scenario:** `business_date_offset_hours` set incorrectly (e.g., 18 hours). Night Audit would post for the wrong date, creating posting keys inconsistent with the actual night.
**Mitigation:** `hotel_settings.manage` restricted to ADMIN only. Default is 6 (safe for most Vietnamese hotels). Admin settings page shows the effective "current business date" preview so operator can verify before saving. Value validated: `min:0, max:23`.

### R10 — Pipeline Dependency Cycle (Phase 3.3+ Extension)
**Probability:** Low | **Impact:** Medium
**Scenario:** Developer adds a new `PostingJob` with circular `dependsOn`.
**Mitigation:** `PipelineCycleException` thrown at `resolveExecutionOrder()` before any posting occurs. CI test: register all production jobs and assert no cycle. Test fails at code review, not production.

### R11 — HotelSettings Cache Staleness
**Probability:** Low | **Impact:** Low
**Scenario:** Settings changed during a Night Audit run; cached old values used for remaining bookings.
**Mitigation:** 10-minute TTL is negligible for settings that change at most a few times per year. Night Audit reads settings once at run start, not per booking, making intra-run changes irrelevant.

### R12 — Service Rate Versioning Query Performance Regression
**Probability:** Low | **Impact:** Low
**Scenario:** Rate catalog grows to hundreds of versions; `effective_from` range query slows.
**Mitigation:** Index on `(charge_type, is_active, effective_from)` makes this O(log n). With 50 distinct services × 10 versions = 500 rows: negligible. Cache if > 200 rows (§32.2).

---

## 35. Regression Analysis

### 35.1 Tests That Phase 3.2 Will Break

| Test | Reason | Required Action |
|------|---------|-----------------|
| Any test asserting `ROOM_CHARGE_{booking_id}_AGGREGATE` | Aggregate deprecated | Update to `ROOM_NIGHT_{stay_id}_{businessDate}` |
| `CheckoutIntegrationTest` balance tests | Per-night replaces aggregate | Update check-in fixture to use `RoomChargePostingJob` |
| Tests calling `FolioService::autoPostRoomCharge()` | Method deprecated | Update to `RoomChargePostingJob` |
| Tests checking exact `folio_entries` column set | New columns `stay_id`, `posting_source` | Update assertions |
| `CheckoutUiTest` balance | Per-night entry replaces aggregate | Check-in step must trigger per-night posting |
| Tests asserting `entry_date = today()` | System entries now use business date | Update to assert business date value |

### 35.2 Tests Unaffected

| Test | Reason |
|------|---------|
| `CheckoutConfirmationGateTest` (10 tests, Phase 3.1.2) | Gate logic is independent of charge model |
| `BookingEngineFoundationTest` | No folio dependency |
| `PaymentCrudTest` | No charge posting dependency |
| `FolioUiTest::test_non_system_entry_can_be_voided_normally` | `posting_key = NULL` path unchanged |
| `RoomAvailabilityCheckerTest` | No folio dependency (pre-existing failures, unrelated) |
| `DashboardTest` | No folio dependency (pre-existing failure, unrelated) |

### 35.3 ADR Compatibility

All ADRs from original design review (ADR-4 through ADR-56) remain active and unchanged. Phase 3.2 ADRs 57–65 are unchanged. New ADRs 66–72 add to — not conflict with — the existing registry.

| ADR | Impact from Architecture Review Changes |
|-----|-----------------------------------------|
| ADR-11 (transition guard) | Unchanged — retained until Phase 3.2.8 |
| ADR-12 (lock order) | Extended — PostingJob::execute() acquires Folio lock inside Booking transaction |
| ADR-27 (autoPostRoomCharge) | Deprecated — replaced by `RoomChargePostingJob` |
| ADR-42 (autoPostRoomCharge idempotent) | Deprecated — replaced by posting key idempotency in `PostingJob` |
| ADR-58 (per-night replaces aggregate) | Updated wording: now credits `RoomChargePostingJob`, not `doPostFirstNightCharge()` |
| ADR-60 (Night Audit only source) | Updated: "only `RoomChargePostingJob` invoked via pipeline or lifecycle" |
| ADR-65 (audit_date immutable) | Unchanged; `audit_date` is now explicitly the **business** date |

---

## 36. ADR Registry

### ADR-57: ServiceRate Is a Display Default, Not a Price Contract

**Decision:** `ServiceRate.unit_price` pre-fills the charge form but is not binding. Staff may override at posting time. No FK links `FolioEntry` to `ServiceRate`. The posted `unit_price` is captured on `FolioEntry` at creation and is authoritative.

**Rationale:** A catalog that enforces prices cannot handle VIP discounts, complimentary items, or overrides. The catalog reduces typing and ensures baseline consistency without removing operational flexibility.

**Consequence:** Rate versions do not retroactively affect historical entries.

---

### ADR-58: Per-Night Room Charges Replace Aggregate at Check-In

**Decision:** `FolioService::autoPostRoomCharge()` is deprecated. Check-in calls `RoomChargePostingJob::execute()` for the first night only. All subsequent nights are posted by `NightAuditPipeline`.

**Rationale:** The aggregate entry conflated revenue recognition with billing. Per-night entries provide accurate daily revenue recognition and are auditable per business date.

**Consequence:** Existing bookings with aggregate entries are unaffected until Phase 3.2.8 backfill.

---

### ADR-59: Night Billing Boundary — Checkout Night Is Not Charged

**Decision:** Night charges are posted for business dates D through D+(N-1). The checkout business date D+N is never posted by either the check-in trigger or Night Audit.

**Rationale:** Hotel industry convention is "nights stayed."

---

### ADR-60: Night Audit Pipeline Is the Only Subsequent-Night Room Charge Mechanism

**Decision:** Only `RoomChargePostingJob` invoked by either `StayService::checkIn()` (first night) or `NightAuditPipeline` (subsequent nights) may create `ROOM_NIGHT_*` entries.

---

### ADR-61: Night Audit Partial Failure — Individual Booking Errors Do Not Abort the Run

**Decision:** If a PostingJob fails for one booking, the pipeline continues to the next booking. Run completes with `errors_count > 0`.

---

### ADR-62: Legacy Aggregate Coexistence — Night Audit Skips Aggregate Bookings

**Decision:** Bookings with active `ROOM_CHARGE_%_AGGREGATE` entries are skipped by Night Audit (`skip_reason = 'AGGREGATE_ENTRY_PRESENT'`). Sunset after Phase 3.2.8 backfill.

---

### ADR-63: Transition Guard Removed Only After Full Per-Night Backfill

**Decision:** `calculateGuardedFolioTotal()` aggregate fallback retained until verification query returns 0.

---

### ADR-64: Per-Booking Transaction Atomicity in Night Audit

**Decision:** Each booking processed in its own `DB::transaction`. Lock order: Booking → Folio → FolioEntry (inside `PostingJob::execute()`).

---

### ADR-65: NightAuditRun.audit_date Is Immutable After Creation

**Decision:** `audit_date` is the business date and cannot be updated after creation.

---

### ADR-66: Service Rate Versioning via Temporal Rows

**Decision:** Price changes INSERT a new `service_rates` row with a new `effective_from` date. No UPDATE to `unit_price` on an existing row. The effective rate for a given charge type and business date is resolved by:
```sql
SELECT * FROM service_rates
WHERE charge_type = :type AND is_active = 1 AND effective_from <= :business_date
ORDER BY effective_from DESC, id DESC LIMIT 1
```

**Rationale:** Temporal rows on the same table have the lowest regression risk — one additive column, zero new tables, zero query structure changes for existing code that reads only the "current" rate. A separate `service_rate_versions` table would require FK joins throughout the service layer. Overwriting `unit_price` loses history permanently.

**Backward compatibility:** Existing rows retain `effective_from = '2000-01-01'` (the default), making them valid before any business date query. No data migration required.

**Consequence:** The admin UI must create a new row (not edit `unit_price`) when changing a price. Phase 3.2 UI shows only current effective rates in the picker; version history view is Phase 3.3.

---

### ADR-67: Business Date Is Canonical for All Financial Dating

**Decision:** All system-generated `FolioEntry.entry_date`, all `NightAuditRun.audit_date`, all posting key date components, and all `ServiceRate.effective_from` comparisons use `BusinessDateService::currentBusinessDate()`, not `Carbon::today()` or `now()->toDateString()`.

`business_date = now() - hotel_settings.business_date_offset_hours` (floored to day).

**Rationale:** Hotels operate past midnight. A Night Audit triggered at 02:30 AM belongs to the previous business date. Using the calendar date would create `ROOM_NIGHT_{stay_id}_2026-07-02` when the night being charged is July 1, breaking revenue recognition and folio reconciliation.

**Consequence:** Manual `entry_date` defaults to business date but staff may override to any past calendar date. The validation `before_or_equal:today` continues to apply to the calendar date.

---

### ADR-68: HotelSettingsService Centralises All Configurable Parameters

**Decision:** No service class may hardcode a configuration value (grace minutes, audit window, business date offset, currency, sequential mode). All such values are read from `HotelSettingsService`, which is backed by the `hotel_settings` DB table with a 10-minute cache.

**Rationale:** Hardcoded constants scattered across services require code deployments to change operational parameters. A centralised settings store lets the ADMIN adjust them at runtime without a release.

**Consequence:** `hotel_settings.manage` is ADMIN-only. Every `set()` call invalidates the cache. Default values seeded on first deploy are safe and conservative.

---

### ADR-69: Night Audit Implemented as an Ordered Posting Pipeline

**Decision:** `NightAuditPipeline` discovers all registered `PostingJob` instances where `isNightAuditStep() = true`, sorts them by topological order of `dependsOn()`, and executes them for each eligible in-house stay. New posting types (Phase 3.3+) are added by registering a new `PostingJob` implementation — no changes to the pipeline.

**Rationale:** Tightly coupling Night Audit to room-charge logic makes adding breakfast fees, resort fees, or city tax require invasive changes to `NightAuditService`. A pipeline with a `PostingJob` registry allows additive extension without modification.

**Consequence:** A `PipelineCycleException` is thrown at order resolution time if a dependency cycle is introduced. CI tests must assert the production pipeline has no cycles.

---

### ADR-70: PostingJob Is the Unit of System-Generated Posting

**Decision:** Every system-generated charge — whether triggered by a lifecycle event (check-in, check-out) or the Night Audit pipeline — is encapsulated in a class implementing the `PostingJob` interface. The interface mandates: `jobId()`, `dependsOn()`, `isNightAuditStep()`, `isRequired()`, `shouldProcess()`, `isAlreadyPosted()`, `execute()`, `rollback()`.

**Rationale:** Unifying the contract for all system posting enables independent testability of each job, consistent idempotency checking, and a rollback contract usable by operational commands (`audit:void-night-audit-entries`).

**Consequence:** `StayService::checkIn()` and `StayService::checkOut()` call `PostingJob::execute()` directly (not via pipeline) for lifecycle jobs. `RoomChargePostingJob` serves dual duty: lifecycle (first night) and pipeline (subsequent nights).

---

### ADR-71: Three-Layer Separation — ChargeType, ChargeCategory, ServiceRate

**Decision:** The architecture explicitly separates three concerns:
1. **`ChargeType` (PHP enum, VARCHAR in DB)** — the accounting/reporting dimension. Small set (≤15), type-checked, stable across hotels. No DB table in Phase 3.2.
2. **`ChargeCategory` (PHP class constants)** — the UI grouping layer. Maps ChargeType values to picker groups. No DB table in Phase 3.2.
3. **`ServiceRate` (DB table)** — the hotel-specific catalog item. Many per ChargeType, versioned, named by the hotel.

**Rationale:** Making ChargeType a DB table in Phase 3.2 would introduce a migration and FK join for a set of values that does not vary between hotels and benefits from compile-time type checking. The ServiceRate table already provides hotel-specific naming. ChargeCategory as a PHP class allows easy UI grouping without a DB query.

**Phase 3.4 path:** If hotels need custom service categories, `ChargeCategory` is promoted to a DB table. ChargeType remains a PHP enum. The separation is designed so this promotion does not require changes to ChargeType or FolioEntry.

**Consequence:** Adding a new ChargeType value requires a PHP enum case change and a deploy. This is acceptable — ChargeType changes are rare (architecture-level decisions) whereas ServiceRate changes (catalog pricing) are frequent and handled via the admin UI.

---

### ADR-72: Revenue Account Code Slot on ServiceRate for Future GL Integration

**Decision:** `service_rates` includes a nullable `gl_account_code VARCHAR(50)` column in Phase 3.2. This column is stored but not used in any calculation until Phase 3.5.

**Rationale:** GL account integration (Phase 3.5) maps each service charge to a revenue account (Room Revenue, F&B Revenue, Laundry Revenue, etc.). Adding the column slot now means Phase 3.5 is an additive change (populating and reading the column) rather than a schema migration on a table that may have thousands of rows. Hotels with accounting systems can pre-populate the field via the admin UI as soon as it appears.

**Consequence:** `gl_account_code` is visible but non-functional in Phase 3.2. No validation beyond `max:50`. Phase 3.5 adds the revenue mapping logic and GL export.

---

## 37. Test Plan

### 37.1 Unit Tests

**`BusinessDateServiceTest`**
- `currentBusinessDate()` with offset=6: real time 05:59 → returns yesterday
- `currentBusinessDate()` with offset=6: real time 06:00 → returns today
- `businessDateFor(Carbon $time)` matches expected date for edge cases
- `currentBusinessDate()` reads offset from `HotelSettingsService` (mock)

**`HotelSettingsServiceTest`**
- `get()` returns default when key not in DB
- `set()` updates DB record and invalidates cache
- `getInt()` casts value correctly
- `getBool()` casts 'true'/'false' string correctly
- Cache: second `get()` call does not hit DB (mock assertion)
- `set()` records `updated_by` correctly

**`ServiceRateServiceTest`** (rate resolution)
- Returns correct rate for charge type on given business date
- Returns new rate when `effective_from` matches business date
- Returns old rate when business date is before new `effective_from`
- Returns null when no active rate exists for type on date
- Multiple rows: most recent `effective_from` wins (DESC sort)
- Inactive row (`is_active=0`) excluded from resolution

**`NightAuditPipelineTest`** (unit — mock jobs)
- `resolveExecutionOrder()` correctly topologically sorts by `dependsOn()`
- `resolveExecutionOrder()` throws `PipelineCycleException` on cycle
- Jobs with `isNightAuditStep=false` excluded from pipeline
- `executeForStay()` skips jobs where `shouldProcess()` returns false
- `executeForStay()` skips jobs where `isAlreadyPosted()` returns true
- `executeForStay()` aborts stay on `isRequired=true` job failure

**`RoomChargePostingJobTest`** (unit — mock DB)
- `isAlreadyPosted()` returns true when `ROOM_NIGHT_{stay_id}_{date}` exists
- `shouldProcess()` returns false when booking has active AGGREGATE entry
- `execute()` returns POSTED result when successful
- `execute()` returns SKIPPED when folio is CLOSED
- `rollback()` voids the correct entry

**`ServiceRatePolicyTest`**
- Admin/Manager: viewAny, create, update, toggleActive → authorized
- Reception/Accountant: all → unauthorized
- All roles: delete → false

**`PostingSourceTest`**
- Manual entries: `posting_source = MANUAL`, `posting_key = NULL`
- System auto entries: `posting_source = SYSTEM_AUTO`, `posting_key NOT NULL`
- Night audit entries: `posting_source = NIGHT_AUDIT`, `posting_key NOT NULL`

### 37.2 Feature Tests

**`HotelSettingsCrudTest`** (≥5 tests)
- ADMIN can update `late_checkout_grace_minutes` → persisted
- MANAGER cannot update settings (403)
- Invalid key rejected (422)
- Value cached after first read
- `updated_by` recorded on change

**`ServiceRateCrudTest`** (≥10 tests)
- Admin creates service rate with `effective_from` → `assertDatabaseHas`
- Admin updates service rate → INSERT new row, old row unchanged
- Toggle active: `is_active` flips 1→0 and 0→1
- Inactive rate excluded from `options.serviceRates` on booking show
- `charge_type = ROOM` rejected on create (422)
- `unit_price < 0` rejected (422)
- Reception cannot create (403)
- Rate with future `effective_from` not shown in current picker
- Rate with past `effective_from` shown in current picker

**`ServiceRateVersioningTest`** (≥5 tests)
- Rate A: `unit_price=300000, effective_from=2026-01-01`
- Rate B: `unit_price=350000, effective_from=2026-07-01`
- `resolveFor(type, '2026-06-30')` → Rate A (300,000)
- `resolveFor(type, '2026-07-01')` → Rate B (350,000)
- `resolveFor(type, '2026-08-01')` → Rate B (350,000)
- Old folio entry still has 300,000 (price captured at posting time)

**`BusinessDateFeatureTest`** (≥4 tests)
- Night Audit triggered at 02:00 (offset=6) → `audit_date = yesterday`
- Check-in at 02:00 → `ROOM_NIGHT_*_yesterday` (business date key)
- Manual charge `entry_date` defaults to business date
- Rate resolution uses business date, not calendar date

**`PerNightChargeTest`** (≥8 tests — updated for PostingJob)
- Check-in executes `RoomChargePostingJob` → `ROOM_NIGHT_{stay_id}_{businessDate}`
- `posting_source = SYSTEM_AUTO`, `stay_id` set, `posted_by = NULL`
- Void attempt on per-night entry → `SystemEntryVoidException`
- Check-in idempotent (PostingJob returns SKIPPED if key exists)
- Balance formula includes per-night charge
- Multi-stay booking: each stay gets its own ROOM_NIGHT entry

**`NightAuditPipelineFeatureTest`** (≥14 tests)
- Pipeline posts `ROOM_NIGHT_{stay_id}_{businessDate}` for all eligible stays
- Pipeline skips stays checking out on business date (ADR-59 boundary)
- Pipeline skips stays that checked in on business date (first night at check-in)
- Pipeline skips stays with CLOSED folio (FAILED status in log)
- Idempotent: second run for same business date blocked (COMPLETED run exists)
- FAILED run retry: re-processes failed bookings only
- Booking with AGGREGATE entry: SKIPPED (ADR-62)
- `NightAuditRun.entries_posted` matches actual FolioEntry insertions
- `NightAuditBookingLog` created for each processed stay
- Prerequisite P1 fails if COMPLETED run exists
- Prerequisite P4 fails if RUNNING run exists
- `--dry-run` inserts no FolioEntry records
- `--dry-run` creates no NightAuditRun record
- Business date of entries matches `audit_date` on the run

**`LateCheckoutFeeTest`** (≥7 tests)
- Checkout > planned + grace → `LateCheckoutFeePostingJob` posts `LATE_CHECKOUT_{stay_id}`
- `posting_source = SYSTEM_AUTO`, `stay_id` set
- Fee resolves rate for **business date** of checkout (not calendar date)
- Checkout within grace → no fee
- No effective LATE_CHECKOUT rate on business date → no fee, warning logged
- `planned_checkout_at = NULL` → no fee
- Fee amount = ceil(minutes_late / 60) × unit_price

**`EarlyCheckinFeeTest`** (≥3 tests)
- Check-in before planned - grace with active rate → `EARLY_CHECKIN_{stay_id}` posted
- Check-in within grace → no fee
- No active EARLY_CHECKIN rate → no fee

**`PerStayAttributionTest`** (≥5 tests)
- Manual charge with `stay_id` → persisted
- `stay_id` from different booking → 422 (IDOR guard)
- `charge_type = ROOM` from HTTP → 422
- Booking show payload includes `stay_id`, `stay_room_number`, `currentBusinessDate`
- Single-stay: `stay_id` pre-selected in `checkableStays`

**`BackfillCommandTest`** (≥4 tests)
- `--dry-run` emits no DB writes
- Booking with AGGREGATE entry → per-night entries use business date keys
- Command is idempotent (converted bookings skipped)
- Booking without AGGREGATE entry → skipped

**`NightAuditRollbackTest`** (≥3 tests)
- `audit:void-night-audit-entries --run-id=` calls `PostingJob::rollback()` for all affected stays
- After void, Night Audit can be re-run with `--force`
- `--stay-id=` voids only that stay's entries

### 37.3 Regression Tests

All Phase 3.1.2 tests must pass unchanged. Key:
- `CheckoutConfirmationGateTest` (10 tests) — passes unchanged
- `CheckoutIntegrationTest` — update fixtures to use `RoomChargePostingJob`; all ADR guards still apply

### 37.4 Night Audit Scenario Matrix

| Scenario | Expected Outcome |
|---------|-----------------|
| 1 stay, 1 night; triggered at 23:30 | 1 entry at check-in (businessDate = today); Night Audit skips (checkout night) |
| 1 stay, 1 night; triggered at 02:00 | 1 entry at check-in (businessDate = yesterday); Night Audit run for yesterday skips (checkout night) |
| 1 stay, 3 nights | Check-in: ROOM_NIGHT night 1; Night Audit nights 2 and 3 |
| 2 stays same booking | 2 check-in entries; Night Audit posts 2 per subsequent night |
| Checkout day of audit | Night Audit skips (actual_checkout_at IS NOT NULL) |
| Run twice same business date | Second run blocked (COMPLETED) |
| FAILED run retry | Re-processes failed bookings; skips succeeded |
| Folio closed before audit | Stay FAILED in log; run continues |
| Booking with AGGREGATE | Night Audit SKIPS; logged with skip_reason |
| Phase 3.3 with 3 registered jobs | All 3 jobs run per stay in dependency order |

### 37.5 Pipeline Cycle Detection Test

```
RegisteredJobs: A(dependsOn:[B]), B(dependsOn:[A])
NightAuditPipeline::resolveExecutionOrder() → throws PipelineCycleException
```

This test must exist in CI to catch Phase 3.3+ misconfiguration at code-review time.

### 37.6 Coverage Targets (≥80% line coverage)

- `app/Services/NightAuditService.php`
- `app/Services/NightAuditPipeline.php`
- `app/Services/BusinessDateService.php`
- `app/Services/HotelSettingsService.php`
- `app/Services/ServiceRateService.php`
- `app/PostingJobs/RoomChargePostingJob.php`
- `app/PostingJobs/LateCheckoutFeePostingJob.php`
- `app/PostingJobs/EarlyCheckinFeePostingJob.php`
- `app/Services/FolioService.php` (modified sections)
- `app/Http/Controllers/Admin/ServiceRateController.php`
- `app/Http/Controllers/Admin/NightAuditController.php`
- `app/Http/Controllers/Admin/HotelSettingsController.php`

---

## Appendix A: Posting Key Reference Table

| Key Pattern | Phase | Creator | Source | Voidable | Date Component |
|-------------|-------|---------|--------|---------|----------------|
| `ROOM_CHARGE_{booking_id}_AGGREGATE` | 3.1A (legacy) | `autoPostRoomCharge()` | SYSTEM_AUTO | NEVER | None |
| `ROOM_NIGHT_{stay_id}_{YYYY-MM-DD}` | 3.2 | `RoomChargePostingJob` | SYSTEM_AUTO / NIGHT_AUDIT | NEVER | **Business date** |
| `LATE_CHECKOUT_{stay_id}` | 3.2 | `LateCheckoutFeePostingJob` | SYSTEM_AUTO | NEVER | None |
| `EARLY_CHECKIN_{stay_id}` | 3.2 | `EarlyCheckinFeePostingJob` | SYSTEM_AUTO | NEVER | None |

---

## Appendix B: Balance Formula Evolution

| Phase | Formula | Transition Guard |
|-------|---------|-----------------|
| Pre-3.1A | `balance = requirements_total − paid_total` | N/A |
| 3.1A – 3.2.7 | `balance = guarded_folio_total − paid_total` | YES |
| 3.2.8+ | `balance = folio_total − paid_total` | REMOVED |

---

## Appendix C: Full Permission Matrix (Phase 3.2)

| Permission | ADMIN | MANAGER | RECEPTION | ACCOUNTANT | SALES | HOUSEKEEPING |
|-----------|-------|---------|-----------|------------|-------|--------------|
| `folio.view` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| `folio.close` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `charge.create` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `charge.void` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `service_rates.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `night_audit.run` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `night_audit.view` | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| `hotel_settings.manage` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

---

## Appendix D: New ChargeType Values — Service Rate Examples

| ChargeType | Service Rate Examples |
|-----------|----------------------|
| `EXTRA_BED` | "Giường phụ" (300,000/đêm) |
| `EXTRA_PERSON` | "Người thêm" (200,000/người/đêm) |
| `AIRPORT_TRANSFER` | "Đón sân bay Nội Bài" (500,000/chiều) |
| `FOOD_BEVERAGE` | "Bữa sáng buffet" (150,000/người) |
| `MINIBAR` | "Bia Heineken" (45,000/chai) |
| `LAUNDRY` | "Giặt áo sơ mi" (30,000/cái) |
| `LATE_CHECKOUT` | "Phí trả phòng muộn" (200,000/giờ) |
| `EARLY_CHECKIN` | "Phí nhận phòng sớm" (200,000 flat) |
| `TRANSPORT` | "Thuê xe 4 chỗ" (600,000/ngày) |
| `SPA` | "Massage 60 phút" (500,000/lần) |

---

## Appendix E: Hotel Settings Key Registry

| Key | Default | Type | Used By |
|-----|---------|------|---------|
| `business_date_offset_hours` | `6` | int | `BusinessDateService` |
| `night_audit_window_start` | `22:00` | time | Night Audit P3 check |
| `night_audit_window_end` | `06:00` | time | Night Audit P3 check |
| `night_audit_require_sequential` | `true` | bool | Night Audit P2 check |
| `late_checkout_grace_minutes` | `30` | int | `LateCheckoutFeePostingJob` |
| `early_checkin_grace_minutes` | `30` | int | `EarlyCheckinFeePostingJob` |
| `currency_code` | `VND` | string | UI display, Phase 3.5 tax |
| `currency_precision` | `0` | int | UI formatting |
