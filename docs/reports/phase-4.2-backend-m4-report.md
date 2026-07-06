# Phase 4.2 Backend Milestone 4

**Date:** 2026-07-06
**Branch:** phase-3
**Baseline:** Milestone 3 (approved by ChatGPT, uncommitted)
**Milestone:** 4 — Controller & Routes & Availability Update
**Status:** COMPLETE — READY FOR CHATGPT REVIEW

---

## Scope

Implement `HousekeepingController` (thin, delegates to `HousekeepingService`), 6 Form Requests, the `housekeeping.*` route group, and the `RoomAvailabilityRuleService`/`RoomAvailabilityCheckerService` CLEANING integration — per `docs/implementation-plans/phase-4.2-housekeeping-workflow-implementation-plan.md` §9 and this milestone's explicit instructions. Applied the 3 feasible M2 hardening notes and 2 of the 3 M3 hardening notes flagged by ChatGPT. No Vue, no Inertia Pages, no navigation, no commit, no push, no financial module touched.

## Files Added

| File | Type |
|------|------|
| `app/Http/Controllers/Admin/HousekeepingController.php` | Controller |
| `app/Http/Requests/Housekeeping/AssignHousekeepingRequest.php` | Form Request |
| `app/Http/Requests/Housekeeping/StartCleaningRequest.php` | Form Request |
| `app/Http/Requests/Housekeeping/CompleteCleaningRequest.php` | Form Request |
| `app/Http/Requests/Housekeeping/InspectionRequest.php` | Form Request |
| `app/Http/Requests/Housekeeping/OutOfOrderRequest.php` | Form Request |
| `app/Http/Requests/Housekeeping/ReleaseFromOutOfOrderRequest.php` | Form Request (not explicitly named in the instructions — see §Scope Control) |
| `tests/Feature/HousekeepingControllerTest.php` | Feature test (24 cases) |
| `tests/Feature/HousekeepingAvailabilityIntegrationTest.php` | Feature test (5 cases) |
| `tests/Unit/Services/RoomAvailabilityRuleServiceTest.php` | Unit test (8 cases) |

## Files Modified

| File | Change |
|------|--------|
| `routes/web.php` | Added `housekeeping.*` route group (9 routes) inside the existing `admin` prefix group. No Booking/Stay/Folio route touched. |
| `app/Services/RoomAvailabilityRuleService.php` | `isRoomUnavailable()` — added `RoomStatus::Cleaning` to the blocked list (ADR-88) |
| `app/Services/RoomAvailabilityCheckerService.php` | Added a `RoomStatus::Cleaning` branch (returns `'cleaning'`) checked before the generic `isRoomUnavailable()`/`resolveAvailability()` path; added `'cleaning'` to the room-type-summary "unavailable" bucket; added `'cleaning'` label to `availabilityLabel()` |
| `app/Services/HousekeepingService.php` | M2 hardening: PHPDoc on `passInspection()`/`failInspection()`/`skipInspection()` (M2-01); `autoMarkOccupied()` log now includes `booking_id` (M2-02); `failInspection()` skips auto-escalation if an active assignment already exists (M2-03) |
| `app/Policies/HousekeepingPolicy.php` | M3-01: added class-level PHPDoc |
| `tests/Unit/Policies/RoomPolicyTest.php` | M3-02: added 2 HTTP-level tests confirming HOUSEKEEPING gets 403 on Room CRUD routes and 200 on the index route |
| `tests/Unit/Seeders/RolePermissionSeederTest.php` | M3-03: added an explicit pivot-row-level duplicate check (`role_has_permissions` table) |

`app/Models/Room.php`, `app/Policies/RoomPolicy.php` (viewAny/view gate), `app/Providers/AppServiceProvider.php`, `database/seeders/RolePermissionSeeder.php` show as modified in `git status` only because they carry uncommitted Milestone 1–3 work — not touched again for M4 except where listed above.

---

## Controller Summary

`HousekeepingController` — 9 actions, one per route. Every action either authorizes via the FormRequest's `authorize()` (which delegates to `$user->can(ability, HousekeepingAssignment::class)`, i.e. `HousekeepingPolicy`) or, for `index()` (no FormRequest, no body), an inline `$this->authorize('view', HousekeepingAssignment::class)`. No action contains a status-transition rule, a validation rule, or a query condition beyond the `index()` read-model — all business logic lives in `HousekeepingService`.

