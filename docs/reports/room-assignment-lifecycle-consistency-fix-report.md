# Room Assignment Lifecycle Consistency Fix

## 1. Objective

Enforce correct state transitions for room assignment, check-in, and check-out workflows. Prevent invalid actions on released, checked-in, and checked-out assignments while preserving audit history.

## 2. Root Cause Analysis

Two distinct issues were found in the existing codebase:

**Bug A — Payload/controller mismatch on `can_release`**
`BookingController::bookingPayload()` set `can_release = true` for both `Assigned` AND `CheckedIn` statuses. The controller's `release()` method correctly blocked CheckedIn, but the frontend still showed the release button for checked-in rooms. The button appeared active but would fail silently via a session error.

**Bug B — Missing lifecycle flags in assignment payload**
The `assignments` array in `bookingPayload()` had no `is_released`, `is_checked_in`, `is_checked_out`, `can_check_in`, `can_check_out`, or `stay_id` fields. The frontend had no way to render the correct UI state per assignment without these.

**Bug C — No guard against check-in/check-out on released assignments**
`StayController::checkIn()` and `checkOut()` had no check on the associated assignment's status. A released assignment's stay could still be checked in/out, which would mutate the assignment status back to `CheckedIn` or `CheckedOut`.

**Bug D — No guard against re-releasing or releasing a completed assignment**
`RoomAssignmentController::release()` only blocked `CheckedIn`. It did not block `Released` (re-release) or `CheckedOut` (historical completed stay).

**Bug E — Stay payload had no `can_check_in`/`can_check_out` flags**
The frontend hardcoded `stay.status === 'RESERVED'` for check-in button visibility. This bypassed any server-side knowledge about whether the corresponding assignment was released.

## 3. Business Rules Implemented

| Rule | Description |
|------|-------------|
| R1 | Released assignments are history-only. Record stays in DB, visible in history. |
| R2 | Released assignments cannot be checked in. |
| R3 | Released assignments cannot be checked out. |
| R4 | Released assignments cannot be released again. |
| R5 | CheckedIn assignments cannot be released (existing rule, preserved). |
| R6 | CheckedOut assignments cannot be released. |
| R7 | Only `Assigned` (pre-check-in) assignments may be released. |
| R8 | History toggle hides released assignments by default; shows them on demand. |

## 4. Files Reviewed

- `app/Enums/AssignmentStatus.php` — values and `activeValues()`
- `app/Enums/StayStatus.php` — Reserved, CheckedIn, CheckedOut
- `app/Models/RoomAssignment.php` — relations, status, audit fields
- `app/Models/Stay.php` — relations, status, actual_checkin/checkout
- `app/Services/RoomAssignmentService.php` — assignRooms, releaseAssignment, getRoomBoard
- `app/Services/StayService.php` — checkIn, checkOut (both update assignment status too)
- `app/Policies/RoomAssignmentPolicy.php` — release permission check
- `app/Policies/StayPolicy.php` — checkIn/checkOut permission check
- `app/Http/Controllers/Admin/Booking/BookingController.php` — bookingPayload()
- `app/Http/Controllers/Admin/Booking/RoomAssignmentController.php` — release, releaseConflict
- `app/Http/Controllers/Admin/Booking/StayController.php` — checkIn, checkOut
- `resources/js/Pages/Admin/Bookings/Show.vue` — assignments table, stays table

## 5. Files Changed

| File | Change Type |
|------|------------|
| `app/Http/Controllers/Admin/Booking/BookingController.php` | Bug fix + feature |
| `app/Http/Controllers/Admin/Booking/RoomAssignmentController.php` | Bug fix |
| `app/Http/Controllers/Admin/Booking/StayController.php` | Bug fix |
| `resources/js/Pages/Admin/Bookings/Show.vue` | UI enhancement |
| `tests/Feature/BookingManagementUiTest.php` | 9 new tests |

## 6. Backend Validation Changes

### BookingController — `show()` eager loading

Added two relations to avoid N+1 queries for the new payload fields:

```php
'roomAssignments.stay',   // so assignment payload can expose stay_id
'stays.roomAssignment',   // so stay payload can check assignment release status
```

