# Phase 4.2 Backend Milestone 2 Report

**Date:** 2026-07-05
**Branch:** phase-3
**Baseline:** Milestone 1 (uncommitted — `docs/reports/phase-4.2-backend-m1-report.md`)
**Milestone:** 2 — Service Layer
**Status:** COMPLETE — READY FOR CHATGPT REVIEW

---

## Objective

Implement the `HousekeepingService` (9 domain operations per the approved implementation plan), wire the two non-throwing `StayService` hooks (ADR-84), and register `AuditObserver` for the two Milestone 1 models. No controller, no routes, no policy/permission changes, no Vue — strictly service-layer scope per `docs/implementation-plans/phase-4.2-housekeeping-workflow-implementation-plan.md` §7.4–7.7 and §15 (Milestone 2).

## Files Reviewed

- `docs/implementation-plans/phase-4.2-housekeeping-workflow-implementation-plan.md` (full, both pages)
- `app/Services/StayService.php`, `app/Services/SpecialRequestService.php` (pattern reference)
- `app/Models/HousekeepingAssignment.php`, `app/Models/CleaningRecord.php`, `app/Models/Room.php`
- `app/Enums/RoomStatus.php`
- `app/Providers/AppServiceProvider.php`
- `app/Observers/AuditObserver.php`, `app/Models/User.php` (Spatie `HasRoles`)

## Files Added

| File | Type |
|------|------|
| `app/Services/HousekeepingService.php` | Service |
| `tests/Unit/Services/HousekeepingServiceTest.php` | Unit test |
| `tests/Feature/StayServiceHousekeepingHookTest.php` | Feature test |

## Files Modified

| File | Change |
|------|--------|
| `app/Services/StayService.php` | Injected `HousekeepingService`; added non-throwing hook call in `checkIn()` (after `updateBookingStayStatus`) and `checkOut()` (after finalise/update-status branch) |
| `app/Providers/AppServiceProvider.php` | Registered `HousekeepingAssignment::observe(AuditObserver::class)` and `CleaningRecord::observe(AuditObserver::class)` |

No controller, route, policy, seeder, or Vue files touched. No financial service (`FolioService`, `BookingPaymentService`, `NightAuditPipeline`, etc.) modified.

---

## Backend Changes

### `HousekeepingService` — all 9 methods per plan §7.4

| Method | Behavior |
|--------|----------|
| `assignRoom()` | Locks room, requires `VACANT_DIRTY` + no active assignment; self-assign always allowed; assigning to a different user requires `room.maintenance` permission (else `AuthorizationException`) |
| `startCleaning()` | Locks room + assignment; requires `pending` + room `VACANT_DIRTY`; creates the opening `CleaningRecord`; room → `CLEANING` |
| `completeCleaning()` | Requires `in_progress` + room `CLEANING`; finds the **open** `CleaningRecord` (M-01: throws `LogicException` if none — never auto-creates); computes `duration_minutes`; room → `INSPECTED`, sets `last_cleaned_at` |
| `passInspection()` | Requires room `INSPECTED`; room → `VACANT_CLEAN` |
| `failInspection()` | Requires room `INSPECTED`; room → `VACANT_DIRTY`; auto-escalates a new `HIGH`-priority assignment |
| `skipInspection()` | Requires non-empty `reason_notes` (else `ValidationException` before the transaction opens); room → `VACANT_CLEAN` |
| `markOutOfOrder()` | Blocks if room is `CLEANING`; cancels any active assignment (M-02 pattern) |
| `releaseFromOutOfOrder()` | Requires room `OUT_OF_ORDER`; defaults target to `VACANT_DIRTY` |
| `autoMarkOccupied()` | **Non-throwing** (ADR-84) — catches `Throwable`, logs, never rethrows |
| `autoMarkDirtyOnCheckout()` | **Non-throwing** (ADR-84) — wraps room update + assignment creation in its own transaction; catches `Throwable`, logs, never rethrows |

All state-changing methods lock the room (and assignment, where relevant) with `lockForUpdate()` inside `DB::transaction()` per ADR-87. No method touches `FolioEntry`, `BookingPayment`, or any financial table.

**Deviation from plan:** the documented constructor listed `BusinessDateService` as a dependency, but no method in the approved spec actually references business-date logic (all timestamps use `now()`). Omitted to avoid an unused dependency — flag for ChatGPT review in case a future milestone expects it.

### `StayService` Integration Hooks

- `checkIn()`: calls `$this->housekeeping->autoMarkOccupied($lockedStay)` after `updateBookingStayStatus()`, before `return`.
- `checkOut()`: calls `$this->housekeeping->autoMarkDirtyOnCheckout($lockedStay)` after the finalise/update-status branch, before `return`.
- Both hooks execute inside the existing outer transaction, after all Phase 3 financial DML — no lock-order change, no new lock acquired outside `Room`/`HousekeepingAssignment`/`CleaningRecord`.

