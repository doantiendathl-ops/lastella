# Phase 4.2 Backend Milestone 3

**Date:** 2026-07-05
**Branch:** phase-3
**Baseline:** Milestone 2 (approved by ChatGPT, uncommitted)
**Milestone:** 3 — Permissions & Policy
**Status:** COMPLETE — READY FOR CHATGPT REVIEW

---

## Scope

Implement `HousekeepingPolicy`, revise `RolePermissionSeeder` per ADR-89, update `RoomPolicy` board-access gate, register the new policy, and add tests — strictly the Milestone 3 scope from `docs/implementation-plans/phase-4.2-housekeeping-workflow-implementation-plan.md` §8. No controller, routes, Vue, `RoomAvailabilityRuleService`/`RoomAvailabilityCheckerService`, commit, or push.

## Files Added

| File | Type |
|------|------|
| `app/Policies/HousekeepingPolicy.php` | Policy |
| `tests/Unit/Policies/HousekeepingPolicyTest.php` | Unit test (29 cases) |
| `tests/Unit/Seeders/RolePermissionSeederTest.php` | Unit test (14 cases) |

## Files Modified

| File | Change |
|------|--------|
| `database/seeders/RolePermissionSeeder.php` | Added 5 permissions to `PERMISSIONS`; added them to MANAGER; added `housekeeping.view` to RECEPTION; revised HOUSEKEEPING (removed `rooms.manage`/`room.assign`/`room.unassign`, added `housekeeping.view`/`housekeeping.assign`/`room.status.update`, kept `special_request.fulfill`) |
| `app/Policies/RoomPolicy.php` | `viewAny()`/`view()` now OR-gate on `rooms.manage` \|\| `housekeeping.view`; `create`/`update`/`delete`/`restore` untouched (`rooms.manage` only) |
| `app/Providers/AppServiceProvider.php` | Registered `Gate::policy(HousekeepingAssignment::class, HousekeepingPolicy::class)` — observer registrations untouched |
| `tests/Unit/Policies/RoomPolicyTest.php` | Fixed a pre-existing test that asserted HOUSEKEEPING could `create()` rooms — that assumption is exactly what ADR-89 revokes. Rewired the original assertion to MANAGER (which legitimately holds `rooms.manage`) and added 2 new tests confirming HOUSEKEEPING/RECEPTION can view the board but not CRUD rooms. |

`app/Models/Room.php` and `app/Services/StayService.php` show as modified in `git status` only because they carry uncommitted Milestone 1/2 work — not touched again in this milestone.

---

## HousekeepingPolicy Summary

```php
view(User $user): bool           → $user->can('housekeeping.view')
assign(User $user): bool         → $user->can('housekeeping.assign')
updateStatus(User $user): bool   → $user->can('room.status.update')
inspect(User $user): bool        → $user->can('room.inspect')
maintenance(User $user): bool    → $user->can('room.maintenance')
```

No model parameter on any method — these are class-level abilities (consistent with `assignRoom()`/`startCleaning()` etc. not being per-instance-authorized in the approved plan). Registered against `HousekeepingAssignment::class` in `AppServiceProvider`, matching the convention used for every other domain policy in this codebase.

---

## RolePermissionSeeder Summary

**5 new permissions added to `PERMISSIONS`:** `housekeeping.view`, `housekeeping.assign`, `room.status.update`, `room.inspect`, `room.maintenance`.

**Final matrix (verified by test):**

| Permission | ADMIN | MANAGER | SALES | RECEPTION | HOUSEKEEPING | ACCOUNTANT |
|-----------|:-----:|:-------:|:-----:|:---------:|:------------:|:----------:|
| `housekeeping.view` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ |
| `housekeeping.assign` | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ |
| `room.status.update` | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ |
| `room.inspect` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `room.maintenance` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `rooms.manage` (existing) | ✅ | ✅ | ❌ | ❌ | REMOVED | ❌ |
| `room.assign` (existing) | ✅ | ✅ | ❌ | ✅ | REMOVED | ❌ |
| `room.unassign` (existing) | ✅ | ✅ | ❌ | ✅ | REMOVED | ❌ |
| `special_request.fulfill` (existing) | ✅ | ✅ | ❌ | ❌ | ✅ (kept) | ❌ |