```
index()               → GET   housekeeping.view    → read-only room list + can{} flags (JSON, no Vue)
assign()               → POST  housekeeping.assign  → HousekeepingService::assignRoom()
startCleaning()        → PATCH housekeeping.status  → HousekeepingService::startCleaning()
completeCleaning()      → PATCH housekeeping.status  → HousekeepingService::completeCleaning()
passInspection()        → PATCH housekeeping.inspect → HousekeepingService::passInspection()
failInspection()        → PATCH housekeeping.inspect → HousekeepingService::failInspection()
skipInspection()        → PATCH housekeeping.inspect → HousekeepingService::skipInspection()
markOutOfOrder()        → PATCH housekeeping.maint.  → HousekeepingService::markOutOfOrder()
releaseFromOutOfOrder() → PATCH housekeeping.maint.  → HousekeepingService::releaseFromOutOfOrder()
```

**`index()` returns JSON, not Inertia.** The instructions explicitly forbid Vue/Inertia Pages this milestone, and no Housekeeping Vue component exists yet (reserved for M5). Rather than call `Inertia::render()` against a component that doesn't exist, `index()` returns a plain `response()->json([...])` with the same data shape the future board will consume (`rooms[]`, `can{}`). This is a deliberate, minimal-risk choice — swapping to `Inertia::render()` in M5 is a one-line change once the Vue page exists.

**Controller namespace:** placed at `app/Http/Controllers/Admin/HousekeepingController.php` (not the literal `app/Http/Controllers/HousekeepingController.php` path given in the instructions) to match the established convention — every other controller in the `admin.*` route group (`RoomAvailabilityController`, `NightAuditController`, `BookingSpecialRequestController`, etc.) lives under `App\Http\Controllers\Admin`. Flagged here for visibility, not hidden in the diff.

---

## Form Requests Summary

| Request | Authorizes via | Rules |
|---------|----------------|-------|
| `AssignHousekeepingRequest` | `assign` | `assigned_to` nullable int (must exist), `priority`/`reason` nullable enum values, `notes` nullable string |
| `StartCleaningRequest` | `updateStatus` | none — pure state transition |
| `CompleteCleaningRequest` | `updateStatus` | `notes` nullable string |
| `InspectionRequest` | `inspect` | shared by all 3 inspection routes; `notes` becomes `required` only when `$this->route()->getActionMethod() === 'skipInspection'` (ADR-90 skip-reason requirement) |
| `OutOfOrderRequest` | `maintenance` | `reason` required string |
| `ReleaseFromOutOfOrderRequest` | `maintenance` | `target_status` nullable, restricted to `VACANT_DIRTY`/`VACANT_CLEAN` |

**`ReleaseFromOutOfOrderRequest` was not one of the 5 named requests in the instructions.** `releaseFromOutOfOrder()` takes an optional `target_status` field that still needs validation, and the instructions state "Validation chỉ nằm trong FormRequest" (validation only lives in FormRequest) — so a 6th, minimal request class was added rather than validating inline in the controller. This is the only addition beyond the named list; flagged explicitly rather than silently expanding scope.

All FormRequests authorize via their own `authorize()` method (matching the existing codebase convention in `StoreBookingSpecialRequestRequest`), so an unauthorized request is rejected before validation ever runs.

---

## Routes Summary

9 routes under `admin.housekeeping.*`, added inside the existing `Route::prefix('admin')->name('admin.')` group in `routes/web.php`. No Booking, Stay, or Folio route was touched (verified via `git diff routes/web.php`).

```
GET    /admin/housekeeping                              admin.housekeeping.index
POST   /admin/housekeeping/{room}/assign                admin.housekeeping.assign
PATCH  /admin/housekeeping/assignments/{assignment}/start     admin.housekeeping.start
PATCH  /admin/housekeeping/assignments/{assignment}/complete  admin.housekeeping.complete
PATCH  /admin/housekeeping/{room}/pass-inspection        admin.housekeeping.inspect.pass
PATCH  /admin/housekeeping/{room}/fail-inspection        admin.housekeeping.inspect.fail
PATCH  /admin/housekeeping/{room}/skip-inspection        admin.housekeeping.inspect.skip
PATCH  /admin/housekeeping/{room}/out-of-order           admin.housekeeping.out-of-order
PATCH  /admin/housekeeping/{room}/release                admin.housekeeping.release
```

Verified via `php artisan route:list --name=housekeeping` — all 9 routes registered correctly, pointing at `Admin\HousekeepingController`.

---

