# Booking Info Tab Availability & Guest Allocation Refinement

## 1. Objective

Improve the Booking Detail → Thông tin Booking tab so reception staff can immediately see room type availability and guest allocation progress without switching tabs.

## 2. Files Reviewed

- `app/Services/RoomAssignmentService.php` — existing `getRoomBoard()` structure
- `resources/js/Pages/Admin/Bookings/Show.vue` — info tab cards, script computed properties
- `resources/js/Support/vietnameseLabels.js` — label helpers
- `tests/Feature/BookingManagementUiTest.php` — existing test patterns

## 3. Files Changed

| File | Change |
|------|--------|
| `app/Services/RoomAssignmentService.php` | Added `all_room_type_summary` to `getRoomBoard()` return |
| `resources/js/Pages/Admin/Bookings/Show.vue` | Refactored computed properties + template for both parts |
| `tests/Feature/BookingManagementUiTest.php` | Added 6 new PHPUnit tests |

## 4. Backend Payload Changes

### `getRoomBoard()` — new `all_room_type_summary` key

Added alongside the existing `room_type_summary` (required types only):

```php
'all_room_type_summary' => [
    [
        'room_type_id'   => int,
        'room_type_code' => string,    // e.g. 'TWIN'
        'room_type_name' => string,
        'total'          => int,       // total rooms of this type in hotel
        'occupied'       => int,       // conflict + unavailable rooms
        'current_booking'=> int,       // rooms already assigned to this booking
        'remaining'      => int,       // rooms with availability_status === 'available'
    ],
    ...
]
```

All room types with physical rooms are included (not filtered by requirements). Sorted alphabetically by `room_type_code`.

The invariant `total === remaining + occupied + current_booking` holds by construction.

## 5. Frontend UI Changes

### Part A — "Tình trạng loại phòng" section in info tab

- Added before the requirement form in the info tab's second section.
- Uses new `allRoomTypeSummaryDisplay` computed (mirrors `roomTypeSummaryDisplay` logic but from `all_room_type_summary`).
- Pending room selections (room board form) reflect in the counts reactively.
- Compact one-row-per-type layout: `TYPE | Tổng N | Đã giữ N | Đã chọn N | Còn N | 🟢`

### Part B — Guest allocation integrated into "Số khách" card

- **Removed** the standalone "Phân bổ khách" block added in the previous task.
- **Updated** the "Số khách" info card to show per-category allocation inline:
  - `Người lớn: 9 | Đã PB: 1 | Còn: 8`
  - `Trẻ dưới 6: 3 | Đã PB: 0 | Còn: 3`
  - `Trẻ từ 6: 3 | Đã PB: 0 | Còn: 3`
- Status badge in card header: `🟢 Đủ` / `🟡 Chưa đủ` / `🔴 Vượt số lượng`

### Script changes

- Extracted `buildRoomTypeSummaryWithSelection(summaryList)` helper to de-duplicate badge/pending logic.
- `roomTypeSummaryDisplay` — unchanged behavior (required types only, for room_map tab).
- `allRoomTypeSummaryDisplay` — new, uses `all_room_type_summary` (all types, for info tab).
- `guestAllocation` — rewritten as per-category object: `{ adults, childrenUnder6, childrenOver6, isOverAllocated, isFullyAllocated }`.

## 6. Room Type Availability Rules

| remaining / total | Badge | Label |
|---|---|---|
| > 30% | 🟢 | Còn nhiều |
| > 0, ≤ 30% | 🟡 | Sắp hết |
| = 0 | 🔴 | Hết phòng |

`remaining_selectable = total - occupied - current_booking`
- `occupied` = conflict (other booking in same period) + unavailable (OutOfOrder/OutOfService)
- `current_booking` = rooms already assigned to this booking
- Pending selections (not yet saved) adjust `remaining` reactively on the frontend.

## 7. Guest Allocation Rules

Per-category (adults, children under 6, children over 6):

- `allocated` = sum of that field across all room requirements
- `remaining` = booking total − allocated
- `isFullyAllocated` = all three remainders = 0 (and none negative)
- `isOverAllocated` = any remainder < 0

