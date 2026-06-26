# Booking Summary and Room Board Tooltip Enhancement

## Summary

Implemented three enhancements to the Booking Detail page: room type availability summary panel, guest allocation summary panel, and conflict room tooltip fix.

## Changes

### Part A — Room Type Availability Summary (`Tình trạng loại phòng`)

**Backend** (`app/Services/RoomAssignmentService.php`):
- Refactored `getRoomBoard()` to extract floors data into `$floorsData` variable first, then derive `room_type_summary` from it via `flatMap` + `groupBy`.
- `room_type_summary` array is keyed by required room types only (excluded non-required types).
- Each entry includes: `room_type_id`, `room_type_code`, `room_type_name`, `total`, `occupied` (conflict + unavailable), `current_booking`, `remaining` (available).
- Return shape extended: `{ floors: [...], room_type_summary: [...] }`.

**Frontend** (`resources/js/Pages/Admin/Bookings/Show.vue`):
- Added `roomTypeSummaryDisplay` computed that adjusts `selected` and `remaining` counts to reflect pending (not-yet-saved) room selections.
- Badge logic: `🟢` remaining > 30% total, `🟡` remaining > 0 ≤ 30%, `🔴` remaining = 0.
- Added "Tình trạng loại phòng" card grid to `room_map` tab, between the assignment summary and the room board form.

### Part B — Guest Allocation Summary (`Phân bổ khách`)

**Frontend** (`resources/js/Pages/Admin/Bookings/Show.vue`):
- Added `guestAllocation` computed: total = booking headcount, allocated = sum of all requirement headcounts, remaining = total − allocated.
- Added "Phân bổ khách" panel to `info` tab, above the requirements table.
- Color coding: `text-pine` when fully allocated, `text-amber-600` when guests remain, `text-coral` when over-allocated.

### Part C — Conflict Room Tooltip Fix

**Frontend** (`resources/js/Pages/Admin/Bookings/Show.vue`):
- Removed `overflow-hidden` from `roomCardClass()` for `conflict` and `current_booking` statuses — this was clipping the absolutely-positioned tooltip div.
- Added `conflict_booking.status` (formatted via `labelFor('bookingStatus', ...)`) to the tooltip's assignment detail block for conflict rooms.
- Added `conflict_booking.lock_reason` display below `disabled_reason` in tooltip (amber text, shown only when `is_assignment_locked` is true).

## Files Changed

| File | Change |
|------|--------|
| `app/Services/RoomAssignmentService.php` | Added `room_type_summary` to `getRoomBoard()` return |
| `resources/js/Pages/Admin/Bookings/Show.vue` | Added 2 computed properties, fixed `roomCardClass`, updated tooltip, added 2 UI sections |
| `tests/Feature/BookingManagementUiTest.php` | Added 8 PHPUnit tests for `room_type_summary` |

## Tests

8 new tests covering:
1. `room_type_summary` key present in `roomBoard` prop
2. Empty when booking has no requirements
3. Available room counted in `remaining`
4. Conflict room counted in `occupied`
5. OutOfOrder room counted in `occupied`
6. Current booking room counted in `current_booking`
7. Non-required room types excluded from summary
8. `total` matches actual DB room count for the type

**Test result:** 66/66 passed (550 assertions) — no regressions.

## Constraints Respected

- Phase 3 not started.
- Payment, Folio, Finance, Service Charges, Reporting, Dashboard, Multi-property modules not modified.
- No commit made — awaiting approval.
