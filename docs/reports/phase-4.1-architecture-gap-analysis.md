# Phase 4.1 Architecture Gap Analysis
## Room Setup Requests vs. Current Phase 3 Architecture

**Date:** 2026-07-04  
**Source Document:** `docs/roadmaps/phase-4.1-room-setup-requests.md` (authored 2026-06-27)  
**Phase 3 Final State:** commit `8117275` (tag: `phase-3.3.6.5`)  
**Branch:** phase-3  

---

## 1. Purpose

The Phase 4.1 architecture document was authored on 2026-06-27, before Phase 3.3.6 was implemented and before Phase 3.3.3–3.3.5 were finalised. This analysis compares every architectural assumption in `phase-4.1-room-setup-requests.md` against the actual Phase 3 deliverables to identify:

- Assumptions that are still valid
- Sections that are outdated or stale
- Components from Phase 3 that Phase 4.1 can reuse
- Required revisions to the Phase 4.1 document before implementation
- Estimated impact on Phase 4.1 implementation effort

---

## 2. Original Assumptions vs. Current Architecture

### 2.1 Database Prerequisites

**Phase 4.1 assumption:**
> "Phase 3.1 complete: folios, folio_entries, payment mutations"
> "Phase 3.2 complete: night_audit_runs, service_rates, night_audit_booking_logs"
> "Phase 3.3 complete: booking_package_flags, RevenueReportService, ReconciliationService"

**Current state:** ✅ ALL CORRECT

All prerequisite tables and services are delivered and committed:
- `folios`, `folio_entries`, `folio_number_sequences` — Phase 3.1B1 ✅
- `hotel_settings`, `service_rates`, `night_audit_runs`, `night_audit_booking_logs` — Phase 3.2 ✅
- `booking_package_flags` — Phase 3.3.2 ✅
- `RevenueReportService`, `ReconciliationService` — Phase 3.3.3–3.3.4 ✅

**Verdict: No revision needed.**

---

### 2.2 City Tax Scope Reference

**Phase 4.1 assumption (implicit — Phase 3.3 section):**
> The Phase 4.1 doc was authored with the understanding that city tax posting was deferred to "Phase 3.4 — City Tax / Occupancy Tax."

**Current state:** ⚠️ OUTDATED

City tax is implemented as `CityTaxPostingJob` in Phase 3.3.6.1 (currently on disk, uncommitted). The `ChargeType::CityTax` enum case has been added. The `booking_package_flags` table does NOT store city tax enrollment — instead, city tax is controlled by a hotel-level setting (`city_tax_enabled` in `hotel_settings`).

**Impact on Phase 4.1:** None — Phase 4.1 does not reference city tax directly. However, the "Phase 3.4" label no longer exists; if Phase 4.1 implementation planning refers to "Phase 3.4 city tax," this is a dead reference.

**Verdict: Informational only. Update Phase 4.1 blocker section to note city tax was completed in Phase 3.3.6.**

---

### 2.3 Phase 4.4 — Night Audit Nightly Room Charge

**Phase 4.1 assumption:**
> "Phase 4.4 — Night Audit nightly room charge posting"

**Current state:** ❌ INCORRECT — Phase 4.4 in the Phase 4.1 roadmap overview describes Night Audit as a future Phase 4 item.

Night Audit pipeline (`NightAuditPipeline`, `RoomChargePostingJob`, `NightAuditService`, `NightAuditOperationsService`, posting keys, run/log tables, per-job summary) was delivered in **Phase 3.2 + Phase 3.3.1 + Phase 3.3.6.5**. This is 100% complete.

**Impact on Phase 4.1:** This is a roadmap labelling inconsistency in the Phase 4.1 document's "Phase 4 overview" section. It does not affect the Phase 4.1 implementation plan itself (Room Setup Requests) but will cause confusion during planning. If any Phase 4 sub-phase is listed as "Phase 4.4 — Night Audit," it must be relabelled.