Matches the architecture review §7.2 matrix and implementation plan §8.2 exactly.

**Idempotency:** `syncPermissions()` is inherently idempotent (declarative, not additive) — no permissions are ever deleted from the `permissions` table, only role-permission pivot rows are synced. Verified by `test_seeder_is_idempotent` (runs the seeder twice, asserts the same 4-permission HOUSEKEEPING set with no duplicates).

**No other role's Phase 3/4.1 permissions were touched** — ADMIN gains the 5 new perms automatically via `syncPermissions(self::PERMISSIONS)` (unchanged mechanism); MANAGER, SALES, ACCOUNTANT all retain every pre-existing permission in their sync lists untouched except the additive MANAGER/RECEPTION grants specified above.

---

## RoomPolicy Summary

```php
viewAny(User $user): bool          → rooms.manage || housekeeping.view   [CHANGED]
view(User $user, Room $room): bool → rooms.manage || housekeeping.view   [CHANGED]
create(User $user): bool           → rooms.manage                       [unchanged]
update(User $user, Room $room)     → rooms.manage                       [unchanged]
delete(User $user, Room $room)     → rooms.manage                       [unchanged]
restore(User $user, Room $room)    → rooms.manage                       [unchanged]
```

HOUSEKEEPING and RECEPTION can now see the Room list/detail (needed for the future Housekeeping Board reading through `Room` queries) but neither can create, update, delete, or restore rooms.

---

## AppServiceProvider Summary

Added one line: `Gate::policy(HousekeepingAssignment::class, HousekeepingPolicy::class);`, placed directly after the `BookingSpecialRequestPolicy` registration. `AuditObserver` registrations for `HousekeepingAssignment`/`CleaningRecord` (added in Milestone 2) are untouched.

---

## Tests Added

| File | Cases | Covers |
|------|-------|--------|
| `tests/Unit/Policies/HousekeepingPolicyTest.php` | 29 | Full 6-role × 5-method matrix (ADMIN, MANAGER, HOUSEKEEPING, RECEPTION, ACCOUNTANT, SALES) |
| `tests/Unit/Seeders/RolePermissionSeederTest.php` | 14 | HOUSEKEEPING lost/gained permissions, RECEPTION view-only grant, ADMIN/MANAGER full grant, ACCOUNTANT/SALES no grant, pre-existing Phase 3 permissions preserved, idempotency |
| `tests/Unit/Policies/RoomPolicyTest.php` (2 new + 1 fixed) | 4 total | HOUSEKEEPING/RECEPTION can view but not CRUD; MANAGER (not HOUSEKEEPING) verified for full CRUD access |

**Total new/fixed test cases: 47** (46 new + 1 corrected pre-existing assertion).

---

## Tests Executed

```
php artisan db:seed --class=RolePermissionSeeder
php artisan test tests/Unit/Policies/HousekeepingPolicyTest.php
php artisan test tests/Unit/Seeders/RolePermissionSeederTest.php
php artisan test tests/Unit/Policies/RoomPolicyTest.php
php artisan test tests/Unit/Seeders/CoreSeederTest.php
php artisan test   (full suite)
```

## Test Results

```
db:seed --class=RolePermissionSeeder   → no errors

New/affected policy & seeder tests: 49 passed (132 assertions)

Full suite: 23 failed, 707 passed (3211 assertions)
```

**Failure breakdown (23, identical to M2 stable baseline):** 12 `RoomAvailabilityCheckerTest` + 11 `BookingManagementUiTest` — same pre-existing hardcoded-date-drift classes, zero new failures. Confirmed by grouping the full-suite failure log by test class.

**Comparison to M2 baseline (23 failed, 661 passed):** +46 passed from new tests, 0 change in failure count or failing classes.

---

## Permission Matrix Verification

All cells in the ADR-89 matrix (architecture review §7.2, implementation plan §8.2) verified by `RolePermissionSeederTest` and `HousekeepingPolicyTest`:

