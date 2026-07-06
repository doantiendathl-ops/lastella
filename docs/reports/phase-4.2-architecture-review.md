# Phase 4.2 Architecture Review
## Housekeeping Workflow

**Date:** 2026-07-05  
**Branch:** phase-3  
**Baseline:** phase-4.1 (commit 7f283fd)  
**Reviewer:** Architecture Review  
**Status:** DRAFT FOR APPROVAL

---

## 1. Executive Summary

Phase 4.2 implements the full Housekeeping Workflow for Lastella PMS, enabling the hotel to track room cleanliness, assign cleaning tasks, manage inspections, and expose a dedicated Housekeeping Board for HOUSEKEEPING staff.

**Key Finding:** The `RoomStatus` enum already defines all 8 required states (`VacantClean`, `VacantDirty`, `Occupied`, `Reserved`, `Cleaning`, `Inspected`, `OutOfOrder`, `OutOfService`). However, zero automatic transitions are implemented. Room status is only writable via the generic admin CRUD (`RoomController/RoomService`). Every lifecycle event — check-in, checkout, cleaning, inspection — leaves room status unchanged. This is the primary gap Phase 4.2 must close.

**Architecture Review Result: PASS WITH CHANGES**

5 ADRs are required before implementation. No blockers that prevent starting the Implementation Plan. All changes are additive or confined to well-isolated hook points.

---

## 2. Scope

### In Scope
1. Automatic room status transitions on check-in / checkout
2. Housekeeping Board (HOUSEKEEPING-facing view)
3. Housekeeping Assignment (assign rooms to housekeepers)
4. Cleaning Workflow: Dirty → Assigned → Cleaning → Inspected → Ready
5. Inspection Workflow: Pass / Fail / Re-clean
6. Cleaning History (structured record, not just audit log)
7. Room Board integration (status badge, special request pending badge)
8. Permission matrix revision for HOUSEKEEPING role
9. Race condition protection on room status transitions
10. Regression analysis for all Phase 3 financial modules

### Out of Scope
- Push / real-time notifications (Phase 5)
- Mobile app for housekeeping staff (Phase 5)
- Guest-facing cleaning request form (Phase 5)
- Night Audit changes
- Financial modules

---

## 3. Current Architecture

### 3.1 Room Status — Enum Exists, Automation Missing

`RoomStatus` enum (8 states):

| Value | Label | Current Trigger |
|-------|-------|----------------|
| `VACANT_CLEAN` | Trống sạch | Manual CRUD only |
| `VACANT_DIRTY` | Trống bẩn | Manual CRUD only |
| `OCCUPIED` | Đang ở | Manual CRUD only |
| `RESERVED` | Đã đặt | Manual CRUD only |
| `CLEANING` | Đang dọn | Manual CRUD only |
| `INSPECTED` | Đã kiểm tra | Manual CRUD only |
| `OUT_OF_ORDER` | Hỏng | Manual CRUD only |
| `OUT_OF_SERVICE` | Ngừng phục vụ | Manual CRUD only |

**Critical gap:** `StayService::checkIn()` and `StayService::checkOut()` do not update `rooms.status`. A room remains `VACANT_CLEAN` while a guest occupies it, and remains `OCCUPIED` (if manually set) after checkout.

### 3.2 Availability Rule — Partial

`RoomAvailabilityRuleService::isRoomUnavailable()` blocks only `OutOfOrder` and `OutOfService`. A room in `CLEANING` state is treated as available for new assignments — a production risk.

```php
// Current — INCOMPLETE
public function isRoomUnavailable(Room $room): bool
{
    return in_array($room->status, [RoomStatus::OutOfOrder, RoomStatus::OutOfService], true);
}
```

`RoomStatus::availableValues()` returns `[VacantClean, Inspected]` — correct for availability gating, but never enforced as a pre-condition for assignment.

### 3.3 HOUSEKEEPING Role — Permission Over-Grant

Current permissions for HOUSEKEEPING:

| Permission | Intended Use | Risk |
|------------|-------------|------|
| `room.assign` | Assign rooms to bookings | Front desk operation — incorrect for HK |
| `room.unassign` | Release room assignments | Front desk operation — incorrect for HK |
| `rooms.manage` | Full room CRUD (create/update/delete) | **Over-grant: allows deleting rooms** |
| `special_request.fulfill` | Fulfill special requests | Correct |

`rooms.manage` gives HOUSEKEEPING access to `RoomController`, including `destroy()`. This must be scoped in Phase 4.2.

