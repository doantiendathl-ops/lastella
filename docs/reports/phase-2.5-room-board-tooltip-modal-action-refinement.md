# Phase 2.5 — Room Board Tooltip / Modal Action Refinement

## Changes

### Dark hover tooltip (preview only)
- Removed both "Gỡ phòng" buttons (conflict and current booking variants)
- Removed "Đã nhận phòng, không thể gỡ" lock label
- Kept only "Xem booking" link for conflict rooms
- Added `anyModalOpen` computed to suppress tooltip via dynamic class when any modal is open

### White conflict modal (operational actions)
- Added lock warning banner when `is_assignment_locked` is true:
  "Phòng đã nhận phòng thực tế. Vui lòng trả phòng trước khi giải phóng."
- Shortened button text from "Gỡ phòng khỏi booking này" to "Gỡ phòng"
- Buttons: Đóng, Xem chi tiết booking, Gỡ phòng (when allowed)

### White current booking modal (operational actions)
- Added "Xem chi tiết booking" link
- Shortened button text from "Gỡ phòng khỏi booking này" to "Gỡ phòng"
- Lock warning already existed (lines 1489-1491)
- Buttons: Đóng, Xem chi tiết booking, Gỡ phòng (when allowed)

### Tooltip hiding rule
- `anyModalOpen` computed tracks `showConflictPanel`, `showCurrentBookingPanel`, `releaseDialogAssignment`
- When any modal is open, tooltip class switches from `hidden group-hover:block group-focus:block` to just `hidden`

## File Changed

`resources/js/Pages/Admin/Bookings/Show.vue` — 1 file

## Test Results

231 passed, 0 failures (1619 assertions)

## Ready for Manual Retest

**YES**
