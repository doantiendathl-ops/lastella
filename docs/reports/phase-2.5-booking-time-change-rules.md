# Phase 2.5 — Booking Time Change Rules

## 1. Objective

Implement safe business rules for changing booking check-in/check-out time
after room assignment to prevent conflicts and invalid stay lifecycle states.

## 2. Business Rules Implemented

| State | Check-in Change | Check-out Change |
|-------|----------------|-----------------|
| No assignments | Allowed | Allowed |
| Assigned, not checked in | Allowed if no room conflict | Allowed if no room conflict |
| Checked in, not checked out | Blocked | Allowed if no room conflict |
| Checked out | Blocked | Blocked |
| Cancelled | Blocked | Blocked |

On successful time change with assignments: assignment `start_at`/`end_at` and
stay `planned_checkin_at`/`planned_checkout_at` are updated atomically.

## 3. Files Reviewed

- `app/Services/BookingService.php`
- `app/Services/RoomAssignmentService.php`
- `app/Services/RoomAvailabilityRuleService.php`
- `app/Services/StayService.php`
- `app/Http/Controllers/Admin/Booking/BookingController.php`
- `app/Http/Requests/Booking/UpdateBookingRequest.php`
- `resources/js/Pages/Admin/Bookings/Form.vue`

## 4. Files Changed

| File | Change |
|------|--------|
| `app/Services/BookingService.php` | Added `validateTimeChange()` method, imported `RoomAssignment` |
| `app/Http/Controllers/Admin/Booking/BookingController.php` | `formPayload()` includes `has_active_assignments`, `has_checked_in`, `has_checked_out`, `is_cancelled` |
| `resources/js/Pages/Admin/Bookings/Form.vue` | Computed flags for field disabling, warning banner, disabled styling |
| `tests/Feature/BookingManagementUiTest.php` | 11 new tests for time change rules |

**4 files changed.**

## 5. Backend Validation

`BookingService::validateTimeChange()` enforces all rules within the existing
`updateBooking()` transaction before `$booking->fill($data)->save()`:

- Detects check-in/check-out time changes by comparing new values against current
- Blocks terminal states (cancelled, checked-out)
- Blocks check-in time change when any stay has `actual_checkin_at`
- Queries `RoomAssignment` for overlapping conflicts, excluding the booking's own
  assignments (`where('id', '!=', $assignment->id)`)
- Uses strict overlap: `start_at < new_checkout AND end_at > new_checkin`
- On success, updates all active assignment and stay date ranges atomically

## 6. Frontend UI Changes

- Warning banner above date fields shows context-specific message
- Check-in fields disabled when booking is checked-in, checked-out, or cancelled
- Check-out fields disabled when booking is checked-out or cancelled
- Disabled fields use gray styling with `cursor-not-allowed`
- Backend validation errors displayed below fields as before

## 7. Conflict Detection Rules

Uses the standard overlap rule, consistent with `RoomAvailabilityRuleService`:
```
assignment.start_at < new_checkout_at AND assignment.end_at > new_checkin_at
```

- Released assignments: not queried (only ASSIGNED and CHECKED_IN)
- Checked-out assignments: not queried
- Boundary touching (checkout == next checkin): NOT a conflict (strict inequality)
- Self-exclusion: booking's own assignments excluded from conflict check

## 8. Tests Added / Updated

| Test | Asserts |
|------|---------|
| `can_change_time_on_booking_with_no_assignments` | Free change, dates updated |
| `can_change_time_when_assigned_rooms_remain_available` | Assignment and stay dates updated |
| `cannot_change_time_when_assigned_room_conflicts` | Validation error, dates unchanged |
| `checked_in_booking_cannot_change_checkin_time` | Blocked with error |
| `checked_in_booking_can_extend_checkout_if_no_conflict` | Checkout extended, actual times unchanged |
| `checked_in_booking_cannot_extend_checkout_into_conflict` | Blocked with error |
| `checked_out_booking_cannot_change_time` | Blocked with error |
| `cancelled_booking_cannot_change_time` | Blocked with error |
| `released_assignment_does_not_block_time_change` | Change succeeds |
| `new_checkout_equal_to_next_booking_checkin_does_not_conflict` | Boundary allowed |
| `edit_form_includes_assignment_state_flags` | Payload has correct flags |

## 9. Test Results

251 passed, 0 failures (1748 assertions)

## 10. Remaining Risks

- No admin override for checked-out bookings (blocked as specified)
- Folio/pricing not automatically recalculated on time change (out of scope)
- Multi-room bookings: all active assignments must be conflict-free; partial
  success is not possible (atomic transaction)

## 11. Ready for Codex Review

**YES**