### 3.4 Existing Services — No Housekeeping Layer

No `HousekeepingService` exists. No `HousekeepingController`. No housekeeping routes in `routes/web.php`. No housekeeping-specific Vue pages.

### 3.5 Data Layer — Missing Tables

No `housekeeping_assignments` table (who is cleaning which room).  
No `cleaning_records` table (history of completed cleanings and inspections).

The `audit_logs` table exists but is insufficient for structured housekeeping reporting — it stores `old_data`/`new_data` JSON, not typed duration/inspection fields.

### 3.6 Special Requests Integration — Ready

`BookingSpecialRequest::pendingCountByRoom()` is already implemented and wired to `RoomAvailabilityController`. The Housekeeping Board can reuse this method directly — no changes needed.

---

## 4. Gap Analysis

| # | Gap | Severity | Phase 4.2 Action |
|---|-----|----------|-----------------|
| G-01 | No automatic room status on check-in | HIGH | Hook in `StayService::checkIn()` |
| G-02 | No automatic room status on checkout | HIGH | Hook in `StayService::checkOut()` |
| G-03 | CLEANING state does not block assignment | HIGH | Update `isRoomUnavailable()` |
| G-04 | No `housekeeping_assignments` table | HIGH | New migration |
| G-05 | No `cleaning_records` table | HIGH | New migration |
| G-06 | No `HousekeepingService` | HIGH | New service |
| G-07 | No `HousekeepingController` | HIGH | New controller |
| G-08 | No Housekeeping Board Vue page | HIGH | New Vue component |
| G-09 | `rooms.manage` over-grants HOUSEKEEPING | MEDIUM | New granular permission |
| G-10 | `room.assign` / `room.unassign` semantically wrong for HK | MEDIUM | Permission re-scoping |
| G-11 | No inspection workflow | HIGH | New service methods |
| G-12 | Cleaning history unstructured | MEDIUM | `cleaning_records` table |
| G-13 | No priority/urgency field for assignments | LOW | Column in `housekeeping_assignments` |

---

## 5. Proposed Architecture

### 5.1 Design Principles

1. **Additive first:** All new tables, services, and routes are additions — no existing schema changes.
2. **Hook pattern:** Room status transitions in `StayService` follow the same non-throwing hook pattern established in Phase 4.1.
3. **Service delegation:** `HousekeepingController` delegates all writes to `HousekeepingService`. No direct model writes in controllers.
4. **Canonical lock order:** Room status updates inside shared transactions must acquire the `Room` lock AFTER `Booking`, consistent with Phase 3 P3.
5. **Financial isolation:** No folio or payment table access in any housekeeping code path.

### 5.2 Service Layer

```
HousekeepingService (new)
├── assignRoom(room, housekeeper, actor, priority?, note?) → HousekeepingAssignment
├── startCleaning(assignment, actor) → HousekeepingAssignment
├── completeCleaning(assignment, actor, note?) → CleaningRecord
├── passInspection(room, inspector, note?) → CleaningRecord
├── failInspection(room, inspector, note?) → Room (back to Dirty)
├── markOutOfOrder(room, actor, reason) → Room
├── releaseFromOutOfOrder(room, actor) → Room
└── autoMarkDirtyOnCheckout(stay, actor) → Room [non-throwing hook]
```

`HousekeepingService` is injected into:
- `StayService` — for the auto-dirty-on-checkout hook (non-throwing, Phase 4.1 pattern)
- `HousekeepingController` — for all board actions

### 5.3 Controller Layer

```
HousekeepingController (new)
├── GET  /admin/housekeeping          → index() [Board view]
├── POST /admin/housekeeping/{room}/assign       → assign()
├── POST /admin/housekeeping/{room}/start        → start()
├── POST /admin/housekeeping/{room}/complete     → complete()
├── POST /admin/housekeeping/{room}/inspect      → inspect()
├── POST /admin/housekeeping/{room}/out-of-order → outOfOrder()
└── POST /admin/housekeeping/{room}/release      → release()
```

### 5.4 Frontend Layer

```
Pages/Admin/Housekeeping/
└── Index.vue       (Housekeeping Board)
    ├── Room list with status badges
    ├── Filter: floor / room type / status / housekeeper
    ├── Assignment panel (drag-and-drop or dropdown)
    ├── Pending special requests badge (reuse pendingCountByRoom)
    └── Quick-action buttons: Assign / Start / Done / Pass / Fail
```

---

