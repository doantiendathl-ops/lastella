# Phase 2.3.x Refinement: Room Assignment Lock & Color Palette Report

**Date:** 2026-06-16
**Tests:** 50 passed in BookingManagementUiTest (131 total suite)
**Status:** Ready

---

## 1. Requirements Implemented

### Requirement 1 — Lock Room Assignment After Check-in

Backend enforcement added to both release endpoints in `RoomAssignmentController.php`.

- `release()`: If `$assignment->status === AssignmentStatus::CheckedIn`, throws `ValidationException` with message `"Phòng đã nhận phòng và chưa trả phòng nên không thể điều chỉnh phân phòng."` before any mutation.
- `releaseConflict()`: Same guard, same message.

### Requirement 2 — Click Current Booking Room Opens Panel

- `roomCardClass()` in `Show.vue`: `current_booking` rooms now return `cursor-pointer border-gray-200 bg-gray-100 overflow-hidden` (was `cursor-not-allowed`).
- `toggleRoomSelection()`: Handles `current_booking` → calls `openCurrentBookingPanel(room)`.
- `aria-disabled` and `tabindex` updated to allow keyboard access on `current_booking` rooms.
- New panel modal added: shows room info, booking code, guest name, assignment dates, assignment status. Shows lock message if `is_assignment_locked`. Shows "Gỡ phòng khỏi booking này" button only when `can_release_assignment === true`. Always shows "Đóng".

### Requirement 3 — Conflict Behavior

Unchanged. Conflict rooms still open the existing conflict panel with full detail.

### Requirement 4 — 16-Color Palette Swatches in Form.vue

Replaced `<input type="color">` with 16 clickable color swatches sourced from `options.recommended_booking_colors`. Custom color picker still available below swatches. Shows hex code next to custom picker. Message shown when all 16 palette colors are in use.

### Requirement 5 — Exclude Used Colors from Recommended Palette

`BookingController::options()` now queries active bookings (status NOT IN `CANCELLED`, `NO_SHOW`, `CHECKED_OUT`) for their `booking_color`. Returns `recommended_booking_colors` (palette minus used) and `used_booking_colors`. When editing, the booking's own color is excluded from the used-colors query so it remains visible in the palette. `edit()` now passes `booking: $booking` to `options()`.

---

## 2. Payload Changes

### current_assignment key (rooms with availability_status === 'current_booking')

```php
'current_assignment' => [
    'assigned_to_current_booking' => true,
    'assignment_id' => int,
    'assignment_status' => string,       // e.g. 'ASSIGNED' | 'CHECKED_IN'
    'is_assignment_locked' => bool,
    'lock_reason' => string|null,
    'can_release_assignment' => bool,    // false when locked OR missing permission
]
```

### conflict_booking key additions

```php
'is_assignment_locked' => bool,
'lock_reason' => string|null,
'can_unassign_room' => bool,    // now false when locked (was only checking permission)
```

---

## 3. Files Changed (6)

| File | Change |
|------|--------|
| `app/Services/RoomAssignmentService.php` | Added lock metadata to `conflict_booking`; added `current_assignment` key |
| `app/Http/Controllers/Admin/Booking/RoomAssignmentController.php` | Added `ValidationException` import; lock guards in `release()` and `releaseConflict()` |
| `app/Http/Controllers/Admin/Booking/BookingController.php` | Added 16-color palette logic to `options()`; `edit()` now passes `booking` |
| `resources/js/Pages/Admin/Bookings/Show.vue` | New refs + functions for current-booking panel; updated `toggleRoomSelection`, `roomCardClass`, `aria-disabled`, `tabindex`; added panel modal |
| `resources/js/Pages/Admin/Bookings/Form.vue` | Replaced single color input with swatch grid + custom picker |
| `tests/Feature/BookingManagementUiTest.php` | 10 new test methods covering all 12 specified test cases |

---

## 4. Tests

**10 new test methods added** (covers all 12 specified tests; tests 11-12 are covered by the full suite):

| # | Test | Result |
|---|------|--------|
| 1 | `test_cannot_release_current_booking_assignment_after_check_in` | ✓ |
| 2 | `test_cannot_release_conflicting_booking_assignment_after_check_in` | ✓ |
| 3 | `test_can_release_current_booking_assignment_before_check_in` | ✓ |
| 4 | `test_current_booking_assignment_payload_includes_lock_metadata_when_checked_in` | ✓ |
| 5 | `test_conflict_payload_includes_lock_metadata_when_checked_in` | ✓ |
| 6 | `test_current_booking_room_payload_includes_current_assignment_data` | ✓ |
| 7 | `test_recommended_palette_excludes_colors_used_by_active_bookings` | ✓ |
| 8 | `test_completed_bookings_do_not_reserve_colors_in_palette` | ✓ |
| 9 | `test_editing_booking_keeps_own_color_in_palette` | ✓ |
| 10 | `test_custom_color_still_accepted_for_booking` | ✓ |
| 11 | Existing room assignment tests | ✓ 131 total pass |
| 12 | Existing booking tests | ✓ 131 total pass |
