# Phase 2.4 QA Seed Data Report

## 1. Objective

Create realistic QA seed data covering all current PMS operational states to enable manual testing before committing Phase 2.4.

## 2. Seeder File Created

`database/seeders/LastellaQaSeeder.php`

Not included in `DatabaseSeeder`. Must be run manually.

## 3. Total Bookings Created

**36 QA bookings** with distinct `QA`-prefixed customer names.

## 4. Distribution

### Requirement-only (9 bookings)

| # | Name | Type | Room Reqs | Status |
|---|------|------|-----------|--------|
| 1 | QA Draft 01 | Overnight | None | DRAFT |
| 2 | QA Pending 01 | Overnight | 1×TWIN | PENDING_ASSIGNMENT |
| 3 | QA Pending 02 | Overnight | 2×DOUBLE | PENDING_ASSIGNMENT |
| 4 | QA Pending 03 | Overnight | 1×TRIP + 1×FAMILY | PENDING_ASSIGNMENT |
| 5 | QA Pending 04 | Overnight | 3×TWIN (tour) | PENDING_ASSIGNMENT |
| 6 | QA Pending 05 | Overnight | 1×TWIN (far future) | PENDING_ASSIGNMENT |
| 7 | QA Pending 06 | Overnight | 1×DOUBLE (weekend) | PENDING_ASSIGNMENT |
| 8 | QA DayUse 01 | Day-use | 1×TRIP_FAMILY | PENDING_ASSIGNMENT |
| 9 | QA Pending 07 | Overnight | 2×FAMILY + 1×TRIP_FAMILY (company) | PENDING_ASSIGNMENT |

### Assigned (12 bookings)

| # | Name | Scenario | Status |
|---|------|----------|--------|
| 10 | QA Assigned 01 | 1×TWIN correctly assigned | FULLY_ASSIGNED |
| 11 | QA Assigned 02 | 2×DOUBLE correctly assigned | FULLY_ASSIGNED |
| 12 | QA Mismatch 01 | DOUBLE room for TWIN requirement (mismatch warning) | FULLY_ASSIGNED |
| 13 | QA Partial 01 | 2×TWIN required, 1 assigned | PARTIALLY_ASSIGNED |
| 14 | QA Released History 01 | Assigned then released | PENDING_ASSIGNMENT |
| 15 | QA Future Reserved 01 | FAMILY room blocks future range | FULLY_ASSIGNED |
| 16 | QA CheckedIn 01 | Currently checked in, blocks all | CHECKED_IN |
| 17 | QA CheckedIn 02 | 2×TRIP rooms checked in (group) | CHECKED_IN |
| 18 | QA Overstay 01 | Planned checkout past, still checked in | CHECKED_IN |
| 19 | QA Assigned Deposit 01 | Assigned + deposit payment | FULLY_ASSIGNED |
| 20 | QA WalkIn 01 | Same-day walk-in | FULLY_ASSIGNED |
| 21 | QA Excess 01 | More rooms assigned than required | FULLY_ASSIGNED |

### Completed / Closed (9 bookings)

| # | Name | Scenario | Status |
|---|------|----------|--------|
| 22 | QA CheckedOut 01 | Basic checkout | PARTIALLY_CHECKED_OUT |
| 23 | QA CheckedOut 02 | Checkout + full payment | PARTIALLY_CHECKED_OUT |
| 24 | QA Cancelled 01 | Had assignment, cancelled (released) | CANCELLED |
| 25 | QA Cancelled 02 | Cancelled before assignment | CANCELLED |
| 26 | QA Cancelled 03 | Cancelled with deposit + refund | CANCELLED |
| 27 | QA NoShow 01 | No-show | NO_SHOW |
| 28 | QA Completed 01 | 2×TWIN multi-room checkout + payment | PARTIALLY_CHECKED_OUT |
| 29 | QA Completed 02 | Single room checkout | PARTIALLY_CHECKED_OUT |
| 30 | QA Archive 01 | 30-day-old checkout | PARTIALLY_CHECKED_OUT |

### Edge Cases (6 bookings)

| # | Name | Scenario | Edge Case |
|---|------|----------|-----------|
| 31 | QA Boundary A | Checkout at 12:00 on shared room | #6 boundary |
| 32 | QA Boundary B | Checkin at 12:00 on same room | #6 boundary |
| 33 | QA MultiBook A | First of two sequential bookings on same room | #8 multi-booking |
| 34 | QA MultiBook B | Second sequential booking on same room | #8 multi-booking |
| 35 | QA Overlap X | First overlapping assignment (via service) | #7 overlap |
| 36 | QA Overlap Y | Second overlapping assignment (forced insert) | #7 overlap |

Plus: 1 FAMILY room set to `OUT_OF_ORDER` status.

## 5. Edge Cases Created

