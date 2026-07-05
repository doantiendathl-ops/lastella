# Phase 4.1 Final Report

**Date:** 2026-07-05  
**Branch:** phase-3  
**Commit:** 7f283fd590e3c167f462c8bae51d1187d983dd8c  
**Tag:** phase-4.1  
**Status:** OFFICIALLY CLOSED

---

## Executive Summary

Phase 4.1 implements the Room Setup Requests (Special Requests) feature for Lastella PMS. Guests may submit up to 24 types of special requests across 5 categories. Staff can acknowledge, fulfill, or cancel requests based on their role. HOUSEKEEPING operates in an isolated restricted view (ADR-81). The feature is fully integrated with the booking cancellation lifecycle and the room availability board, with zero financial module changes.

---

## Scope

**In Scope:**
- Full CRUD lifecycle for `BookingSpecialRequest` records
- Role-gated access: ADMIN/MANAGER/RECEPTION (create+cancel), HOUSEKEEPING (fulfill only), ACCOUNTANT (no access)
- Booking detail "Yêu cầu" tab with request list, status badges, add form, action buttons
- HOUSEKEEPING standalone restricted view (ADR-81)
- Stay Summary request labels in Room Board panel
- Pending count badges: booking detail tab + Room Availability board room cards
- Auto-cancel hook on booking cancellation (inside DB transaction)
- Auto-link hook when first stay is assigned (non-throwing)
- AuditObserver registration for full audit trail

**Out of Scope:**
- Housekeeping Board (Phase 4.2)
- Financial modules (untouched)
- Notification system

---

## Architecture Decisions

### ADR-80: Nullable Stay Bridge Pattern
`booking_special_requests.stay_id` is nullable. A request may exist before any stay is assigned (booking-level). When a stay is assigned, the auto-link hook connects unlinked requests to the new stay. This mirrors `folio_entries.stay_id` semantics.

### ADR-81: HOUSEKEEPING Restricted Mode
`BookingPolicy::view()` requires `booking.create/update/cancel` or `report.view` — none of which HOUSEKEEPING holds. HOUSEKEEPING cannot access the booking detail page. They use a dedicated standalone page rendered by `BookingSpecialRequestController::index()` which only requires `special_request.fulfill`.

### ADR-82: RESTRICT on booking_id FK
`booking_id` uses `RESTRICT` on delete (not CASCADE) to prevent silent deletion of request history when a booking is archived. Cancellation is explicit via `autoCancelForBooking()`.

### ADR-83: requested_by NOT NULL
`requested_by` is always required. Requests must have a traceable actor. The policy ensures only authenticated users with the appropriate permission can create requests.

### N+1 Avoidance
All `specialRequests` data in the booking detail payload is derived from eager-loaded collections (`$booking->specialRequests`). Zero additional queries in `bookingPayload()`. `pendingCountByRoom()` uses a single GROUP BY query regardless of room count.

---

## ADR Summary

| ADR | Title | Decision |
|-----|-------|----------|
| ADR-80 | Nullable stay bridge | `stay_id` nullable; auto-link on first stay assignment |
| ADR-81 | HOUSEKEEPING restricted mode | Standalone page; no booking detail access |
| ADR-82 | FK RESTRICT on booking_id | No silent cascade; explicit cancel via service |
| ADR-83 | requested_by NOT NULL | Full actor traceability required |

---

## Backend Summary

| Component | File | Description |
|-----------|------|-------------|
| Migration | `2026_07_04_000000_create_booking_special_requests_table.php` | Schema with FK constraints, indexes |
| Model | `BookingSpecialRequest.php` | 6 relationships, 2 scopes, pendingCountByRoom() |
| Service | `SpecialRequestService.php` | 7 methods: add, linkToStay, acknowledge, fulfill, cancel, autoCancelForBooking, autoLinkSingleStayRequests |
| Controller | `BookingSpecialRequestController.php` | 5 actions, delegates to service |
| Policy | `BookingSpecialRequestPolicy.php` | view/create/fulfill/cancel gates |
| FormRequest | `StoreBookingSpecialRequestRequest.php` | 24-type allowlist, qty 1–99, stay scoped |
| Enums | `RequestCategory.php`, `RequestStatus.php` | 5 categories, 4 states |
| Factory | `BookingSpecialRequestFactory.php` | Test factory |
| Seeder | `RolePermissionSeeder.php` | 3 new permissions added |
| Routes | `routes/web.php` | 5 routes registered |
| Observer | `AppServiceProvider.php` | AuditObserver + Gate policy registered |
| Hook (cancel) | `BookingService.php` | autoCancelForBooking inside cancel transaction |
| Hook (auto-link) | `StayService.php` | autoLinkSingleStayRequests on stay creation |

---

## Frontend Summary

| Component | File | Description |
|-----------|------|-------------|
| New | `SpecialRequestPanel.vue` | Booking detail tab: 24-type catalog, status badges, add form, action buttons |
| New | `Admin/Booking/SpecialRequests.vue` | HOUSEKEEPING standalone view (no create, no cancel) |
| Modified | `Show.vue` | SpecialRequestPanel mount + pending count tab badge |
| Modified | `RoomBoardPanel.vue` | Stay Summary: emoji labels + status icons per request |
| Modified | `RoomAvailability/Index.vue` | Amber pending badge on room cards |

---

## Integration Summary

