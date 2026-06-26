# Phase 2.5 — Codex Blocker Fix Report

## 1. Objective

Fix all issues identified by Codex review of Phase 2.5 Booking UX & Room Operations before commit.

## 2. Codex Issues Addressed

| Issue | Severity | Status |
|-------|----------|--------|
| Race condition in release/check-in/check-out | CRITICAL | Fixed |
| Released assignment leaves stay as RESERVED | HIGH | Fixed |
| Booking list column order (Color before Code) | MEDIUM | Fixed |
| Invalid nested interactive elements in tooltip | MEDIUM | Fixed |
| Incorrect view permission for tooltip can_view | MEDIUM | Fixed |
| New booking default color bypasses palette | MEDIUM | Fixed |
| Guest allocation misleading after simplification | MEDIUM | Fixed |

## 3. Files Reviewed

- `app/Services/RoomAssignmentService.php`
- `app/Services/StayService.php`
- `app/Services/BookingService.php`
- `app/Http/Controllers/Admin/Booking/BookingController.php`
- `app/Policies/BookingPolicy.php`
- `resources/js/Pages/Admin/Bookings/Show.vue`
- `resources/js/Pages/Admin/Bookings/Index.vue`
- `resources/js/Pages/Admin/Bookings/Form.vue`

## 4. Files Changed

| File | Changes |
|------|---------|
| `app/Services/RoomAssignmentService.php` | Row-level locking in release; cancel stay on release; fix can_view permission |
| `app/Services/StayService.php` | Row-level locking in checkIn/checkOut; validate after lock |
| `resources/js/Pages/Admin/Bookings/Index.vue` | Swap column order: Color before Code |
| `resources/js/Pages/Admin/Bookings/Show.vue` | Room card `<button>` → `<div role="button">`; remove misleading allocation display |
| `resources/js/Pages/Admin/Bookings/Form.vue` | Default color from first recommended palette color |
| `tests/Feature/BookingManagementUiTest.php` | Updated 2 existing tests; added 10 new tests |

**Total files changed: 6**

## 5. Race Condition Fix

### Before
- `releaseAssignment()`: Guards ran before transaction, without locking. State could change between guard and mutation.
- `checkIn()`: Guards ran outside transaction. Concurrent release could leave invalid state.
- `checkOut()`: Same pattern as checkIn.

### After
All three operations now:
1. Open a DB transaction
2. Lock the `room_assignments` row with `lockForUpdate()`
3. Lock the related `stays` row with `lockForUpdate()`
4. Validate state on the locked, fresh rows
5. Mutate state

### Release guards (after lock)
- `assignment.status` must be `Assigned`
- `stay.actual_checkin_at` must be null

### Check-in guards (after lock)
- `assignment.status` must be `Assigned`
- `stay.actual_checkin_at` must be null

### Check-out guards (after lock)
- `assignment.status` must be `CheckedIn`
- `stay.actual_checkin_at` must not be null
- `stay.actual_checkout_at` must be null

## 6. Stay Aggregation Fix

When releasing an assignment before check-in:
- Assignment status → `Released`
- Related stay status → `Cancelled` (was: left as `Reserved`)
- `updateBookingStayStatus()` called after release to refresh booking status

The `updateBookingStayStatus()` method already excludes `Cancelled` and `NoShow` stays from active counts, so released stays no longer contribute to `PARTIALLY_CHECKED_OUT` calculations.

## 7. Booking List Column Order Fix

Changed from: `Action | Code | Color | ...`
Changed to: `Action | Color | Code | ...`

Both header `<th>` and data `<td>` cells reordered.

## 8. Tooltip Structure Fix

Room card element changed from `<button>` to `<div role="button">` with:
- `tabindex` for keyboard accessibility
- `@keydown.enter` and `@keydown.space.prevent` handlers
- Tooltip action `<Link>` and `<button>` elements are no longer nested inside a `<button>`

## 9. Permission Fix

Tooltip `can_view` changed from `Auth::user()?->can('booking.update')` to `Auth::user()?->can('viewAny', Booking::class)`.

BookingPolicy::viewAny allows `report.view || booking.create || booking.update || booking.cancel`, so users with any booking-related permission can view bookings through tooltip links.

## 10. Default Color Fix

New booking form default color changed from hardcoded `#196251` to `props.options.recommended_booking_colors?.[0] ?? '#196251'`.

This uses the first available recommended palette color, falling back to hardcoded default only when all palette colors are in use.

## 11. Guest Allocation / Capacity Fix

Removed the misleading per-requirement guest allocation display that depended on hidden `adults`/`children_under_6`/`children_over_6` fields. The guest count card now shows simple totals only. The capacity warning panel (added in Phase 2.5) provides the capacity-based summary derived from room type rules.

## 12. Tests Added / Updated

### Updated (2 tests)
- `test_released_assignment_cannot_check_in` — Assert stay is `Cancelled` (not `Reserved`)
- `test_released_assignment_cannot_check_out` — Assert stay is `Cancelled` (not `Reserved`)

### Added (10 tests)
1. `test_release_locks_fresh_state_before_mutating`
2. `test_release_sets_stay_to_cancelled`
3. `test_released_stay_excluded_from_booking_stay_aggregation`
4. `test_checkin_locks_fresh_state_rejects_released`
5. `test_checkout_rejects_assigned_stay`
6. `test_checkout_rejects_released_assignment`
7. `test_booking_list_column_order_color_before_code`
8. `test_can_view_uses_view_permission_not_update`
9. `test_new_booking_defaults_to_first_recommended_palette_color`
10. `test_capacity_summary_displayed_without_misleading_allocation`

## 13. Test Results

```
Tests: 122 passed (1033 assertions)
Duration: 131.39s
```

## 14. Remaining Risks

- Row-level locking uses `lockForUpdate()` which requires InnoDB (MySQL/MariaDB default). SQLite (used in tests) does not support row-level locking; the guard logic still runs correctly in tests but the lock is a no-op.
- Capacity rules remain client-side in Vue. Adding new room types requires updating the `roomTypeCapacity` map.

## 15. Ready For Commit

**YES**
