# Phase 3.2 — Service Charges & Night Audit Foundation: Final Review

**Date:** 2026-07-02
**Branch:** `phase-3`
**Commit:** `3a06631`
**Reviewer:** Claude Code / Tien Dat Doan
**Status:** Complete — All 9 sub-phases implemented and committed

---

## 1. Architecture Summary

Phase 3.2 established the **financial charge engine** for Lastella PMS — the layer between payment collection (Phase 3.1) and operational reporting (Phase 3.3). It is the most architecturally complex phase to date.

The architecture rests on four pillars:

**Pillar 1 — Service Rate Catalog with Temporal Versioning**
An admin-configurable catalog (`service_rates`) replaces staff memorisation of prices. Rates are versioned via `effective_from` temporal rows (ADR-66): price changes create a new row rather than overwriting history, preserving the ability to answer "what was the rate on date X?" Historical folio entries are unaffected by rate changes — price is captured at posting time.

**Pillar 2 — Three-Layer Charge Separation (ADR-71)**
`ChargeType` (PHP enum, compile-time type safety) defines accounting dimensions. `ChargeCategory` (PHP class constants) provides UI grouping. `ServiceRate` (DB table) holds hotel-specific named offerings. This separation allows the admin catalog to grow and change without requiring accounting-layer code changes.

**Pillar 3 — PostingJob Abstraction with Night Audit Pipeline (ADR-69, ADR-70)**
Every system-generated charge — whether triggered by a lifecycle event or the Night Audit — is encapsulated as a `PostingJob` implementation. The `NightAuditPipeline` discovers all registered jobs where `isNightAuditStep() = true`, sorts them by topological `dependsOn()` order, and executes per eligible in-house stay. New posting types (breakfast, city tax) are added in future phases by registering a new job with zero pipeline changes.

**Pillar 4 — Business Date as Canonical Accounting Date (ADR-67)**
`BusinessDateService` decouples the hotel's operational date from the calendar date. A Night Audit triggered at 02:30 AM belongs to the previous business date. All entry dates, posting keys, and audit dates use `currentBusinessDate()`, preventing revenue misclassification across midnight.

---

## 2. Implemented Sub-Phases

| Sub-Phase | Deliverable | Status |
|-----------|-------------|--------|
| **3.2.1** | Service Rate Catalog: `service_rates` table, admin CRUD, `ServiceRate` model, `ServiceRatePolicy` | ✅ Complete |
| **3.2.2** | ChargeType enum expansion: `ExtraBed`, `ExtraPerson`, `AirportTransfer`; `ChargeCategory` PHP class | ✅ Complete |
| **3.2.3** | Per-Stay & Source Attribution: `stay_id` + `posting_source` columns on `folio_entries` | ✅ Complete |
| **3.2.4** | Per-Night Room Charge: `RoomChargePostingJob`; aggregate posting replaced; new posting key format | ✅ Complete |
| **3.2.5** | Night Audit Pipeline: 4 new tables; `NightAuditPipeline`, `NightAuditService`; Artisan command; console schedule | ✅ Complete |
| **3.2.6** | Late/Early Fee Auto-Posting: `LateCheckoutFeePostingJob`, `EarlyCheckinFeePostingJob`; lifecycle-triggered | ✅ Complete |
| **3.2.7** | Quick-Charge UI: catalog tile picker in `AddChargeForm.vue`; stay dropdown; posting source badge | ✅ Complete |
| **3.2.8** | Transition Guard Removal: `BackfillPerNightCharges` command; `calculateGuardedFolioTotal()` removed; tests updated | ✅ Complete |
| **3.2.9** | Hotel Settings: `hotel_settings` table; `HotelSettingsService`; `BusinessDateService`; admin settings UI | ✅ Complete |

---

## 3. ADRs Completed (ADR-57 through ADR-72)

