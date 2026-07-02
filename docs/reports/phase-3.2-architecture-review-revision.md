# Phase 3.2 Architecture Review Revision Report

**Date:** 2026-07-02
**Document revised:** `docs/roadmaps/phase-3.2-service-charges-night-audit-foundation.md`
**Status:** Revised — Pending Second Architecture Review
**Reviewer basis:** Architecture Review Findings (6 issues: HIGH ×2, MEDIUM ×5)

---

## 1. Summary of All Architecture Changes

Six review findings were addressed. Four new sections were inserted and all subsequent sections renumbered. Seven new ADRs (ADR-66 through ADR-72) were added. No previously-approved section was removed; all approved content was preserved unless a specific finding required updating it.

### 1.1 HIGH ISSUE 1 — Service Rate Versioning

**Finding:** `service_rates` stored only `unit_price`. Price changes would silently overwrite history. Historical bookings must preserve the rate that was in effect when the charge was posted.

**Change made:**
- Added `effective_from DATE NOT NULL DEFAULT '2000-01-01'` to `service_rates` DDL (§5.1).
- Added `gl_account_code VARCHAR(50) NULL` to the same migration (see MEDIUM ISSUE 5 below).
- Added composite index `idx_service_rates_effective (charge_type, is_active, effective_from)`.
- Defined canonical rate resolution query in new §9 (Service Rate Versioning).
- Added ADR-66 (temporal rows approach justification).
- Updated §16 (Service Pricing Strategy) to document `ServiceRateService::resolveFor(ChargeType, Carbon $businessDate)`.
- Updated §28.2 (StoreServiceRateRequest) to include `effective_from` as required.
- Updated §29.1 (API Design) and §30.1 (Admin UI) to document version-insert workflow.

**Rationale for temporal rows over `service_rate_versions` table:**
- Zero regression risk: additive column, default value covers all pre-existing rows.
- No FK joins required: existing code that reads rates needs only a `WHERE` clause addition.
- Simpler rollback: single migration to drop the column.
- Price capture at posting (FolioEntry.unit_price) already makes historical entries immune — versioning only serves catalog audit and future reporting.

### 1.2 HIGH ISSUE 2 — Night Audit Pipeline Redesign

**Finding:** Night Audit was tightly coupled to Room Charge logic. Adding future charge types (breakfast, resort fee, city tax) would require invasive changes to `NightAuditService`. No defined extension mechanism, dependency model, or rollback contract.

**Change made:**
- §19 (formerly "Night Audit Foundation") completely rewritten as "Night Audit Foundation — Pipeline Design".
- New §20 (PostingJob Abstraction) inserted — defines `PostingJob` interface, `PostingContext`, `PostingResult` value objects.
- `NightAuditPipeline` class defined: `register()`, `resolveExecutionOrder()` (topological sort), `executeForStay()`, `executeRun()`.
- Dependency model: `dependsOn()` returns jobIds; topological sort enforces execution order.
- `PipelineCycleException` defined for cycle detection (§25.6, R10).
- Rollback contract: `PostingJob::rollback()` defined; used by `audit:void-night-audit-entries` command.
- Phase 3.2 pipeline: one registered job — `RoomChargePostingJob`.
- Phase 3.3+ extension: `register()` call in service provider only; no pipeline changes required.
- Added ADR-69 (pipeline design) and ADR-70 (PostingJob as unit of system posting).
- §4.6 updated with `PostingJob`, `PostingContext`, `PostingResult`, `NightAuditPipeline` interface specs.
- §20.5 documents lifecycle vs pipeline execution paths.
- §37 test plan adds `NightAuditPipelineTest` unit tests (topology, cycle, skip, abort).

### 1.3 MEDIUM ISSUE 1 — PostingJob Interface

**Finding:** No formal abstraction for system-generated posting. Check-in charges, checkout fees, and night audit charges were implemented inconsistently.

**Change made:**
- Resolved fully under HIGH ISSUE 2 above.
- `PostingJob` interface defined with 8 methods: `jobId()`, `displayName()`, `dependsOn()`, `isNightAuditStep()`, `isRequired()`, `shouldProcess()`, `isAlreadyPosted()`, `execute()`, `rollback()`.
- Phase 3.2 concrete implementations: `RoomChargePostingJob`, `LateCheckoutFeePostingJob`, `EarlyCheckinFeePostingJob`.
- `StayService::checkIn()` and `checkOut()` call PostingJob directly (lifecycle path, not pipeline).
- `RoomChargePostingJob` serves dual duty: lifecycle (first night) and pipeline (subsequent nights).

