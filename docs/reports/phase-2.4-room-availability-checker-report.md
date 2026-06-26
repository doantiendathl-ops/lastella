# Phase 2.4 — Room Availability Checker

## 1. Objective

Add a standalone operational feature that lets staff check which rooms are available in any selected date/time range — independent of any specific booking.

## 2. Business Logic

### Availability Status Priority (highest to lowest)

| Priority | Status | Condition | Label |
|----------|--------|-----------|-------|
| 1 | `out_of_order` | Room status = OutOfOrder / OutOfService | Không khả dụng |
| 2 | `overstay` | CheckedIn assignment with `end_at < now()` | Quá hạn lưu trú |
| 3 | `occupied` | CheckedIn assignment (guest physically present) | Đang ở |
| 4 | `overlap` | Multiple Assigned in range, time ranges conflict | Xung đột |
| 5 | `multi_booking` | Multiple Assigned in range, no time conflict | Nhiều booking |
| 6 | `reserved` | One Assigned assignment overlapping range | Đã giữ phòng |
| 7 | `available` | No blocking assignment | Trống |

### Key Rules

- **CheckedIn** assignments ALWAYS block, regardless of planned dates or query range. A guest physically in the room blocks all future queries until they check out (`status = CheckedOut`).
- **Assigned** (planned, not yet checked in) assignments block only when their `start_at < query_end AND end_at > query_start`.
- **CheckedOut** and **Released** assignments do NOT block.
- Overstay is detected when `status = CheckedIn AND assignment.end_at < now()`.

### Summary Badge Thresholds

- `remaining / sellable > 30%` → `many` / Còn nhiều
- `0 < remaining / sellable ≤ 30%` → `low` / Sắp hết
- `remaining = 0` → `none` / Hết phòng

(`sellable = total - out_of_order`)

## 3. Files Created / Modified

### New Files

| File | Purpose |
|------|---------|
| `app/Services/RoomAvailabilityCheckerService.php` | Core availability logic |
| `app/Http/Controllers/Admin/RoomAvailabilityController.php` | HTTP handler, authorization |
| `resources/js/Pages/Admin/RoomAvailability/Index.vue` | Frontend page |
| `tests/Feature/RoomAvailabilityCheckerTest.php` | 12 PHPUnit tests |

### Modified Files

| File | Change |
|------|--------|
| `database/seeders/RolePermissionSeeder.php` | Added `room_availability.view` permission to ADMIN, MANAGER, SALES, RECEPTION, ACCOUNTANT |
| `routes/web.php` | Added `GET /admin/room-availability` route |
| `resources/js/Layouts/AppLayout.vue` | Added "Kiểm tra phòng" nav item with `Search` icon |
| `app/Services/RoomAssignmentService.php` | Updated `checkRoomConflict()` and `getRoomBoard()` to use same blocking rules |

## 4. Backend: RoomAvailabilityCheckerService

### Query Strategy

Two separate queries per `check()` call:

```php
// 1. CheckedIn: always blocking
RoomAssignment::where('status', CheckedIn)->get()

// 2. Assigned: blocking only within date range
RoomAssignment::where('status', Assigned)
    ->where('start_at', '<', $endAt)
    ->where('end_at', '>', $startAt)
    ->get()
```

### Per-Room Status Resolution

```
if isUnavailable → 'out_of_order'
if any CheckedIn:
    if any CheckedIn.end_at < now → 'overstay'
    else → 'occupied'
else if reserved.count == 0 → 'available'
else if reserved.count == 1 → 'reserved'
else if hasTimeOverlap(reserved) → 'overlap'
else → 'multi_booking'
```

### Room Detail Payload per Room

```json
{
  "room_id": 1,
  "room_number": "101",
  "room_type_id": 1,
  "room_type_code": "TWIN",
  "room_type_name": "Twin Bed",
  "availability": "reserved",
  "availability_label": "Đã giữ phòng",
  "booking_count": 1,
  "has_overlap": false,
  "primary_color": "#8B5CF6",
  "bookings": [
    {
      "booking_id": 5,
      "booking_code": "BK-00000005",
      "customer_name": "Jane Guest",
      "booking_color": "#8B5CF6",
      "booking_status": "PENDING_ASSIGNMENT",
      "assignment_status": "ASSIGNED",
      "checkin_at": "2026-08-01 14:00",
      "checkout_at": "2026-08-02 12:00",
      "can_view": true
    }
  ]
}
```

### Room Type Summary

```json
{
  "room_type_code": "TWIN",
  "total": 10,
  "out_of_order": 1,
  "occupied": 3,
  "remaining": 6,
  "badge": "many",
  "badge_label": "Còn nhiều"
}
```

`occupied` in the summary includes all blocking statuses (overstay + occupied + reserved + overlap + multi_booking).

## 5. Conflict Detection Update