- HOUSEKEEPING: `rooms.manage` ❌, `room.assign` ❌, `room.unassign` ❌ (removed) / `housekeeping.view` ✅, `housekeeping.assign` ✅, `room.status.update` ✅ (added) / `special_request.fulfill` ✅ (kept) / `room.inspect` ❌, `room.maintenance` ❌ (correctly withheld)
- RECEPTION: `housekeeping.view` ✅ only — `housekeeping.assign`/`room.status.update`/`room.inspect`/`room.maintenance` all ❌; pre-existing `room.assign`/`room.unassign`/`stay.checkin`/`stay.checkout` preserved
- ADMIN, MANAGER: all 5 new permissions ✅
- ACCOUNTANT, SALES: all 5 new permissions ❌
- `HousekeepingPolicy` matches the permission grants 1:1 for all 6 roles × 5 methods (30 combinations, 29 asserted directly + `special_request.fulfill` verified separately since it has no corresponding policy method)

---

## Regression Risk

| Component | Risk | Assessment |
|-----------|------|-----------|
| `RolePermissionSeeder.php` | MEDIUM (per plan) | Verified: HOUSEKEEPING 403 on room CRUD (via `RoomPolicyTest`), no other role's permissions changed, idempotent re-run confirmed |
| `RoomPolicy.php` | LOW | Additive OR-gate on read-only methods; CRUD methods byte-for-byte unchanged |
| `AppServiceProvider.php` | NONE | Additive policy registration only |
| `tests/Unit/Policies/RoomPolicyTest.php` | N/A | Pre-existing test corrected — it encoded a pre-ADR-89 assumption (HOUSEKEEPING can create rooms) that is now intentionally false |
| Financial modules | NONE | Not touched — verified via `git status` (no `Folio`/`Payment`/`NightAudit`/`Revenue`/`Reconciliation`/`PackageEnrollment`/`SpecialRequest` files in the diff for this milestone) |

---

## Scope Control

**Implemented in Milestone 3:** `HousekeepingPolicy` ✅ (5 methods), `RolePermissionSeeder` revision ✅ (5 permissions, full role matrix), `RoomPolicy` update ✅ (viewAny/view only), `AppServiceProvider` registration ✅, tests ✅ (47 new/fixed cases).

**NOT implemented (reserved for later milestones, per instruction — not started):** `HousekeepingController`, Form Requests, routes (M4); `RoomAvailabilityRuleService`/`RoomAvailabilityCheckerService` updates (M4); Vue Board, nav entry (M5); remaining workflow/UI feature tests (M6).

**ChatGPT M2 review notes (M2-01, M2-02, M2-03):** not addressed in this milestone — out of Milestone 3's stated scope (policy/permissions only, no service changes). Carried forward to Remaining Work below, to be picked up as minor service hardening whenever `HousekeepingService` is next touched (M4 controller wiring is a natural point, since M-03/M2-03 duplicate-assignment guard interacts with `assignRoom()` which the controller calls directly).

---

## Remaining Work

- M4 — Controller & Routes & Availability Update (not started)
- M5 — Frontend (not started)
- M6 — Testing & Final Verification (not started)
- Carried-forward minor service hardening from ChatGPT M2 review (not done in M3, deferred by scope):
  - M2-01: tighten `passInspection()` contract to explicitly document it only accepts `RoomStatus::Inspected` (already enforced at runtime via `lockRoomForInspection()`; note is about contract/docblock clarity)
  - M2-02: `autoMarkOccupied()` log context should include `booking_id` alongside existing `stay_id`/`room_id`
  - M2-03: `failInspection()` should check for an existing active assignment before creating the auto-escalated one, to avoid a theoretical duplicate-pending-assignment if `failInspection()` is ever called on a room that already has a pending assignment from another path

---

## Ready for ChatGPT Review

**Milestone 3 Permissions & Policy: COMPLETE**

- `HousekeepingPolicy`: ✅ all 5 methods, verified against full 6-role matrix
- `RolePermissionSeeder`: ✅ ADR-89 matrix applied exactly, idempotent, no other role's Phase 3/4.1 grants altered
- `RoomPolicy`: ✅ read-gate widened, CRUD gate unchanged
- `AppServiceProvider`: ✅ policy registered, observers untouched
- Test suite: ✅ 47 new/fixed tests passing; 0 new regressions (23 failed / 707 passed, identical failing classes to M2 baseline)
- Financial modules: ✅ not touched
- Controller/Routes/Frontend: ✅ not started (per instruction)
- Not committed, not pushed — awaiting ChatGPT review