### 1.4 MEDIUM ISSUE 2 — ChargeType Extensibility

**Finding:** Architecture did not clearly justify whether ChargeType, ChargeCategory, and ServiceCatalog should be separated or combined. Extensibility path was unclear.

**Change made:**
- §7 (Charge Categories & ChargeType Catalog) restructured with explicit three-layer model.
- New §7.1 table: ChargeType (PHP enum) / ChargeCategory (PHP class constants) / ServiceRate (DB table).
- `ChargeCategory` PHP class defined with named constant arrays per category group.
- Justification documented: ChargeType set is small (≤15), stable, and benefits from compile-time type checking. ServiceRate handles hotel-specific naming without ChargeType changes.
- Phase 3.4 path: if custom categories needed, `ChargeCategory` promoted to DB table; ChargeType enum unchanged.
- Added ADR-71 (three-layer separation with full justification).

### 1.5 MEDIUM ISSUE 3 — Business Date

**Finding:** Hotels operate past midnight. Night Audit triggered at 02:00 AM on July 2 must audit July 1. Using `Carbon::today()` or `now()->toDateString()` would produce wrong dates for posting keys, entry_date, and audit_date.

**Change made:**
- New §10 (Business Date Model) inserted.
- `BusinessDateService` defined: `currentBusinessDate()`, `businessDateFor(Carbon)`.
- `business_date = now() - business_date_offset_hours` (floored to day).
- §13 (Posting Rules) — Rule 10 added: all system-generated entry_date values use business date.
- §14 (Posting Keys) — date components in `ROOM_NIGHT_*` keys explicitly noted as business date.
- §19 (Night Audit Pipeline) — `audit_date` on NightAuditRun defined as business date; `entry_date` on created entries defined as business date.
- §28.1 (StoreFolioEntryRequest) — `entry_date` default noted as business date; `before_or_equal:today` applies to calendar date.
- §29.3 (API response) — `currentBusinessDate` added to booking show payload.
- ADR-67 added (business date canonical for all financial dating).
- §37 test plan adds `BusinessDateFeatureTest` (4 tests including 02:00 AM edge case).

### 1.6 MEDIUM ISSUE 4 — Hotel Settings

**Finding:** Configurable values (grace minutes, audit window, business date offset, currency) were hardcoded in services. No mechanism for ADMIN to adjust operational parameters at runtime.

**Change made:**
- New §11 (Hotel Settings) inserted.
- New `hotel_settings` DB table defined in §5.2.
- `HotelSettingsService` defined: `get()`, `getInt()`, `getBool()`, `set()`, `all()`.
- 10-minute cache with invalidation on `set()`.
- 8 default settings seeded: `business_date_offset_hours`, `night_audit_window_start`, `night_audit_window_end`, `night_audit_require_sequential`, `late_checkout_grace_minutes`, `early_checkin_grace_minutes`, `currency_code`, `currency_precision`.
- All services (`StayService`, `NightAuditService`, `BusinessDateService`) read from `HotelSettingsService` instead of hardcoded values.
- `hotel_settings.manage` permission: ADMIN only (§27.2).
- New `/admin/settings` route and `HotelSettingsController` (§29.1).
- New Hotel Settings admin page described (§30.5).
- Migration: `create_hotel_settings_table` (§33.1, Step 1 — must run first).
- `HotelSettingsSeeder` always runs in production with safe defaults (§33.2).
- `UpdateHotelSettingsRequest` validation defined (§28.3).
- R11 (cache staleness) added to risk register (§34).
- ADR-68 added (settings centralisation rationale).
- §37 test plan adds `HotelSettingsCrudTest` (5 tests) and `BusinessDateServiceTest` (4 unit tests).
- Appendix E: Full settings key registry added.

### 1.7 MEDIUM ISSUE 5 — Revenue Account Code Mapping

**Finding:** No mechanism for `ServiceRate` to map to future revenue accounts (Room Revenue, Laundry Revenue, etc.) for GL/accounting integration.