| # | Case | How Created | Verification |
|---|------|-------------|-------------|
| 1 | Future reserved | QA Future Reserved 01 — assigned, not checked in | Blocks in Room Availability Checker for planned range |
| 2 | Checked-in current | QA CheckedIn 01 — actual checkin set, no checkout | Blocks all future availability |
| 3 | Overstay | QA Overstay 01 — planned checkout past, no actual checkout | Shows "Quá hạn lưu trú" |
| 4 | Checked-out | QA CheckedOut 01/02 — actual checkout set | Does not block future |
| 5 | Released | QA Released History 01 / QA Cancelled 01 | In history, does not block |
| 6 | Boundary | QA Boundary A+B — same room, touching times | Should NOT conflict |
| 7 | Overlap | QA Overlap X+Y — forced overlapping on same room | Shows "Xung đột" in checker |
| 8 | Multi-booking | QA MultiBook A+B — same room, sequential | Shows "Nhiều booking" |

## 6. How to Run the Seeder

```bash
# Run QA seeder (idempotent — cleans previous QA data first)
php artisan db:seed --class=LastellaQaSeeder

# Or after a full database reset
php artisan migrate:fresh --seed
php artisan db:seed --class=LastellaQaSeeder
```

The seeder:
- Authenticates as `admin@lastella.local`
- Cleans all previous `QA *` bookings before recreating
- Dynamically finds available rooms (resilient to existing data)
- Uses service layer for realistic data (folios, status updates, etc.)
- Only the overlap edge case (#7) bypasses the service to force inconsistent data

## 7. Manual Test Checklist

### Booking List (`/admin/bookings`)
- [ ] All QA bookings visible with correct status badges
- [ ] Date filter returns overlapping bookings correctly
- [ ] Status filter works (CANCELLED, CHECKED_IN, etc.)
- [ ] Edit/Cancel actions show correctly per status
- [ ] Cancelled bookings show disabled edit for non-admin

### Booking Detail → Thông tin Booking
- [ ] QA Pending 03: Mixed room types in requirements table
- [ ] QA Mismatch 01: Room assignment mismatch warning visible
- [ ] QA Partial 01: Partial assignment warning visible
- [ ] QA Excess 01: Excess assignment warning visible
- [ ] QA Assigned Deposit 01: Payment summary shows deposit
- [ ] QA Cancelled 03: Shows deposit + refund in payment history

### Booking Detail → Sơ đồ phòng
- [ ] QA Assigned 01: Room shown as "Đã phân booking này"
- [ ] QA CheckedIn 01: Room locked (cannot release)
- [ ] QA Overstay 01: Room shows conflict for other bookings
- [ ] QA Released History 01: Room available, history shows released
- [ ] QA Future Reserved 01: Room blocked for overlapping bookings only
- [ ] Room type summary counts correct (total / occupied / remaining)

### Room Availability Checker (`/admin/room-availability`)
- [ ] QA CheckedIn 01/02 rooms: Show "Đang ở" regardless of date range queried
- [ ] QA Overstay 01 room: Shows "Quá hạn lưu trú"
- [ ] QA Future Reserved 01 room: Shows "Đã giữ phòng" when querying overlapping range
- [ ] QA CheckedOut 01/02 rooms: Show "Trống" for any range
- [ ] QA Cancelled 01 released room: Shows "Trống"
- [ ] Out-of-order room: Shows "Không khả dụng"
- [ ] QA Boundary A+B room: Both bookings visible, NO conflict
- [ ] QA MultiBook A+B room: Shows "Nhiều booking" for full range query
- [ ] QA Overlap X+Y room: Shows "Xung đột" for overlapping range query
- [ ] Room type summary badge (Còn nhiều / Sắp hết / Hết phòng)

### Overstay Display
- [ ] QA Overstay 01 room shows overstay status in both Room Board and Availability Checker

### Released Room Behavior
- [ ] QA Released History 01: Room is available, not blocked
- [ ] QA Cancelled 01: Released assignment in history, room available

### Completed Bookings Not Blocking
- [ ] QA CheckedOut 01/02 rooms available in future date range
- [ ] QA Archive 01 room available

### Cancelled Bookings Not Blocking
- [ ] QA Cancelled 01 released rooms available
- [ ] QA Cancelled 02 (no assignment) has no room impact

## 8. Test Results

```
Tests: 198 passed (1359 assertions)
Duration: 141.48s
```

Zero failures. QA seeder does not affect test suite (tests use RefreshDatabase).

## 9. Git Diff Summary

| File | Action |
|------|--------|
| `database/seeders/LastellaQaSeeder.php` | Created |
| `docs/reports/phase-2.4-qa-seed-data-report.md` | Created |

## 10. Risks / Notes

1. **Idempotent**: The seeder cleans all `QA *`-prefixed bookings before recreating. Safe to run multiple times.
2. **Room availability**: The seeder dynamically finds available rooms. If many rooms are already occupied by non-QA data, some assignments may be skipped with a warning.
3. **Out-of-order room**: One FAMILY room is set to `OUT_OF_ORDER`. The cleaner resets it on re-run.
4. **Intentional overlap** (QA Overlap Y): Created via direct DB insert to bypass conflict prevention. Marked with `QA INTENTIONAL OVERLAP` in the booking note.
5. **Not in DatabaseSeeder**: The QA seeder is never run automatically. Must be invoked explicitly.

## 11. Phase 2.4 Commit Readiness

Phase 2.4 remains ready for commit (pending user approval). The QA seeder is an additional tool for manual verification, not a blocker.
