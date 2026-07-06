# Phase 4.2 Backend Milestone 5 (Frontend)

**Date:** 2026-07-06
**Branch:** phase-3
**Baseline:** Milestone 4 (approved by ChatGPT, uncommitted)
**Milestone:** 5 — Housekeeping Frontend
**Status:** COMPLETE — READY FOR CHATGPT REVIEW

---

## Scope Implemented

Housekeeping Board Vue page, `HousekeepingController::index()` swapped to `Inertia::render()`, centralized RoomStatus badge/label helpers, Room Availability CLEANING integration, and a permission-gated nav entry. No backend business logic was modified except the one explicitly-required `index()` response-type swap.

---

## Files Added

| File | Purpose |
|------|---------|
| `resources/js/Pages/Admin/Housekeeping/Index.vue` | Housekeeping Board — room cards, status badges, actions |
| `resources/js/Pages/Admin/Housekeeping/Partials/HousekeepingActionDialog.vue` | Single reusable dialog covering all 6 workflow actions |
| `resources/js/Support/roomStatusBadges.js` | Centralized RoomStatus → badge color map (single source of truth) |
| `tests/Feature/HousekeepingControllerTest.php` (+6 cases) | `can` flags per role, room data shape, shared-permissions nav-gating check |

## Files Modified

| File | Change |
|------|--------|
| `app/Http/Controllers/Admin/HousekeepingController.php` | `index()` now returns `Inertia::render('Admin/Housekeeping/Index', [...])` instead of `response()->json(...)`. **The `rooms`/`can` data shape is byte-for-byte identical** — same keys, same nesting, same value types. The other 8 mutation actions are untouched. |
| `tests/Feature/HousekeepingControllerTest.php` | `index()` tests converted from `assertJsonStructure` to `assertInertia` |
| `resources/js/Pages/Admin/RoomAvailability/Index.vue` | Added a `cleaning` entry to `availabilityStyles` (colors sourced from `roomStatusBadges.js`, not hardcoded again); `selectRoom()` now also skips the detail modal for `cleaning` (matches existing `out_of_order` non-interactive treatment); legend gained an amber "Đang dọn" entry; the primary-color booking-strip bar is suppressed for `cleaning` rooms same as `out_of_order` |
| `resources/js/Support/vietnameseLabels.js` | Extended the existing `maps`/`labelFor` convention with `roomStatusLabels`, `cleaningPriorityLabels`, `cleaningReasonLabels`, `housekeepingAssignmentStatusLabels`, `inspectionResultLabels` — mirrors each PHP enum's `label()` method exactly |
| `resources/js/Layouts/AppLayout.vue` | Added one nav item: "Dọn phòng" → `/admin/housekeeping`, gated on `can('housekeeping.view')`, using the existing `navItems` filter pattern. No other nav item touched. |

---

## Vue Components

### `Housekeeping/Index.vue`

