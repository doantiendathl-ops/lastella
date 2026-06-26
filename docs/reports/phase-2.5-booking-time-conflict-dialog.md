# Phase 2.5 — Booking Time Conflict Dialog With View Booking Link

## Summary

When saving a booking's check-in/check-out time triggers a room assignment conflict, the system now flashes a structured conflict payload to the session and surfaces it as a modal dialog on the edit form. The plain validation error on `checkin_at` / `checkout_at` is preserved for backward compatibility.

## Files Changed (4)

| File | Change |
|------|--------|
| `app/Services/RoomAvailabilityRuleService.php` | Extended eager-load in `findConflictForTimeChange()` to include `booking.customer_name`, `booking.checkin_at`, `booking.checkin_at`, `room.room_type_id`, `room.roomType.code` |
| `app/Services/BookingService.php` | Re-added `RoomAssignment` import; added `assignmentStatusLabel()` helper; `validateTimeChange()` now builds a structured conflict array and calls `session()->flash('booking_time_conflicts', ...)` before throwing `ValidationException` |
| `app/Http/Controllers/Admin/Booking/BookingController.php` | `edit()` now passes `bookingTimeConflicts => session('booking_time_conflicts')` as an Inertia prop |
| `resources/js/Pages/Admin/Bookings/Form.vue` | Added `bookingTimeConflicts` prop; `watch` auto-opens `showConflictModal` when prop is non-empty; added `<Teleport to="body">` conflict modal with room/booking details and per-conflict "Xem booking" link |

## Structured Conflict Payload Shape

```json
[
  {
    "room_id": 1,
    "room_number": "101",
    "room_type": "TWIN",
    "booking_id": 42,
    "booking_code": "BK-20260701-0001",
    "customer_name": "Nguyễn Văn A",
    "checkin_at": "2026-07-02 15:00",
    "checkout_at": "2026-07-03 12:00",
    "status_label": "Đã phân phòng",
    "view_url": "/admin/bookings/42"
  }
]
```

## Status Labels

| Assignment Status | Label |
|-------------------|-------|
| `ASSIGNED` | Đã phân phòng |
| `CHECKED_IN` (within dates) | Đã nhận phòng |
| `CHECKED_IN` (past `end_at`) | Quá hạn lưu trú |

## Tests

8 new tests added to `tests/Feature/BookingManagementUiTest.php`:

1. `test_time_conflict_dialog_flashes_structured_data_on_conflict`
2. `test_time_conflict_dialog_structured_data_includes_all_required_fields`
3. `test_time_conflict_dialog_includes_multiple_conflicts`
4. `test_time_conflict_dialog_checkout_only_still_reports_checkout_field_and_flashes`
5. `test_time_conflict_dialog_no_flash_when_no_conflict`
6. `test_time_conflict_dialog_no_flash_for_released_assignment`
7. `test_time_conflict_dialog_status_label_assigned`
8. `test_time_conflict_dialog_status_label_checked_in`

Full suite: **268 tests / 1837 assertions — all passed**.