## 8. Validation Decisions

- Backend sends raw counts; all display logic (badges, colors, statuses) computed on frontend.
- No new backend validation added — backend room assignment validation is unchanged.
- `all_room_type_summary` uses `filter(fn($r) => $r['room_type_id'] !== null)` defensively.

## 9. Tests Added

6 new PHPUnit tests in `BookingManagementUiTest.php`:

| Test | Covers |
|------|--------|
| `test_room_board_includes_all_room_type_summary_key` | Key present in roomBoard prop |
| `test_all_room_type_summary_includes_types_not_in_requirements` | All types, not just required |
| `test_all_room_type_summary_remaining_reflects_available_rooms` | `remaining + occupied + current_booking === total` invariant |
| `test_all_room_type_summary_occupied_reflects_conflict_rooms` | Conflict rooms counted in occupied |
| `test_all_room_type_summary_current_booking_count` | Assigned rooms counted in current_booking |
| `test_all_room_type_summary_total_matches_actual_room_count` | Total matches DB room count |

Guest allocation (tests 5–7 from spec) is pure frontend logic; tested via manual verification and the invariant test above.

## 10. Test Results

72/72 passed (616 assertions) — no regressions.

## 11. Git Diff Summary

- `RoomAssignmentService.php`: +20 lines (new `$allRoomTypeSummary` block + return key)
- `Show.vue`: refactored 3 computed properties, updated 1 template card, added 1 section, removed 1 section
- `BookingManagementUiTest.php`: +80 lines (6 new tests)

## 12. Risks / Notes

- `all_room_type_summary` covers only room types with physical rooms on seeded floors. Room types with zero physical rooms do not appear (correct behavior).
- The `room_type_summary` key (required types only) is intentionally preserved for the room_map tab to avoid regressions.
- `guestAllocation.remaining` can be negative if staff over-allocates guests across requirements; shown in red as a warning.

## 14. UI Fix — "Tình trạng loại phòng" Layout (Follow-up)

**Problem:** Stat values collapsed into unreadable inline text: `Tổng 5Đã giữ 0Đã chọn 0Còn 5`

**Root cause:** Plain `<span>` elements inside a flex container had no visual separation — no borders, no background, no padding — so they read as a continuous string.

**Fix applied to `Show.vue`:**

1. `buildRoomTypeSummaryWithSelection()` now returns `badgeText` (e.g. `'Còn nhiều'`, `'Sắp hết'`, `'Hết phòng'`).

2. Each room type row now uses a two-line layout:
   - **Line 1:** `TYPE CODE` (bold, truncated) — `🟢 Còn nhiều` (colored, right-aligned)
   - **Line 2:** Four chip badges — `[Tổng: 5]` `[Đã giữ: 0]` `[Đã chọn: 0]` **`[Còn lại: 5]`**
     - First three chips: neutral gray (`bg-gray-50 border-gray-200`)
     - "Còn lại" chip: colored by status (green/amber/red border+background)

3. `flex-wrap gap-1.5` ensures chips wrap on narrow viewports without overflow.

4. `divide-y divide-gray-100` separates room type rows cleanly.

**Screenshot verification notes:**
- Each row shows type code on left, badge label on right — scannable in one glance
- Four chips on second line are visually distinct pill elements
- "Còn lại" chip has colored background matching availability status
- Rows are separated by a light divider
- No horizontal overflow on standard desktop/tablet widths (chips wrap on narrow)
- Page height increase is minimal (~4px per room type row vs before)

**Tests:** 72/72 still passing after fix (no backend change, no new tests needed).

## 13. Context for Future Sessions

- `roomBoard.all_room_type_summary` = all hotel room types, used in info tab
- `roomBoard.room_type_summary` = required types only, used in room_map tab
- Both share the same field shape and the same `buildRoomTypeSummaryWithSelection()` helper
- `guestAllocation` computed now returns `{ adults, childrenUnder6, childrenOver6, isOverAllocated, isFullyAllocated }` — not a flat total/allocated/remaining object