| Integration Point | Implementation | Risk |
|------------------|---------------|------|
| BookingController payload | Eager load + 3 derived props (specialRequests, pendingCount, availableStays) | None — additive |
| RoomAvailabilityController | pendingRequestCounts via pendingCountByRoom() | None — 1 query |
| BookingService::cancelBooking() | autoCancelForBooking() inside DB transaction | None — atomic |
| StayService::createStay() | autoLinkSingleStayRequests() non-throwing | None — failures logged, not propagated |
| Inertia props | can.createSpecialRequest/fulfillSpecialRequest/cancelSpecialRequest | None — read-only flags |

---

## Regression Summary

| Component | Status |
|-----------|--------|
| FolioService | ✅ Unchanged |
| BookingPaymentService | ✅ Unchanged |
| NightAuditService | ✅ Unchanged |
| RevenueReportService | ✅ Unchanged |
| ReconciliationService | ✅ Unchanged |
| PackageEnrollmentService | ✅ Unchanged |
| PostingJobs (all) | ✅ Unchanged |
| BookingService | ✅ Additive only (+4 lines) |
| StayService | ✅ Additive only (+2 lines) |
| New regressions | **0** |

---

## Test Summary

```
php artisan test
Tests: 26 failed (all pre-existing), 632 passed (3075 assertions)
Duration: 520.63s

npm run build
✓ built in 16.87s
```

| Test File | Count | Result |
|-----------|-------|--------|
| `SpecialRequestCrudTest.php` | Feature CRUD + lifecycle | ✅ All passing |
| `SpecialRequestUiTest.php` | 14 Inertia UI tests | ✅ All passing |
| `BookingSpecialRequestPolicyTest.php` | Unit policy tests | ✅ All passing |
| `SpecialRequestServiceTest.php` | Unit service tests | ✅ All passing |
| `BookingManagementUiTest.php` | Updated tabs assertion | ✅ Passing |

**Pre-existing failures (26):** All date-sensitive tests from prior phases. Confirmed pre-existing via `git stash` baseline. None related to special requests.

---

## Performance Summary

- `pendingCountByRoom()`: 1 GROUP BY query — O(1) round-trips
- Booking detail eager load: 1 additional query for `specialRequests` with relations
- Room Availability: +2 queries (Room::pluck + GROUP BY)
- Frontend bundle: unchanged from Phase 3 baseline (508 kB / 147 kB gzipped)
- Build time: 16.87s (vs 30.92s during development — typical variance)

---

## Security Summary

- All controller actions gated by `$this->authorize()` using `BookingSpecialRequestPolicy`
- `StoreBookingSpecialRequestRequest` validates `request_type` against explicit allowlist (24 codes)
- `stay_id` scoped to booking — cross-booking reference impossible
- `quantity` bounded 1–99
- All DB queries use Eloquent/query builder with parameterized bindings
- No raw SQL in Phase 4.1 code
- No hardcoded secrets or credentials

---

## Known Technical Debt

| Item | Severity | Notes |
|------|----------|-------|
| `RoomAvailabilityCheckerTest` (12 failures) | LOW | Date-sensitive; pre-existing from Phase 2/3 |
| `BookingManagementUiTest > conflicted room` | LOW | Hardcoded 2026-07-01 dates; pre-existing |
| JS bundle chunk size warning | LOW | Single-chunk admin SPA; pre-existing before Phase 4.1 |
| `docs/implementation-plans/` | NONE | Planning artifacts; not committed |
| `docs/project-handover-v2.md` | NONE | Intentionally excluded from commit |

---

## Extension Points

Phase 4.1 was designed to be extended in Phase 4.2+:

1. **Housekeeping Board** — `BookingSpecialRequest::pendingCountByRoom()` is already wired to Room Availability. A dedicated Housekeeping Board can query the same method filtered by `assigned_housekeeper_id` (field not yet added).
2. **Push Notifications** — `SpecialRequestService::acknowledge()` and `fulfill()` dispatch events. A listener can send push/email notifications without modifying service code.
3. **Request Priority** — `BookingSpecialRequest` table can accept a `priority` column via a new migration; service methods already return the full model.
4. **Multi-stay requests** — `stay_id` is nullable. Requests without a stay_id are booking-level; Phase 4.2 can implement bulk-link UI.
5. **Reporting** — `special_request.create/fulfill/cancel` permissions can gate a future `SpecialRequestReportController` without policy changes.

---

## Statistics

| Metric | Count |
|--------|-------|
| Files Added | 20 |
| Files Modified | 13 |
| Total Files Changed | 33 |
| Migration Count | 1 |
| Route Count | 5 |
| Controller Count | 1 new (BookingSpecialRequestController) |
| Service Count | 1 new (SpecialRequestService, 7 methods) |
| Policy Count | 1 new (BookingSpecialRequestPolicy) |
| Enum Count | 2 (RequestCategory, RequestStatus) |
| Permission Count | 3 new (special_request.create/fulfill/cancel) |
| Vue Components Added | 2 (SpecialRequestPanel, SpecialRequests) |
| Vue Components Modified | 3 (Show, RoomBoardPanel, RoomAvailability/Index) |
| Test Files Added | 4 |
| Tests Added (approx.) | 38 (14 UI + ~24 unit/CRUD) |
| Total Assertions | 3075 (full suite) |
| ADR Count | 4 (ADR-80 through ADR-83) |
| LOC Added (approx.) | 4,229 insertions, 15 deletions |

---

## Commit Information

| Field | Value |
|-------|-------|
| Branch | phase-3 |
| HEAD Commit | 7f283fd590e3c167f462c8bae51d1187d983dd8c |
| Commit Message | feat(room): implement Phase 4.1 room setup requests |
| Tag | phase-4.1 |
| Remote | origin/phase-3 (pushed) |
| Tag Remote | origin/phase-4.1 (pushed) |
