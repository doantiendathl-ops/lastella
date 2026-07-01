# Room Sync & QA Seeder Update Report

**Date:** 2026-07-01  
**Branch:** phase-3  
**Status:** Complete — awaiting review before commit

---

## 1. Files Modified

| File | Change |
|---|---|
| `database/seeders/RoomSeeder.php` | Fixed PHP integer key coercion bug in `notes` condition |
| `database/seeders/LastellaQaSeederV2.php` | 4 targeted fixes (see §6) |

No business logic files were modified.

---

## 2. Floors Synchronized

| Code | Name | Status |
|---|---|---|
| B1 | Basement 1 | ✅ Correct (7 rooms) |
| 2 | Floor 2 | ✅ Correct (5 rooms) |
| 3–7 | Floors 3–7 | ✅ Correct (9 rooms each) |
| 8 | Floor 8 | ✅ Correct (2 rooms) |

FloorSeeder unchanged — all 11 floors were already correct.

---

## 3. Rooms Synchronized

- **Total:** 59 rooms ✅
- **All room numbers, floors, types, bed configs match Excel ("So do phong.xlsx")**

| Floor | Rooms |
|---|---|
| B1 | 101–107 |
| 2 | 201–205 |
| 3 | 301–309 |
| 4 | 401–409 |
| 5 | 501–509 |
| 6 | 601–609 |
| 7 | 701–709 |
| 8 | 801–802 |

---

## 4. Room Type Summary

| Type | Count | Rooms |
|---|---|---|
| TWIN | 44 | 101,103–105 / 201–205 / x01,x03–x05,x07–x09 (floors 3–7) |
| DOUBLE | 5 | x02 (floors 3–7) |
| TRIP | 3 | 102, 801, 802 |
| FAMILY | 2 | 106, 107 |
| TRIP_FAMILY | 5 | x06 (floors 3–7) |

**Bed configurations (all match Excel):**

| Type | Bed Config |
|---|---|
| TWIN | 2 × 1.2m |
| DOUBLE | 1 × 1.8m |
| TRIP | 3 × 1.2m |
| FAMILY | 4 × 1.2m |
| TRIP_FAMILY | 1 × 1.5m + 1 × 1.2m |

---

## 5. Room 101 Status Confirmation

| Field | Value |
|---|---|
| room_number | 101 |
| floor | B1 |
| type | TWIN |
| status | OUT_OF_ORDER ✅ |
| notes | Under maintenance ✅ |
| bed_config | Twin — 2 × 1.2m ✅ |

Room 101 is excluded from availability resolution, cannot be assigned, cannot be checked in, and displays as "bảo trì" (maintenance) on the Room Board.

Room 802: `VACANT_CLEAN` ✅ (no longer forced OutOfOrder by QA seeder).

---

## 6. LastellaQaSeederV2 Update Confirmation

Four targeted changes were made:

### 6a — `cleanPreviousQaData()` scope fix
**Was:** `Room::where('status', RoomStatus::OutOfOrder)->update([...VacantClean...])` — reset ALL OutOfOrder rooms globally  
**Now:** `Room::where('room_number', '101')->update([...VacantClean...])` — resets only the QA-managed maintenance room  
**Why:** Prevents accidental reset of any other rooms genuinely marked OutOfOrder by operations.

### 6b — `markOneRoomOutOfOrder()` room change
**Was:** targets room `'802'`  
**Now:** targets room `'101'`  
**Why:** Room 101 is the maintenance room per Excel. Room 802 is an operational Trip room.

### 6c — `seedGroupE()` payment order fix (ADR-40)
**Was:** create payment AFTER `checkOutAllAssignments()` → crashed with `OutstandingBalanceException`  
**Now:** create payment BEFORE `checkOutAllAssignments()` — satisfies ADR-40  
**Why:** Phase 3.1B1 (ADR-40) blocks checkout when `balance_due > 0`. The seeder was written before this rule was enforced.

### 6d — `seedGroupB()` timing fix
**Was:** 3 specs had `past=0` → check-in time = "today at 14:00" → fails before 14:00 server time  
**Now:** all `past=0` changed to `past=1` (yesterday at 14:00) → always in the past  
**Why:** `StayService::checkIn()` throws `ValidationException` when `now() < planned_checkin_at`. The seeder must be runnable at any time of day.

QA coverage unchanged: all 10 scenarios still present, all 50 bookings created, seeder is idempotent.

---

## 7. Seeder Commands Run

```bash
php artisan db:seed                           # Full base seeder (all rooms + config)
php artisan db:seed --class=LastellaQaSeederV2  # QA data (50 bookings)
php artisan db:seed --class=LastellaQaSeederV2  # Second run — idempotency confirmed
```

---

## 8. Verification Counts

| Table | Count |
|---|---|
| users | 2 |
| floors | 11 |
| room_types | 5 |
| rooms | 59 ✅ |
| bookings | 50 ✅ |
| stays | 151 |
| room_assignments | 151 |
| folios | 50 |
| folio_entries | 18 |
| booking_payments | 3 |

Room 101: `OUT_OF_ORDER` ✅  
Room 802: `VACANT_CLEAN` ✅  
QA bookings: 50 ✅  
Idempotency: ✅ (same counts on second QA seeder run)

---

## 9. Regression Risk Assessment

| Area | Risk | Rationale |
|---|---|---|
| Room availability | None | `isRoomUnavailable()` unchanged; room 101 excluded via existing `OutOfOrder` logic |
| Room Board display | None | Vue availability resolver unchanged; room 101 shows as "bảo trì" |
| Booking / checkout | None | No business logic files modified |
| Stay / folio | None | No business logic files modified |
| Payment | None | No business logic files modified |
| RoomSeeder idempotency | None | `updateOrCreate` on `room_number` preserves existing IDs |
| QA seeder idempotency | None | Cleanup deletes all QA-coded bookings before re-creating |
| Room 802 behaviour | None | Now correctly `VACANT_CLEAN`; was incorrectly set OutOfOrder by old QA seeder |

**Commits pending:** None. Do NOT commit or push until review is complete.
