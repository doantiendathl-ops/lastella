# Phase 2.5 — Room Board Conflict vs Informational Booking

## Root Cause

Three methods in `RoomAvailabilityRuleService` treated ALL `CHECKED_IN`
assignments as blocking regardless of date overlap:

1. **`hasConflict()`** — returned `true` for any room with a CHECKED_IN
   assignment, preventing assignment even when dates don't overlap.
2. **`getBlockingAssignments()`** (default mode) — fetched all CHECKED_IN
   assignments without date filter, causing the Room Board to display
   non-overlapping bookings as conflicts.
3. **`isAssignmentBlockingBooking()`** — returned `true` for CHECKED_IN
   unconditionally, used as a guard on the `releaseConflict` endpoint.

A booking checked in Jun 24–27 would show as a conflict on the Room Board
for a booking planned Aug 4–6, even though dates don't overlap.

## Files Changed

| File | Change |
|------|--------|
| `app/Services/RoomAvailabilityRuleService.php` | `hasConflict()`: unified CHECKED_IN and ASSIGNED into one date-overlap query. `isAssignmentBlockingBooking()`: CHECKED_IN now uses same overlap check as ASSIGNED. |
| `app/Services/RoomAssignmentService.php` | `getRoomBoard()`: passes `filterCheckedInByDate: true`. Added query for non-overlapping CHECKED_IN to populate `info_booking` field. |
| `resources/js/Pages/Admin/Bookings/Show.vue` | Tooltip shows informational message when `room.info_booking` exists. |
| `tests/Feature/RoomAvailabilityCheckerTest.php` | Replaced old "always blocks" test with two new tests: non-overlapping = available+info, overlapping = conflict. |
| `tests/Feature/BookingManagementUiTest.php` | Updated `releaseConflict` test for non-overlapping CHECKED_IN (now 404). Added 4 regression tests. |

**5 files changed.**

## Tests

| Test | Asserts |
|------|---------|
| `room_board_shows_non_overlapping_checked_in_as_available_with_info` | Non-overlapping CHECKED_IN → `available` with `info_booking` payload |
| `room_board_shows_overlapping_checked_in_as_conflict` | Overlapping CHECKED_IN → `conflict` |
| `non_overlapping_checked_in_room_is_available_on_room_board` | Room Board shows `available`, no `conflict_booking`, has `info_booking` |
| `overlapping_checked_in_room_is_conflict_on_room_board` | Room Board shows `conflict` with `conflict_booking` |
| `non_overlapping_checked_in_room_can_be_assigned` | Assignment succeeds, no validation error |
| `non_overlapping_checked_in_room_not_counted_as_occupied_in_summary` | Room type summary: `occupied=0`, full `remaining` |
| `release_conflict_rejects_non_overlapping_checked_in_assignment` | Release endpoint returns 404 for non-conflict |

**236 tests passed, 0 failures (1654 assertions).**

## Ready for Manual QA

**YES**
