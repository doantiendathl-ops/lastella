# Phase 2.5 — Availability Range Filter Fix

## Root Cause

`RoomAvailabilityRuleService::getBlockingAssignments()` fetched ALL `CHECKED_IN`
assignments globally without date overlap filtering (lines 58-60). This caused
any room with an active checked-in booking to appear as occupied in the
Availability Checker regardless of whether the booking's dates overlapped the
searched period.

The Room Board intentionally uses this behavior — a physically present guest
blocks a room regardless of planned dates. But the Availability Checker queries
arbitrary date ranges where this logic is incorrect.

## Files Changed

| File | Change |
|------|--------|
| `app/Services/RoomAvailabilityRuleService.php` | Added `filterCheckedInByDate` parameter to `getBlockingAssignments()`. When true, applies the same `start_at < end AND end_at > start` overlap filter to CHECKED_IN assignments. |
| `app/Services/RoomAvailabilityCheckerService.php` | Passes `filterCheckedInByDate: true` to `getBlockingAssignments()`. |
| `tests/Feature/RoomAvailabilityCheckerTest.php` | Updated 4 existing tests to match corrected behavior; added 7 regression tests. |

**Not changed:** `RoomAssignmentService::getRoomBoard()` — keeps default
`filterCheckedInByDate: false` so the Room Board still treats CHECKED_IN as
always blocking (correct for assignment decisions).

## Tests Added

| # | Test | Asserts |
|---|------|---------|
| 1 | Booking ends before search period → NOT visible | CHECKED_IN with end_at < search_start shows as available |
| 2 | Booking starts after search period → NOT visible | CHECKED_IN with start_at > search_end shows as available |
| 3 | Partial overlap → visible | CHECKED_IN overlapping search shows as occupied |
| 4 | Exact boundary (checkout == search start) → NOT visible | Strict inequality: end_at == search_start is not overlap |
| 5 | Exact boundary (checkin == search end) → NOT visible | Strict inequality: start_at == search_end is not overlap |
| 6 | Checked-in overstay with overlapping dates → visible | Overstay whose planned dates overlap search is shown |
| 7 | Released assignment → NOT visible | Released within search range is not blocking |

Total: 23 tests (16 existing updated + 7 new regression), all passing.

## Full Test Suite

224 tests passed, 0 failures.

## Ready for Manual Retest

**YES**