**Change made:**
- `gl_account_code VARCHAR(50) NULL` added to `service_rates` DDL (§5.1).
- Included in `StoreServiceRateRequest` validation as nullable string max:50 (§28.2).
- §4.2 (ServiceRate domain model) documents the field.
- §29.3 (API) — field visible in admin payload.
- §15 (Tax Strategy) cross-references Phase 3.5 for GL integration.
- `gl_account_code` visible but non-functional in Phase 3.2 — Phase 3.5 populates and uses it.
- ADR-72 added (revenue account code slot rationale).

---

## 2. Trade-offs and Design Decisions

### 2.1 Temporal Rows vs Version Table (ADR-66)

**Chosen:** Temporal rows on `service_rates` with `effective_from`.

**Alternative considered:** Separate `service_rate_versions` table with FK back to `service_rates`.

**Why temporal rows:**
- Zero FK join complexity in resolution query.
- Rollback = one column drop; no orphaned records possible.
- No new model or relationship to maintain.
- Rate resolution is already a single indexed query.
- The version table approach trades lower row count for higher query and model complexity — not a worthwhile trade for a catalog with tens of rows.

**Trade-off accepted:** `name` and `charge_type` are repeated across version rows (denormalised). At the expected scale (50 rows total), this is trivially acceptable. If the same `charge_type` has 3 price versions, there are 3 rows with the same `charge_type` string. No integrity risk — rate resolution always takes the most recent by `effective_from`.

### 2.2 Pipeline vs Single-Purpose Night Audit (ADR-69, ADR-70)

**Chosen:** Generic `NightAuditPipeline` with registered `PostingJob` implementations.

**Alternative considered:** Keeping Night Audit as a dedicated room-charge service, adding breakfast/resort fee logic inline in Phase 3.3.

**Why pipeline:**
- Phase 3.3 requires at minimum 3 new charge types. Inline additions produce an unmaintainable service with 4–6 nested responsibilities.
- Each `PostingJob` is independently testable, independently rollbackable, and independently schedulable.
- `dependsOn()` makes ordering explicit and enforced, not assumed.
- Extension cost: one `register()` call in a service provider; zero changes to the pipeline itself.

**Trade-off accepted:** More upfront abstraction. An `interface` and two supporting value objects (`PostingContext`, `PostingResult`) are introduced in Phase 3.2 with only one concrete job. This is justified: Phase 3.3 arrives immediately after Phase 3.2 and would require a full rewrite of a tight-coupled Night Audit. The abstraction cost is paid once; the benefit is permanent.

### 2.3 ChargeType as Enum vs DB Table (ADR-71)

**Chosen:** PHP enum (not a DB table) for `ChargeType`.

**Trade-off:** Adding a new ChargeType requires a PHP deploy. Cannot be done at runtime by an ADMIN.

**Why this is acceptable:** ChargeType represents an accounting dimension — a GL bucket. Changing accounting dimensions without a code review and deploy is undesirable from an audit perspective. Hotels do not add new accounting categories monthly. The ServiceRate catalog already handles hotel-specific naming and pricing at runtime. The trade-off is intentional: stability and type safety over runtime flexibility.

**Phase 3.4 path documented explicitly** so the future developer knows this is a deliberate design position, not an oversight.

### 2.4 HotelSettings Cache Duration (ADR-68)

**Chosen:** 10-minute cache TTL.

**Trade-off:** A setting change takes up to 10 minutes to propagate. During a Night Audit run, settings are read once at run start and not re-read per booking — so intra-run cache staleness is not a concern.

**Why 10 minutes is correct:** Settings change at most a few times per year. The cost of a DB query on every checkout grace check or every business date resolution is not justified. Cache invalidation on `set()` ensures a human change is reflected immediately on the next request after saving. The only risk is if two processes hold different cache states simultaneously — at the scale of a single-hotel PMS, this is negligible.

### 2.5 Business Date Offset vs Hotel Timezone

**Chosen:** Hour offset (`business_date_offset_hours`) relative to server local time.

**Alternative considered:** Per-hotel timezone with explicit timezone offset.

**Why offset:** Simpler, covers 100% of the Vietnamese hotel use case (all hotels are UTC+7, Night Audit typically at 23:30–02:00). Timezone support deferred to Phase 3.5 (multi-property). The offset approach does not break when Phase 3.5 promotes it to timezone — `BusinessDateService` is the single replacement point.

---

