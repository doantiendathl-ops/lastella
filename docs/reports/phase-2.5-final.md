# Phase 2.5 — Final Closure Report

## Phase Summary

Phase 2.5 delivered the operational layer of the PMS: everything between a booking existing and a guest physically checking out. The following modules were implemented or hardened in this phase.

### Implemented Modules

| Module | Description |
|---|---|
| Room Assignment | Assign, release, and conflict-detect rooms against a booking. Locking, lifecycle validation, and audit trail. |
| Room Board | Interactive board on the booking detail page showing per-room availability, conflict state, tooltip metadata, color palette, and lock indicators. |
| Room Availability Checker | Standalone page (`/admin/room-availability`) for arbitrary date-range availability queries across all floors and rooms. Includes room-type summary cards and floor-row layout. |
| Booking Time Change | Update `checkin_at` / `checkout_at` with full conflict re-validation under pessimistic locks, cascading to active assignments and stays. |
| Check-in / Check-out | Stay lifecycle transitions with time guards, balance check on checkout, and status propagation to booking. |
| Release Room | Release an assignment with reason, status enforcement, and stay cancellation. |
| Booking Permission Consistency | Aligned Room Availability "View Booking" action to `BookingPolicy::viewAny()` instead of the incorrect `booking.update` gate. |
| UI Improvements | 24-hour time format, compact room cards, floor-row layout, conflict dialog with structured flash data, requirement summary cards, color palette picker. |
| Lock Ordering | Established canonical lock order: Room → Stay → RoomAssignment across `BookingService`, `StayService`, and `RoomAssignmentService`. |
| Concurrency Protection | `validateTimeChange` wrapped in `DB::transaction` with pessimistic row locks; post-lock re-validation on all freshly-locked rows before mutation. |
| Regression Test Coverage | Feature test suite expanded to cover all edge cases: boundary touching, multi-booking, lifecycle state guards, concurrency, and permission variations. |

---

## Quality Summary

| Severity | Count |
|---|---|
| Critical | 0 |
| High | 0 |
| Medium | 0 |

All Codex review findings from Phase 2.5 have been resolved.

---

## Test Summary

```
281 / 281 tests passing (1874 assertions)
```

Test classes covering Phase 2.5 deliverables:

- `BookingEngineFoundationTest` — booking lifecycle, assignment, stay, time-change concurrency (26 tests)
- `BookingManagementUiTest` — controller/Inertia payloads, permission gates, UI state flags (164 tests)
- `RoomAvailabilityCheckerTest` — availability logic, date-range overlap, permission model (26 tests)
- `DashboardTest` — room count accuracy after state transitions (6 tests)

---

## Architecture Improvements

| Improvement | Detail |
|---|---|
| Shared `RoomAvailabilityRuleService` | Single source of truth for blocking-assignment queries and availability resolution. Used by both Room Board (`RoomAssignmentService`) and Room Availability Checker (`RoomAvailabilityCheckerService`). Eliminates duplicated overlap logic. |
| Unified conflict validation | `findConflictForTimeChange` centralises the overlap check for booking time edits. Both the pre-existing UI conflict check and the new locked path call the same rule. |
| Consistent lock ordering | All three services that touch Stay + RoomAssignment rows now acquire locks in Room → Stay → RA order, preventing deadlock. Lock order is documented and tested. |
| `BookingPolicy` permission consistency | Room Availability "View Booking" now delegates to `BookingPolicy::viewAny()`, matching the Room Board and booking list. Roles with `report.view` but not `booking.update` (e.g. ACCOUNTANT) correctly see the link. |
| Race-condition protection | `BookingService::validateTimeChange` runs inside `DB::transaction`. Conflict detection and status checks execute against pessimistic-locked rows, preventing the validate-then-concurrent-write race. |
| Deadlock prevention | Lock acquisition order aligned across `BookingService`, `StayService`, and `RoomAssignmentService`. Deterministic ascending-ID ordering within each resource type. |
| Availability checker consistency | `RoomAvailabilityCheckerService::check()` uses `filterCheckedInByDate: true` to apply strict date-range overlap for CheckedIn assignments, matching the semantics of the Room Board. |

---

## Remaining Technical Debt

| Item | Severity | Notes |
|---|---|---|
| SQLite `lockForUpdate` is a no-op in tests | Testing only | All behaviour tests pass. True concurrency serialization is enforced by MySQL/PostgreSQL row-level locks in production. Parallel-process concurrency tests would require a real DB engine; acceptable to defer. |

No known production-impacting technical debt.

---

## Final Status

```
Phase 2.5 COMPLETE
Ready for Phase 3
```
