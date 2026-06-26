# Phase 2.5 — Room Type Availability UI Polish

## 1. Objective

Polish the "Tình trạng loại phòng" cards in **Booking Detail → Sơ đồ phòng** to use the same visual design language as the "Card nhu cầu phòng" section. UI-only change — no backend logic modified.

## 2. Files Changed (1)

| File | Change |
|------|--------|
| `resources/js/Pages/Admin/Bookings/Show.vue` | Updated both `availabilityInRequirement` and `availabilityOutsideRequirement` card templates in the `room_map` tab |

## 3. UI Changes

### Before

| Aspect | Old behaviour |
|--------|--------------|
| Card background | Always white — no status color |
| Card border | Light color-tinted border only (`border-green-200`, `border-amber-200`, `border-red-200`) |
| Badge | Colored text background, but kept emoji prefix ("🟢 Còn nhiều") |
| Number color | `text-pine` for green, `text-coral` for red (not matching Requirement Summary palette) |
| Detail row | `"trống · Giữ X"` — "trống" had no count |
| TRIP_FAMILY | No overflow protection — could break layout |

### After

| Aspect | New behaviour |
|--------|--------------|
| Card background | Full status background: `bg-green-50/50` / `bg-amber-50/50` / `bg-red-50/50` |
| Card border | Stronger status border: `border-green-300` / `border-amber-300` / `border-red-300` |
| Badge | Same colored badge, text-only label (no emoji), `shrink-0` to never squish |
| Number color | `text-green-700` / `text-amber-600` / `text-red-700` — matches Requirement Summary |
| Detail row | `"Trống X • Giữ Y"` — "Trống" now shows count; `• Bảo trì Z` shown if data present |
| TRIP_FAMILY | Room type code: `min-w-0 flex-1 truncate` + `:title` tooltip — layout never breaks |
| Transition | Added `transition-colors duration-150` to card and number for smooth state changes |

## 4. Before / After Summary

```
BEFORE                              AFTER
┌─────────────────────────┐         ┌─────────────────────────┐
│ DOUBLE    🟢 Còn nhiều  │         │ DOUBLE        Còn nhiều │  ← badge text only, shrink-0
│                          │         │                          │
│      6 / 12 (green)      │  →     │      6 / 12              │  ← text-green-700 + bg-green-50/50
│                          │         │                          │
│  trống · Giữ 5           │         │  Trống 6 • Giữ 5        │  ← count added
└─────────────────────────┘         └─────────────────────────┘
  white bg, pale border                green bg, stronger border
```

## 5. Manual QA Checklist

- [ ] Every card has identical layout (header / number / detail)
- [ ] Room type code is left-aligned in header
- [ ] Badge is right-aligned in header, never squishes
- [ ] Large number is perfectly centered
- [ ] Detail row is centered, format "Trống X • Giữ Y"
- [ ] "Còn nhiều" card: light green background + green border + green badge + green number
- [ ] "Sắp hết" card: light amber background + amber border + amber badge + amber number
- [ ] "Hết phòng" card: light red background + red border + red badge + red number
- [ ] TRIP_FAMILY truncates gracefully with tooltip on hover, layout intact
- [ ] "TRONG NHU CẦU" / "NGOÀI NHU CẦU" divider still renders correctly
- [ ] Desktop: cards appear in one row within each section when space permits
- [ ] Mobile: cards wrap correctly
- [ ] Visual appearance matches "Card nhu cầu phòng" design language

## 6. Test Results

268 tests / 1837 assertions — all passed (UI-only change, no backend test coverage needed).

## 7. Ready for Manual QA

YES
