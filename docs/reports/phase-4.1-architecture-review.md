# Phase 4.1 Architecture Review
## Room Setup Requests / Yêu cầu chuẩn bị phòng

**Date:** 2026-07-04
**Reviewer:** Architecture Review Process
**Branch:** phase-3
**HEAD Commit:** 892dc1b (Phase 3 officially closed)
**Status:** REVIEW COMPLETE — PASS
**ADRs Issued:** ADR-80, ADR-81, ADR-82, ADR-83 (docs/adr/)

---

## 1. Executive Summary

Phase 4.1 proposes a **`booking_special_requests` table** to capture operational room setup requests (bed configuration, extra items, decorations, accessibility) that belong to the guest's visit but span two lifecycle phases: booking (booking exists, room not yet assigned) and stay (room assigned, preparation needed). The design uses a **nullable `stay_id` bridge** — the same pattern established for `folio_entries.stay_id` in Phase 3.3 — making it architecturally consistent with the existing codebase.

The core design is **architecturally sound**. The entity scope is correct. The status machine is well-defined. The service layer follows Phase 3 FolioService patterns. No financial data is touched.

All architecture blockers have been resolved: ADR-80 through ADR-83 have been issued and accepted. The roadmap has been updated to reflect all required changes. No remaining blockers.

**Final Decision: PASS**
**Permitted to write Implementation Plan: YES**

---

## 2. Input Documents Reviewed

| Document | Date | Status |
|---|---|---|
| `docs/roadmaps/phase-4.1-room-setup-requests.md` | 2026-06-27 | Design Proposal (pre-Phase 3.3 completion) |
| `docs/reports/phase-4.1-architecture-gap-analysis.md` | 2026-07-04 | Completed gap analysis vs. Phase 3 final state |
| `docs/architecture/phase-3-architecture-baseline.md` | 2026-07-04 | Official Phase 3 architecture baseline (13 sections) |
| `docs/reports/phase-3-final-report.md` | 2026-07-04 | Phase 3 final completion report |

All four documents were read in full. Code verification was performed against live application files:
- `app/Services/BookingService.php` line 344: `cancelBooking()` confirmed
- `app/Services/StayService.php` line 32: `createStayFromAssignment()` confirmed
- `app/Providers/AppServiceProvider.php` lines 77–92: AuditObserver registration list confirmed
- `database/seeders/RolePermissionSeeder.php` lines 118–122: HOUSEKEEPING permissions confirmed

---

## 3. Phase 3 Baseline Compatibility

### 3.1 Prerequisites — ALL MET

| Prerequisite | Status | Evidence |
|---|---|---|
| Phase 3.1: `folios`, `folio_entries`, `booking_payments` | ✅ COMPLETE | Commit `68abffa`, tag `phase-3.1` |
| Phase 3.2: `service_rates`, `night_audit_runs`, `hotel_settings` | ✅ COMPLETE | Commit `e1ac762`, tag `phase-3.2` |
| Phase 3.3: `booking_package_flags`, CityTaxPostingJob, ReconciliationService | ✅ COMPLETE | Commit `9935423`, tag `phase-3.3.6.3` |
| NightAuditPipeline (RoomChargePostingJob, NightAuditService) | ✅ COMPLETE | Phase 3.2 — NOT Phase 4.4 (see §11) |
| Spatie Laravel Permission RBAC | ✅ OPERATIONAL | 17 permissions across 5 roles |

### 3.2 No-Conflict Verification

| Component | Phase 3 State | Phase 4.1 Impact |
|---|---|---|
| `folio_entries` domain | Financial charges only | `booking_special_requests` is operational only — no FK into folio domain |
| `booking_payments` domain | Payment records | Not touched by Phase 4.1 |
| `NightAuditPipeline` | Fully operational | Phase 4.1 adds no PostingJob |
| Existing permissions | 17 permissions defined | New `special_request.*` permissions have no name conflicts |
| `folio_entries.stay_id` nullable pattern | Established in migration `2026_07_02_000020` | Phase 4.1 replicates the same pattern — confirmed valid |
| HOUSEKEEPING role | Seeded with `room.assign`, `room.unassign`, `rooms.manage` | No conflict — `special_request.fulfill` is first financial-adjacent permission |

