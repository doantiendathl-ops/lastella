# QA Seed Data V2 Report

## 1. Objective

Generate 50 realistic QA bookings for manual testing and regression testing of:
Booking List, Booking Detail, Room Requirements, Room Assignment, Room Board,
Room Availability Checker, Check-in/out, Overstay, Release Room, Capacity Warning,
Booking Colors, Occupancy Summary, and Historical Bookings.

## 2. Total Bookings Created

**50 bookings** (QA-BK-0001 → QA-BK-0050)

## 3. Booking Distribution

| Group | Codes | Count | Assignment | Status |
|-------|-------|-------|------------|--------|
| A — Reserved | QA-BK-0001 → 0010 | 10 | Fully Assigned | FULLY_ASSIGNED |
| B — Checked In | QA-BK-0011 → 0020 | 10 | Fully Assigned | CHECKED_IN |
| C — Overstay | QA-BK-0021 → 0025 | 5 | Fully Assigned | CHECKED_IN |
| D — Partial | QA-BK-0026 → 0035 | 10 | Partially Assigned | PARTIALLY_ASSIGNED |
| E — History | QA-BK-0036 → 0038 | 3 | Fully Assigned (past) | CHECKED_OUT |
| F — Cancelled | QA-BK-0039 → 0040 | 2 | None (cancelled) | CANCELLED |
| G — Unassigned | QA-BK-0041 → 0048 | 8 | None (requirements only) | PENDING_ASSIGNMENT |
| Conflict | QA-BK-0049 → 0050 | 2 | Force-assigned overlap | PENDING_ASSIGNMENT |

**Status summary:** 20 Assigned, 10 CheckedIn, 5 Overstay, 10 Partial, 3 CheckedOut, 2 Cancelled = 50

## 4. Room Distribution

| Room Type | Available | Checked In (B+C) | Assigned (A+D) | Checked Out (E) |
|-----------|-----------|-------------------|-----------------|-----------------|
| DOUBLE | 12 | 9 | variable | variable |
| TWIN | 12 | 10 | variable | variable |
| TRIP | 12 | 12 | variable | variable |
| TRIP_FAMILY | 11 | 9 | variable | variable |
| FAMILY | 11 | 8 | variable | variable |
| **Total** | **58** | **48** | spread across future | past (freed) |

**Out Of Order:** Room 802 (1 room)
**Sellable rooms:** 58

## 5. Guest Distribution

Calculated per booking: `adults ≈ rooms × 2.2`, `under_6 ≈ rooms × 0.3`, `over_6 ≈ rooms × 0.15`

| Booking Size | Room Range | Example Guest Counts |
|-------------|------------|---------------------|
| Small | 3–5 rooms | 7 adults, 1 under 6, 1 over 6 |
| Medium | 6–10 rooms | 13–22 adults, 2–3 under 6, 1–2 over 6 |
| Large | 12–30 rooms | 26–66 adults, 4–9 under 6, 2–5 over 6 |

## 6. Active Room Usage Summary

| Period | Rooms Occupied | Available |
|--------|---------------|-----------|
| Current (checked in + overstay) | 48 | 10 |
| Future (assigned only, per week) | 3–8 per booking window | reusable across windows |
| Past (checked out) | 0 (freed) | 58 |

**Capacity constraint respected:** Max 48 of 58 rooms occupied concurrently.

## 7. Special QA Scenarios

| # | Scenario | Booking(s) | Details |
|---|----------|------------|---------|
| 1 | Future Reserved | QA-BK-0001 → 0010 | Assigned, not checked in |
| 2 | Checked In | QA-BK-0011 → 0020 | Active stays |
| 3 | Overstay | QA-BK-0021 → 0025 | Checkout passed, still checked in |
| 4 | Checked Out | QA-BK-0036 → 0038 | Historical, fully paid |
| 5 | Released Assignment | QA-BK-0001 | Extra room assigned then released |
| 6 | Boundary Case | QA-BK-0008 / 0009 | Same room, checkout 12:00 / checkin 12:00 |
| 7 | Intentional Conflict | QA-BK-0049 / 0050 | Same room, overlapping dates |
| 8 | Multi-Book Same Room | QA-BK-0001 / 0002 | Same room, non-overlapping |
| 9 | Large Group | QA-BK-0047 | 30 rooms required |
| 10 | Room Type Mismatch | QA-BK-0026 | DOUBLE required, TWIN assigned |

## 8. Out Of Order Room

Room **802** set to `OUT_OF_ORDER`. Excluded from sellable inventory (58 → 57+1).

## 9. Capacity Validation

- Total rooms: 59
- OOO: 1 (room 802)
- Sellable: 58
- Max concurrent occupation: 48 rooms (48/58 = 82.8%)
- Remaining for manual assignment testing: 10 rooms
- **No unintentional conflicts** (only QA-BK-0049/0050 conflict is intentional)

## 10. Test Results

```
Tests: 224 passed (1542 assertions)
Duration: 77.59s
```

Seeder runs cleanly with zero room conflict warnings.

## 11. Seeder Path

`database/seeders/LastellaQaSeederV2.php`

## 12. Manual QA Checklist

- [ ] Booking List loads and shows all 50 QA bookings
- [ ] Booking Detail shows correct status per group
- [ ] Room Board shows occupied/available rooms correctly
- [ ] Availability Checker detects QA-BK-0049/0050 conflict
- [ ] Capacity Warning appears for large unassigned bookings (QA-BK-0047: 30 rooms)
- [ ] Overstay bookings (QA-BK-0021 → 0025) appear with overstay indicator
- [ ] Released assignment on QA-BK-0001 visible in history
- [ ] Boundary case (QA-BK-0008/0009) does not show false conflict
- [ ] Room type mismatch warning on QA-BK-0026
- [ ] Booking colors display correctly (16 unique colors)
- [ ] Checked-out bookings (QA-BK-0036 → 0038) show as historical
- [ ] Cancelled bookings (QA-BK-0039/0040) show cancellation reason

## 13. Risks / Notes

- Seeder uses `Auth::login($admin)` — requires admin user in database
- Seed order matters: checked-in groups seeded LAST (CHECKED_IN status blocks all future dates)
- Conflict scenario (QA-BK-0049/0050) uses `forceAssignment` to bypass conflict validation
- Room type distribution for Groups B+C uses explicit per-booking type specs to avoid exhausting DOUBLE/TWIN
- Seeder cleans previous QA data on re-run (idempotent)

## 14. Ready For Manual QA

**YES**
