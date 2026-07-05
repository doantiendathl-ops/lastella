# Phase 4.1 Frontend Milestone — Report

**Date:** 2026-07-05
**Status:** COMPLETE — awaiting ChatGPT review
**Branch:** phase-3

---

## Summary

Frontend Milestone 4 implements the "Yêu cầu đặc biệt" (Special Requests) UI integrated into the Booking Detail page, plus supporting views for Room Board and Stay Summary.

## Deliverables

### 1. Backend additions (additive only — no migrations/services/policies touched)

| File | Change |
|------|--------|
| `BookingController.php` | Added special requests eager load; `specialRequests`, `pendingCount`, `availableStays` to payload; `special_requests` tab to `detailTabs()`; `special_requests` case to `normalizeDetailTab()`; 3 permission flags to `can` |
| `RoomAvailabilityController.php` | Added `pendingRequestCounts` prop via `BookingSpecialRequest::pendingCountByRoom()` |

### 2. New Vue components

| File | Purpose |
|------|---------|
| `resources/js/Pages/Admin/Bookings/Partials/SpecialRequestPanel.vue` | Booking Detail "Yêu cầu" tab — request list, status badges, add form, action buttons |
| `resources/js/Pages/Admin/Booking/SpecialRequests.vue` | Standalone HOUSEKEEPING view (rendered by `BookingSpecialRequestController::index()`) |

### 3. Modified Vue files

| File | Change |
|------|---------|
| `Show.vue` | Import SpecialRequestPanel; add pending badge to tab button; render `<SpecialRequestPanel>` for `tab === 'special_requests'` |
| `RoomBoardPanel.vue` | Stay Summary: show emoji request labels + status icon (✓⏳✕) per active stay |
| `RoomAvailability/Index.vue` | `pendingRequestCounts` prop; amber badge on room cards when count > 0 |

### 4. Tests

`tests/Feature/SpecialRequestUiTest.php` — 14 tests, all passing:
- Tab visible for ADMIN, MANAGER, RECEPTION
- Tab hidden for ACCOUNTANT
- HOUSEKEEPING denied access to booking detail (policy)
- HOUSEKEEPING restricted mode via standalone page (create=false, fulfill=true, cancel=false)
- RECEPTION cannot cancel (cancel=false)
- `can` flags correct for all roles
- Empty state (pendingCount=0, specialRequests=[])
- Pending count reflects active (pending + acknowledged) requests
- Payload shape verified (id, category, request_type, quantity, status, note, category_label)
- `tab=special_requests` query param sets activeTab correctly

`tests/Feature/BookingManagementUiTest.php` — updated existing tabs assertion to include `special_requests`.

### 5. Pre-existing failure (not introduced by this milestone)

`BookingManagementUiTest > conflicted room is disabled on room board` — fails with both old and new code. Cause: hardcoded assignment dates (`2026-07-01`) are now in the past; room board conflict detection may be time-bound. Not in scope to fix.

---

## Architecture decisions

**ADR-81 HOUSEKEEPING:** HOUSEKEEPING cannot access `admin.bookings.show` (blocked by `BookingPolicy::view()` which requires booking-level permissions). They use the standalone page at `admin.bookings.special-requests.index`. The `SpecialRequestPanel` in the booking detail is for ADMIN / MANAGER / RECEPTION only.

**No extra queries:** `specialRequests`, `pendingCount`, and `availableStays` are all derived from the eager-loaded `$booking->specialRequests` and `$booking->stays` collections — zero additional DB hits.

**Tab badge:** `booking.pendingCount` is passed in the `booking` object; tab badge shown in `Show.vue` template via `booking.pendingCount > 0`.

**Room Board badge:** `pendingRequestCounts` is a keyed object `{room_id: count}` returned by `BookingSpecialRequest::pendingCountByRoom()`, added to `RoomAvailability/Index` props.

---

## What was NOT done

- Housekeeping Board (not in scope per task)
- Vue frontend for standalone special requests beyond the minimal HOUSEKEEPING view
- Financial modules (FolioService, BookingPaymentService, etc.) — untouched
- Migrations, Policies, Routes, Services — untouched

---

## Build / test verification

```
npm run build   → ✓ built in 30.92s (chunk size warning is pre-existing)
php artisan test tests/Feature/SpecialRequestUiTest.php → 14 passed
php artisan test tests/Feature/SpecialRequestCrudTest.php tests/Unit/ → 132 passed
git status      → no commit, no push
```
