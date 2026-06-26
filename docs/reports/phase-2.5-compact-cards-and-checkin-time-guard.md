# Phase 2.5 — Compact Cards and Check-in Time Guard

## 1. Objective

Three refinements: compact Requirement Summary cards, compact Room Type
Availability cards, and backend+frontend check-in time guard.

## 2. Files Changed

| File | Change |
|------|--------|
| `resources/js/Pages/Admin/Bookings/Show.vue` | Compact card layouts, check-in time guard UI |
| `app/Services/StayService.php` | Check-in time guard in `checkIn()` |
| `app/Http/Controllers/Admin/Booking/BookingController.php` | `can_check_in`, `checkin_too_early`, `planned_checkin_label` flags |
| `tests/Feature/BookingManagementUiTest.php` | 4 new tests, time travel in setUp |
| `tests/Feature/BookingEngineFoundationTest.php` | Time travel in setUp |
| `tests/Feature/DashboardTest.php` | Time travel fix for check-in guard |

**6 files changed.**

## 3. Requirement Summary UI Changes

- Grid: `lg:grid-cols-4` → `lg:grid-cols-5` (fits 5 room types in one row)
- Padding: `p-3` → `px-3 py-2`
- Font sizes: room type `text-sm` → `text-xs`, badge `text-[10px]` → `text-[9px]`
- Large number: `text-3xl` → `text-2xl`
- Detail rows replaced with single line: `Phân X · Chọn Y`
- Status badges kept: 🔴 Thiếu / 🟢 Đủ / 🟠 Thừa

## 4. Room Type Availability UI Changes

- Removed wrapper border/padding container
- Grid: `md:grid-cols-3` → `lg:grid-cols-5` (matches requirement cards)
- Same compact card style: `px-3 py-2`
- Large number: `Remaining / Total` in `text-2xl font-bold`
- Detail: `trống · Giữ X` in single line
- Color-coded borders/badges matching availability status

## 5. Check-in Time Guard — Backend

`StayService::checkIn()` now validates:
```
if now() < planned_checkin_at → reject with message
```
Message: "Chưa đến thời gian nhận phòng dự kiến. Thời gian nhận phòng dự kiến: DD/MM/YYYY HH:mm."

## 6. Check-in Time Guard — Frontend

`BookingController` payload changes:
- `can_check_in`: now also checks `now() >= planned_checkin_at`
- `checkin_too_early`: true when assignment is Assigned but time hasn't arrived
- `planned_checkin_label`: formatted date for tooltip

Stay table UI:
- Check-in button hidden when `checkin_too_early`
- Shows amber text: "Chưa đến giờ nhận phòng" with planned time in title attribute

## 7. Tests Added / Updated

| Test | Type |
|------|------|
| `test_check_in_before_planned_time_is_rejected` | New — backend rejects early check-in |
| `test_check_in_at_planned_time_is_allowed` | New — check-in at exact planned time succeeds |
| `test_check_in_after_planned_time_is_allowed` | New — check-in after planned time succeeds |
| `test_stay_payload_reflects_checkin_too_early_flag` | New — payload contains correct flags |
| `BookingManagementUiTest::setUp` | Updated — time travel to 2026-07-01 14:00 |
| `BookingEngineFoundationTest::setUp` | Updated — time travel to 2026-07-01 14:00 |
| `DashboardTest::createReservedStay` | Updated — time from 10:00 to 14:00 |
| `DashboardTest::test_dashboard_occupied_rooms_*` | Updated — explicit time control |

## 8. Test Results

240 passed, 0 failures (1679 assertions)

## 9. Known Risks

- No override permission for early check-in (as specified — can be added later if needed)
- Time travel in test setUp means all tests run at 2026-07-01 14:00 — tests that need different times must call `travelTo` explicitly

## 10. Ready for Manual QA

**YES**