### `AppServiceProvider`

Registered `AuditObserver` for `HousekeepingAssignment` and `CleaningRecord`, matching the pattern used for every other domain model.

---

## Business Logic Notes

- Self-assignment is always permitted regardless of permission; assigning to **another** user requires `room.maintenance` (permission itself is seeded in Milestone 3 — until then, this branch is unreachable in production because no role holds it yet, and unit tests grant it directly via `Permission::firstOrCreate()` for isolation from M3 scope).
- `failInspection()` re-uses the original `CleaningRecord.reason` for the auto-escalated assignment, consistent with plan §7.4.
- `skipInspection()` validates non-empty notes **before** opening the transaction (fail fast, no wasted lock).

## Authorization

No `HousekeepingPolicy`, no seeder changes, no `RoomPolicy` update — all reserved for Milestone 3 per plan. The only authorization check present in this milestone is the in-service `room.maintenance` gate described above (`AuthorizationException`, maps to HTTP 403 automatically).

---

## Tests Added

### `tests/Unit/Services/HousekeepingServiceTest.php` — 24 tests

Covers all 9 methods: happy paths, precondition violations (wrong status, already assigned, self-assign vs. cross-assign authorization), `completeCleaning()` duration computation and the M-01 "no open record" `LogicException`, `failInspection()` priority escalation, `skipInspection()` empty-notes rejection, `markOutOfOrder()` cancelling active assignments, and both non-throwing hooks (happy path + forced failure via FK violation on `autoMarkDirtyOnCheckout`, asserted via `Log::shouldReceive('error')->once()`).

### `tests/Feature/StayServiceHousekeepingHookTest.php` — 2 tests

End-to-end through `BookingService` → `RoomAssignmentService` → `StayService::checkIn()/checkOut()`, asserting the room status side effects and the auto-created `CleaningRecord`-triggering `HousekeepingAssignment` row.

---

## Test Results

```
php artisan test tests/Unit/Services/HousekeepingServiceTest.php
  24 passed (46 assertions)

php artisan test tests/Feature/StayServiceHousekeepingHookTest.php
  2 passed (3 assertions)

php artisan test (full suite, run twice for stability)
  Run 1: 23 failed, 661 passed (3126 assertions)
  Run 2: 24 failed, 660 passed  — extra failure was PerStayAttributionTest,
         a UniqueConstraintViolationException on resources.code (Faker's
         numberBetween(100,999) unique-pool collision), not reproducible
         on a third run. Confirmed pre-existing test-infra fragility,
         unrelated to Housekeeping code (that test never touches Room
         status, HousekeepingService, or StayService hooks).
  Run 3: 23 failed, 661 passed — matches Run 1 exactly.
```

**Failure breakdown (stable 23):** 12 `RoomAvailabilityCheckerTest` + 11 `BookingManagementUiTest` — both are the same pre-existing hardcoded-date-drift classes documented in the Milestone 1 report. `DashboardTest` (1 failure at M1 baseline) is no longer failing — favorable date drift, not a regression fix.

**Comparison to M1 baseline (27 failed, 631 passed):** zero new deterministic failures. All 26 new tests (24 unit + 2 feature) pass consistently across 3 runs.

---

## Regression Risk

| Component | Risk | Assessment |
|-----------|------|-----------|
| `StayService.php` | LOW | 2 non-throwing hook calls added after existing DML; no change to existing checkIn/checkOut behavior or return values |
| `AppServiceProvider.php` | NONE | Additive observer registration only |
| Financial modules | NONE | Not touched — verified via file diff |
| `HousekeepingService.php` | NONE (new file) | Not called by any existing code path outside the two new hooks |

---

## Scope Control

**Implemented in Milestone 2:** `HousekeepingService` (9 methods) ✅, `StayService` hooks (2) ✅, `AuditObserver` registration (2 models) ✅, unit + feature tests ✅.

**NOT implemented (reserved for later milestones):** `HousekeepingPolicy`, `RolePermissionSeeder` revision, `RoomPolicy` update (M3); `HousekeepingController`, form requests, routes, `RoomAvailabilityRuleService`/`RoomAvailabilityCheckerService` updates (M4); Vue Board, nav entry (M5); remaining workflow/policy/UI feature tests (M6).

---

## Ready for ChatGPT Review

**Milestone 2 Service Layer: COMPLETE**

- `HousekeepingService`: ✅ all 9 methods implemented per spec, one documented deviation (unused `BusinessDateService` dependency omitted)
- `StayService` hooks: ✅ non-throwing, verified via feature test
- `AuditObserver`: ✅ registered for both new models
- Test suite: ✅ 26/26 new tests passing; 0 new regressions against M1 baseline
- Financial modules: ✅ not touched
- Not yet committed — awaiting review per no-commit-without-approval policy