### 3.3 Reusable Phase 3 Components

Phase 4.1 can directly use or adapt:
- **`FolioService` status transition pattern** → `SpecialRequestService::cancel()` terminal guard
- **`FolioPolicy` / `FolioEntryPolicy` RBAC patterns** → `BookingSpecialRequestPolicy`
- **`AuditObserver`** → register `BookingSpecialRequest` in `AppServiceProvider.php`
- **`Booking/Show.vue` tab navigation pattern** → add "Yêu cầu" tab
- **`BookingPackageFlag` actor FK columns** (`acknowledged_by/at`, `fulfilled_by/at`) → directly mirrors `folio_entries.voided_by`, `voided_at`

**Phase 3 Baseline Compatibility: PASS**

---

## 4. Proposed Scope Review

### 4.1 Scope Definition

Phase 4.1 defines Room Setup Requests as **pure operational instructions** with these characteristics:

| Property | Value | Assessment |
|---|---|---|
| Affects pricing? | No | ✅ Correct — no `FolioEntry` created |
| Affects availability? | No | ✅ Correct — no `RoomAssignment` conflict check |
| Affects Night Audit? | No | ✅ Correct — no PostingJob |
| Lifecycle owner | Guest's visit (spans Booking + Stay) | ✅ Correct entity analysis |
| Long-term home | Future Housekeeping module | ✅ Correct phasing |

### 4.2 "Not-a-service-charge" Boundary

The roadmap explicitly states: "If a request incurs a charge (e.g., flower decoration), the charge is separately entered via `FolioService::addCharge()`. The request and charge are independent records." This is architecturally correct. Phase 3's single-sided ledger principle (P1) is preserved — operational requests and financial charges are decoupled.

### 4.3 Scope Boundary with Phase 4.2

Phase 4.1 provides the **data foundation** (table + status + stay_id link). Phase 4.2 (Housekeeping Board) consumes that data for operational views. The boundary is clean and well-defined.

**Scope Review: PASS**

---

## 5. Database Architecture Review

### 5.1 Table Design — Core Assessment

The `booking_special_requests` proposed schema is structurally sound. The nullable `stay_id` FK mirrors the established `folio_entries.stay_id` pattern (migration `2026_07_02_000020`). The `category` + `request_type` split (enum-enforced + string-free) is the correct extensibility trade-off.

### 5.2 Issues Found

**ISSUE DB-1: `booking_id` FK — CASCADE vs RESTRICT (MEDIUM)**

The roadmap specifies `ON DELETE CASCADE` for `booking_id`. Phase 3 Architecture Baseline Principle P8 (atomic checkout) and the folio FK convention use `RESTRICT` for `folios.booking_id` to prevent silent data loss. Using `CASCADE` means hard-deleting a booking silently removes all its special requests. Bookings should never be hard-deleted in production (only cancelled), but RESTRICT is the safer and more consistent choice.

> **Required change:** Change `booking_id` FK to `ON DELETE RESTRICT`. If hard-deletion of bookings is ever needed, the service layer should explicitly handle orphan cleanup.

**ISSUE DB-2: `requested_by` — NULL vs NOT NULL (MEDIUM)**

The roadmap schema uses `requested_by BIGINT UNSIGNED NULL`. The gap analysis schema (section 2.11) uses `requested_by BIGINT UNSIGNED NOT NULL`. Per the operational flow, every request is always recorded by a logged-in staff member (ADMIN, MANAGER, RECEPTION). There is no automated system path that would create a request without an actor.

> **Required change:** Clarify and standardize to `NOT NULL`. If a future system-automated path is needed (e.g., auto-created from booking form), this can be revisited at that time.