### BookingController — `bookingPayload()` assignments section

**Before:**
```php
'can_release' => in_array($assignment->status, [Assigned, CheckedIn], true),
```

**After:** Full lifecycle flag set:
```php
'is_released'                 => $assignment->status === AssignmentStatus::Released,
'is_checked_in'               => $assignment->status === AssignmentStatus::CheckedIn,
'is_checked_out'              => $assignment->status === AssignmentStatus::CheckedOut,
'is_checked_in_not_checked_out' => $assignment->status === AssignmentStatus::CheckedIn,
'can_release'                 => $assignment->status === AssignmentStatus::Assigned,
'can_check_in'                => $assignment->status === AssignmentStatus::Assigned,
'can_check_out'               => $assignment->status === AssignmentStatus::CheckedIn,
'action_disabled_reason'      => match(true) { ... },
'stay_id'                     => $assignment->stay?->id,
```

### BookingController — `bookingPayload()` stays section

Added backend-computed flags (accounts for released assignment):

```php
'can_check_in'  => $stay->status === StayStatus::Reserved
                    && $stay->roomAssignment?->status !== AssignmentStatus::Released,
'can_check_out' => $stay->status === StayStatus::CheckedIn,
```

### RoomAssignmentController — `release()` and `releaseConflict()`

Both methods now guard against three blocked statuses before the existing CheckedIn check:

```php
if ($assignment->status === AssignmentStatus::Released) {
    throw ValidationException::withMessages(['assignment' => 'Phòng đã được giải phóng. Không thể giải phóng lại.']);
}
// ... existing CheckedIn check ...
if ($assignment->status === AssignmentStatus::CheckedOut) {
    throw ValidationException::withMessages(['assignment' => 'Phòng đã hoàn thành lưu trú. Không thể giải phóng phòng lịch sử.']);
}
```

### StayController — `checkIn()` and `checkOut()`

Both methods now guard against released assignment before delegating to service:

```php
$stay->loadMissing('roomAssignment');
if ($stay->roomAssignment?->status === AssignmentStatus::Released) {
    throw ValidationException::withMessages(['stay' => 'Không thể nhận phòng. Phòng đã được giải phóng.']);
}
```

## 7. Frontend UI Changes

### History toggle

Added above the assignments table:

```html
<label class="flex cursor-pointer items-center gap-2 text-xs text-steel">
    <input v-model="showAssignmentHistory" type="checkbox" />
    Hiển thị lịch sử phân phòng
</label>
```

`showAssignmentHistory` ref defaults to `false`. `visibleAssignments` computed filters out `is_released === true` entries when unchecked.

### Assignment row actions (Thao tác column)

Each row now shows the correct action based on status:

| Assignment status | Actions shown |
|------------------|--------------|
| `ASSIGNED` | [Nhận phòng] [Giải phóng] |
| `CHECKED_IN` | [Trả phòng] |
| `CHECKED_OUT` | ✓ (CheckCircle icon) |
| `RELEASED` | "Đã giải phóng" text (row dimmed) |

Check-in/check-out buttons in the assignment row use `stay_id` from the payload to hit the `/stays/{id}/check-in` and `/stays/{id}/check-out` endpoints.

### Stays table

Check-in/check-out buttons now use server-computed flags:

```html
<button v-if="can.checkIn && stay.can_check_in" ...>Nhận phòng</button>
<button v-if="can.checkOut && stay.can_check_out" ...>Trả phòng</button>
```

Previously these used `stay.status === 'RESERVED'` (hardcoded), which did not account for released assignments.

## 8. History Visibility Changes

Released assignments remain in `booking.assignments` payload (never physically deleted). The `is_released` flag marks them for conditional rendering:

- **Default view** (`showAssignmentHistory = false`): `visibleAssignments` filters out `is_released` entries → clean operational view
- **History mode** (`showAssignmentHistory = true`): all assignments shown, released rows dimmed with `opacity-60`

Released rows still display: Phòng | Loại | Bắt đầu | Kết thúc | Trạng thái | Phân bởi | Giải phóng lúc | Lý do | "Đã giải phóng"

## 9. Authorization / Policy Changes