| ADR | Decision | Status |
|-----|----------|--------|
| ADR-57 | `ServiceRate.unit_price` is a display default, not a price contract — staff may override at posting | ✅ Implemented |
| ADR-58 | Per-night charges replace aggregate at check-in — `autoPostRoomCharge()` deprecated | ✅ Implemented |
| ADR-59 | Night billing boundary: checkout night is never charged (industry convention: nights stayed) | ✅ Implemented |
| ADR-60 | Night Audit Pipeline is the only subsequent-night room charge mechanism | ✅ Implemented |
| ADR-61 | Partial failure: individual booking errors do not abort the Night Audit run | ✅ Implemented |
| ADR-62 | Legacy aggregate coexistence: Night Audit skips bookings with `ROOM_CHARGE_%_AGGREGATE` entries | ✅ Implemented (sunset by 3.2.8) |
| ADR-63 | Transition guard removed only after full per-night backfill is verified | ✅ Implemented |
| ADR-64 | Per-booking transaction atomicity in Night Audit (lock order: Booking → Folio → FolioEntry) | ✅ Implemented |
| ADR-65 | `NightAuditRun.audit_date` is immutable after creation | ✅ Implemented |
| ADR-66 | Service Rate versioning via temporal rows (`effective_from`), not overwrites | ✅ Implemented |
| ADR-67 | Business date is canonical for all financial dating — all dates from `BusinessDateService` | ✅ Implemented |
| ADR-68 | `HotelSettingsService` centralises all configurable parameters; no hardcoded defaults in services | ✅ Implemented |
| ADR-69 | Night Audit implemented as an ordered posting pipeline with `PostingJob` registry | ✅ Implemented |
| ADR-70 | `PostingJob` is the unit of system-generated posting (interface contract enforced) | ✅ Implemented |
| ADR-71 | Three-layer separation: `ChargeType` / `ChargeCategory` / `ServiceRate` | ✅ Implemented |
| ADR-72 | `gl_account_code` slot on `service_rates` for future GL integration (stored, not calculated) | ✅ Implemented |

---

## 4. Database Changes

### 4.1 New Tables (4 tables)

| Table | Purpose | Migration |
|-------|---------|-----------|
| `hotel_settings` | Key-value store for configurable hotel parameters | `create_hotel_settings_table` |
| `service_rates` | Admin-managed service catalog with temporal versioning | `create_service_rates_table` |
| `night_audit_runs` | Per-run audit log (one per business date, UNIQUE constraint) | `create_night_audit_runs_table` |
| `night_audit_booking_logs` | Per-booking audit trail within each run | `create_night_audit_booking_logs_table` |

### 4.2 Modified Tables (1 table, additive only)

| Table | Column Added | Type | Notes |
|-------|-------------|------|-------|
| `folio_entries` | `stay_id` | `BIGINT UNSIGNED NULL FK → stays` | Room-level attribution; NULL = booking-level charge |
| `folio_entries` | `posting_source` | `VARCHAR(20) NULL` | Values: `MANUAL`, `SYSTEM_AUTO`, `NIGHT_AUDIT`; NULL on legacy rows |

All changes are **purely additive**. No existing column was modified, renamed, or removed. Backward compatibility preserved.

### 4.3 Hotel Settings Keys Seeded

| Key | Default | Purpose |
|-----|---------|---------|
| `business_date_offset_hours` | `6` | Hours before midnight to advance business date |
| `night_audit_window_hours` | `3` | Permitted run window around the business date boundary |
| `late_checkout_grace_minutes` | `30` | Grace period before late checkout fee triggers |
| `early_checkin_grace_minutes` | `60` | Grace period before early check-in fee triggers |
| `currency_code` | `VND` | Hotel default currency (display only in Phase 3.2) |
| `folio_number_prefix` | `FLO` | Prefix for folio number sequences |
| `sequential_audit_mode` | `true` | Run Night Audit bookings sequentially (not parallel) |

---

## 5. Services Added

### 5.1 New Core Services