## 6. Workflow Design

### 6.1 Full Cleaning Lifecycle

```
[Guest Checks In]
        │ StayService::checkIn() hook (ADR-84)
        ▼
   OCCUPIED ───────────────────────────────────────┐
        │                                           │ (manual: MANAGER)
        │ StayService::checkOut() hook (ADR-84)     ▼
        ▼                                      OUT_OF_ORDER
   VACANT_DIRTY  ◄─────────────────────────────────┤
        │         failInspection() (ADR-88)         │ releaseFromOutOfOrder()
        │                                           ▼
        │ assignRoom() — MANAGER or HOUSEKEEPING    │
        ▼                                           │
    CLEANING ──────────────────────────────────────┘
    (assignment: IN_PROGRESS)
        │
        │ completeCleaning()
        ▼
   INSPECTED
   (CleaningRecord created)
        │
        ├─ passInspection() ──► VACANT_CLEAN  (available for new bookings)
        │
        └─ failInspection() ──► VACANT_DIRTY  (back to queue)
```

### 6.2 Transition Rules (Enforced in HousekeepingService)

| From | To | Trigger | Actor |
|------|----|---------|-------|
| Any | `OCCUPIED` | `StayService::checkIn()` (auto) | System |
| `OCCUPIED` | `VACANT_DIRTY` | `StayService::checkOut()` (auto) | System |
| `VACANT_DIRTY` | `CLEANING` | `assignRoom()` + `startCleaning()` | HOUSEKEEPING / MANAGER |
| `CLEANING` | `INSPECTED` | `completeCleaning()` | HOUSEKEEPING |
| `INSPECTED` | `VACANT_CLEAN` | `passInspection()` | MANAGER / ADMIN |
| `INSPECTED` | `VACANT_DIRTY` | `failInspection()` | MANAGER / ADMIN |
| Any → `OUT_OF_ORDER` | Manual | `markOutOfOrder()` | MANAGER / ADMIN |
| `OUT_OF_ORDER` → Any | Manual | `releaseFromOutOfOrder()` | MANAGER / ADMIN |
| `OUT_OF_SERVICE` | Manual | Admin CRUD (existing) | ADMIN |

### 6.3 Assignment Workflow

```
MANAGER / ADMIN                   HOUSEKEEPING
      │                                 │
      ▼                                 │
assignRoom(room, housekeeper) ──────────►
      │                                 │
      │                         startCleaning()
      │                                 │
      │                         completeCleaning()
      │                                 │
      ▼                                 │
passInspection() / failInspection() ◄───
      │
      ▼
Room → VACANT_CLEAN or VACANT_DIRTY
```

Self-assignment (HOUSEKEEPING assigns to self):
- Allowed if user has `housekeeping.assign` permission
- `assigned_by = assigned_to = auth()->id()`

### 6.4 Inspection Workflow

```
completeCleaning() → room.status = INSPECTED
                   → CleaningRecord(status='awaiting_inspection') created

passInspection()   → room.status = VACANT_CLEAN
                   → CleaningRecord.inspection_result = 'pass'
                   → CleaningRecord.inspected_by = actor
                   → CleaningRecord.inspected_at = now()

failInspection()   → room.status = VACANT_DIRTY
                   → CleaningRecord.inspection_result = 'fail'
                   → New HousekeepingAssignment created (re-queue)
```

---

## 7. Permission Matrix

### 7.1 New Permissions Required

| Permission | Purpose |
|-----------|---------|
| `housekeeping.view` | View Housekeeping Board |
| `housekeeping.assign` | Assign rooms to housekeepers |
| `room.status.update` | Update room status (cleaning transitions) |
| `room.inspect` | Perform inspection (pass/fail) |
| `room.maintenance` | Mark/release OutOfOrder |

### 7.2 Revised Role Matrix

| Permission | ADMIN | MANAGER | SALES | RECEPTION | HOUSEKEEPING | ACCOUNTANT |
|-----------|:-----:|:-------:|:-----:|:---------:|:------------:|:----------:|
| `housekeeping.view` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ |
| `housekeeping.assign` | ✅ | ✅ | ❌ | ❌ | ✅* | ❌ |
| `room.status.update` | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ |
| `room.inspect` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `room.maintenance` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `rooms.manage` (existing) | ✅ | ✅ | ❌ | ❌ | **REMOVE** | ❌ |
| `room.assign` (existing) | ✅ | ✅ | ❌ | ✅ | **REMOVE** | ❌ |
| `room.unassign` (existing) | ✅ | ✅ | ❌ | ✅ | **REMOVE** | ❌ |
| `special_request.fulfill` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ |

*`housekeeping.assign` for HOUSEKEEPING = self-assign only (enforced at service layer)

### 7.3 HOUSEKEEPING Permission Rationale

- `rooms.manage` REMOVED: gives full room CRUD including delete. Replace with granular `room.status.update`.
- `room.assign` REMOVED: this is a front-desk operation (assign rooms to guest bookings). HOUSEKEEPING should not assign bookings to rooms.
- `room.unassign` REMOVED: same reason.
- `housekeeping.assign` ADDED: self-assignment to cleaning tasks.
- `room.status.update` ADDED: HOUSEKEEPING can transition rooms through cleaning workflow.

**Important:** Removing `rooms.manage` from HOUSEKEEPING requires updating `RoomPolicy` so that housekeeping board reads (needed for the board) go through a separate gate (`housekeeping.view`), not `rooms.manage`.

---

## 8. Database Design

> Architecture only — no migration files to be created in this phase.

### 8.1 New Table: `housekeeping_assignments`

```sql
CREATE TABLE housekeeping_assignments (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id         BIGINT UNSIGNED NOT NULL,      -- FK RESTRICT (room must not be deleted while assigned)
  assigned_to     BIGINT UNSIGNED NULL,           -- FK nullOnDelete (housekeeper user)
  assigned_by     BIGINT UNSIGNED NULL,           -- FK nullOnDelete (manager/admin/self)
  priority        TINYINT UNSIGNED NOT NULL DEFAULT 5, -- 1=urgent, 10=low; lower = higher priority
  notes           TEXT NULL,
  status          VARCHAR(32) NOT NULL DEFAULT 'pending',  -- pending/in_progress/done/cancelled
  started_at      TIMESTAMP NULL,
  completed_at    TIMESTAMP NULL,
  cancelled_at    TIMESTAMP NULL,
  cancelled_by    BIGINT UNSIGNED NULL,           -- FK nullOnDelete
  created_at      TIMESTAMP NULL,
  updated_at      TIMESTAMP NULL,

  FOREIGN KEY (room_id)      REFERENCES rooms(id) ON DELETE RESTRICT,
  FOREIGN KEY (assigned_to)  REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_by)  REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL,

  INDEX (room_id),
  INDEX (assigned_to),
  INDEX (status),
  INDEX idx_hka_room_status (room_id, status),
  INDEX idx_hka_assignee_status (assigned_to, status)
);
```

**Assignment Status Lifecycle:**
```
pending → in_progress → done      (normal flow)
pending → cancelled               (discarded before start)
in_progress → cancelled           (emergency)
```

**One active assignment per room constraint:** Enforced at service layer. A new assignment cannot be created if a `pending` or `in_progress` assignment already exists for the room. This is a uniqueness constraint enforced in code (not DB UNIQUE, because multiple historical assignments per room are valid).

### 8.2 New Table: `cleaning_records`

```sql
CREATE TABLE cleaning_records (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id               BIGINT UNSIGNED NOT NULL,      -- FK RESTRICT
  assignment_id         BIGINT UNSIGNED NULL,           -- FK nullOnDelete (link to the assignment)
  cleaned_by            BIGINT UNSIGNED NOT NULL,       -- FK RESTRICT (must not be deleted)
  inspected_by          BIGINT UNSIGNED NULL,           -- FK nullOnDelete
  room_status_before    VARCHAR(40) NOT NULL,           -- snapshot at cleaning start
  room_status_after     VARCHAR(40) NULL,               -- snapshot after outcome
  started_at            TIMESTAMP NOT NULL,
  completed_at          TIMESTAMP NULL,
  duration_minutes      SMALLINT UNSIGNED NULL,         -- computed on completeCleaning()
  inspection_result     VARCHAR(32) NULL,               -- 'pass' | 'fail' | 'skip'
  inspected_at          TIMESTAMP NULL,
  cleaning_notes        TEXT NULL,
  inspection_notes      TEXT NULL,
  created_at            TIMESTAMP NULL,
  updated_at            TIMESTAMP NULL,

  FOREIGN KEY (room_id)       REFERENCES rooms(id) ON DELETE RESTRICT,
  FOREIGN KEY (assignment_id) REFERENCES housekeeping_assignments(id) ON DELETE SET NULL,
  FOREIGN KEY (cleaned_by)    REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (inspected_by)  REFERENCES users(id) ON DELETE SET NULL,

  INDEX (room_id),
  INDEX (cleaned_by),
  INDEX (started_at),
  INDEX idx_cr_room_date (room_id, started_at)
);
```

