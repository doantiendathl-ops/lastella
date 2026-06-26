# Phase 2.5 – Room Availability Floor Row Layout

## Summary

Changed the room map in `/admin/room-availability` so every floor displays all its rooms in a single, non-wrapping horizontal row with overflow-x scrolling on narrow screens.

## Problem

The floor room grid used a responsive CSS grid (`grid-cols-3 … xl:grid-cols-8`). With 9 rooms per floor and the grid capped at 8 columns at xl, some floors wrapped rooms onto a second line.

## Change

**File:** `resources/js/Pages/Admin/RoomAvailability/Index.vue` (line 174)

| Before | After |
|--------|-------|
| `class="grid grid-cols-3 gap-2 p-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8"` | `class="flex flex-nowrap gap-2 overflow-x-auto p-3"` |
| Room card: `class="relative border p-2 text-left transition"` | Room card: `class="relative shrink-0 min-w-[80px] border p-2 text-left transition"` |

- `flex flex-nowrap` — all cards stay on one row regardless of count.
- `overflow-x-auto` — horizontal scroll appears inside the floor row when rooms exceed viewport width.
- `shrink-0 min-w-[80px]` — cards hold their minimum size and never compress below 80px.

## Files Changed

1. `resources/js/Pages/Admin/RoomAvailability/Index.vue`

## Tests

No automated tests cover this UI layout. No tests were run.

## Ready for Manual QA

YES
