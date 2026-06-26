# Phase 2.5 — Release Dialog Bug Fix Report

## 1. Root Cause

Multiple root causes found across two rounds of investigation:

### Round 1: Bypass paths
Two release paths bypassed the confirmation dialog entirely:
- **Conflict panel** "Gỡ phòng khỏi booking này" → `window.confirm()` + direct `router.post({})` 
- **Current booking panel** "Gỡ phòng khỏi booking này" → same `window.confirm()` + direct `router.post({})`

Both sent POST requests with empty body, and `release_reason` is nullable — so the release succeeded without going through the dialog.

### Round 2: Dialog hardening
The release dialog itself had deficiencies:
- Used raw `router.post()` instead of `useForm().post()` — no built-in processing state guard
- No double-submit protection (rapid clicks could fire multiple POST requests)
- Backdrop used `@click.self` which can mis-fire during drag events
- Dialog content was a `<div>` not a `<form>` — no implicit submit prevention

## 2. Files Changed

| File | Changes |
|------|---------|
| `resources/js/Pages/Admin/Bookings/Show.vue` | All release paths unified through dialog; `useForm` replaces `router.post`; `<form @submit.prevent>` wrapper; ESC/backdrop/double-submit guards |
| `tests/Feature/BookingManagementUiTest.php` | 5 release-specific tests |

## 3. Fix Implemented

### All release paths unified
- `unassignConflictRoom()` → closes conflict panel, then calls `openReleaseDialog(assignment, true)`
- `releaseCurrentBookingAssignment()` → closes current booking panel, then calls `openReleaseDialog(assignment, false)`  
- `releaseAssignment()` → calls `openReleaseDialog(assignment)`
- Tooltip "Gỡ phòng" → calls `openReleaseDialog(assignment, isConflict)`
- **Zero** `window.confirm()` or direct `router.post()` paths remain for release

### Dialog uses Inertia `useForm`
- `releaseForm = useForm({ release_reason: '' })` manages form state
- `releaseForm.post(url, ...)` sends the request — `releaseForm.processing` prevents double submit
- Confirm button has `:disabled="releaseForm.processing"` to grey out during request

### Double-submit protection
- `releaseConfirmed` ref prevents `confirmRelease()` from firing twice even if Vue re-renders between frames

### Dialog wrapped in `<form @submit.prevent>`
- Implicit form submission (e.g. pressing Enter) goes through `confirmRelease` only — no raw browser submit
- Cancel button is `type="button"` — cannot trigger form submit
- Confirm button is `type="submit"` — works with form submit flow

### ESC handling
- Global `keydown` listener via `onMounted`/`onBeforeUnmount`
- ESC calls `closeReleaseDialog()` which clears all state — no API request

### Backdrop click
- `@mousedown.self` on overlay (not `@click.self`) — prevents accidental trigger during text selection drag
- `@click.stop` on form content prevents propagation

### Cancel button
- `closeReleaseDialog()` sets `releaseDialogAssignment = null`, `releaseForm.release_reason = ''`, `releaseConfirmed = false`
- No API request, no state mutation, no history entry

### Frontend rebuild
- `npx vite build` executed — production bundle regenerated with new JS hash

## 4. Tests Added

5 tests:

1. `test_release_endpoint_not_called_without_explicit_post` — viewing booking does not release
2. `test_release_only_executes_on_explicit_post_request` — explicit POST releases correctly  
3. `test_release_conflict_only_executes_on_explicit_post_request` — conflict POST releases correctly
4. `test_get_request_to_release_endpoint_returns_method_not_allowed` — GET to release = 405
5. `test_assignment_unchanged_after_viewing_booking_detail` — multiple page views preserve state

## 5. Test Results

```
Tests: 127 passed (1051 assertions)
Duration: 49.31s
```

## 6. Ready for Manual Retest

**YES**

**Important:** clear browser cache or hard-refresh (Ctrl+Shift+R) after deployment — old JS bundle may be cached.

### Retest steps:
1. Open a booking with assigned rooms → tab "Sơ đồ phòng"
2. In assignment table, click "Giải phóng" → dialog opens
3. Press ESC → dialog closes, room NOT released (verify in DB / refresh page)
4. Click "Giải phóng" again → dialog opens → click "Hủy" → dialog closes, NOT released
5. Click "Giải phóng" again → dialog opens → click dark backdrop → dialog closes, NOT released
6. Click "Giải phóng" again → dialog opens → click "Xác nhận giải phóng" → room IS released
7. Click a room on the board that belongs to this booking → panel opens → click "Gỡ phòng" → release dialog opens → test ESC/Cancel/Confirm
8. Click a room on the board that belongs to another booking → conflict panel → "Gỡ phòng khỏi booking này" → release dialog opens → test ESC/Cancel/Confirm