**ISSUE DB-3: Missing `cancelled_by` / `cancelled_at` columns (MEDIUM)**

`cancelled` is a terminal state but has no actor tracking. Phase 3 consistently tracks actors on all terminal transitions:
- `folio_entries.voided_by`, `voided_at`
- `booking_payments` (deleted_by pattern)
- `room_assignments.released_by`, `released_at`

Cancelling a request without recording who cancelled it and when breaks the audit trail pattern established in Phase 3.

> **Required change:** Add `cancelled_by BIGINT UNSIGNED NULL` + `cancelled_at TIMESTAMP NULL` columns. Add `CONSTRAINT fk_bsr_can_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL`.

**ISSUE DB-4: VARCHAR size inconsistency (LOW)**

`category` column: roadmap specifies `VARCHAR(32)`, gap analysis shows `VARCHAR(50)`. All defined category values are ≤ 13 chars (`bed_config`, `accessibility`).

> **Recommended:** Standardize to `VARCHAR(32)` in the implementation migration.

**ISSUE DB-5: Missing composite index (LOW)**

The most frequent query — fetching pending requests for a booking (`WHERE booking_id = ? AND status NOT IN ('fulfilled', 'cancelled')`) — benefits from a composite index. Four separate single-column indexes are defined; none cover the most common access pattern.

> **Recommended:** Add `INDEX idx_bsr_booking_status (booking_id, status)` alongside or replacing the two separate `idx_bsr_booking` and `idx_bsr_status` indexes.

### 5.3 Valid Design Decisions (No Change Needed)

| Decision | Rationale | Assessment |
|---|---|---|
| `stay_id ON DELETE SET NULL` | Request persists on booking when stay is cancelled/re-assigned | ✅ Correct |
| `request_type` as VARCHAR(64), not DB enum | Zero-migration extensibility for new request types | ✅ Correct |
| `quantity SMALLINT UNSIGNED` | Handles countable items (extra pillows: 3) | ✅ Correct |
| No `amount` field | Charges tracked separately in FolioEntry | ✅ Correct |
| No soft delete | `cancelled` terminal state replaces deletion | ✅ Consistent with Phase 3 pattern |

**Database Architecture Review: PASS WITH CHANGES (DB-1, DB-2, DB-3 required)**

---

## 6. Service Layer Review

### 6.1 `SpecialRequestService` Design

Proposed methods: `addRequest()`, `linkToStay()`, `acknowledge()`, `fulfill()`, `cancel()`, `autoCancelForBooking()`, `autoLinkSingleStayRequests()`.

The method set is complete and appropriate. Each method maps to a clear business operation. The pattern mirrors `FolioService` (immutable status transitions, terminal state guards, idempotency on re-fulfill).

**ISSUE SVC-1: Acknowledge permission undefined (MEDIUM)**

The status machine allows `pending → acknowledged`. The `acknowledge()` method requires a permission check. The roadmap defines three permissions (`special_request.create`, `special_request.fulfill`, `special_request.cancel`) but no `special_request.acknowledge`. Who can acknowledge?

Per the operational flow, Housekeeping would acknowledge before fulfilling. The most coherent design: `special_request.fulfill` grants both acknowledge and fulfill (HOUSEKEEPING, ADMIN, MANAGER). This should be explicit in the Implementation Plan.

> **Required clarification:** Document that `special_request.fulfill` covers both acknowledge and fulfill transitions. No fourth permission is needed.

**ISSUE SVC-2: Terminal booking guard on `addRequest()` (MEDIUM)**

The implementation plan mentions `StoreBookingSpecialRequestRequest` validates category and request_type. It does not specify which booking statuses block request creation. Per Phase 3 baseline, terminal booking statuses are: CHECKED_OUT, CANCELLED, NO_SHOW. The service should guard: if `$booking->status` is terminal, throw a `ValidationException`.

> **Required clarification:** Add explicit terminal booking status guard to `SpecialRequestService::addRequest()`. Follow `BookingPaymentService::createPayment()` pattern.