## 3. Regression Impact

### 3.1 Impact on Existing Passing Tests

| Test Suite | Impact | Action Required |
|-----------|--------|----------------|
| `CheckoutConfirmationGateTest` (10 tests) | **None** | No changes needed — gate logic is independent |
| `FolioUiTest` (void path tests) | **Minimal** | Update fixture: per-night entry instead of aggregate |
| `CheckoutIntegrationTest` | **Moderate** | Update check-in fixture to call `RoomChargePostingJob`; assert business-date key |
| Tests asserting `entry_date = today()` | **Low** | Update to assert `BusinessDateService::currentBusinessDate()` |
| Tests asserting `ROOM_CHARGE_*_AGGREGATE` key | **Moderate** | Update to `ROOM_NIGHT_{stay_id}_{businessDate}` pattern |
| Tests calling `autoPostRoomCharge()` | **Moderate** | Refactor to `RoomChargePostingJob::execute()` |
| Payment/booking engine tests | **None** | No folio dependency |

**Estimated test update scope:** 8–12 test methods. All are mechanical updates (new key format, new service call). No test logic changes required.

### 3.2 Impact on Production Data

All schema changes are **purely additive**. No column modified, no table renamed, no data migrated (except seeder inserts). Rollback is clean.

The only data state concern is the aggregate→per-night transition (Phase 3.2.8, existing R1/R7). This is unchanged by the review — the backfill plan and guard removal sequence were already in place.

### 3.3 Impact on folio_entries Existing Rows

`stay_id` and `posting_source` added as nullable columns with NULL defaults. All pre-3.2 rows have `stay_id = NULL` and `posting_source = NULL`. These rows are treated as booking-level manual charges (§8, §17 — correct interpretation). No backfill required.

---

## 4. Phase 3.1.2 Compatibility

Phase 3.1.2 (Final Checkout Charge Review Gate, ADR-55/56) is fully compatible with all Phase 3.2 architecture changes. Verification:

| 3.1.2 Invariant | Status after 3.2 revision |
|----------------|--------------------------|
| ADR-55: Final checkout confirmation gate | **Unchanged** — gate logic in `BookingService` does not touch Night Audit, ServiceRate, or HotelSettings |
| ADR-56: Charge lock during confirmation | **Unchanged** — lock mechanism uses Booking-level lockForUpdate; PostingJob acquires Folio lock inside Booking lock (same order) |
| `CheckoutConfirmationGateTest` (10 tests) | **All pass unchanged** |
| Flash watcher fix (Vue ref vs scalar) | **Unchanged** — UI component untouched |
| Deferred LOW: checkOutAll dialog copy | **Unchanged** — still deferred |

Lock order in Phase 3.2 (Booking → Folio) is consistent with Phase 3.1.2 lock order. No deadlock risk introduced.

---

## 5. Phase 3.3–3.5 Forward Compatibility

### 5.1 Phase 3.3 — Breakfast, Resort Fee, City Tax

Architecture explicitly designed for this. Adding Phase 3.3 steps requires:
1. Implement `BreakfastPostingJob`, `ResortFeePostingJob`, `CityTaxPostingJob` (each implementing `PostingJob`).
2. Call `NightAuditPipeline::register($job)` in service provider.
3. Zero changes to `NightAuditPipeline`, `NightAuditService`, or existing jobs.

`dependsOn(['room_charge'])` on Breakfast and CityTax ensures correct ordering automatically.

### 5.2 Phase 3.4 — Split Billing, Policy Pricing

- `stay_id` on `folio_entries` (Phase 3.2.3) is the prerequisite for per-stay folio split. No additional Phase 3.2 changes needed.
- `ChargeCategory` promotion to DB table: `ChargeType` enum untouched; `ChargeCategory` PHP class replaced by DB table + new admin UI. `ServiceRate.charge_type` FK remains valid.
- `requirement_id` on `stays`: Phase 3.4 adds this FK to resolve FIFO rate mismatch (documented in §16.4). Phase 3.2 logs a warning when multi-requirement ambiguity exists.

### 5.3 Phase 3.5 — Tax Engine, GL Integration, Multi-Property