**Verdict: Phase 4.1 document's Phase 4 roadmap overview requires correction before Phase 4 kickoff to remove/relabel the "Night Audit" Phase 4.4 entry.**

---

### 2.4 `StayService::createStayFromAssignment()` Integration Point

**Phase 4.1 assumption:**
> "When a stay is created (StayService::createStayFromAssignment()), auto-link pending requests with null stay_id to the new stay for single-stay bookings."

**Current state:** ✅ VERIFIED — Method exists at `app/Services/StayService.php`, line 32.

The method signature matches the expected hook location. Phase 4.1 implementation adds a service call inside this method after the Stay is created.

**Verdict: No revision needed. Integration point is confirmed.**

---

### 2.5 `BookingService::cancelBooking()` Integration Point

**Phase 4.1 assumption:**
> "On booking cancellation (BookingService::cancelBooking()), auto-cancel all pending and acknowledged requests."

**Current state:** ✅ VERIFIED — Method exists at `app/Services/BookingService.php`, line 344.

**Verdict: No revision needed. Integration point is confirmed.**

---

### 2.6 HOUSEKEEPING Role

**Phase 4.1 assumption:**
> "HOUSEKEEPING role can view and fulfill requests via the Yêu cầu tab. Permissions: special_request.fulfill."

**Current state:** ✅ VERIFIED — `HOUSEKEEPING` role is seeded in `database/seeders/RolePermissionSeeder.php`, line 17.

No existing Phase 3 permissions assigned to HOUSEKEEPING conflict with Phase 4.1. The new `special_request.fulfill` permission will be the first HOUSEKEEPING-assigned permission (HOUSEKEEPING currently has no folio, payment, or audit permissions).

**Verdict: No revision needed. HOUSEKEEPING role exists and is clean.**

---

### 2.7 `stay_id` FK on `folio_entries`

**Phase 4.1 assumption:**
> "booking_special_requests.stay_id references stays.id (nullable) — mirrors the pattern established in folio_entries.stay_id."

**Current state:** ✅ VERIFIED — Migration `2026_07_02_000020` added `folio_entries.stay_id` as a nullable FK referencing `stays.id`.

The nullable FK pattern is exactly what Phase 4.1 proposes for `booking_special_requests.stay_id`. The model is already proven.

**Verdict: No revision needed. Pattern is confirmed and established.**

---

### 2.8 `BookingPackageFlag` Pattern Reference

**Phase 4.1 assumption:**
> "Similar to BookingPackageFlag, requests are associated per-booking with optional per-stay attribution."

**Current state:** ✅ VERIFIED — `BookingPackageFlag` model and `booking_package_flags` table are delivered (Phase 3.3.2). The `UNIQUE(booking_id, package_key)` pattern is distinct from the `booking_special_requests` design (which allows multiple rows per booking+category).

**Verdict: Pattern similarity is valid; no structural conflict.**

---

### 2.9 Permission System

**Phase 4.1 assumption:**
> "New permissions: special_request.create, special_request.fulfill, special_request.cancel"
> "Roles: ADMIN, MANAGER, RECEPTION can create; HOUSEKEEPING, ADMIN, MANAGER can fulfill; ADMIN, MANAGER, RECEPTION can cancel (own); ADMIN can cancel any"

**Current state:** The permission system (via Spatie Laravel Permission) is operational. Phase 3 added: `folio.*`, `folio_entry.*`, `payment.*`, `night_audit.*`, `revenue.view`, `reconciliation.view`, `package.manage` permissions. None conflict with `special_request.*`.

**Verdict: No revision needed. Permission system is ready to accept new entries.**

---

### 2.10 Booking Detail UI Tab Pattern

**Phase 4.1 assumption:**
> "New 'Yêu cầu' tab in Bookings/Show.vue alongside existing tabs."

**Current state:** `resources/js/Pages/Admin/Booking/Show.vue` already has multiple tabs (Details, Financial, Folio, Packages, Posting Timeline). The tab pattern is established with Inertia-compatible navigation and `$page.url` or prop-based active tab detection.