### 6.2 `SpecialRequestPolicy` Design

The policy structure (create/fulfill/cancel per role) is correct. The "own vs. any cancel" scope distinction for RECEPTION vs. MANAGER/ADMIN is appropriate. No issues.

**Service Layer Review: PASS WITH CHANGES (SVC-1 required, SVC-2 documentation required)**

---

## 7. Lifecycle Integration Review

### 7.1 `BookingService::cancelBooking()` Hook (line 344)

The `cancelBooking()` method at `app/Services/BookingService.php:344` is wrapped in `DB::transaction()`. The auto-cancel hook `SpecialRequestService::autoCancelForBooking()` will execute **inside the transaction** — correct. If the update fails, the entire cancellation rolls back atomically.

The auto-cancel query is: `UPDATE booking_special_requests SET status = 'cancelled' WHERE booking_id = ? AND status NOT IN ('fulfilled', 'cancelled')`. This is idempotent and low-risk.

**Assessment: PASS** ✅

### 7.2 `StayService::createStayFromAssignment()` Hook (line 32)

The `createStayFromAssignment()` method at `app/Services/StayService.php:32` uses `firstOrCreate()` **without a `DB::transaction()` wrapper**. The auto-link hook `SpecialRequestService::autoLinkSingleStayRequests()` will execute **outside a transaction**.

**ISSUE LI-1: Auto-link is not transactional (MEDIUM)**

If the auto-link fails after Stay creation, the Stay record exists but pending requests remain unlinked. However, this is acceptable if the auto-link is:
1. **Non-throwing** — failure logs a warning but does not throw (preventing stay creation failure)
2. **Idempotent** — can be re-run manually without side effects
3. **Recoverable** — staff can manually link via UI

> **Required implementation constraint:** `SpecialRequestService::autoLinkSingleStayRequests()` MUST catch its own exceptions, log them, and return gracefully. Stay creation must never fail due to auto-link failure. Document this in the implementation plan.

### 7.3 Multi-Stay Auto-Link Ambiguity

The roadmap correctly handles R1 (multi-stay bed_config ambiguity): do NOT auto-link when multiple stays exist for that booking; leave `stay_id = null`; show UI prompt. This is the correct conservative approach.

**Assessment: PASS** ✅

### 7.4 Checkout / Cancellation of Individual Stays

When a stay is cancelled (not the whole booking) and the stay FK is `ON DELETE SET NULL`, the request correctly reverts to `stay_id = null`. The request remains on the booking with its current status. If it was `pending`, it stays pending and may be re-linked to a replacement stay. This is correct behavior.

**Lifecycle Integration Review: PASS WITH CHANGES (LI-1 required)**

---

## 8. Permission Matrix Review

### 8.1 Proposed Permission Matrix

| Permission | ADMIN | MANAGER | RECEPTION | HOUSEKEEPING | ACCOUNTANT |
|---|---|---|---|---|---|
| `special_request.create` | ✅ | ✅ | ✅ | ❌ | ❌ |
| `special_request.fulfill` | ✅ | ✅ | ❌ | ✅ | ❌ |
| `special_request.cancel` | ✅ | ✅ | ❌ (own only?) | ❌ | ❌ |

### 8.2 Issues Found

**ISSUE PM-1: RECEPTION cancel scope ambiguous (MEDIUM)**

The roadmap says `special_request.cancel → ADMIN, MANAGER` only. RECEPTION cannot cancel requests. However, RECEPTION can create requests. If RECEPTION makes an erroneous entry, they cannot remove it — they must escalate to MANAGER. This is a deliberate design choice but should be documented as an explicit ADR decision (not an oversight).

> **Required clarification:** Document explicitly in ADR-81 or phase implementation plan that RECEPTION cannot cancel (escalation required). If this is intentional, mark it as ACCEPTED DESIGN.

**ISSUE PM-2: HOUSEKEEPING `acknowledge` permission undefined (linked to SVC-1)**