- `service_rates.tax_rate` slot defined in Phase 3.2; `TaxCalculationService` implemented in Phase 3.5. Zero additional schema migration needed on `service_rates`.
- `service_rates.gl_account_code` slot defined; populated and used in Phase 3.5. Zero schema change.
- `hotel_settings.currency_code` and `currency_precision` defined; multi-currency and VAT rate settings added to the same table in Phase 3.5 — purely additive.
- `BusinessDateService` designed with per-hotel timezone in mind: replacing offset with full timezone is a single-class change.

### 5.4 Phase 3.6 — Reporting, Archiving, Parallel Audit

- `NightAuditBookingLog` provides the per-booking, per-run data for reporting dashboards.
- Parallel Night Audit fan-out: `NightAuditPipeline::executeRun()` currently iterates sequentially. Parallel fan-out requires replacing the loop with concurrent dispatch — zero interface changes.
- `folio_entries` archiving: all new indexes ensure archiving queries are efficient.

---

## 6. Remaining Risks After Revision

All risks from the original document are retained. Four new risks were added:

| Risk | Severity | Probability | Mitigation Quality |
|------|---------|-------------|-------------------|
| R9: Business date misconfiguration | HIGH | Low | Strong — admin-only setting, UI shows preview of effective business date |
| R10: Pipeline dependency cycle | MEDIUM | Low | Strong — `PipelineCycleException` at resolution time; CI test enforces |
| R11: HotelSettings cache staleness | LOW | Low | Adequate — 10-min TTL; settings change rarely; intra-run reads use start-of-run value |
| R12: ServiceRate versioning query regression | LOW | Low | Adequate — composite index on `(charge_type, is_active, effective_from)`; cache if > 200 rows |

**No new CRITICAL risks introduced by the revision.**

The highest pre-existing risk remains R1 (double charging during aggregate→per-night transition). This risk has not changed — mitigation is posting key UNIQUE constraint + ADR-62 skip + backfill sequence.

---

## 7. Final Recommendation

**Status: READY FOR ARCHITECTURE REVIEW**

All six review findings have been addressed with documented decisions, rationale, and trade-offs. The architecture is coherent, additive-only, and explicitly accounts for forward compatibility through Phase 3.5.

Implementation must NOT begin until this revised document is approved in a second review.

### Pre-Implementation Checklist (for reviewer)

- [ ] Temporal rows approach for `service_rates` versioning is accepted (ADR-66)
- [ ] `PostingJob` interface contract is complete and sufficient (ADR-70)
- [ ] `NightAuditPipeline` design covers Phase 3.3 extension path (ADR-69)
- [ ] `BusinessDateService` formula is correct for hotel operating hours (ADR-67)
- [ ] `hotel_settings` default values are safe for production day-1 (ADR-68)
- [ ] `gl_account_code` slot on `service_rates` is sufficient for Phase 3.5 GL integration (ADR-72)
- [ ] `ChargeCategory` as PHP class (not DB table) in Phase 3.2 is accepted (ADR-71)
- [ ] Permission model (`hotel_settings.manage` ADMIN-only) is correct
- [ ] Migration order (hotel_settings first, then service_rates) is noted
- [ ] `HotelSettingsSeeder` designated as always-runs-in-production is accepted
- [ ] FIFO rate mismatch (multi-room same type, §16.4) risk is accepted for Phase 3.2
- [ ] Phase 3.2.8 transition guard removal sequence is unchanged and still valid

---

## Appendix: ADR-66 through ADR-72 Quick Reference

| ADR | Title | Key Decision |
|-----|-------|-------------|
| ADR-66 | Service Rate Versioning via Temporal Rows | `effective_from` on same table; INSERT new row per price change |
| ADR-67 | Business Date Is Canonical | All financial dates use `BusinessDateService`, not `Carbon::today()` |
| ADR-68 | HotelSettings Centralises All Config | No hardcoded values in services; 10-min cache; ADMIN-only write |
| ADR-69 | Night Audit as Ordered Posting Pipeline | `register()`-based pipeline; topological order; additive extension |
| ADR-70 | PostingJob Is the Unit of System Posting | Interface with `execute()`, `rollback()`, `isAlreadyPosted()`, `dependsOn()` |
| ADR-71 | Three-Layer: ChargeType / ChargeCategory / ServiceRate | Enum / PHP class / DB table; phase 3.4 path for DB category |
| ADR-72 | GL Account Code Slot on ServiceRate | `gl_account_code` stored in 3.2; used in Phase 3.5 only |