No changes to `RoomAssignmentPolicy` or `StayPolicy`. Existing permission gates (`room.unassign`, `stay.checkin`, `stay.checkout`) are preserved. The new guards are state-based, not permission-based.

## 10. Tests Added / Updated

9 new PHPUnit tests in `BookingManagementUiTest.php` (existing 78 tests unchanged):

| Test | Covers |
|------|--------|
| `test_released_assignment_cannot_check_in` | Rule R2 — backend rejects, stay remains Reserved |
| `test_released_assignment_cannot_check_out` | Rule R3 — backend rejects, stay remains Reserved |
| `test_released_assignment_cannot_release_again` | Rule R4 — backend rejects, stays Released |
| `test_checked_out_assignment_cannot_be_released` | Rule R6 — backend rejects, stays CheckedOut |
| `test_release_action_hidden_for_checked_in_assignment` | Payload: can_release=false, is_checked_in=true, can_check_out=true |
| `test_release_action_hidden_for_checked_out_assignment` | Payload: can_release=false, is_checked_out=true, all actions false |
| `test_released_assignments_remain_visible_in_payload` | Rule R1 — record preserved, is_released=true, release_reason intact |
| `test_assignment_payload_includes_lifecycle_flags` | All new flags present; active assignment has can_check_in=true, stay_id non-null |
| `test_stay_payload_includes_can_check_in_flag_false_for_released_assignment` | Stay payload: can_check_in=false after assignment release |

Pre-existing tests that also cover this fix:
- `test_cannot_release_current_booking_assignment_after_check_in` (Rule R5)
- `test_cannot_release_conflicting_booking_assignment_after_check_in` (Rule R5 via conflict endpoint)
- `test_can_release_current_booking_assignment_before_check_in` (Rule R7 happy path)
- `test_admin_can_check_in_stay` / `test_admin_can_check_out_stay` (normal flow unaffected)

## 11. Test Results

87/87 passed (778 assertions) — zero regressions.

## 12. Git Diff Summary

- `BookingController.php`: +13 lines (StayStatus import, 2 eager loads, expanded assignments/stays payload)
- `RoomAssignmentController.php`: +24 lines (Released + CheckedOut guards in both release endpoints)
- `StayController.php`: +14 lines (AssignmentStatus import, ValidationException import, guards in checkIn/checkOut)
- `Show.vue`: +30 lines (showAssignmentHistory ref, visibleAssignments computed, history toggle UI, reworked assignment row actions, stays table flag-based buttons)
- `BookingManagementUiTest.php`: +126 lines (9 new tests)

## 13. Risks / Notes

- `StayService::releaseAssignment()` does NOT automatically cancel the corresponding stay when an assignment is released. The stay remains in `Reserved` status. This is intentional — the stay record serves as audit history. The backend guard in `StayController::checkIn()` prevents it from being actioned. If the business later requires auto-cancelling the stay on release, that change belongs in `RoomAssignmentService::releaseAssignment()`.
- The `releaseConflict` endpoint received the same Released/CheckedOut guards as `release()` for consistency, even though in practice a conflicting assignment would not normally be in those states (the `whereIn('status', activeValues())` check in `releaseConflict` already filters to only Assigned/CheckedIn).
- `is_checked_in_not_checked_out` is exposed in the payload as an alias of `is_checked_in` for clarity in future UI expressions that need to distinguish "currently occupied" from other states.

## 14. Context For Future Sessions

- `booking.assignments[].is_released` = true → record is historical, filter out from active view
- `booking.assignments[].can_release` = true ONLY when status === Assigned (pre-check-in, not released, not checked-in, not checked-out)
- `booking.assignments[].stay_id` = linked stay ID for check-in/check-out endpoints
- `booking.stays[].can_check_in` = false if assignment is Released (server-computed, not just status check)
- `showAssignmentHistory` (frontend ref) = toggle for history view, default false
- `visibleAssignments` (frontend computed) = filtered list used in assignments table
- StayController now imports AssignmentStatus and ValidationException
- BookingController now imports StayStatus
- Both `release()` and `releaseConflict()` block: Released, CheckedIn, CheckedOut