## Availability Integration Summary

- `RoomAvailabilityRuleService::isRoomUnavailable()` now includes `RoomStatus::Cleaning` alongside `OutOfOrder`/`OutOfService` (ADR-88).
- `RoomAvailabilityCheckerService::check()` checks `$room->status === RoomStatus::Cleaning` **before** calling `isRoomUnavailable()`/`resolveAvailability()`, returning the distinct `'cleaning'` label instead of the generic `'out_of_order'` one. `resolveAvailability()`'s signature and every other branch is untouched.
- Room-type summary: `'cleaning'` added to the same bucket as `occupied`/`reserved`/`overlap`/`multi_booking` (reduces `remaining`, does **not** reduce `sellable` — a cleaning room is still sellable inventory once it finishes, unlike a permanently out-of-order room).
- `availabilityLabel()` gained a `'cleaning' => 'Đang dọn'` case.
- **BOOKED/OCCUPIED/CHECKED_IN unaffected:** these are derived entirely from `RoomAssignment` overlap (via `getBlockingAssignments()`/`resolveAvailability()`'s `$checkedIn`/`$reserved` collections), a code path this milestone did not touch. Verified with a dedicated regression test (`test_checked_in_room_still_shows_occupied_not_cleaning`) and by the full-suite diff showing zero new failures in `RoomAvailabilityCheckerTest`/`BookingManagementUiTest`.
- `RoomAssignmentService`'s Room Board (used by `BookingManagementUiTest`) required no code change — it already buckets any `isRoomUnavailable()`-true room into its generic `'unavailable'` status, so CLEANING rooms are automatically treated as non-assignable there too.
- Seed audit (already confirmed in M1/M2/M3): no existing test seeds a room into `CLEANING` status, so this change is additive with zero risk to the 12 pre-existing `RoomAvailabilityCheckerTest` failures.

---

## Tests Added

| File | Cases | Covers |
|------|-------|--------|
| `tests/Feature/HousekeepingControllerTest.php` | 24 | All 9 routes: 403 (per gate, including cross-assign `AuthorizationException`), 422 (invalid priority, wrong room status, missing skip-notes, missing OOO reason, invalid target_status), 201/200 success paths, duplicate-assignment-blocked |
| `tests/Unit/Services/RoomAvailabilityRuleServiceTest.php` | 8 | `isRoomUnavailable()` for all 8 `RoomStatus` values — explicit CLEANING/OUT_OF_ORDER/OUT_OF_SERVICE unavailable, VACANT_CLEAN/VACANT_DIRTY/INSPECTED/OCCUPIED/RESERVED available-by-status |
| `tests/Feature/HousekeepingAvailabilityIntegrationTest.php` | 5 | End-to-end through the existing `/admin/room-availability` Inertia endpoint: CLEANING/OUT_OF_ORDER/VACANT_CLEAN labels, room-type-summary exclusion, CHECKED_IN unaffected |
| `tests/Unit/Policies/RoomPolicyTest.php` (+2) | 2 | M3-02 hardening: HTTP-level 403 on Room CRUD, 200 on Room index, for HOUSEKEEPING |
| `tests/Unit/Seeders/RolePermissionSeederTest.php` (+1) | 1 | M3-03 hardening: no duplicate `(role_id, permission_id)` rows in `role_has_permissions` after 3 reseeds |

**Total new/hardening test cases: 40.**

---

## Tests Executed

```
php artisan route:list --name=housekeeping
php artisan test tests/Feature/RoomAvailabilityCheckerTest.php tests/Feature/BookingManagementUiTest.php
php artisan test tests/Feature/HousekeepingControllerTest.php
php artisan test tests/Unit/Services/RoomAvailabilityRuleServiceTest.php
php artisan test tests/Feature/HousekeepingAvailabilityIntegrationTest.php
php artisan test tests/Unit/Policies/RoomPolicyTest.php
php artisan test tests/Unit/Seeders/RolePermissionSeederTest.php
php artisan test tests/Unit/Services/HousekeepingServiceTest.php
php artisan test   (full suite, run twice)
```

## Test Results

```
New/hardening tests: 40/40 passing

Full suite:
  Run 1: 24 failed, 746 passed (3333 assertions)
  Run 2: 23 failed, 747 passed (3335 assertions)
```

**The 24th failure in Run 1** was `PerStayAttributionTest > charge type room rejected from http` — a `UniqueConstraintViolationException` on `resources.code`. This is the exact same Faker room-number-uniqueness flake first documented in the Milestone 2 report (confirmed non-reproducible; it did not recur on Run 2). Not caused by Housekeeping code — `PerStayAttributionTest` never touches `Room.status`, `HousekeepingService`, or the availability services.

**Stable result: 23 failed, 747 passed** — identical failing classes to the M3 baseline (12 `RoomAvailabilityCheckerTest` + 11 `BookingManagementUiTest`, all pre-existing date-drift). Zero new deterministic failures. All 40 new/hardening tests pass consistently.

---

## Regression Risk

| Component | Risk | Assessment |
|-----------|------|-----------|
| `routes/web.php` | NONE | Purely additive route group; existing routes untouched (diff-verified) |
| `HousekeepingController.php` | NONE (new file) | Not called by any existing code path |
| Form Requests | NONE (new files) | Not referenced elsewhere |
| `RoomAvailabilityRuleService.isRoomUnavailable()` | LOW | Additive enum case; seed audit confirms no test room is CLEANING |
| `RoomAvailabilityCheckerService.check()` | LOW | New branch only triggers for `RoomStatus::Cleaning`; existing branches byte-for-byte unchanged |
| `HousekeepingService` hardening (M2-01/02/03) | NONE | Additive PHPDoc, additive log field, additive pre-check before an existing create — no existing passing test's assertions changed |
| Financial modules | NONE | Not touched — verified via `git status` |

---

## Scope Control

**Implemented in Milestone 4:** `HousekeepingController` (9 actions) ✅, 6 Form Requests ✅ (5 named + 1 necessary addition, flagged), routes ✅ (9, no Booking/Stay/Folio touched), `RoomAvailabilityRuleService`/`RoomAvailabilityCheckerService` CLEANING integration ✅, tests ✅ (40 new/hardening cases).

**Hardening applied (not required, judged in-scope for this milestone's touch of the same files):**
- M2-01 (passInspection contract PHPDoc) — done
- M2-02 (autoMarkOccupied log booking_id) — done
- M2-03 (failInspection duplicate-assignment guard) — done
- M3-01 (HousekeepingPolicy class-level PHPDoc) — done
- M3-02 (HOUSEKEEPING Room CRUD 403 test) — done
- M3-03 (seeder duplicate-pivot-row test) — done

**NOT implemented (explicitly out of scope, per instructions):** Vue, Inertia Pages, Navigation, Sidebar, Housekeeping Board UI, Dashboard/Charts/Widgets (M5); remaining M6 test suites (`HousekeepingWorkflowTest`-style end-to-end workflow test, race-condition/concurrent-claim test — the controller-level duplicate-assignment test in this milestone covers the sequential case, not concurrent).

---

## Remaining Work

- M5 — Frontend: `Housekeeping/Index.vue`, `RoomAvailability/Index.vue` cleaning badge, `AppLayout.vue` nav entry. `HousekeepingController::index()` will need to swap its `response()->json()` return for `Inertia::render()` once the Vue page exists.
- M6 — Testing & Final Verification: concurrent-assignment-claim race test (`ADR-87` verification — the current `HousekeepingControllerTest::test_assign_blocked_duplicate_active_assignment` proves the sequential guard but not concurrent-request locking), full manual QA across all 5 roles, `npm run build` check (irrelevant until M5 adds Vue).
- Not addressed from ChatGPT's M2/M3 notes: none — all 6 flagged notes (M2-01/02/03, M3-01/02/03) were applied in this milestone.

---

## Ready for ChatGPT Review

**Milestone 4 Controller & Routes & Availability: COMPLETE**

- `HousekeepingController`: ✅ thin, 9 actions, all delegate to `HousekeepingService`, no business logic
- Form Requests: ✅ all validation isolated to FormRequest classes, authorization checked before validation
- Routes: ✅ 9 routes registered, zero Booking/Stay/Folio routes modified
- Availability integration: ✅ CLEANING correctly blocks assignment and gets a distinct label; BOOKED/OCCUPIED/CHECKED_IN verified unaffected
- Service hardening: ✅ all 6 ChatGPT M2/M3 notes applied
- Test suite: ✅ 40 new tests passing; 0 new regressions (23 failed / 747 passed, identical failing classes to M3 baseline; one confirmed pre-existing Faker flake, non-reproducible)
- Vue/Inertia/Navigation: ✅ not touched
- Financial modules: ✅ not touched
- Not committed, not pushed — awaiting ChatGPT review