`RoomAssignmentService::checkRoomConflict()` updated to match new rules:

```php
// CheckedIn always blocks
$hasCheckedIn = query->where('status', CheckedIn)->exists();
if ($hasCheckedIn) return true;

// Assigned blocks only if date overlaps
return query->where('status', Assigned)
    ->where('start_at', '<', $endAt)
    ->where('end_at', '>', $startAt)
    ->exists();
```

`getRoomBoard()` uses the same split: `$checkedInConflicts->union($reservedConflicts)` with checked-in taking precedence for rooms appearing in both.

## 6. Permission

| Permission | Roles |
|------------|-------|
| `room_availability.view` | ADMIN, MANAGER, SALES, RECEPTION, ACCOUNTANT |

`can.viewBooking` (booking links in room detail) → `booking.update` permission only (ADMIN, MANAGER, RECEPTION).

## 7. Navigation

- Added "Kiểm tra phòng" after "Đặt phòng" in sidebar
- Route: `GET /admin/room-availability` → `admin.room-availability.index`
- Visible to: users with `room_availability.view`

## 8. Frontend

### Date Range Filter

- Default: today 14:00 to tomorrow 12:00
- `datetime-local` inputs (ISO T format converted to space format for server)
- Submits via `router.get()` (full page reload with new params)

### Room Card Visual States

| Status | Border/Background | Dot Color |
|--------|------------------|-----------|
| available | green-200 / green-50 | green-400 |
| reserved | blue-200 / blue-50 | blue-400 |
| occupied | orange-300 / orange-50 | orange-500 |
| overstay | red-400 / red-50 | red-600 |
| multi_booking | amber-200 / amber-50 | amber-400 |
| overlap | red-300 / red-50 | red-500 |
| out_of_order | gray-200 / gray-100 (dim) | gray-400 |

### Room Detail Modal

Click any non-OOO room to open a modal showing all blocking assignments with booking code, guest name, planned check-in/out, and booking color indicator. "Xem booking" link shown only if `can.viewBooking = true`.

## 9. Tests

12 PHPUnit tests in `tests/Feature/RoomAvailabilityCheckerTest.php`:

| # | Test | Covers |
|---|------|--------|
| 1 | `authorized_user_can_view_room_availability_page` | Auth: 200 for permitted user |
| 2 | `user_without_permission_is_rejected_with_403` | Auth: 403 for HOUSEKEEPING |
| 3 | `guest_is_redirected_to_login` | Auth: redirect for unauthenticated |
| 4 | `response_includes_required_structure` | Inertia props structure |
| 5 | `assigned_room_in_range_shows_as_reserved_and_blocks` | BL-1: Reserved blocks in range |
| 6 | `assigned_room_outside_range_shows_as_available` | BL-2: Reserved doesn't block outside range |
| 7 | `checked_in_room_blocks_even_after_planned_checkout` | BL-3: CheckedIn always blocks |
| 8 | `checked_in_room_past_planned_checkout_is_marked_overstay` | BL-4: Overstay detection |
| 9 | `checked_out_room_does_not_block_availability` | BL-5: CheckedOut doesn't block |
| 10 | `released_assignment_does_not_block_availability` | BL-6: Released doesn't block |
| 11 | `room_type_summary_counts_all_blocking_statuses_as_unavailable` | BL-7: Summary accuracy |
| 12 | `room_board_and_availability_checker_use_same_blocking_rules` | BL-8: Consistency between board and checker |

**Result: 180/180 tests passed (1225 assertions) — zero regressions.**

## 10. Risks / Notes

- **No DB migration needed**: all availability logic is derived from existing `room_assignments.status` and `end_at` fields.
- **`checkRoomConflict()` is now stricter**: a room with a CheckedIn guest blocks ALL new assignments, even if the planned dates don't overlap. This is the correct behavior but operators should be aware that a checked-in room is completely unavailable until the actual checkout (`status → CheckedOut`).
- **Dashboard occupancy counters** (counting `CheckedIn` assignments) are unaffected — that logic uses `AssignmentStatus::CheckedIn` directly and was not changed.
- **Seeder re-run required** in staging/production to apply the new `room_availability.view` permission to existing roles.

## 11. Context For Future Sessions

- `available_status` values: `available`, `reserved`, `occupied`, `overstay`, `overlap`, `multi_booking`, `out_of_order`
- `occupied` in `room_type_summary` = ALL blocking rooms (reserved + occupied + overstay + overlap + multi_booking)
- `checkRoomConflict()` now has TWO checks: CheckedIn (no date) + Assigned (with date overlap)
- `getRoomBoard()` conflicts: `$checkedInConflicts->union($reservedConflicts)` (checked-in takes precedence)
- Permission: `room_availability.view` (not `booking.update`)
- Vue: `availabilityStyles` object has 7 keys (including `reserved` and `overstay` as new additions)