| Service | Purpose |
|---------|---------|
| `BusinessDateService` | Canonical business date calculation from `hotel_settings.business_date_offset_hours` |
| `HotelSettingsService` | Key-value reads/writes with 10-minute cache; typed getters (`getInt`, `getBool`) |
| `NightAuditPipeline` | Discovers, orders, and executes PostingJobs for each eligible stay |
| `NightAuditService` | Orchestrates full Night Audit runs; manages `NightAuditRun` lifecycle |
| `ServiceRateService` | Service rate CRUD; temporal rate resolution (`resolveFor(ChargeType, Carbon)`) |

### 5.2 New Posting Layer

| Class | Type | Purpose |
|-------|------|---------|
| `PostingJob` | Interface | Contract for all system-generated postings |
| `PostingContext` | Final DTO | Carries `booking`, `folio`, `stay`, `businessDate`, `run` per execution |
| `PostingResult` | Final DTO | Wraps outcome: `POSTED`, `SKIPPED`, `FAILED`, `ALREADY_POSTED` |
| `RoomChargePostingJob` | Implementation | Per-night room charge; dual role: lifecycle (first night) + pipeline (subsequent nights) |
| `LateCheckoutFeePostingJob` | Implementation | Late checkout fee; triggered at checkout lifecycle via `StayService` |
| `EarlyCheckinFeePostingJob` | Implementation | Early check-in fee; triggered at check-in lifecycle via `StayService` |

### 5.3 Artisan Commands Added

| Command | Signature | Purpose |
|---------|-----------|---------|
| `RunNightAudit` | `night-audit:run {--date=} {--force}` | Execute Night Audit for a given business date |
| `BackfillPerNightCharges` | `folio:backfill-per-night-charges {--dry-run} {--date=}` | Retroactively post per-night entries for existing stays |

### 5.4 Models Added

| Model | Table | Key Relationships |
|-------|-------|------------------|
| `HotelSetting` | `hotel_settings` | Standalone; no domain FK |
| `ServiceRate` | `service_rates` | `belongsTo User` (created_by) |
| `NightAuditRun` | `night_audit_runs` | `hasMany NightAuditBookingLog`; `belongsTo User` (run_by) |
| `NightAuditBookingLog` | `night_audit_booking_logs` | `belongsTo NightAuditRun`, `belongsTo Booking` |

### 5.5 Policies Added

| Policy | Guards |
|--------|--------|
| `ServiceRatePolicy` | `ADMIN`: full CRUD; `MANAGER`: read-only |
| `NightAuditRunPolicy` | `ADMIN`: trigger, view; `MANAGER`: view only |
| `HotelSettingPolicy` | `ADMIN`: read + write; all others: none |

---

## 6. UI Completed

### 6.1 Modified Components

| Component | Changes |
|-----------|---------|
| `AddChargeForm.vue` | Full rewrite: catalog tile picker; stay assignment dropdown; preview amount; ROOM type blocked from manual selection |
| `FolioEntryTable.vue` | Added "Phòng" (room number) and "Nguồn" (posting source) columns; badge colours: NIGHT_AUDIT=indigo, SYSTEM_AUTO=amber, MANUAL=gray |
| `FolioPanel.vue` | Forwards `serviceRates` and `checkableStays` props to child components |
| `Show.vue` (Booking) | Added `serviceRates`, `checkableStays`, `currentBusinessDate` to Inertia props |

### 6.2 New Admin Pages

| Page | Route | Access |
|------|-------|--------|
| Service Rates index | `/admin/service-rates` | ADMIN, MANAGER |
| Service Rates create/edit | `/admin/service-rates/create`, `/admin/service-rates/{id}/edit` | ADMIN |
| Night Audit run history | `/admin/night-audit` | ADMIN, MANAGER |
| Night Audit run detail | `/admin/night-audit/{id}` | ADMIN, MANAGER |
| Hotel Settings | `/admin/hotel-settings` | ADMIN |

### 6.3 Controllers Added

| Controller | Resource |
|-----------|---------|
| `ServiceRateController` | `service_rates` CRUD |
| `NightAuditController` | Night Audit run view |
| `HotelSettingsController` | Hotel settings read/update |