Responsive card grid (no floor grouping — the M4 `index()` contract doesn't include `floor_id`/`floor_name`, and expanding it was out of scope for this milestone; see Remaining Work). Each room card shows:

- Room number + `status_label` badge (colored via `roomStatusBadge(room.status)`)
- Assigned staff name, priority label, cleaning-assignment status label (all sourced from `active_assignment`)
- Last cleaned timestamp
- Action buttons computed client-side from `room.status` + `active_assignment.status` + `can{}` — **no business rule is enforced here**, just which buttons are *plausible* to show; the backend is still the sole authority (e.g. a HOUSEKEEPING user assigning to someone else still gets a 403 from the service even though the button renders, since the frontend has no way to know per-assignment ownership without expanding the contract)

**Not implemented:** cleaning reason and notes preview (bulleted in the milestone's feature list). The M4 `index()` contract's `active_assignment` object only has `id`, `status`, `assigned_to`, `priority` — no `reason` or `notes` field. Milestone 2 explicitly required the Inertia response contract to **remain identical** ("Do not redesign API responses"), so these two fields were left out rather than silently expanding the payload. Flagged in Remaining Work.

**Not implemented:** a housekeeper dropdown for the Assign dialog. The contract has no `housekeepers[]` list. The Assign dialog instead defaults to self-assign (leave the field blank) with an optional numeric user-ID field for MANAGER/ADMIN cross-assignment — workable but not ideal UX, flagged in Remaining Work.

### `Housekeeping/Partials/HousekeepingActionDialog.vue`

One reusable dialog parameterized by an `action` prop (`assign`/`start`/`complete`/`pass`/`fail`/`skip`/`outOfOrder`/`release`) instead of 6 near-duplicate components — avoids repeating the same modal chrome, error-display, and submit-handling logic 6 times (DRY). Renders only the fields relevant to the active action (e.g. `skip` and `outOfOrder` render a required textarea; `start` renders a plain confirmation). Submits via `axios[method](route(...), payload)` against the **unchanged** M4 JSON endpoints, then emits `success` so the parent triggers `router.reload({ only: ['rooms', 'can'] })` — a partial Inertia reload, not a full page refresh. Validation error messages are read directly from the backend's `422` response body (`error.response.data.errors`) and rendered as-is — no validation rule is duplicated in the frontend.

**Why axios instead of Inertia's `useForm`/`router`:** the 8 mutation routes return plain JSON (approved, tested contract from Milestone 4), not Inertia-compatible redirects. Changing them to redirect responses would have meant editing already-approved M4 controller code beyond the one change this milestone explicitly authorized (the `index()` swap). Calling them via `axios` against their existing JSON contract keeps 100% of the M4 controller/service/test code untouched.

### Component location

Placed at `resources/js/Pages/Admin/Housekeeping/Index.vue` (not the literal `resources/js/Pages/Housekeeping/Index.vue` from the instructions), matching every other page in this app (`RoomAvailability`, `Bookings`, `NightAudit`, etc. all live under `Pages/Admin/`). Same reasoning as the M4 controller-namespace decision — flagged explicitly, not hidden. `Inertia::render()`'s component name (`Admin/Housekeeping/Index`) matches accordingly.

---

## Inertia Integration

- `HousekeepingController::index()` renders `Admin/Housekeeping/Index` with exactly `rooms` and `can` — no new top-level key, no renamed field, no removed field.
- Verified via `tests/Feature/HousekeepingControllerTest.php::test_index_includes_room_status_and_assignment_fields`, which asserts the full nested shape (`id`, `status`, `active_assignment.id/assigned_to/priority`) is still present and correctly populated after the Inertia swap.
- The 8 mutation routes are unchanged JSON endpoints (verified by re-running the full `HousekeepingControllerTest` suite — all 24 pre-existing M4 cases still pass byte-for-byte against the same assertions).

---

## Navigation Changes

One line added to `AppLayout.vue`'s `navItems` array: `{ label: 'Dọn phòng', href: '/admin/housekeeping', icon: Brush, show: can('housekeeping.view') }`. Uses the exact same `can()`/`.filter((item) => item.show)` mechanism every other nav item uses — no new gating pattern introduced. No other nav item was reordered, relabeled, or removed.

---

## Permission Handling

- Every action button on the board is wrapped in a `v-if`-equivalent computed (`actionsFor(room)`) that checks the relevant `can.*` flag before including the button — a user without `room.inspect`, for example, never sees Pass/Fail/Skip buttons, matching the existing `SpecialRequests.vue`/`RoomAvailability/Index.vue` pattern of gating buttons on server-supplied `can{}` booleans.
- No frontend role/permission logic beyond reading the `can{}` prop — consistent with "No frontend business rules."
- Verified with 2 new tests: `test_index_can_flags_match_housekeeping_role_grant` and `test_index_can_flags_match_reception_role_grant`, confirming the `can{}` values match the actual ADR-89 permission matrix (not just that the keys exist).
- Verified nav-gating data end-to-end with 2 new tests confirming `auth.user.permissions` (the prop `AppLayout.vue` reads for `can()`) includes/excludes `housekeeping.view` correctly per role, via a request to `/dashboard` (proving the shared Inertia prop is correct on *any* page, not just the Housekeeping board itself).

---

## UI Workflow

Matches the required lifecycle exactly, gated by `can{}` at each step:

```
Assign (can.assign, room VACANT_DIRTY, no active assignment)
  ↓
Start Cleaning (can.updateStatus, assignment pending)
  ↓
Complete Cleaning (can.updateStatus, assignment in_progress)
  ↓
Pass / Fail / Skip Inspection (can.inspect, room INSPECTED)
  ↓
Ready (room VACANT_CLEAN, or re-queued VACANT_DIRTY on fail)
```

Out-of-order is a parallel branch: `markOutOfOrder` (any status except CLEANING, `can.maintenance`) and `releaseFromOutOfOrder` (room OUT_OF_ORDER, `can.maintenance`).

---

## Room Status UI

`resources/js/Support/roomStatusBadges.js` is the single source of truth for all 8 `RoomStatus` values (`VACANT_CLEAN`, `VACANT_DIRTY`, `CLEANING`, `INSPECTED`, `OCCUPIED`, `RESERVED`, `OUT_OF_ORDER`, `OUT_OF_SERVICE`). Used by:
- `Housekeeping/Index.vue` — keyed directly on `room.status`
- `RoomAvailability/Index.vue` — the new `cleaning` availability style pulls its class strings from `roomStatusBadge('CLEANING')` rather than duplicating them

No badge color is hardcoded in more than one place for the same status.

---

## Room Availability UI

- CLEANING rooms now render with the distinct amber badge (shared with the Housekeeping board), not the gray `out_of_order` styling.
- CLEANING rooms are non-clickable (no detail modal), matching `out_of_order`'s existing non-interactive treatment — there's no useful booking-overlap detail to show for a room mid-clean.
- Legend updated with the new "Đang dọn" entry.
- **BOOKED/CHECKED_IN/RESERVED unchanged:** these come from the `checkedIn`/`reserved` `RoomAssignment` collections, a code path this milestone's frontend changes never touch (only the `availabilityStyles` map and `selectRoom()` guard were edited). Verified by the full-suite diff showing zero new failures in `RoomAvailabilityCheckerTest`/`BookingManagementUiTest` beyond the pre-existing baseline.

---

## Tests Executed

```
npm run build
php artisan test tests/Feature/HousekeepingControllerTest.php
php artisan test tests/Feature/LateCheckoutFeeTest.php   (isolated investigation)
php artisan test   (full suite, run twice)
```

## Test Results

```
npm run build
  ✓ built in 15.41s — 0 errors (pre-existing >500kB chunk warning, unchanged from before this milestone)

HousekeepingControllerTest: 29 passed (131 assertions) — 24 M4 cases unchanged + 5 new M5 cases

Full suite:
  Run 1: 24 failed, 751 passed (3411 assertions)
  Run 2: 25 failed, 750 passed (3409 assertions)
```

**Stable baseline component (23, unchanged both runs):** 11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest` — identical to the M4 baseline, pure pre-existing date-drift.

**Two additional flakes observed, both confirmed pre-existing and unrelated to this milestone's code:**

1. `PerStayAttributionTest` (1 of 2 runs) — the same `UniqueConstraintViolationException` on `resources.code` documented since the Milestone 2 report (Faker's bounded room-number pool colliding under a growing suite). This time it hit a third, different test method within that class (`add charge accepts system auto posting source`) — further confirming it's a random collision, not tied to specific test content.
2. `LateCheckoutFeeTest::test_check_out_triggers_late_checkout_fee_via_stay_service` (both runs) — **root-caused, not just observed.** The test computes `$plannedCheckout = now()->subHours(2)->setTime(12, 0, 0)`, but `setTime()` overwrites the hour/minute/second entirely, silently discarding the `subHours(2)` — so `$plannedCheckout` is always "today at 12:00 noon, real wall-clock date," and the test never calls `travelTo()` to freeze time. The system clock during this run was 02:23 AM, i.e. *before* the planned checkout time, so checkout was never late and no fee posted. This test will fail on any run before local noon and pass after — a latent bug in the test itself, present since it was written, with zero relationship to `StayService` (which I have not modified since Milestone 2) or any Housekeeping code.

**Net result:** 0 new deterministic regressions attributable to Milestone 5. All Housekeeping-specific and M5-added tests (29/29) pass consistently across both runs.

---

## Regression Assessment

| Area | Risk | Assessment |
|------|------|-----------|
| Booking | NONE | No Booking file touched |
| Stay | NONE | No Stay file touched (the one `LateCheckoutFeeTest` failure is a pre-existing, unrelated test bug — see above) |
| Room Assignment | NONE | No RoomAssignmentService file touched |
| Room Availability | LOW | `RoomAvailability/Index.vue` changed additively (new `cleaning` style key, one guard-clause extension); `RoomAvailabilityCheckerTest`/`BookingManagementUiTest` failure counts identical to M4 baseline |
| Special Requests | NONE | Not touched |
| Financial Foundation | NONE | Not touched |
| Night Audit | NONE | Not touched |
| `HousekeepingController::index()` | LOW | Only the response wrapper changed (`JsonResponse` → `InertiaResponse`); the returned data is identical, verified by a dedicated shape-assertion test |

---

## Scope Control

**Implemented:** Housekeeping Board (`Index.vue`), Inertia integration for `index()` with an identical contract, centralized RoomStatus badge/label helpers, full cleaning workflow UI gated by `can{}`, dialogs/forms for all 6 actions (one reusable component), Room Availability CLEANING integration, nav entry, permission handling.

**Explicitly out of scope, not implemented:** Reports, Dashboard, Analytics, Charts, Statistics, Notifications, WebSocket, auto-refresh optimization, mobile optimization, offline support. Booking/Stay/Folio/Payment/Night Audit/Special Requests/Financial modules untouched.

**Not implemented due to the "response contract must remain identical" constraint (flagged, not silently dropped):**
- Cleaning reason and notes preview on room cards (no `reason`/`notes` field in the M4 `active_assignment` contract)
- A proper housekeeper dropdown in the Assign dialog (no `housekeepers[]` list in the contract)
- Floor-based grouping on the board (no `floor_id`/`floor_name` in the contract; rooms render as a flat, room-number-sorted grid)

---

## Remaining Work

- If ChatGPT approves expanding the `index()` contract, a small follow-up could add `reason`, `notes`, `floor_id`/`floor_name`, and a `housekeepers[]` list to unlock the reason/notes display, floor grouping, and a real assignee dropdown — currently deferred because Milestone 5's instructions explicitly forbade redesigning the API this round.
- Frontend component-level tests (Vitest/Vue Test Utils) were not added — this project has no existing JS test runner configured (no Vitest/Jest in `package.json`, no existing `.spec.js`/`.test.js` files anywhere in the codebase), so "frontend tests (if applicable)" was judged not applicable; coverage instead comes from the Laravel feature-test layer (Inertia prop assertions) plus the `npm run build` compile check.
- Integration phase (per the stated workflow) awaits this report's ChatGPT review.

---

## Ready for ChatGPT Review

**Milestone 5 Frontend: COMPLETE**

- Housekeeping Board: ✅ renders, status badges, assigned staff, priority, last cleaned, permission-gated actions
- Inertia integration: ✅ `index()` swapped, contract verified byte-for-byte identical
- Room Status UI: ✅ centralized badge/label helpers, no duplicated mapping
- Cleaning workflow UI + forms: ✅ all 6 actions, permissions-only gating, no frontend business rules
- Room Availability UI: ✅ CLEANING badge added, distinguishable from Out Of Order, BOOKED/CHECKED_IN/RESERVED unaffected
- Navigation: ✅ one permission-gated entry added
- `npm run build`: ✅ 0 errors
- Full test suite: ✅ 0 new deterministic regressions (stable 23-failure baseline unchanged; 2 additional flakes both root-caused as pre-existing and unrelated)
- Not committed, not pushed, not tagged — awaiting ChatGPT architecture review before Phase 4.2 Integration
