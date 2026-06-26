# Room Board Tooltip Duplication Fix Report

**Date:** 2026-06-16
**Tests:** 139 passed (778 assertions)
**Status:** Ready

---

## 1. Objective

Remove the duplicate tooltip that appeared when hovering room cards in the Booking Detail → Sơ đồ phòng tab. Ensure only one tooltip (the custom black tooltip) is shown on hover.

---

## 2. Root Cause

Two independent tooltip mechanisms were simultaneously active on every room card `<button>`:

1. **Native browser tooltip** — caused by `:title="roomTooltipText(room)"` on the button element. The browser renders this as a white native popover on hover.
2. **Custom black tooltip** — a `<div>` with class `hidden group-hover:block` inside the button, styled as a dark card with structured fields.

Both triggered on hover, showing overlapping, duplicate information. The `roomTooltipText` function duplicated what the custom tooltip already rendered, but as a flat multi-line text string.

---

## 3. Files Reviewed

| File | Purpose |
|------|---------|
| `resources/js/Pages/Admin/Bookings/Show.vue` | Room board implementation — sole location of room card tooltip logic |

No extracted room card component exists; the room board is inlined in Show.vue.

---

## 4. Files Changed (1)

| File | Change |
|------|--------|
| `resources/js/Pages/Admin/Bookings/Show.vue` | Removed `:title` attribute from room card button; removed unused `roomTooltipText` function |

---

## 5. Frontend Changes

### Removed: `:title` attribute from room card button

```diff
-                                    :title="roomTooltipText(room)"
                                     @click="toggleRoomSelection(room)"
```

### Removed: `roomTooltipText` function (32 lines)

```javascript
// REMOVED — duplicated what the custom CSS tooltip already shows
const roomTooltipText = (room) => { ... };
```

The function built a newline-joined string covering room number, type, status, availability, assignment detail, disabled reason, and requirement mismatch. All of this is already rendered by the custom black tooltip in the template.

### Kept: Custom black tooltip (unchanged)

```html
<div class="pointer-events-none absolute left-1/2 top-full z-30 mt-2 hidden w-64
            -translate-x-1/2 border border-gray-200 bg-gray-950 p-3 text-left
            text-xs leading-relaxed text-white shadow-xl
            group-hover:block group-focus:block">
    ...
</div>
```

This remains the single source of truth for hover information on all room card types.

---

## 6. Tooltip Behavior After Fix

| Room state | Hover result |
|------------|-------------|
| Available | One black tooltip: room number, type, status, "Có thể chọn" |
| Selected | One black tooltip: same + "Đã chọn cho booking hiện tại" |
| Matches requirement warning | One black tooltip: same + amber warning text |
| Conflict (another booking) | One black tooltip: room info + assignment detail (booking code, guest, dates, status) |
| Current booking assigned | One black tooltip: room info + assignment detail |
| Unavailable | One black tooltip: room info + disabled reason |
| Click conflict room | Conflict panel still opens (click handler unchanged) |
| Click current booking room | Release modal still opens (click handler unchanged) |

No native browser tooltip. No white popover. No overlapping boxes.

---

## 7. Tests / Manual Verification

**PHPUnit** — all 139 existing tests pass. The `roomTooltipText` function was JavaScript-only with no backend dependency; its removal does not affect any server-side assertion.

**Manual verification steps:**

1. Open Booking Detail → Sơ đồ phòng tab
2. Hover an available room → one black tooltip appears, no white native browser tooltip
3. Hover a conflict room → one black tooltip with assignment detail; native tooltip absent
4. Hover a current-booking room → one black tooltip with assignment detail
5. Click a conflict room → conflict panel opens as before
6. Click a current-booking room → release modal opens as before

---

## 8. Test Results

```
Tests: 139 passed (778 assertions)
Duration: ~82s
```

---

## 9. Git Diff Summary

```diff
--- a/resources/js/Pages/Admin/Bookings/Show.vue
+++ b/resources/js/Pages/Admin/Bookings/Show.vue

-const roomTooltipText = (room) => {
-    const lines = [
-        `Phòng: ${room.room_number}`,
-        `Loại phòng: ${room.room_type_name ?? room.room_type}`,
-        `Trạng thái phòng: ${room.status_label}`,
-        `Khả dụng: ${availabilityLabel(room)}`,
-    ];
-    if (room.assignment_detail) {
-        lines.push( ... );
-    }
-    if (room.disabled_reason) { lines.push(...); }
-    if (!room.matches_requirement) { lines.push(...); }
-    if (isRoomSelected(room)) { lines.push(...); }
-    return lines.filter(Boolean).join('\n');
-};
-
                                     :tabindex="..."
-                                    :title="roomTooltipText(room)"
                                     @click="toggleRoomSelection(room)"
```

Net change: −33 lines.

---

## 10. Risks / Notes

- **Accessibility**: The `title` attribute also served as an accessible label for screen readers on some browsers. The button already has `aria-disabled` and the custom tooltip has `group-focus:block` to show on keyboard focus, so keyboard and screen-reader navigation is unaffected.
- **No behavior regression**: All click handlers (`toggleRoomSelection`, conflict panel, release modal) are unchanged.
- **No backend change**: The fix is purely frontend template cleanup.

---

## 11. Context For Future Sessions

- Room cards in `Show.vue` use CSS `group`/`group-hover` for the custom black tooltip (`group-hover:block group-focus:block`).
- There is no separate tooltip component or external tooltip library.
- The `title` attribute must NOT be added back to room card buttons — it creates the duplicate native tooltip.
- `roomTooltipText` has been deleted; do not recreate it.
- The custom black tooltip div is inside the `<button>` and is the authoritative hover UI.