Same as SVC-1. Document that `special_request.fulfill` covers both acknowledge and fulfill for HOUSEKEEPING.

**Permission Matrix Review: PASS WITH CHANGES (PM-1 documentation required)**

---

## 9. UI Architecture Review

### 9.1 Booking Detail — "Yêu cầu" Tab

New tab in `Booking/Show.vue` (ADMIN, MANAGER, RECEPTION). The tab pattern is established in Phase 3 (Tài chính, Folio, Packages, Posting Timeline tabs). This is a standard Inertia-compatible tab addition. No architectural concerns.

**Assessment: PASS** ✅

### 9.2 Stay Detail Inline Display

Inline request list within the Stay card (fulfilled/pending/cancelled icons). Non-interactive for most roles; read-only display. Low complexity.

**Assessment: PASS** ✅

### 9.3 Room Board Indicator Badge

Phase 2.x Room Board shows pending request count badge per room. The indicator is visible to ADMIN, MANAGER, RECEPTION, HOUSEKEEPING (all four roles).

**Assessment: PASS** ✅

### 9.4 ~~CRITICAL — HOUSEKEEPING Fulfill Workflow Gap~~ → RESOLVED (ADR-81)

**~~ISSUE UI-1~~** — **RESOLVED via ADR-81 (Option A, 2026-07-04)**

**Decision:** HOUSEKEEPING is granted access to the "Yêu cầu" tab in **Restricted Mode**:
- ✅ View all requests
- ✅ Acknowledge pending requests (`pending → acknowledged`)
- ✅ Fulfill acknowledged requests (`acknowledged → fulfilled`)
- ❌ Cannot create, cancel, edit, or delete requests

The tab renders for all roles (ADMIN, MANAGER, RECEPTION, HOUSEKEEPING). Action buttons are conditionally shown via `hasPermission()` check. Backend `BookingSpecialRequestPolicy` enforces all gates server-side.

`special_request.fulfill` covers both acknowledge and fulfill transitions — no separate `special_request.acknowledge` permission is introduced.

See `docs/adr/ADR-81-housekeeping-restricted-ui.md` for full context and alternatives considered.

### 9.5 Room Board Indicator Not in Implementation Checklist (MEDIUM)

The Phase 4.1.2 Frontend checklist mentions the Room Board indicator (item 5) but Phase 4.1.1 and Phase 4.1.3 checklists do not include corresponding backend queries for it. The backend must provide an efficient count endpoint or eager-load pending request counts with Room Board data.

> **Required addition to Implementation Plan:** Add backend Room Board support to Phase 4.1.1 checklist: a query/scope that counts pending requests per room for the Room Board view.

**UI Architecture Review: FAIL on ISSUE UI-1 — REQUIRED RESOLUTION before Implementation Plan**

---

## 10. Audit & Regression Risk Review

### 10.1 Financial Foundation — No Risk

Phase 4.1 makes no changes to:
- `FolioService::calculateGuardedFolioTotal()` — unchanged
- `folio_entries` — no new rows from setup requests
- `booking_payments` — untouched
- `NightAuditPipeline` — no new PostingJob
- `ServiceRateService` — not invoked

**Phase 3 Financial Foundation: ZERO REGRESSION RISK** ✅

### 10.2 Existing Service Hook Risk

Two service method modifications (cancelBooking + createStayFromAssignment) are additive. Neither changes existing behavior — they append new calls after existing logic completes.

- `cancelBooking()` — adding auto-cancel inside existing transaction: LOW RISK
- `createStayFromAssignment()` — adding non-throwing auto-link after `firstOrCreate`: LOW RISK (subject to LI-1 constraint)

### 10.3 AuditObserver Registration (REQUIRED)

`BookingSpecialRequest` model must be added to the AuditObserver registration in `app/Providers/AppServiceProvider.php` (lines 77–92). This was not mentioned in the original Phase 4.1 roadmap but is identified in gap analysis R5.