**Why a separate table instead of audit_logs?**  
`audit_logs` stores JSON diffs (`old_data`/`new_data`). Cleaning reports require typed fields: `duration_minutes`, `inspection_result`, `cleaned_by`, `inspected_by`. Joining audit_logs JSON for reporting is fragile and slow. A typed table is required.

### 8.3 Changes to Existing Tables

**No schema changes required** to existing tables. Room status transitions are handled by updating the existing `rooms.status` column (VARCHAR 40). The column already exists and is wide enough.

**Optional addition (Phase 4.2 decision):** `rooms.last_cleaned_at TIMESTAMP NULL` — a denormalized convenience column for the Housekeeping Board "last cleaned" display. Avoids a JOIN to `cleaning_records` on every board load. This is a single nullable column addition, low risk.

### 8.4 Indexes Summary

All foreign keys are indexed. The most-queried patterns:
- `housekeeping_assignments` by `(room_id, status)` — Board load: "active assignments per room"
- `housekeeping_assignments` by `(assigned_to, status)` — "my tasks" view for HOUSEKEEPING
- `cleaning_records` by `(room_id, started_at)` — Room cleaning history
- `cleaning_records` by `(cleaned_by)` — Housekeeper performance report

---

## 9. Integration Points

### 9.1 StayService::checkIn() Hook (ADR-84)

```
checkIn(stay) [existing transaction]
  ├── Lock Booking (existing)
  ├── Lock Stay (existing)
  ├── Lock RoomAssignment (existing)
  ├── Update stay.status = CHECKED_IN (existing)
  ├── Update assignment.status = CHECKED_IN (existing)
  ├── Post room charge + early checkin fee (existing)
  ├── updateBookingStayStatus() (existing)
  └── [NEW] housekeeping.autoMarkOccupied(stay) — NON-THROWING
      └── room.update(['status' => OCCUPIED])
          (inside existing transaction, after existing DML)
```

**Lock order compliance:** Room lock is acquired AFTER all existing locks (`Booking → Stay → RoomAssignment → Room`). This extends the canonical order (P3) but does not violate it — room is a leaf node with no downstream locks.

### 9.2 StayService::checkOut() Hook (ADR-84)

```
checkOut(stay) [existing transaction]
  ├── ... all existing logic ...
  ├── finaliseBookingCheckout() or updateBookingStayStatus() (existing)
  └── [NEW] housekeeping.autoMarkDirtyOnCheckout(stay) — NON-THROWING
      └── room.update(['status' => VACANT_DIRTY])
          └── HousekeepingAssignment::create([status=>'pending']) — auto-queue
```

The auto-queue creation is OPTIONAL. Decision: create a `pending` housekeeping assignment automatically on checkout, or rely on MANAGER to manually queue. **Recommendation: create automatically** — this is standard hotel practice (every checkout generates a cleaning task). The assignment can be unassigned (assigned_to = null) and MANAGER assigns a housekeeper later.

### 9.3 RoomAvailabilityRuleService Update

`isRoomUnavailable()` must include `CLEANING`:

```php
// Phase 4.2 — extended
public function isRoomUnavailable(Room $room): bool
{
    return in_array($room->status, [
        RoomStatus::OutOfOrder,
        RoomStatus::OutOfService,
        RoomStatus::Cleaning,     // NEW: cannot assign a room being cleaned
    ], true);
}
```

The availability display in `RoomAvailability/Index.vue` should show a `cleaning` badge for CLEANING rooms (distinct from `out_of_order`). This requires:
- `resolveAvailability()` to return `'cleaning'` for CLEANING rooms
- Frontend badge style (yellow/amber, distinct from gray `out_of_order`)

**Regression risk:** This change affects `RoomAvailabilityCheckerTest`. However, CLEANING rooms in tests are unlikely to be in the seeded dataset. Must be verified.

### 9.4 Special Requests — No Changes

`BookingSpecialRequest::pendingCountByRoom()` is already implemented. The Housekeeping Board reads this directly — no changes to `SpecialRequestService` or `BookingSpecialRequestController`.

The Board will receive `pendingRequestCounts` from `HousekeepingController` using the same query.

### 9.5 Night Audit — No Changes

