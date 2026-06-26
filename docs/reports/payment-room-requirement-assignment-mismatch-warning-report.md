# Payment Warning — Room Requirement vs Assignment Mismatch

## 1. Objective

Show a non-blocking safety warning in Booking Detail → Tài chính tab when the number of assigned rooms (by room type) does not match the booking's requirements, so reception staff can review before collecting payment.

## 2. Files Changed

| File | Change |
|------|--------|
| `app/Http/Controllers/Admin/Booking/BookingController.php` | Added `roomAssignmentMismatch()` method; added `room_assignment_mismatch` key to `bookingPayload()` |
| `resources/js/Pages/Admin/Bookings/Show.vue` | Added mismatch warning banner in Tài chính tab |
| `tests/Feature/BookingManagementUiTest.php` | Added 6 new PHPUnit tests |

## 3. Backend Changes

### `BookingController::roomAssignmentMismatch(Booking $booking): array`

New private method that computes the mismatch between requirements and active assignments.

**Active statuses counted:** `ASSIGNED`, `CHECKED_IN`, `CHECKED_OUT`
- `Released`, `Cancelled`, `NoShow` are excluded.
- `CheckedOut` is included because a checked-out room was genuinely used by this booking.

**Algorithm:**

1. Group `bookingRequirements` by `room_type_id`, sum `quantity` per type.
2. Filter `roomAssignments` to active statuses, group by `room_type_id`, count per type.
3. Merge all type IDs from both sides.
4. For each type with a non-zero difference: emit an item with `status: 'missing'` (assigned < required) or `status: 'excess'` (assigned > required).

**Return shape:**

```json
{
  "has_mismatch": true,
  "items": [
    {
      "room_type_id": 1,
      "room_type_name": "TWIN",
      "required_quantity": 2,
      "assigned_quantity": 1,
      "difference": -1,
      "status": "missing"
    }
  ]
}
```

When there is no mismatch: `has_mismatch: false`, `items: []`.

### Placement in `bookingPayload()`

```php
'room_assignment_mismatch' => $this->roomAssignmentMismatch($booking),
```

No eager loading changes needed — `bookingRequirements.roomType` and `roomAssignments.roomType` are already loaded in `show()`.

## 4. Frontend UI

Warning banner added at the **top** of the Tài chính tab (before the three financial summary cards).

**Shown only when:** `booking.room_assignment_mismatch?.has_mismatch === true`

**Contents:**
- Amber border + background block
- ⚠️ icon + Vietnamese warning headline
- Per-room-type detail list with required/assigned/difference and thiếu/vượt label
- Two action links: "Xem sơ đồ phòng" (switches to room_map tab) and "Xem thông tin booking" (switches to info tab)

**Does NOT block payment** — the form and submit button are unchanged below the warning.

## 5. Mismatch Detection Rules

| Scenario | has_mismatch | status |
|----------|-------------|--------|
| assigned === required per type | false | — |
| assigned < required | true | missing |
| assigned > required | true | excess |
| assigned = 0, required > 0 | true | missing |
| type in assignments only (not in requirements) | true | excess |
| all Released / Cancelled / NoShow | true (counts as 0 assigned) | missing |

## 6. Tests Added

6 new PHPUnit tests in `BookingManagementUiTest.php`:

| Test | Scenario |
|------|---------|
| `test_booking_payload_includes_room_assignment_mismatch_key` | Key and sub-keys present in booking prop |
| `test_no_mismatch_when_requirements_and_assignments_match` | 1 TWIN req + 1 TWIN assigned → has_mismatch=false |
| `test_mismatch_when_fewer_rooms_assigned_than_required` | 2 TWIN req + 1 TWIN assigned → missing, diff=-1 |
| `test_mismatch_when_more_rooms_assigned_than_required` | 1 TWIN req + 2 TWIN assigned → excess, diff=1 |
| `test_mismatch_calculated_per_room_type` | TWIN matched + DOUBLE extra → only DOUBLE in items |
| `test_released_assignments_excluded_from_mismatch_check` | Released assignment counts as 0 → mismatch still detected |

## 7. Test Results

78/78 passed (692 assertions) — no regressions.

## 8. Constraints Respected

- Warning only — no payment blocking, no folio calculation changes.
- No changes to `FolioService`, `PaymentService`, or any finance module.
- No changes to `RoomAssignmentService` or room board logic.
- No new eager loads (relations already loaded in `show()`).