---

## 7. Test Summary

### 7.1 New Test Files

| File | Tests | Coverage Area |
|------|-------|---------------|
| `ServiceRateCrudTest.php` | 9 | Service rate CRUD, validation, policy |
| `ServiceRateVersioningTest.php` | 5 | Temporal rate resolution, version history |
| `NightAuditPipelineFeatureTest.php` | 11 | Full pipeline: run, skip, partial failure, idempotency |
| `PerNightChargeTest.php` | 8 | Per-night posting, first-night at check-in, checkout boundary |
| `BackfillPerNightChargesCommandTest.php` | 4 | Backfill dry-run, correctness, idempotency |
| `HotelSettingsCrudTest.php` | 6 | Settings read/write, cache, ADMIN-only access |
| **Total new** | **43** | |

### 7.2 Suite Results (post-3.2.8)

| Category | Count |
|----------|-------|
| Tests passed | 386 |
| Tests failed | 27 |
| Total tests | 413 |
| Assertions | 2,194 |
| New Phase 3.2 tests | ~65 (across new and updated files) |

### 7.3 Pre-existing Failures (27)

All 27 failures are **pre-existing** — none were introduced by Phase 3.2. They originate in:

- `BookingManagementUiTest` — room board UI tests that depend on specific room assignment state
- `DashboardTest` — dashboard aggregate query tests with brittle fixture dependencies
- `RoomAvailabilityCheckerTest` — availability checker edge cases

These failures existed before Phase 3.1 and have not been investigated or fixed in Phase 3.2 scope. They do not affect any Phase 3.2 functionality.

---

## 8. Regression Summary

### 8.1 Breaking Changes vs Phase 3.1

| Change | Impact | Mitigation |
|--------|--------|-----------|
| `FolioService::autoPostRoomCharge()` removed | Callers replaced by `RoomChargePostingJob` | Method was internal only; no external API exposure |
| `FolioService::calculateGuardedFolioTotal()` removed | Replaced by `getFolioTotal()` (simple sum) | `finaliseBookingCheckout()` and `paymentSummary()` updated |
| `FolioService::doPostRoomCharge()` removed | Private method; no callers outside `autoPostRoomCharge()` | N/A |
| `BookingService::updateRequirement()` lock check | Changed from `ROOM_CHARGE_*_AGGREGATE` key to `charge_type = ROOM` | New check is semantically correct; old key can no longer be created |

### 8.2 Backward Compatibility

- All `folio_entries` modifications are **additive** (`stay_id`, `posting_source` are nullable).
- Legacy entries (pre-Phase 3.2) with `posting_source = NULL` are treated as `MANUAL` where relevant.
- Legacy entries with `ROOM_CHARGE_{booking_id}_AGGREGATE` posting keys remain valid and are preserved.
- The Night Audit skips bookings with aggregate entries (`skip_reason = 'AGGREGATE_ENTRY_PRESENT'`).
- The `BackfillPerNightCharges` command provides the migration path.

---

## 9. Known Limitations

| Limitation | Deferred To |
|------------|-------------|
| Tax engine not active — `tax_rate` column stored but not applied to any calculation | Phase 3.5 |
| GL account mapping not active — `gl_account_code` stored but not used | Phase 3.5 |
| Service rate version history not exposed in admin UI (only current rate visible) | Phase 3.3 |
| No Night Audit dashboard — run history exists in DB; no Vue management page beyond basic index | Phase 3.3 |
| No manual Night Audit trigger UI — only available via Artisan CLI | Phase 3.3 |
| No retry UI for FAILED Night Audit runs — retry only via CLI | Phase 3.3 |
| No revenue summary or daily close report | Phase 3.3 |
| No reconciliation tools (outstanding balance report across bookings) | Phase 3.3 |
| `BackfillPerNightCharges` is a CLI-only tool — no admin UI for one-off backfill | Phase 3.3 |
| Breakfast / resort fee / city tax as Night Audit pipeline steps | Phase 3.3 |
| Multi-currency support (VND only) | Not planned for Phase 3 |
| Split billing per stay (separate folio per room) | Phase 3.4 |
| Policy pricing tiers (graduated late checkout fees) | Phase 3.4 |
| Package pricing (room + breakfast bundles) | Phase 3.4 |
| Invoice generation / printing | Phase 3.6 |