Night Audit pipeline is completely independent. No `rooms.status` reads or writes in any `PostingJob`. Room cleaning state has no financial implications in Phase 4.2.

### 9.6 Room Board (RoomAvailability/Index.vue) — Minor Update

Add `cleaning` display state to `availabilityStyles` map:

```js
cleaning: {
  card: 'border-yellow-300 bg-yellow-50 hover:border-yellow-500 cursor-pointer',
  dot: 'bg-yellow-500',
  badge: 'bg-yellow-100 text-yellow-800',
},
```

And add to legend: `Đang dọn` with yellow dot.

---

## 10. Regression Risk

### 10.1 Risk Matrix

| Component | Change Type | Risk | Mitigation |
|-----------|-------------|------|-----------|
| `StayService::checkIn()` | Add non-throwing hook | LOW | Same pattern as Phase 4.1 `autoLinkSingleStayRequests` |
| `StayService::checkOut()` | Add non-throwing hook | LOW | Same pattern |
| `RoomAvailabilityRuleService::isRoomUnavailable()` | Add CLEANING to blocked | MEDIUM | Verify no CLEANING rooms in test seeds |
| `RoomAvailabilityCheckerService` | `resolveAvailability()` new 'cleaning' case | LOW | Additive new case |
| `RoomPolicy` | Read gate changes for HK board | MEDIUM | New `housekeeping.view` gate; existing `rooms.manage` gate unchanged |
| Financial modules | None | NONE | No financial code touched |
| Night Audit | None | NONE | Completely independent |
| `booking_special_requests` | None | NONE | Read-only from HK Board |

### 10.2 Financial Module Isolation Guarantee

The following modules must NOT be modified in Phase 4.2:

| Module | Risk |
|--------|------|
| `FolioService` | NONE — no folio writes in housekeeping |
| `BookingPaymentService` | NONE |
| `NightAuditPipeline` | NONE |
| `NightAuditService` | NONE |
| `RevenueReportService` | NONE |
| `ReconciliationService` | NONE |
| `PackageEnrollmentService` | NONE |
| `PostingJobs/*` | NONE |

### 10.3 Existing Test Impact

| Test | Impact | Action |
|------|--------|--------|
| `RoomAvailabilityCheckerTest` | Possible — if any seed has CLEANING rooms | Audit seed data before implementing |
| `BookingManagementUiTest` | NONE — room status not tested here | No action |
| `SpecialRequestUiTest` | NONE | No action |
| `SpecialRequestCrudTest` | NONE | No action |
| All financial tests | NONE | No action |

### 10.4 Pre-existing Failures (26)

These must not increase. Phase 4.2 Implementation Plan must verify the baseline before writing any code.

---

## 11. Required ADRs

### ADR-84: Automatic Room Status Transitions

**Context:** Room status currently only changes via manual admin CRUD. After checkout, rooms remain "occupied" (or whatever manual status) instead of "dirty". This makes housekeeping planning impossible.

**Decision:** `StayService::checkIn()` automatically sets `rooms.status = OCCUPIED`. `StayService::checkOut()` automatically sets `rooms.status = VACANT_DIRTY`. Both transitions use the non-throwing hook pattern (try/catch, log on failure, never propagate). Both execute inside the existing `DB::transaction()`.

**Lock Order:** Room lock acquired as last node after `Booking → Stay → RoomAssignment → Room`. Complies with Phase 3 P3 (which defines `Booking → Requirements → Folio → …` for financial transactions; room is not in the financial lock chain).

**Consequence:** Automatic status transitions mean staff can no longer rely on manual status as the source of truth. The enum-driven workflow becomes authoritative.

---

### ADR-85: Housekeeping Assignment as Separate Table

**Context:** Two alternatives: (a) add `assigned_to` / `priority` columns to `rooms` table, or (b) separate `housekeeping_assignments` table.

**Decision:** Separate `housekeeping_assignments` table. Rationale:
- Rooms can have multiple assignments over time (history needed).
- A room may be re-assigned mid-clean (housekeeper swap).
- Assignment priority, notes, and timing are temporal data — inappropriate on a static `rooms` row.
- Adding nullable columns to `rooms` would require updating `RoomService`, `RoomPolicy`, `RoomRepository`, and all room CRUD tests.

**Consequence:** Cleaning state is split between `rooms.status` (current snapshot) and `housekeeping_assignments` (workflow record). The Board must JOIN both.

---

### ADR-86: Cleaning Records as a Typed Table

