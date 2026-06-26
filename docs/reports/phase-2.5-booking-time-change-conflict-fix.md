# Phase 2.5 — Booking Time Change Conflict Fix

## 1. Objective

Fix four correctness issues in the booking time-change validation logic and bring it into alignment with the shared `RoomAvailabilityRuleService`.

## 2. Issues Fixed

| # | Issue | Root Cause | Fix |
|---|-------|-----------|-----|
| 1 | Duplicated conflict query | `BookingService::validateTimeChange` re-implemented the overlap query inline instead of delegating to the shared service | Extracted `findConflictForTimeChange()` into `RoomAvailabilityRuleService`; `BookingService` now calls it |
| 2 | Self-conflict on multi-room bookings | Old code excluded only `id != $assignment->id`; another assignment on the same room belonging to the same booking would still appear as a conflict | New method excludes `booking_id != $booking->id`, removing all assignments of the current booking |
| 3 | Wrong validation field | Error was always placed on `checkin_at` even when only the checkout time changed | `conflictField` is now `checkout_at` when only checkout changed, `checkin_at` otherwise |
| 4 | Vague conflict message | Message did not include the room number or the conflicting booking code | Message now reads: "Phòng {room_number} đang được booking {booking_code} sử dụng trong khoảng thời gian này." |

## 3. Files Changed

- `app/Services/RoomAvailabilityRuleService.php` — added `findConflictForTimeChange(int $roomId, …, int $excludeBookingId): ?RoomAssignment`
- `app/Services/BookingService.php` — injected `RoomAvailabilityRuleService $rules`; replaced inline query with shared method; fixed conflict field; improved message
- `tests/Feature/BookingManagementUiTest.php` — added 8 new tests; corrected 1 existing test (`checkin_at` → `checkout_at`)

## 4. Business Logic Changes

- **Single source of truth**: Room conflict rules are now exclusively governed by `RoomAvailabilityRuleService` across Room Assignment, Room Board, Availability Checker, and Booking Time Change.
- **Booking exclusion scope**: During time-change validation, all assignments belonging to the current booking are excluded (not just the one assignment being iterated), preventing any multi-assignment booking from conflicting with itself.
- **Field precision**: Validation errors now land on the field the user actually needs to fix. A checkout-only extension that hits a future booking returns the error under `checkout_at`; moving the checkin earlier into a prior booking returns the error under `checkin_at`.
- **Message clarity**: Error message body includes the exact room number and conflicting booking code so staff know which room and reservation to resolve.

## 5. Tests Added / Updated

### New (8 tests)

| Test | Covers |
|------|--------|
| `test_time_change_no_self_conflict_same_room_assigned_twice` | Regression for issue #2 — same room assigned twice in one booking must not self-conflict |
| `test_time_change_no_self_conflict_with_two_rooms` | Multi-room booking extends checkout with no external conflict → success |
| `test_time_change_blocks_on_assigned_room_conflict` | External assigned room in the new range blocks the update |
| `test_time_change_checkout_only_conflict_reports_checkout_field` | Error field is `checkout_at` when only checkout changed |
| `test_time_change_checkin_conflict_reports_checkin_field` | Error field is `checkin_at` when checkin moved earlier into a prior booking |
| `test_time_change_blocks_on_checked_in_room_conflict` | Checked-in assignment in the new range also blocks the update |
| `test_time_change_boundary_does_not_conflict` | Adjacent booking (end == new start) does not conflict |
| `test_time_change_released_assignment_does_not_conflict` | Released assignment is ignored by conflict check |
| `test_time_change_conflict_message_includes_room_number_and_booking_code` | Error message body contains the room number and conflicting booking code |

### Updated (1 test)

- `test_checked_in_booking_cannot_extend_checkout_into_conflict`: assertion changed from `checkin_at` to `checkout_at` to match the now-correct field routing.

## 6. Test Results

```
Tests: 260 passed (1788 assertions)
Duration: 160s
```

All 260 tests pass.

## 7. Remaining Risks

- None of the four fixes change database schema; rollback is safe.
- `findConflictForTimeChange` runs one query per active assignment. For a booking with many rooms this produces N queries, consistent with the previous behavior.

## 8. Ready for Codex Review

**YES**
