# Phase 2.4 Final Blocker Fix Report

## 1. Objective

Fix the three remaining blockers identified by Codex after the initial Phase 2.4 critical fixes. All three concern shared availability logic consistency.

## 2. Codex Blockers Addressed

| # | Blocker | Status |
|---|---------|--------|
| 1 | `releaseConflict()` uses old date-overlap logic instead of shared rules | Fixed |
| 2 | Current-booking room board assignments are date-bound (hides CheckedIn on date drift) | Fixed |
| 3 | Multi-room assignment lock order not normalized (deadlock risk) | Fixed |

## 3. Files Reviewed

- `app/Http/Controllers/Admin/Booking/RoomAssignmentController.php`
- `app/Services/RoomAssignmentService.php`
- `app/Services/RoomAvailabilityRuleService.php`
- `tests/Feature/BookingManagementUiTest.php`

## 4. Files Changed

| File | Action |
|------|--------|
| `app/Services/RoomAvailabilityRuleService.php` | Added `isAssignmentBlockingBooking()` method |
| `app/Http/Controllers/Admin/Booking/RoomAssignmentController.php` | Refactored `releaseConflict()` to delegate to shared rule service |
| `app/Services/RoomAssignmentService.php` | Fixed current-assignment query + added lock order sort |
| `tests/Feature/BookingManagementUiTest.php` | Added 7 new test cases |

## 5. releaseConflict Refactor (Blocker 1)

**Before:** Controller had inline query with `whereIn('status', activeValues())` and date-overlap predicates (`start_at < checkout_at AND end_at > checkin_at`). This missed CheckedIn assignments whose planned dates didn't overlap the booking (overstay/date-drift case).

**After:** Controller calls `$this->rules->isAssignmentBlockingBooking($assignment, $booking)`. The shared method implements the full blocking ruleset:

| Status | Blocks? | Logic |
|--------|---------|-------|
| Released | No | Never blocks |
| CheckedOut | No | Never blocks |
| CheckedIn | Yes | Always blocks (guest physically present) |
| Assigned | Conditional | Blocks only when planned range overlaps booking range |

Controller no longer contains any availability/conflict logic — only authorization and delegation.

## 6. Current Booking Assignment Visibility Fix (Blocker 2)

**Before:** Current-booking assignments were queried with:
```
whereIn('status', activeValues()) AND start_at < checkout_at AND end_at > checkin_at
```
If a booking's dates changed after check-in, the CheckedIn assignment could disappear from the board.

**After:** Split into two conditions joined by OR:
- `status = CheckedIn` — always shown regardless of dates (guest physically present)
- `status = Assigned AND start_at < checkout_at AND end_at > checkin_at` — shown only when date range overlaps

Released and CheckedOut assignments are excluded from the active board (they only appear in history views).

## 7. Lock Order Normalization (Blocker 3)

**Before:** `assignRooms()` iterated over assignments in caller-provided order. Two concurrent requests assigning rooms `[5, 3]` and `[3, 5]` could deadlock.

**After:** Added `usort($assignments, fn ($a, $b) => $a['room_id'] <=> $b['room_id'])` before entering the transaction. All requests now lock rooms in ascending ID order, eliminating circular-wait deadlock conditions.

## 8. Tests Added / Updated

### New Tests (7)

| # | Test | Blocker |
|---|------|---------|
| 1 | `test_release_conflict_works_for_checked_in_assignment_regardless_of_dates` | B1 |
| 2 | `test_release_conflict_rejects_non_blocking_released_assignment` | B1 |
| 3 | `test_release_conflict_rejects_non_blocking_checked_out_assignment` | B1 |
| 4 | `test_checked_in_current_booking_assignment_visible_even_when_dates_drift` | B2 |
| 5 | `test_released_current_booking_assignment_not_in_active_board` | B2 |
| 6 | `test_checked_out_current_booking_assignment_not_in_active_board` | B2 |
| 7 | `test_multi_room_assignment_succeeds_regardless_of_input_order` | B3 |

### Pre-existing Tests (verified still passing)

All 191 previously passing tests continue to pass with no regressions.

## 9. Test Results

```
Tests: 198 passed (1359 assertions)
Duration: 145.48s
```

Zero failures.

## 10. Remaining Risks

1. **SQLite lock semantics in tests**: SQLite serializes transactions at the connection level rather than supporting row-level `lockForUpdate`. The deterministic sort order is verified by test #7 (correct insertion regardless of input order), but actual deadlock prevention can only be confirmed under MySQL/PostgreSQL concurrent load.

2. **BookingService::cancelBooking()** still updates assignments directly (bulk release) without calling `releaseAssignment()`. This is intentional for bulk cancellation but remains a parallel mutation path that bypasses the service-level guards.

## 11. Ready For Commit: YES