**Verdict: No revision needed. The tab component pattern is confirmed and reusable.**

---

### 2.11 `booking_special_requests` Table Design

**Phase 4.1 assumption:**
```sql
CREATE TABLE booking_special_requests (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id        BIGINT UNSIGNED NOT NULL,
  stay_id           BIGINT UNSIGNED NULL,
  category          VARCHAR(50) NOT NULL,     -- RequestCategory enum
  request_type      VARCHAR(64) NOT NULL,
  quantity          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  note              TEXT NULL,
  status            VARCHAR(20) NOT NULL DEFAULT 'pending',
  requested_by      BIGINT UNSIGNED NOT NULL,
  acknowledged_by   BIGINT UNSIGNED NULL,
  acknowledged_at   TIMESTAMP NULL,
  fulfilled_by      BIGINT UNSIGNED NULL,
  fulfilled_at      TIMESTAMP NULL,
  created_at        TIMESTAMP NULL,
  updated_at        TIMESTAMP NULL
);
```

**Current state:** This table does NOT exist. Phase 3 did not create it. All Phase 3 financial tables are entirely separate in domain.

**Verdict: No conflict. Table must be created fresh in Phase 4.1.1.**

---

### 2.12 `RequestCategory` and `RequestStatus` Enums

**Phase 4.1 assumption:**
```php
enum RequestCategory: string {
    case BedConfig = 'bed_config';
    case ExtraItem = 'extra_item';
    case Decoration = 'decoration';
    case Accessibility = 'accessibility';
    case General = 'general';
}

enum RequestStatus: string {
    case Pending = 'pending';
    case Acknowledged = 'acknowledged';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}
```

**Current state:** Neither enum exists. Phase 3 enums added: `ChargeType`, `ChargeCategory`, `FolioStatus`, `PaymentMethod`, `PaymentType`, `PriceSource`, `RateStatus`. None conflict.

**Verdict: No conflict. Both enums must be created fresh.**

---

### 2.13 `SpecialRequestService` and `SpecialRequestPolicy`

**Phase 4.1 assumption:**
> "SpecialRequestService handles create, acknowledge, fulfill, cancel with status transition guard."
> "BookingSpecialRequestPolicy enforces permission rules and cancel scope (own vs. any)."

**Current state:** Neither service nor policy exists. Phase 3 service patterns (FolioService, FolioPolicy pattern) can be used as implementation templates.

**Existing reusable patterns:**
- `FolioService` — immutable status transition pattern (OPEN→CLOSED→VOIDED) maps to (pending→acknowledged→fulfilled/cancelled)
- `FolioPolicy` / `FolioEntryPolicy` — ADMIN/MANAGER scope + role-based access pattern is identical to what Phase 4.1 needs
- `AuditObserver` — auto-applied to new models (Booking, Folio, etc.); Phase 4.1 should register `BookingSpecialRequest` for audit trail

**Verdict: No conflict. Both must be created. Use Phase 3 service patterns as structural templates.**

---

## 3. Outdated Sections in Phase 4.1 Document

| Section | Issue | Recommended Fix |
|---------|-------|-----------------|
| "Ready for Implementation: NO" blocker box | All listed prerequisites (Phase 3.1, 3.2, 3.3) are complete | Change to "READY — prerequisites met as of 2026-07-04" |
| "Phase 4.4 — Night Audit room charge posting" | Night Audit is Phase 3.2, not Phase 4 | Remove or relabel to "Delivered in Phase 3.2" |
| "City tax — Phase 3.4 (future)" | CityTaxPostingJob is implemented in Phase 3.3.6.1 | Update to "Delivered in Phase 3.3.6.1" |
| Phase 4.1 implementation timeline | References "after Phase 3.3 completes" as a future condition | Update to "Phase 3 complete as of 2026-07-04" |

---

## 4. Reusable Phase 3 Components