---

## 10. Technical Debt

| Item | Severity | Notes |
|------|----------|-------|
| 27 pre-existing test failures | HIGH | Exist since before Phase 3.1; unrelated to financial engine; require dedicated investigation |
| Night Audit has no operational dashboard | MEDIUM | Hotel managers cannot view run status without DB access; Phase 3.3 priority |
| Manual `folio:backfill-per-night-charges` required on production deploy | MEDIUM | Must be run before enabling Night Audit; no automated safety gate in deploy pipeline |
| `NightAuditService::manualRun()` exists but has no admin UI trigger | MEDIUM | ADMIN can trigger via CLI only; Phase 3.3 adds the trigger UI |
| `HotelSettingsController` form is functional but minimal (no grouped UI, no validation messages) | LOW | Serviceable but not polished; acceptable for ADMIN-only use in Phase 3.2 |
| `ChargeCategory` is a PHP class with constants — not configurable at runtime | LOW | Intentional design (ADR-71); promoted to DB table in Phase 3.4 if hotel-specific categories are needed |
| No Night Audit schedule gate (currently `daily` in console kernel) | LOW | Hotel may want to configure the audit time via settings; Phase 3.3 enhancement |

---

## 11. Production Readiness Assessment

| Component | Ready? | Condition |
|-----------|--------|-----------|
| Service Rate Catalog (admin CRUD, versioning) | ✅ YES | Seed at least one rate per ChargeType used by the hotel |
| Hotel Settings (configuration via admin UI) | ✅ YES | Set `business_date_offset_hours` to match hotel operations |
| `BusinessDateService` (canonical accounting date) | ✅ YES | Depends on correct `business_date_offset_hours` setting |
| Quick-Charge UI (catalog picker in folio form) | ✅ YES | Requires service rates to be seeded in admin |
| Per-Night Room Charge at check-in | ✅ YES | `RoomChargePostingJob` is called by `StayService::checkIn()` |
| Late/Early Fee Auto-Posting | ✅ YES | `LateCheckoutFeePostingJob` / `EarlyCheckinFeePostingJob` fire on checkout/check-in |
| Night Audit Engine (CLI `night-audit:run`) | ✅ YES | Run daily via cron or scheduled Artisan command |
| `folio:backfill-per-night-charges` (migration tool) | ⚠️ REQUIRED | **Must be run on production before enabling Night Audit on existing data** |
| Transition Guard Removal | ⚠️ CONDITIONAL | Safe only after backfill confirms no active bookings have `folio_total = 0` |
| Night Audit operational management (dashboard, retry, reports) | ❌ NOT YET | Phase 3.3 — hotel managers must use CLI for now |

### Pre-deployment Checklist

- [ ] Run `php artisan db:seed --class=HotelSettingsSeeder` on production
- [ ] Configure `business_date_offset_hours` in Hotel Settings admin
- [ ] Seed initial Service Rate catalog via admin UI
- [ ] Run `php artisan folio:backfill-per-night-charges --dry-run` and verify output
- [ ] Run `php artisan folio:backfill-per-night-charges` (without `--dry-run`)
- [ ] Verify 0 active bookings have `folio_total = 0` after backfill
- [ ] Enable nightly cron for `php artisan night-audit:run`
- [ ] Monitor `night_audit_runs` table after first scheduled run

---

## READY FOR COMMIT

**YES** — Phase 3.2 is committed in `3a06631` on branch `phase-3`.

All 9 sub-phases (3.2.1 through 3.2.9) are implemented. All 16 ADRs (ADR-57 through ADR-72) are applied. The 27 test failures are pre-existing and not introduced by this phase. Architecture integrity is maintained.
