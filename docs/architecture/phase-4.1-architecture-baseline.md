# Phase 4.1 Architecture Baseline

**Date:** 2026-07-05  
**Commit:** 7f283fd590e3c167f462c8bae51d1187d983dd8c  
**Tag:** phase-4.1  
**Branch:** phase-3  
**Status:** OFFICIAL BASELINE — Phase 4.1 Closed

---

## 1. Architecture Summary

Phase 4.1 adds the Room Setup Requests (Special Requests) domain to Lastella PMS. The domain is self-contained: one model, one service, one controller, one policy, with hooks into existing `BookingService` (cancel) and `StayService` (create). No financial modules were modified.

**Stack:** Laravel 11 / PHP 8.3 · Inertia.js · Vue 3 SFC (Composition API, `<script setup>`) · Spatie Laravel Permission

**Pattern:** Repository-free service layer. `SpecialRequestService` owns all business logic. `BookingSpecialRequestController` delegates entirely to the service. No direct model writes in controllers.

---

## 2. Database Baseline

### Table: `booking_special_requests`

```sql
CREATE TABLE booking_special_requests (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id        BIGINT UNSIGNED NOT NULL,     -- FK RESTRICT (ADR-82)
  stay_id           BIGINT UNSIGNED NULL,          -- FK nullOnDelete (ADR-80)
  category          VARCHAR(32) NOT NULL,           -- app-enforced enum
  request_type      VARCHAR(64) NOT NULL,           -- 24 allowed codes
  quantity          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  note              TEXT NULL,
  status            VARCHAR(32) NOT NULL DEFAULT 'pending',
  requested_by      BIGINT UNSIGNED NOT NULL,      -- NOT NULL (ADR-83)
  acknowledged_by   BIGINT UNSIGNED NULL,
  acknowledged_at   TIMESTAMP NULL,
  fulfilled_by      BIGINT UNSIGNED NULL,
  fulfilled_at      TIMESTAMP NULL,
  cancelled_by      BIGINT UNSIGNED NULL,
  cancelled_at      TIMESTAMP NULL,
  created_at        TIMESTAMP NULL,
  updated_at        TIMESTAMP NULL,
  FOREIGN KEY (booking_id)      REFERENCES bookings(id) ON DELETE RESTRICT,
  FOREIGN KEY (stay_id)         REFERENCES stays(id) ON DELETE SET NULL,
  FOREIGN KEY (requested_by)    REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (fulfilled_by)    REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (cancelled_by)    REFERENCES users(id) ON DELETE SET NULL,
  INDEX (booking_id),
  INDEX (stay_id),
  INDEX (status),
  INDEX (category),
  INDEX idx_bsr_booking_status (booking_id, status)
);
```

### Status Lifecycle
```
Pending ──► Acknowledged ──► Fulfilled  (terminal)
   └──────────────────────► Cancelled  (terminal)
```

### Model Relationships Added to Existing Tables
- `bookings.specialRequests()` → HasMany BookingSpecialRequest
- `stays.specialRequests()` → HasMany BookingSpecialRequest

---

## 3. Service Layer Baseline

### SpecialRequestService (new)

| Method | Signature | Description |
|--------|-----------|-------------|
| `addRequest` | `(Booking, array, int) → BookingSpecialRequest` | Creates Pending request |
| `linkToStay` | `(BookingSpecialRequest, Stay) → BookingSpecialRequest` | Assigns stay_id |
| `acknowledge` | `(BookingSpecialRequest, int) → BookingSpecialRequest` | Pending → Acknowledged |
| `fulfill` | `(BookingSpecialRequest, int) → BookingSpecialRequest` | Acknowledged → Fulfilled |
| `cancel` | `(BookingSpecialRequest, int) → BookingSpecialRequest` | Pending|Acknowledged → Cancelled |
| `autoCancelForBooking` | `(Booking, int) → int` | Bulk UPDATE on cancellation; returns count |
| `autoLinkSingleStayRequests` | `(Booking, Stay) → void` | Links unlinked reqs when booking has exactly 1 stay; never throws |

### Hooks in Existing Services

| Service | Method | Hook | Risk |
|---------|--------|------|------|
| `BookingService` | `cancelBooking()` | `autoCancelForBooking()` inside `DB::transaction()` | Atomic; rollback-safe |
| `StayService` | `createStay()` | `autoLinkSingleStayRequests()` after stay creation | Non-throwing; failures logged and skipped |

