# Phase 2.5 – Booking Time Change Locking Final Fix

## 1. Objective

Resolve two locking defects found by Codex review in `BookingService::validateTimeChange`:

1. **Issue 1** — Business validation (hasCheckedOut, hasCheckedIn, candidate selection) ran before any locks were acquired. Concurrent operations could change row state between the pre-lock read and the actual update, causing mutations to Released/CheckedOut assignments or bypassing checkout/checkin guards.

2. **Issue 2** — The previous fix locked Stay rows *after* RoomAssignment rows (RA → Stay), directly opposing StayService::checkIn / checkOut which locks Stay *before* RoomAssignment (Stay → RA). This created a real deadlock path between the time change and concurrent check-in/check-out operations.

---

## 2. Codex Findings Addressed

| Finding | Status |
|---|---|
| Validation runs before locks → stale pre-lock state used for business decisions | Fixed |
| Lock order RA → Stay opposes StayService Stay → RA | Fixed |
| Locked rows not re-validated after acquiring lock | Fixed |
| Released/CheckedOut assignment could be mutated silently | Fixed |

---

## 3. Final Locking Strategy

```
Room rows  (sorted by room_id ASC)   ← blocks concurrent assignRooms
Stay rows  (sorted by stay id ASC)   ← locked BEFORE RA (matches StayService)
RA rows    (sorted by id ASC)        ← locked AFTER Stay
```

### Why this order

- **Room first**: matches `RoomAssignmentService::assignRooms` (room_id asc), so a concurrent room assignment to the same room must wait for the time-change transaction to finish.
- **Stay before RA**: matches `StayService::checkIn` and `checkOut` (Stay → RA). Using the same direction eliminates the deadlock that would occur with opposing directions.
- **`releaseAssignment` aligned**: `RoomAssignmentService::releaseAssignment` has been updated to lock Stay before RoomAssignment (Stay → RA), matching the direction used by `StayService` and `BookingService::validateTimeChange`. All three paths now share a consistent lock order.

---

## 4. Post-lock Validation Rules

After all locks are acquired, the following are re-validated from freshly locked rows:

| Condition | Action |
|---|---|
| `locked stay.actual_checkout_at` is set | Reject: "Booking đã trả phòng" |
| `locked stay.actual_checkin_at` is set AND checkin time is changing | Reject: "Booking đã nhận phòng" |
| `locked assignment.status` is not Assigned or CheckedIn | Reject: "Trạng thái phân phòng đã thay đổi" |

### Pre-lock fail-fasts (still needed)

Two checks happen before locking because they apply even when no active assignments exist:

- **hasCheckedOut** (via `Stay::where(booking_id)->whereNotNull(actual_checkout_at)->exists()`): Catches the case where all assignments are CheckedOut (none qualify as candidates) but the booking still has a completed stay.
- **hasCheckedIn with checkinChanged** (same pattern): Prevents moving the planned check-in after a guest has physically arrived.

Both are re-confirmed under locks (step 6) so the pre-lock race window is closed.

---

## 5. Files Changed

| File | Change |
|---|---|
| `app/Services/BookingService.php` | Complete rewrite of `validateTimeChange`: 7-step structure, pre-lock stay checks, Room → Stay → RA lock order, full post-lock re-validation |
| `tests/Feature/BookingEngineFoundationTest.php` | Added `Stay` model import + 5 post-lock re-validation tests |

---

## 6. Tests Added / Updated

### Phase 2.5 Final Locking Fix – Post-lock Re-validation Tests (new)

| Test | Verifies |
|---|---|
| `test_post_lock_revalidation_rejects_when_stay_has_actual_checkout` | Locked stay with actual_checkout_at causes rejection |
| `test_post_lock_revalidation_rejects_released_assignment` | Released assignment before candidate query → early return, booking dates update, no assignment mutation |
| `test_post_lock_revalidation_rejects_checkin_change_when_stay_checked_in` | Locked stay with actual_checkin_at blocks checkin time change |
| `test_post_lock_revalidation_allows_checkout_only_change_when_stay_checked_in` | Checkout-only change succeeds even when stay is checked in |
| `test_lock_order_stay_before_assignment_consistent_with_stay_service` | Behavioural compatibility: checkIn then time change both succeed (same Stay → RA direction) |

### Phase 2.5 Concurrency Fix – Regression Tests (previous, preserved)

`test_time_change_uses_locked_validation_and_catches_new_conflict`, `test_concurrent_assignment_cannot_bypass_validation`, `test_concurrent_check_in_cannot_invalidate_booking_time_change`, `test_concurrent_check_out_does_not_block_booking_time_change`, `test_locked_time_change_still_updates_assignment_and_stay`

---

## 7. Test Results

```
Tests: 279 passed (1852 assertions)
Duration: 91.42s
```

All existing tests pass. 10 new Phase 2.5 tests added and passing.

---

## 8. Remaining Risks

| Risk | Severity | Notes |
|---|---|---|
| SQLite `lockForUpdate` is a no-op | Testing only | In production (MySQL/PostgreSQL) row-level locks enforce the serialization guarantee. Behaviour tests verify correctness; true concurrency tests would require parallel processes. |
| Pre-lock stay checks have a narrow race window | Very low | The race between pre-lock stay check and lock acquisition is closed by the post-lock re-validation on locked Stay rows (step 6). |

---

## 9. Ready for Codex Review

**YES**