Current registration list ends at `Stay::observe(AuditObserver::class)` (line 92). `BookingSpecialRequest` must be added as line 93.

> **Required addition to Implementation Plan Phase 4.1.1:** After creating the `BookingSpecialRequest` model, register `BookingSpecialRequest::observe(AuditObserver::class)` in `AppServiceProvider.php`.

### 10.4 Regression Test Baseline

Phase 3 regression baseline: **553 tests / 26 test files / 311 assertions**.

Phase 4.1 must:
- Not break any of the 553 existing tests
- Add minimum 12 tests (`SpecialRequestCrudTest`) per Phase 4.1.1 plan, plus policy tests and E2E

**Audit & Regression Safety Review: PASS WITH CHANGES (AuditObserver registration required)**

---

## 11. ADR Review

### 11.1 ADR Numbering

Phase 3 ADR range: ADR-1 through ~ADR-79 (confirmed in Phase 3 Architecture Baseline, Section 8).
Phase 4.1 ADRs must start from **ADR-80**.

### 11.2 Required ADRs for Phase 4.1

| ADR | Decision | Reason Required |
|---|---|---|
| **ADR-80** | `booking_special_requests` table design (nullable `stay_id` bridge, `category`+`request_type` split, status machine, actor FK pairs) | Formalizes the core new table design |
| **ADR-81** | HOUSEKEEPING fulfill UI surface in Phase 4.1 (Option A/B/C from §9.4) | Blocking unresolved design decision |
| **ADR-82** | `booking_id` FK: CASCADE vs RESTRICT | Consistency with Phase 3 FK conventions |
| **ADR-83** | `requested_by` NOT NULL — system-actor path not supported in Phase 4.1 | Resolves discrepancy between roadmap and gap analysis |

ADR-84+ may be added during implementation as needed (acknowledge permission scope, RECEPTION cancel limitation, etc.).

### 11.3 Outdated Roadmap References (Must Fix Before Implementation Plan)

The Phase 4.1 roadmap document (`docs/roadmaps/phase-4.1-room-setup-requests.md`) has three stale sections identified in the gap analysis. These must be corrected before the Implementation Plan is written:

| Stale Reference | Current State | Required Fix |
|---|---|---|
| Section 12: "Ready for Implementation: NO" with Phase 3 blockers | Phase 3 COMPLETE as of 2026-07-04 | Change to: "READY — all Phase 3 prerequisites met" |
| Section 9: "Phase 4.4 — Night Audit nightly room charge posting" | Night Audit delivered in Phase 3.2 | Relabel: "Delivered in Phase 3.2. Not a Phase 4 item." |
| Implicit "City tax — Phase 3.4" reference | CityTaxPostingJob delivered in Phase 3.3.6.1 | Update: "Delivered in Phase 3.3.6.1" |

**ADR Review: PASS — numbering correct; 4 ADRs required**

---

## 12. Architecture Changes — Resolved

All required changes have been resolved as of 2026-07-04. The following table shows resolution status.

### 12.1 BLOCKERS — RESOLVED

| # | Issue | Resolution |
|---|---|---|
| **B-1** | HOUSEKEEPING fulfill UI gap | ✅ RESOLVED — Option A (ADR-81): HOUSEKEEPING gets restricted tab access |
| **B-2** | `requested_by` NULL vs NOT NULL | ✅ RESOLVED — NOT NULL confirmed (ADR-83); roadmap and gap analysis synced |

### 12.2 REQUIRED CHANGES — ALL ADDRESSED