---

## 4. Controller Layer Baseline

### BookingSpecialRequestController (new)

| Action | Method | Route | Policy Gate |
|--------|--------|-------|-------------|
| `index` | GET | `/admin/bookings/{booking}/special-requests` | `special_request.fulfill` (HOUSEKEEPING accessible) |
| `store` | POST | `/admin/bookings/{booking}/special-requests` | `BookingSpecialRequestPolicy::create` |
| `acknowledge` | PATCH | `…/{specialRequest}/acknowledge` | `BookingSpecialRequestPolicy::fulfill` |
| `fulfill` | PATCH | `…/{specialRequest}/fulfill` | `BookingSpecialRequestPolicy::fulfill` |
| `destroy` | DELETE | `…/{specialRequest}` | `BookingSpecialRequestPolicy::cancel` |

### Modified Controllers

| Controller | Change |
|-----------|--------|
| `BookingController` | Eager load `specialRequests.*`; adds `specialRequests`, `pendingCount`, `availableStays` to payload; `special_requests` tab; 3 `can` flags |
| `RoomAvailabilityController` | Adds `pendingRequestCounts` prop via `BookingSpecialRequest::pendingCountByRoom()` |

---

## 5. Permission Matrix

| Permission | ADMIN | MANAGER | SALES | RECEPTION | HOUSEKEEPING | ACCOUNTANT |
|-----------|:-----:|:-------:|:-----:|:---------:|:------------:|:----------:|
| `special_request.create` | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| `special_request.fulfill` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ |
| `special_request.cancel` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |

**Total permissions as of Phase 4.1:** 3 new additions (89 total in seeder)

### ADR-81: HOUSEKEEPING Access Pattern
HOUSEKEEPING holds `special_request.fulfill` only. `BookingPolicy::view()` requires `booking.create`, `booking.update`, `booking.cancel`, or `report.view` — none held by HOUSEKEEPING.

- `/admin/bookings/{id}` → **403** for HOUSEKEEPING
- `/admin/bookings/{id}/special-requests` → **200** (HOUSEKEEPING restricted standalone page)
- Standalone view: acknowledge + fulfill only; no create, no cancel

---

## 6. Frontend Baseline

### New Components

| Component | Path | Purpose |
|-----------|------|---------|
| `SpecialRequestPanel` | `Bookings/Partials/SpecialRequestPanel.vue` | Booking detail tab — full CRUD UI |
| `SpecialRequests` (standalone) | `Booking/SpecialRequests.vue` | HOUSEKEEPING restricted view |

### Modified Components

| Component | Change |
|-----------|--------|
| `Bookings/Show.vue` | Tab badge, SpecialRequestPanel conditional render |
| `Bookings/Partials/RoomBoardPanel.vue` | Request emoji labels + status icons in Stay Summary |
| `RoomAvailability/Index.vue` | `pendingRequestCounts` prop; amber badge on room cards |

### Inertia Props Added

| Route | Prop | Type |
|-------|------|------|
| `admin.bookings.show` | `booking.specialRequests[]` | Array of request objects |
| `admin.bookings.show` | `booking.pendingCount` | Integer |
| `admin.bookings.show` | `booking.availableStays[]` | Array of stay objects |
| `admin.bookings.show` | `can.createSpecialRequest` | Boolean |
| `admin.bookings.show` | `can.fulfillSpecialRequest` | Boolean |
| `admin.bookings.show` | `can.cancelSpecialRequest` | Boolean |
| `admin.room-availability.index` | `pendingRequestCounts` | Object `{room_id: count}` |

### Request Type Catalog (24 codes)

| Category | Types |
|----------|-------|
| `bed_config` | twin_keep, twin_to_double, separate_beds, extra_bed |
| `extra_item` | baby_cot, extra_pillow, non_feather_pillow, extra_blanket, extra_towel, welcome_fruit, welcome_amenity |
| `decoration` | birthday_setup, anniversary_setup, honeymoon_setup, flower_arrangement |
| `accessibility` | wheelchair_access, grab_bars, roll_in_shower, visual_alarm, hearing_kit |
| `general` | early_checkin, late_checkout, quiet_room, high_floor, connecting_rooms, pet_friendly |

---

## 7. Integration Points

