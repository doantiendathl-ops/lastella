# Phase 2.4 Critical Fixes Before Commit Report

## 1. Objective

Address five critical issues identified by Codex review of Phase 2.4 Room Availability Checker before committing. Issues range from P0 race condition to test coverage gaps.

## 2. Codex Findings Addressed

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| 1 | Double Assignment Race Condition | P0 | Fixed |
| 2 | Invalid Stay Lifecycle Transitions | P1 | Fixed |
| 3 | Duplicate / Drifting Availability Logic | P1 | Fixed |
| 4 | Availability Query Performance | P2 | Fixed |
| 5 | Test Coverage Gaps | P2 | Fixed |

## 3. Files Reviewed

- `app/Services/RoomAssignmentService.php`
- `app/Services/RoomAvailabilityCheckerService.php`
- `app/Services/StayService.php`
- `app/Http/Controllers/Admin/Booking/StayController.php`
- `app/Http/Controllers/Admin/Booking/RoomAssignmentController.php`
- `app/Services/BookingService.php`
- `app/Enums/AssignmentStatus.php`
- `app/Enums/StayStatus.php`
- `app/Models/RoomAssignment.php`
- `app/Models/Stay.php`
- `database/migrations/2026_02_01_000030_create_room_assignments_table.php`
- `tests/Feature/RoomAvailabilityCheckerTest.php`
- `tests/Feature/BookingManagementUiTest.php`

## 4. Files Changed

| File | Action |
|------|--------|
| `app/Services/RoomAvailabilityRuleService.php` | **Created** — shared source of truth for blocking logic |
| `app/Services/RoomAssignmentService.php` | Modified — lockForUpdate, shared rules, release guards |
| `app/Services/RoomAvailabilityCheckerService.php` | Modified — delegates to shared rules |
| `app/Services/StayService.php` | Modified — lifecycle guards for check-in/check-out |
| `app/Http/Controllers/Admin/Booking/StayController.php` | Modified — simplified (guards moved to service) |
| `app/Http/Controllers/Admin/Booking/RoomAssignmentController.php` | Modified — simplified (guards moved to service) |
| `database/migrations/2026_06_22_000000_add_status_date_index_to_room_assignments_table.php` | **Created** — composite index for global queries |
| `tests/Feature/RoomAvailabilityCheckerTest.php` | Modified — added boundary, overlap, multi-booking, OOO tests |
| `tests/Feature/BookingManagementUiTest.php` | Modified — added lifecycle, boundary, race condition tests |

## 5. Race Condition Fix (Issue 1)

**Before:** `assignRooms()` ran inside a transaction but used `Room::findOrFail()` without row-level locking. Two concurrent requests could both pass `checkRoomConflict()` and insert overlapping ASSIGNED rows.

**After:** `assignRooms()` now uses `Room::whereKey($roomId)->lockForUpdate()->firstOrFail()` inside the transaction. The pessimistic lock on the room row prevents a second request from reading the room until the first transaction commits or rolls back. The conflict check runs while the lock is held, eliminating the TOCTOU window.

**Impact:** Database-level protection against concurrent double assignment. No application-level mutex needed.

## 6. Stay Lifecycle Fix (Issue 2)

**Before:** `StayService::checkIn()` and `checkOut()` blindly updated status with no validation. `StayController` only checked for Released assignments. Invalid transitions like checkout-before-checkin or re-checkin-after-checkout were possible.

**After:** Comprehensive guards enforced in the service layer:

| Action | Required State | Guards |
|--------|---------------|--------|
| Check-in | assignment.status = Assigned | Not Released, not CheckedIn, not CheckedOut, no prior actual_checkin_at |
| Check-out | assignment.status = CheckedIn | actual_checkin_at exists, actual_checkout_at is null |
| Release | assignment.status = Assigned | Not Released, not CheckedIn, not CheckedOut |

Guards throw `ValidationException` with descriptive messages. Controllers simplified — no duplicate validation.