| # | Issue | Resolution |
|---|---|---|
| **R-1** | `booking_id` FK → RESTRICT | ✅ ADR-82 accepted; roadmap DDL updated to ON DELETE RESTRICT |
| **R-2** | Add `cancelled_by` / `cancelled_at` columns | ✅ ADR-80; roadmap DDL and Eloquent model updated |
| **R-3** | AuditObserver registration | ✅ Added to Phase 4.1.1 implementation checklist (item 5) |
| **R-4** | Auto-link must be non-throwing | ✅ Added to Phase 4.1.1 implementation checklist (item 9 note) |
| **R-5** | Room Board backend query | ✅ Added to Phase 4.1.1 implementation checklist (item 16) |
| **R-6** | Update 3 stale roadmap sections | ✅ Phase 4.4 Night Audit label fixed; blocker box updated to YES; city tax label not found (likely informational only) |
| **R-7** | Write ADR-80 through ADR-83 | ✅ All 4 ADRs created in docs/adr/ |

### 12.3 RECOMMENDED — ADDRESSED

| # | Issue | Resolution |
|---|---|---|
| **P-1** | Composite index `(booking_id, status)` | ✅ Added to roadmap DDL |
| **P-2** | Category VARCHAR(32) vs VARCHAR(50) | ✅ Standardized to VARCHAR(32) in roadmap DDL |
| **P-3** | Document RECEPTION cannot cancel | ✅ Documented in ADR-81 permission matrix |
| **P-4** | `special_request.fulfill` covers acknowledge | ✅ Documented in ADR-81 and roadmap §7.5 |
| **P-5** | Terminal booking status guard in `addRequest()` | ✅ Added to Phase 4.1.1 checklist (item 9 note) |

---

## 13. Final Decision

### 13.1 Review Scores by Area

| Area | Score | Resolution |
|---|---|---|
| Scope Definition | ✅ PASS | Correct entity analysis; clean boundary with financial domain |
| Database Architecture | ✅ PASS | FK RESTRICT (ADR-82), requested_by NOT NULL (ADR-83), cancelled_by/at added (ADR-80), composite index added |
| Service Layer | ✅ PASS | `special_request.fulfill` covers acknowledge (documented ADR-81); terminal booking guard added to checklist |
| Lifecycle Integration | ✅ PASS | Auto-link non-throwing requirement documented in implementation checklist |
| Permission Matrix | ✅ PASS | HOUSEKEEPING restricted mode resolved (ADR-81); RECEPTION cancel scope documented |
| UI Architecture | ✅ PASS | ADR-81 Option A: HOUSEKEEPING gets restricted "Yêu cầu" tab access |
| Audit / Regression Safety | ✅ PASS | AuditObserver registration added to implementation checklist item 5 |
| ADR Numbering | ✅ PASS | ADR-80 through ADR-83 created in docs/adr/ |

### 13.2 What Is Not in Question

The following architectural decisions are **confirmed correct** and require no changes:
- Nullable `stay_id` bridge pattern (mirrors folio_entries.stay_id)
- `category` + `request_type` design (enum-enforced + string-free extensibility)
- Status machine: pending → acknowledged → fulfilled / cancelled
- No financial impact (zero FolioEntry, zero balance change)
- HOUSEKEEPING role exists and is clean (no conflicting permissions)
- Inertia + Vue 3 + FolioService patterns as implementation templates
- Auto-cancel in cancelBooking() transaction scope
- Phase 4.2 Housekeeping Board as natural successor

### 13.3 Architecture Decision

Phase 4.1's architecture is sound and buildable. The design correctly applies Phase 3 patterns to an operational (non-financial) domain. All architecture blockers have been resolved: HOUSEKEEPING Restricted UI (ADR-81), `booking_id` FK (ADR-82), `requested_by` nullability (ADR-83), and full table design (ADR-80). The roadmap has been updated with all required schema changes, implementation checklist additions, and corrected stale references.

---

```
Architecture Review Result: PASS
Ready for Implementation Plan: YES

ADRs issued:
  ADR-80: booking_special_requests table architecture
  ADR-81: HOUSEKEEPING restricted "Yêu cầu" tab access (Option A)
  ADR-82: booking_id FK ON DELETE RESTRICT
  ADR-83: requested_by NOT NULL

All blockers resolved. All required changes applied to roadmap.
No remaining architecture blockers.
```