**Context:** Phase 3 `audit_logs` captures JSON diffs of all model changes. An alternative to a `cleaning_records` table is querying `audit_logs` for `Room` entity with `status` changes.

**Decision:** Dedicated `cleaning_records` table. Rationale:
- Cleaning reports require typed fields: `duration_minutes`, `inspection_result`, `cleaned_by`, `inspected_by`.
- Joining JSON audit logs for performance metrics is fragile and slow at scale.
- `audit_logs` is append-only and cannot be updated (e.g., to add inspection result to an existing clean record).
- Inspection outcome (pass/fail) must update the same record created at clean completion.

**Consequence:** Two sources of truth for room status history: `audit_logs` (generic) and `cleaning_records` (typed). `AuditObserver` continues to record `Room` changes in `audit_logs` — not removed. `cleaning_records` is additive.

---

### ADR-87: Room Status Lock Order

**Context:** Concurrent requests (two housekeepers claiming the same room, a checkout and a cleaning assignment racing) can cause inconsistent room status.

**Decision:** All `HousekeepingService` methods that update `rooms.status` must:
1. Acquire a `SELECT FOR UPDATE` lock on the `Room` record at the start of the transaction.
2. Re-read `room.status` after acquiring the lock and validate the expected transition is still valid.
3. If the status no longer matches the expected pre-condition, throw a `ValidationException` (not silently succeed).

Example:
```php
// startCleaning(assignment)
DB::transaction(function() use ($assignment) {
    $room = Room::whereKey($assignment->room_id)->lockForUpdate()->firstOrFail();
    if ($room->status !== RoomStatus::VacantDirty) {
        throw ValidationException::withMessages(['room' => 'Room is no longer dirty.']);
    }
    $room->update(['status' => RoomStatus::Cleaning]);
    $assignment->update(['status' => 'in_progress', 'started_at' => now()]);
});
```

**Consequence:** Housekeeping actions are safe under concurrent staff. The UI will show a clear error if two housekeepers try to claim the same room simultaneously.

---

### ADR-88: CLEANING Status Blocks Room Assignment

**Context:** Currently `RoomAvailabilityRuleService::isRoomUnavailable()` only blocks `OutOfOrder` and `OutOfService`. A room in `CLEANING` state can be assigned to a new booking.

**Decision:** `CLEANING` is added to `isRoomUnavailable()`. The `resolveAvailability()` method returns a new `'cleaning'` availability string for CLEANING rooms (not `'out_of_order'`). The Room Availability board shows a distinct amber badge for CLEANING rooms. `RoomAvailabilityRuleService::hasConflict()` does not change — conflict detection is based on `room_assignments` overlaps, not room status. Status gate is at the assignment creation step.

**Consequence:** A room being cleaned cannot be assigned to a new booking. MANAGER must pass inspection (`passInspection()` → `VACANT_CLEAN`) before the room is bookable again. This is the correct hotel operational behavior.

---

### ADR-89: HOUSEKEEPING Permission Revision

**Context:** HOUSEKEEPING currently holds `rooms.manage` (full room CRUD, including delete), `room.assign`, and `room.unassign`. These permissions are semantically incorrect for housekeeping staff.

**Decision:**  
Remove from HOUSEKEEPING: `rooms.manage`, `room.assign`, `room.unassign`  
Add to HOUSEKEEPING: `housekeeping.view`, `housekeeping.assign`, `room.status.update`

`RoomPolicy` must be updated: `viewAny()`/`view()` for the purposes of the Housekeeping Board should gate on `housekeeping.view` OR `rooms.manage` (so ADMIN/MANAGER/HOUSEKEEPING can all see the board without all needing `rooms.manage`).

`HousekeepingController::index()` gates on `housekeeping.view`.  
All HK status transition actions gate on `room.status.update`.  
Inspection (`passInspection`, `failInspection`) gates on `room.inspect` (MANAGER/ADMIN only).  
`markOutOfOrder` / `releaseFromOutOfOrder` gates on `room.maintenance` (MANAGER/ADMIN only).

**Consequence:** HOUSEKEEPING staff can only view the board and update cleaning status. They cannot create/delete rooms or assign rooms to guest bookings. Separation of concerns is enforced at the permission level.

---

## 12. Required Changes Before Implementation Plan

The following must be addressed in the Implementation Plan before any code is written:

### 12.1 Mandatory

