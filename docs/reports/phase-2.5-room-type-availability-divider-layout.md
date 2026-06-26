# Phase 2.5 — Room Type Availability Divider Layout

## Summary

Replaced the fixed two-column `flex-1` + `grid grid-cols-2/3` layout in the "Tình trạng loại phòng" section with a single flex-wrap row where the divider flows inline between the required and outside-requirement card groups.

## Changes

**File:** `resources/js/Pages/Admin/Bookings/Show.vue` (lines ~1227–1273)

### Before
- Outer: `flex flex-col gap-4 lg:flex-row` with each group as `flex-1`
- Cards: `grid grid-cols-2 gap-2 sm:grid-cols-3` inside each group
- Divider: `border-l` on the outside group's wrapper (fixed 50/50 split)

### After
- Outer: `flex flex-wrap` (default `align-items: stretch`)
- Cards: `flex flex-wrap gap-2` with `w-28` per card — all cards flow naturally on one row
- Divider: standalone `hidden lg:block w-px bg-gray-200` flex item between the two groups — moves dynamically based on how many required cards exist
- Mobile: divider hidden; outside group gets `w-full border-t` to stack cleanly below required group

## QA Checklist

| Scenario | Expected | Notes |
|---|---|---|
| 3 required + 2 outside | All 5 on one row, divider after card 3 | Desktop |
| 1 required + 4 outside | Divider after first card, all 5 on one row | Desktop |
| 5 required + 0 outside | No divider rendered | `v-if` guards both sides |
| 0 required + 5 outside | No divider before first card | `v-if` guards both sides |
| Mobile any mix | Groups stack; horizontal separator; no overlap | `w-full` forces new row |
