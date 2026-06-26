# Booking List Action Column Reposition Report

**Date:** 2026-06-16
**Phase:** 2.x Operational UX Enhancement

---

## 1. Objective

Move the "Thao tác" (action) column from the far-right of the booking list table to the far-left, eliminating the need for horizontal scrolling to access daily-use actions. Implemented as a sticky column so it remains visible during horizontal scrolling.

---

## 2. Files Reviewed

| File | Purpose |
|------|---------|
| `resources/js/Pages/Admin/Bookings/Index.vue` | Booking list table template |
| `tests/Feature/BookingManagementUiTest.php` | Existing booking list tests |
| `app/Http/Controllers/Admin/Booking/BookingController.php` | `index()` — confirmed `can.*` props unchanged |

---

## 3. Files Changed (2)

| File | Change |
|------|--------|
| `resources/js/Pages/Admin/Bookings/Index.vue` | Moved action `<th>` and `<td>` to first position; added sticky classes; added `group` to `<tr>` for hover passthrough |
| `tests/Feature/BookingManagementUiTest.php` | Added `test_booking_list_action_fields_are_present_in_each_row` |

---

## 4. UI Changes

### Previous layout

```text
Mã | Khách | Điện thoại | Loại | Nhận phòng | Trả phòng | ... | Màu | Ngày tạo | Thao tác
```

Action column at far right → requires horizontal scrolling to reach.

### New layout

```text
Thao tác | Mã | Khách | Điện thoại | Loại | Nhận phòng | Trả phòng | ... | Màu | Ngày tạo
```

Action column at far left → immediately visible on page load.

### Sticky implementation: **Yes**

The action column is implemented as a sticky left column:

**`<th>` (header cell):**
```html
<th class="sticky left-0 z-10 bg-gray-50 px-4 py-3 border-r border-gray-200">Thao tác</th>
```

**`<td>` (body cells):**
```html
<td class="sticky left-0 z-10 whitespace-nowrap border-r border-gray-200 bg-white px-4 py-3 group-hover:bg-gray-50">
```

**`<tr>` receives `group` class for hover propagation:**
```html
<tr ... class="group hover:bg-gray-50">
```

- `sticky left-0` — pins the column to the left edge of the scrollable container
- `z-10` — ensures the cell renders above other cells during scroll
- `bg-white` / `bg-gray-50` — opaque backgrounds prevent content bleed-through
- `group-hover:bg-gray-50` — maintains the row hover effect on the sticky cell
- `border-r border-gray-200` — visual separator between sticky and scrollable columns

The parent container `<div class="overflow-x-auto">` was already present, making the sticky implementation work immediately.

---

## 5. Authorization Impact

**None.** All permission checks are unchanged:

- `can.updateBooking` — edit button visibility
- `can.cancelBooking` — cancel button visibility
- `can.assignRoom` — room assignment button visibility
- `can.addPayment` — payment button visibility
- `booking.can_edit`, `booking.can_cancel`, `booking.can_restore` — per-row action state

The column move is purely structural (HTML element reordering). No backend controller or policy code was modified.

---

## 6. Tests Updated

### New test added

`test_booking_list_action_fields_are_present_in_each_row` — verifies that:
- Each booking row in the list response includes `can_edit`, `can_cancel`, `edit_disabled_reason`, `cancel_confirmation`
- The `can` prop includes `updateBooking`, `cancelBooking`, `assignRoom`, `addPayment`

### Existing tests that continue to cover this feature

| Test | Coverage |
|------|---------|
| `test_admin_can_view_booking_list` | List renders (200 OK) |
| `test_booking_list_date_filter_returns_bookings_that_overlap_selected_range` | Data integrity |
| `test_admin_closed_booking_exposes_enabled_edit_state` | Edit action state (`can_edit`) |
| `test_booking_ui_exposes_cancel_confirmation_data` | Cancel action state (`can_cancel`) |
| Cancel / restore tests | Action endpoints work |

---

## 7. Test Results

```
Tests: 132 passed (723 assertions)
Duration: ~39s
```

All 132 tests pass, 0 failures.

---

## 8. Risks / Notes

- **Column order test**: A pure frontend assertion (first `<th>` = "Thao tác") cannot be verified via PHPUnit/Inertia tests. This is verified visually. The backend tests confirm data integrity and permission propagation.
- **Sticky hover**: `group-hover:bg-gray-50` on the sticky `<td>` mirrors the `hover:bg-gray-50` on the `<tr>`. Without this, the sticky cell would stay white while the rest of the row turns gray.
- **z-index**: `z-10` is sufficient for horizontal stickiness. No conflicting stacked elements exist in the current page layout.
- **Column count**: Still 13 columns total. The `colspan="13"` on the empty-state row is unchanged.

---

## 9. Context For Future Sessions

- The action column is sticky-left in `Index.vue` using Tailwind `sticky left-0 z-10`.
- Row hover on sticky cells requires `group` on `<tr>` + `group-hover:bg-gray-50` on the sticky `<td>`.
- No backend changes were needed for this feature.
- All existing booking list and action tests still pass.