| # | Item | Reason |
|---|------|--------|
| C-01 | Create `housekeeping_assignments` migration | Required for assignment tracking |
| C-02 | Create `cleaning_records` migration | Required for cleaning history |
| C-03 | Implement `HousekeepingService` with 7 methods | Core workflow logic |
| C-04 | Implement `HousekeepingController` with 7 routes | Board and actions |
| C-05 | Implement `Housekeeping/Index.vue` | HOUSEKEEPING Board UI |
| C-06 | Hook `autoMarkOccupied` in `StayService::checkIn()` | Auto status on check-in |
| C-07 | Hook `autoMarkDirtyOnCheckout` in `StayService::checkOut()` | Auto status on checkout |
| C-08 | Update `isRoomUnavailable()` to include CLEANING | Prevent assignment of cleaning rooms |
| C-09 | Add `resolveAvailability()` case for 'cleaning' | Correct availability display |
| C-10 | Add 5 new permissions to seeder | ADR-89 permission revision |
| C-11 | Remove `rooms.manage`, `room.assign`, `room.unassign` from HOUSEKEEPING in seeder | ADR-89 |
| C-12 | Update `RoomPolicy` for board access via `housekeeping.view` | ADR-89 |
| C-13 | Write tests: HousekeepingService unit tests | 80% coverage minimum |
| C-14 | Write tests: HousekeepingController feature tests | All 7 actions |
| C-15 | Write tests: Race condition tests (concurrent start) | ADR-87 verification |

### 12.2 Recommended (non-blocking)

| # | Item | Reason |
|---|------|--------|
| R-01 | `rooms.last_cleaned_at` column | Board "last cleaned" without JOIN |
| R-02 | Housekeeping Board filters: floor / type / status / housekeeper | UX quality |
| R-03 | Cleaning priority display on board | Operational clarity |

### 12.3 Audit Required Before Implementation

- Verify no test seeds have rooms in `CLEANING` status → `RoomAvailabilityCheckerTest` impact
- Confirm `StayService::checkIn()` transaction already acquires Room lock (it currently does not — Room is not locked in checkIn, only Booking, Stay, RoomAssignment)
- Confirm `StayService::checkOut()` same check

---

## 13. Final Assessment

### What Phase 4.2 Gets Right from Phase 4.1

- Non-throwing hook pattern established — carry forward to HK hooks
- Service delegation pattern — carry forward to HousekeepingService
- `pendingCountByRoom()` ready for reuse on HK board
- AuditObserver will auto-log all Room status changes
- Inertia shared permissions pattern established

### Risks

| Risk | Probability | Impact | Mitigation |
|------|------------|--------|-----------|
| Race condition on room status | MEDIUM | MEDIUM | ADR-87 lock pattern |
| CLEANING rooms appear available | HIGH (current bug) | HIGH | ADR-88 + C-08 |
| HOUSEKEEPING accidentally deletes rooms | LOW (needs `rooms.manage`) | HIGH | ADR-89 + C-10/C-11 |
| `StayService` hook increases transaction time | LOW | LOW | Hook is single UPDATE, non-throwing |
| Test regression from `isRoomUnavailable()` change | LOW | MEDIUM | Audit seeds before implementing |

### Architecture Assessment

The proposed architecture is:
- **Additive:** 2 new tables, 1 new service, 1 new controller, 2 new Vue components
- **Non-invasive to financial layer:** Zero financial table access
- **Consistent with established patterns:** Service delegation, non-throwing hooks, Inertia + Vue 3 SFC
- **Safe for concurrent operations:** Pessimistic locking per ADR-87
- **Permission-safe:** ADR-89 removes HOUSEKEEPING over-grants

The only structural change to existing code is:
1. `StayService::checkIn/checkOut()` — adds 2 non-throwing hooks
2. `RoomAvailabilityRuleService::isRoomUnavailable()` — adds 1 enum case
3. `RoomAvailabilityCheckerService` — adds 1 branch in `resolveAvailability()`
4. `RolePermissionSeeder` — permission matrix revision (additive + 3 removals from HK)

All changes are small, isolated, and testable.

---

## Architecture Review Result: PASS WITH CHANGES

**5 ADRs required:** ADR-84, ADR-85, ADR-86, ADR-87, ADR-88, ADR-89 (6 ADRs total)  
**15 mandatory implementation items** (C-01 through C-15)  
**0 blockers that prevent starting the Implementation Plan**

**Permission to begin Implementation Plan: YES**  
Condition: Implementation Plan must include all 15 mandatory items (C-01 through C-15) and verify seed data before modifying `isRoomUnavailable()`.