The following Phase 3 components can be directly reused or adapted in Phase 4.1 with minimal rework:

### 4.1 Database Patterns

| Pattern | Phase 3 Example | Phase 4.1 Application |
|---------|----------------|----------------------|
| Nullable `stay_id` FK | `folio_entries.stay_id` | `booking_special_requests.stay_id` |
| Soft-state via `voided_at` / `cancelled_at` | `folio_entries.voided_at` | `booking_special_requests` cancelled transition |
| Actor FK columns (created_by, etc.) | `folio_entries.voided_by` | `acknowledged_by`, `fulfilled_by` columns |
| Timestamp pairs (`*_by` + `*_at`) | `folio_entries.voided_by`, `voided_at` | `acknowledged_by/at`, `fulfilled_by/at` |

### 4.2 Service Patterns

| Phase 3 Service | Phase 4.1 Adaptation |
|----------------|---------------------|
| `FolioService::voidEntry()` status guard pattern | `SpecialRequestService::cancel()` terminal status guard |
| `FolioService::autoCloseFolio()` idempotency check | `SpecialRequestService::fulfill()` idempotency (already-fulfilled returns early) |
| `BookingPaymentService::deletePayment()` terminal booking block | `SpecialRequestService::create()` terminal booking block |
| `HotelSettingsService::get()` key-value store | Hotel-level defaults for special request types |

### 4.3 Policy Patterns

| Phase 3 Policy | Phase 4.1 Adaptation |
|---------------|---------------------|
| `FolioPolicy::close()` — ADMIN, MANAGER only | `SpecialRequestPolicy::fulfill()` — HOUSEKEEPING, ADMIN, MANAGER |
| `FolioEntryPolicy::void()` — Manager scope (today only) | `SpecialRequestPolicy::cancel()` — own vs. any scope |

### 4.4 Observer Patterns

`AuditObserver` is applied to key models (Booking, Folio, FolioEntry, etc.). Phase 4.1 should apply it to `BookingSpecialRequest` by adding it to `app/Providers/AppServiceProvider.php` (or wherever observers are registered).

### 4.5 UI Patterns

| Phase 3 Component | Phase 4.1 Adaptation |
|-----------------|---------------------|
| `Booking/Packages.vue` — status cards, enroll/unenroll actions | Request list with action buttons per request |
| `NightAudit/Show.vue` — filterable table with status badges | Request list with status filter |
| `Admin/Booking/Show.vue` — tab navigation | Add "Yêu cầu" tab alongside existing tabs |
| Status badge components (custom colors per status) | Pending (yellow), Acknowledged (blue), Fulfilled (green), Cancelled (gray) |

### 4.6 Test Patterns

| Phase 3 Test | Phase 4.1 Application |
|-------------|----------------------|
| `FolioCrudTest.php` — status transition matrix | `SpecialRequestTransitionTest.php` — pending→acknowledged→fulfilled, rejected→cancelled |
| `PackageEnrollmentControllerTest.php` — role-gated controller | `SpecialRequestControllerTest.php` — role-gated CRUD |
| `FolioUiTest.php` — Inertia page structure | `SpecialRequestUiTest.php` — Yêu cầu tab renders |

---

## 5. Required Revisions Before Phase 4.1 Implementation

The following changes must be applied to `docs/roadmaps/phase-4.1-room-setup-requests.md` before implementation begins. **This analysis is NOT modifying that document.** This section lists what the revision pass must address.

### R1 — Update Implementation Blocker Box

**Current text (approximate):** "Status: Blocked — Phase 3.1, 3.2, 3.3 must complete."  
**Required:** Update to "Status: READY — all Phase 3 prerequisites met as of 2026-07-04."

### R2 — Remove/Clarify "Phase 4.4 — Night Audit"

**Current text:** Phase 4 roadmap overview lists Night Audit as Phase 4.4.  
**Required:** Relabel as "Delivered in Phase 3.2. Not a Phase 4 item."