## 7. Shared Availability Logic (Issue 3)

**Before:** Blocking rules duplicated across four locations: `RoomAvailabilityCheckerService`, `RoomAssignmentService::checkRoomConflict()`, `getRoomBoard()`, and `releaseConflict()`.

**After:** Created `RoomAvailabilityRuleService` as the single source of truth:

- `hasConflict()` — single-room conflict check (replaces inline queries in checkRoomConflict)
- `getBlockingAssignments()` — global blocking query (used by both room board and availability checker)
- `resolveAvailability()` — status resolution logic (used by both views)
- `isRoomUnavailable()` — room physical status check
- `hasTimeOverlap()` — multi-assignment overlap detection

All consumers (`RoomAssignmentService`, `RoomAvailabilityCheckerService`) delegate to this shared service. Date inputs are normalized to Carbon to prevent SQLite string comparison issues.

## 8. Index / Performance Changes (Issue 4)

**Existing index:** `['room_id', 'status', 'start_at', 'end_at']` — optimized for single-room queries.

**New index:** `['status', 'start_at', 'end_at']` — added via migration `2026_06_22_000000`. Optimizes global availability queries that filter by status and date range without a room_id filter (the Room Availability Checker page).

Existing indexes preserved. No removals.

## 9. Tests Added / Updated

### New Tests in RoomAvailabilityCheckerTest (4 tests)

1. `test_checkout_at_boundary_touching_next_checkin_does_not_overlap` — boundary at exact same timestamp
2. `test_overlapping_assigned_rooms_are_blocked` — partial overlap detection
3. `test_same_room_multiple_non_overlapping_assignments_shows_multi_booking` — multi_booking vs overlap
4. `test_out_of_order_room_blocks_availability` — OOO room status

### New Tests in BookingManagementUiTest (7 tests)

5. `test_reserved_assignment_cannot_check_out_directly` — checkout without checkin
6. `test_checked_in_assignment_cannot_be_released` — release blocked during stay
7. `test_checked_out_assignment_cannot_check_in_again` — no re-checkin
8. `test_checked_out_assignment_cannot_check_out_again` — no re-checkout
9. `test_boundary_touching_assignment_does_not_conflict_on_room_board` — room board boundary
10. `test_boundary_touching_assignment_can_be_assigned` — assignment at boundary succeeds
11. `test_assignment_uses_database_transaction` — documents lock/transaction behavior

### Pre-existing Tests (verified still passing)

- `test_released_assignment_cannot_check_in`
- `test_released_assignment_cannot_check_out`
- `test_released_assignment_cannot_release_again`
- `test_checked_out_assignment_cannot_be_released`
- `test_room_board_and_availability_checker_use_same_blocking_rules`

## 10. Test Results

```
Tests: 191 passed (1310 assertions)
Duration: 113.06s
```

All tests pass. Zero failures.

## 11. Git Diff Summary

- 1 new service file (`RoomAvailabilityRuleService`)
- 1 new migration file
- 5 modified PHP files (3 services, 2 controllers)
- 2 modified test files
- 1 report document

## 12. Remaining Risks

1. **True concurrent stress test**: The `lockForUpdate` strategy is correct for MySQL/PostgreSQL. SQLite (used in tests) does not support row-level locking — the test documents the behavior but cannot exercise actual lock contention. Consider adding a MySQL integration test if concurrent assignment volume is high.

2. **BookingService::cancelBooking()** bypasses `RoomAssignmentService::releaseAssignment()` and updates assignments directly via Eloquent. This is intentional (bulk release during cancellation should not be blocked by lifecycle guards) but worth noting as a parallel mutation path.

3. **checkInMany / checkOutMany** in StayService now inherit the lifecycle guards. If bulk operations are used for batch check-in, any invalid stay in the batch will throw and abort the entire batch. This is safe behavior but callers should be aware.

## 13. Ready For Commit: YES
