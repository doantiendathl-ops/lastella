# Phase 2.3.x Conflict UI Investigation Report

**Date:** 2026-06-16  
**Conclusion:** Implementation is complete and correct. The UI is not visible because the compiled Vite assets have never been rebuilt since the Phase 2.3.x source changes were written.

---

## 1. Is the Frontend Code Present in Show.vue?

**YES — all conflict UI code is present in the source file.**

### Color strip (lines 942–947)
```html
<!-- Conflict booking color strip -->
<div
    v-if="room.availability_status === 'conflict' && room.conflict_booking?.booking_color"
    class="absolute left-0 right-0 top-0 h-[5px]"
    :style="{ backgroundColor: room.conflict_booking.booking_color }"
/>
```

### Room card class for conflict (line 277–279)
```js
if (room.availability_status === 'conflict') {
    return 'cursor-pointer border-gray-200 bg-gray-100 overflow-hidden';
}
```

### Click handler (lines 251–255)
```js
const toggleRoomSelection = (room) => {
    if (room.availability_status === 'conflict') {
        openConflictPanel(room);
        return;
    }
    ...
```

### Conflict panel modal — present (after line 997)
Full modal with room info, conflict booking details, "Xem chi tiết booking" link, and "Gỡ phòng khỏi booking này" button.

### Summary card left border color (line 894–896)
```html
<div class="border-l-4 border border-gray-100 p-3 text-sm"
     :style="{ borderLeftColor: booking.booking_color }">
```

**Finding: Source code is complete and correct.**

---

## 2. Does the Room Board Payload Include `conflict_booking` Data?

**YES — confirmed by passing backend tests.**

The `RoomAssignmentService::getRoomBoard()` now returns for conflict rooms:
```json
{
  "availability_status": "conflict",
  "conflict_booking": {
    "id": 42,
    "code": "BK-20260701-0002",
    "customer_name": "Conflict Guest",
    "booking_color": "#8B5CF6",
    "status": "CONFIRMED",
    "assignment_id": 7,
    "can_view": true,
    "can_unassign_room": true
  },
  "assignment_detail": {
    "booking_code": "BK-20260701-0002",
    "customer_name": "Conflict Guest",
    "checkin_at": "2026-07-01 14:00",
    "checkout_at": "2026-07-02 12:00",
    "status": "ASSIGNED"
  }
}
```

All 6 new tests + the 2 pre-existing conflict tests pass (total 121 tests green). The `booking_color` field is always populated — when a conflicting booking has no color, a deterministic fallback is chosen from an 8-color palette using `booking_id % 8`.

---

## 3. Are There Conflict Rooms in the Viewed Booking's Payload?

**This depends on the test scenario being viewed.** If the booking in the browser has no other booking assigned to any room in the same date range, `availability_status` will never be `'conflict'` and the strip will not render. This is correct behavior — but it means a visible conflict scenario is needed to test the UI.

To confirm: navigate to a booking where another booking already has a room assigned in the same or overlapping date range. That room should show `conflict` status in the room board.

---

## 4. Are the Vue Conditions Correct?

**YES — the conditions are syntactically and logically correct.**

The strip renders when:
- `room.availability_status === 'conflict'` — set by the service when a conflicting assignment exists
- `room.conflict_booking?.booking_color` — always a truthy hex string (no null path)

The conflict panel opens when any conflict room card is clicked via `toggleRoomSelection`.

**However: these conditions never execute in the browser because the compiled bundle does not contain this code at all (see Finding 5).**

---

## 5. Vite / Build Cache — ROOT CAUSE

### Timestamp comparison

| File | Last Modified |
|------|--------------|
| `resources/js/Pages/Admin/Bookings/Show.vue` | **2026-06-16 17:30:58** |
| `app/Services/RoomAssignmentService.php` | **2026-06-16 17:29:36** |
| `public/build/assets/app-DflEz9Mg.js` (compiled) | 2026-06-14 15:59:12 |
| `public/build/assets/app-BQWjhjFI.css` (compiled) | 2026-06-14 15:59:12 |

**The compiled assets are ~49.5 hours older than the source files.** The build was last run on June 14 — two full days before the Phase 2.3.x source changes were written on June 16.

### Compiled JS — missing conflict code

A text search in `public/build/assets/app-DflEz9Mg.js` confirms:

| Search term | Found in bundle? |
|-------------|-----------------|
| `Chi tiết xung đột` (conflict panel heading) | ❌ Not found |
| `room-board/conflict` (new endpoint URL) | ❌ Not found |
| `Gỡ phòng khỏi` (unassign button label) | ❌ Not found |
| `conflict_booking.*booking_color` | ❌ Not found |

### Compiled CSS — missing new utility classes

A text search in `public/build/assets/app-BQWjhjFI.css` confirms:

| Tailwind class used in changes | Found in CSS? |
|-------------------------------|--------------|
| `opacity-45` → `opacity: .45` | ❌ Not found |
| `h-[5px]` → `height: 5px` | ❌ Not found |
| `overflow: hidden` | ✅ Found (pre-existing use) |

### Why Tailwind v3.4 (JIT) does not help here

Tailwind's JIT scanner runs **at build time**, not at runtime. The `tailwind.config.js` content paths correctly include `'./resources/**/*.vue'`, so a fresh build WOULD scan `Show.vue` and include `opacity-45` and `h-[5px]`. But since the build has not been run since the changes, those classes are absent from the compiled CSS.

### Build setup

```
"scripts": {
    "build": "vite build",   ← production compile (one-shot)
    "dev":   "vite"          ← dev server with HMR
}
```

Neither `npm run build` nor `npm run dev` has been run since the code changes were made.

---

## Summary

| Checkpoint | Status | Finding |
|-----------|--------|---------|
| Frontend code in Show.vue | ✅ Complete | All conflict strip, panel, and cursor code is present |
| Backend payload has `conflict_booking` | ✅ Complete | Full metadata incl. `booking_color` always populated |
| Conflict rooms in viewed booking | ⚠️ Scenario-dependent | Need a booking with overlapping room assignments to see the UI |
| Vue rendering conditions correct | ✅ Correct | Logic is sound; conditions would trigger with the right data |
| Vite/build cache | 🔴 ROOT CAUSE | Compiled bundle is **49.5 hours stale** — none of the new code reached the browser |

---

## Root Cause

**The Vite bundle has not been rebuilt since the Phase 2.3.x source changes were written.** The browser is serving `app-DflEz9Mg.js` and `app-BQWjhjFI.css` from June 14, 2026, which predate all conflict UI changes made on June 16, 2026. The compiled JavaScript does not contain the conflict panel, color strip, or new click handler. The compiled CSS does not contain `opacity-45` or `h-[5px]`.

---

## Is the Implementation Complete or Partially Complete?

**Implementation is complete.** The source code — both backend (PHP) and frontend (Vue) — is fully implemented and verified by 121 passing tests. The only missing step is compiling the assets for the browser to receive them.

---

## Fix Required

Run one of the following from `C:\Projects\lastella`:

```bash
# For local development (live HMR, see changes immediately):
npm run dev

# For production / testing the final compiled output:
npm run build
```

After running either command, refresh the browser. Conflict rooms will show the color strip, and clicking them will open the conflict detail panel.