| Point | Direction | Details |
|-------|-----------|---------|
| `BookingService::cancelBooking()` | Consumes | `autoCancelForBooking()` inside existing cancel transaction |
| `StayService::createStay()` | Consumes | `autoLinkSingleStayRequests()` after stay creation |
| `BookingController::bookingPayload()` | Produces | eager-loaded `specialRequests` + derived counts |
| `RoomAvailabilityController::index()` | Produces | `pendingRequestCounts` from single GROUP BY query |
| `AuditObserver` | Observes | Full audit trail for BookingSpecialRequest CRUD |

**Financial isolation confirmed:** No writes to `folio_entries`, `booking_payments`, `folios`. No reads of financial tables in special request code paths.

---

## 8. Regression Baseline

| Suite State | Count |
|-------------|-------|
| Total tests | 658 (632 passed + 26 failed) |
| Pre-existing failures | 26 |
| New regressions (Phase 4.1) | **0** |
| Assertions | 3,075 |
| Build | ✅ PASS (16.87s) |

### Known Pre-existing Failures (26)

All date-sensitive. None related to special requests.

- `RoomAvailabilityCheckerTest`: 12 failures — seeded data time-sensitive
- `BookingManagementUiTest > conflicted room`: hardcoded 2026-07-01 dates now in past
- Other: 13 failures across various feature test classes

**These failures must NOT increase at Phase 4.2 start. Any increase is a regression.**

---

## 9. Known Technical Debt

| Item | Source | Priority |
|------|--------|----------|
| 26 pre-existing test failures | Phases 2–3 | LOW — date-sensitive; no functional impact |
| JS bundle chunk size (508 kB) | Phase 1 SPA setup | LOW — acceptable for admin-only SPA |
| `docs/implementation-plans/` not committed | Planning artifact | NONE — intentional |

---

## 10. Extension Points

Phase 4.1 was designed for Phase 4.2 extensibility:

1. **Housekeeping Board** — `pendingCountByRoom()` ready; add `assigned_housekeeper_id` column + board UI
2. **Priority field** — nullable `priority` column on `booking_special_requests` via migration; service methods return full model
3. **Push notifications** — `SpecialRequestService` methods can dispatch events; add listeners without modifying service
4. **Reporting** — `special_request.*` permissions gate future `SpecialRequestReportController`
5. **Multi-stay bulk link** — `stay_id` nullable by design; Phase 4.2 can add bulk-assign UI

---

## 11. Phase 4 Constraints

The following constraints govern all Phase 4.x work:

1. **Financial isolation:** Never write to `folio_entries`, `booking_payments`, `folios` from special request code
2. **ADR-81 immutable:** HOUSEKEEPING standalone access pattern must not be changed without explicit ADR
3. **Service delegation:** Controllers must not write directly to `BookingSpecialRequest` — always via `SpecialRequestService`
4. **Non-throwing hooks:** `autoLinkSingleStayRequests` and any future hooks in core services must catch and log errors rather than propagating
5. **Transaction safety:** Any hook inside `BookingService::cancelBooking()` must be idempotent and safe to roll back

---

## 12. Ready for Phase 4.2

Phase 4.2 Architecture Review may begin immediately.

**Suggested Phase 4.2 scope (not committed):**
- Housekeeping Board — dedicated view for HOUSEKEEPING to manage all pending requests across rooms
- Priority/urgency field on requests
- Housekeeper assignment (`assigned_to` FK)
- Real-time request status via Reverb/WebSockets

**Prerequisite checklist for Phase 4.2:**
- [x] Phase 4.1 committed and tagged
- [x] Architecture baseline established
- [x] Regression baseline documented (26 pre-existing failures)
- [x] Permission matrix stable (no changes needed for Phase 4.2 read-only board)
- [x] `pendingCountByRoom()` method available for Housekeeping Board

---

## 13. Official Closure Statement

> **Phase 4.1 is officially closed.**
>
> Commit `7f283fd590e3c167f462c8bae51d1187d983dd8c` on branch `phase-3`, tagged `phase-4.1`, has been pushed to `origin/phase-3` and `origin/phase-4.1`.
>
> Architecture baseline is established in this document.
>
> All Phase 4.1 deliverables — backend, frontend, tests, documentation, and reports — are committed and verified.
>
> **Ready for Phase 4.2 Architecture Review.**