### R3 — City Tax Reference Correction

**Current text:** References "City tax → Phase 3.4 (future)."  
**Required:** Update to "Delivered in Phase 3.3.6.1."

### R4 — Add Explicit Integration Point References

Add to Phase 4.1 implementation task for `StayService::createStayFromAssignment()`:
> "Hook location: `app/Services/StayService.php` line 32, after Stay model is persisted."

Add to Phase 4.1 implementation task for `BookingService::cancelBooking()`:
> "Hook location: `app/Services/BookingService.php` line 344, before method returns."

### R5 — Add AuditObserver Registration

Phase 4.1 must register `AuditObserver::observe(BookingSpecialRequest::class)` in the appropriate service provider. This was not mentioned in the original Phase 4.1 document.

### R6 — ADR Numbering

Phase 4.1 ADRs should start from **ADR-80** (Phase 3 used ADR-1 through ~ADR-79). The first ADR should formalise the `booking_special_requests` table design (nullable `stay_id` bridge, status state machine, actor timestamp pairs).

---

## 6. Estimated Impact on Phase 4.1 Effort

| Category | Original Estimate | Revised Estimate | Change |
|---------|-----------------|-----------------|--------|
| Phase 3 prerequisites blocked | Blocked | ✅ Unblocked | -0 days (unblocked not simplified) |
| `booking_special_requests` schema | Still needed | Still needed | No change |
| `SpecialRequestService` | Still needed | Templates available from FolioService | -0.25 days |
| `BookingSpecialRequestPolicy` | Still needed | Templates available from FolioPolicy | -0.25 days |
| Integration hooks | Still needed | Hook lines confirmed (StayService:32, BookingService:344) | -0.25 days |
| HOUSEKEEPING role setup | Still needed | Role already seeded | -0.5 days |
| Night Audit Phase 4.4 | Was planned | Already done — remove from scope | -1.5 days |
| City tax "Phase 3.4" | Was planned | Already done — remove from scope | -1.0 days |

**Net impact: Phase 4.1 is approximately 1.5–2 days shorter than originally estimated** because Night Audit (listed as Phase 4.4) and CityTax (listed as Phase 3.4) are already delivered, and the HOUSEKEEPING role pre-exists.

**Revised Phase 4.1 estimate: ~2–2.5 days** (down from the implied ~4 days when Night Audit and City Tax scope were included).

---

## 7. No-Change Sections

The following sections of the Phase 4.1 document require **no changes** — they remain fully accurate given Phase 3's final state:

- `booking_special_requests` full table DDL
- `RequestCategory` enum values and meanings
- `RequestStatus` state machine (pending → acknowledged → fulfilled / cancelled)
- Auto-link logic description for single-stay bookings
- Auto-cancel logic description for booking cancellation
- Permission matrix (`special_request.create`, `special_request.fulfill`, `special_request.cancel`)
- "Yêu cầu" tab placement in `Bookings/Show.vue`
- Inertia-based controller response pattern
- All Phase 4.1 sub-task breakdown (4.1.1 through 4.1.3)

---

## 8. Summary Verdict

| Dimension | Status |
|-----------|--------|
| Phase 3 prerequisites | ✅ All met |
| Integration points | ✅ All confirmed to exist |
| Schema conflicts | ✅ None |
| Enum conflicts | ✅ None |
| Permission conflicts | ✅ None |
| Outdated roadmap references | ⚠️ 3 sections need revision (Night Audit 4.4, City Tax 3.4, blocker box) |
| Missing from Phase 4.1 doc | ⚠️ AuditObserver registration, explicit hook line references, ADR numbering |
| Effort impact | ✅ Shorter than originally estimated (−1.5 to −2 days) |

**Phase 4.1 is architecturally sound and ready to implement.** The Phase 4.1 document needs a targeted revision pass for the 3 outdated sections identified above before implementation begins. The core schema, service design, and state machine are unaffected by Phase 3 additions.
