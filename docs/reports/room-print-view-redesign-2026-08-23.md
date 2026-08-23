# Room Operations Print View Redesign — 2026-08-23

Two passes in the same chat, second pass supersedes the first's layout.

## Pass 1 (superseded layout, still-valid backend)

User request (`docs/Ghichuchosua.txt` item 2): redesign the A4 print view of
the Daily Room Operations Board (`c6d673e`) — landscape, max 2 pages, 4
field groups per room. Implemented, then replaced by Pass 2 below before
being verified in a browser. The backend addition from this pass
(`RoomOperationsBoardService::specialRequestsByStayId()` + label map) is
still in use by Pass 2 unchanged.

## Pass 2 (current) — portrait, max 1 page, 3 columns

User's revised spec: "in dọc khổ A4" (portrait), "tối đa trong 1 trang", and
exactly 3 columns per room:
1. Room number.
2. Info block: guest name; occupancy status as one of exactly 5 phrases
   ("Đã nhận phòng" / "Dự kiến hôm nay nhận phòng" / "Phòng lưu" / "Đã trả
   phòng" / "Dự kiến trả phòng") instead of raw check-in/out timestamps;
   quick note; special requests spelled out in full.
3. Room cleanliness status (Sạch/Bẩn).

Plus: "có thể tạo thành 2 khung trong 1 trang ... nhưng các khung phải tách
nhau rõ ràng" — two clearly bordered, separated frames side by side (not one
continuous table) to use the narrow portrait width.

## Changes

**`resources/js/Pages/Admin/RoomOperations/Index.vue`:** `@page` rule
switched `landscape` → `portrait`, margin `10mm` → `8mm`.

**`RoomOperationsPrintView.vue`:** full rewrite (replaces Pass 1's version).
- 3 columns per room, exactly as specified above.
- `occupancyStatus(occupant)`: derives one of the 5 phrases by comparing
  `occupant.end_at` / `occupant.actual_checkin_at` date parts against the
  board's own selected `date` prop (not the browser's today) — so a
  look-ahead or historical print date still reads correctly. Order checked:
  already-checked-out → already-trả-phòng; checked-in AND planned end date
  == print date → dự-kiến-trả-phòng; checked-in AND actual check-in date ==
  print date → đã-nhận-phòng (arrived today); checked-in otherwise →
  phòng-lưu (stay-over, checked in on an earlier day); not checked in →
  dự-kiến-hôm-nay-nhận-phòng.
- `specialRequestItems()` unchanged from Pass 1 (bed_join + extra_bed_quantity
  + special_requests + other_services merged into one plain-text list).
- Two-frame split: `flatRooms` flattens all floors into one ordered list,
  split by straight count (`Math.ceil(n/2)`) into `leftRooms`/`rightRooms` —
  count-based rather than floor-based so both frames stay roughly the same
  height regardless of how unevenly rooms are distributed across floors,
  which is what actually keeps this on 1 page. Each frame is its own
  `<table>` with a visible border, separated by a 6mm gap — reads as two
  distinct boxes, not one merged grid. Floor-divider rows still appear
  inside each frame wherever its floor label changes.
- Font shrunk to 7pt (from Pass 1's 8pt), tighter padding — necessary
  headroom for the 1-page target now that portrait width is narrower than
  landscape.

## Verification

- `npm run build` — 0 errors (pre-existing >500kB chunk warning, unchanged).
- **Live browser verification performed this time** (dev server via
  `php artisan serve`, logged in as the real user): confirmed the board
  renders correctly for the current data (59 rooms). Since the print-only
  view is normally `display:none` outside an actual print context, verified
  page-fit by temporarily forcing it visible via a scoped `javascript_tool`
  script (no page files touched) that set `.print-page`'s width to the exact
  A4-portrait content-box width (`(210mm−16mm)` for the 8mm margins) and
  measured its rendered height against one page's content-box height
  `(297mm−16mm)`: **724px rendered vs 1062px available for the current 59
  vacant/mixed rooms (today's date) — comfortably 1 page**, and **766px vs
  1062px at a second date (2027-03-01) with 25 occupied, not-yet-checked-in
  rooms carrying real guest names and the "Dự kiến hôm nay nhận phòng"
  status line — still comfortably 1 page.** Zoomed screenshot confirmed the
  two frames render as clearly separated boxes with legible 3-column content
  and correct Sạch/Bẩn + occupancy-status text. Did not verify the other 4
  occupancy-status phrases against live data (no `CheckedIn` or
  post-checkout stays exist in the current seed data reachable from today);
  reviewed the date-comparison logic by inspection instead — recommend a
  spot-check once real in-house stays exist.
- No PHP files touched in this pass — the existing 70/70 RoomOperations
  regression suite from Pass 1 still applies unchanged.

## Status

Implementation complete, build green, live-browser page-fit verified for 2
different dates/data shapes. **Not committed/pushed.**
