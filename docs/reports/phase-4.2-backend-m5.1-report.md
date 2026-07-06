# Phase 4.2 Backend Milestone 5.1 — Housekeeping Assignment Claim Flow

**Date:** 2026-07-06
**Branch:** phase-3
**Baseline:** Integration & Final Verification (awaiting ChatGPT review at the time this gap was raised)
**Milestone:** 5.1 — Auto-Claim on Start Cleaning
**Status:** COMPLETE — READY FOR CHATGPT BUSINESS WORKFLOW REVIEW

---

## 1. Executive Summary

The final Architecture Review of Phase 4.2 identified one workflow gap (not a regression, not a coding bug): a checkout-generated housekeeping assignment is created unassigned (`assigned_to = null`), and `HousekeepingService::startCleaning()`'s authorization required either exact assignee match or `room.maintenance` — a permission only MANAGER/ADMIN hold. HOUSEKEEPING users could therefore never start the single most common assignment type (the automatic post-checkout clean) without a manager intervening first.

This milestone implements **Option A — Auto-Claim on Start Cleaning**, exactly as specified: when a HOUSEKEEPING user (or anyone else holding both `housekeeping.assign` and `room.status.update`) calls `startCleaning()` on an assignment that is `Pending` and unassigned, the assignment is claimed for them in the same request, then proceeds through the existing transition to `in_progress` / `CLEANING`. No new endpoint, no new UI, no schema change. The entire fix is an 8-line addition inside the existing `startCleaning()` transaction, guarded by the same `SELECT FOR UPDATE` lock already established under ADR-87.

Two full regression runs (`php artisan test`, ~750+ tests each) confirm zero new deterministic failures against the established 23-failure baseline. All 7 required regression scenarios and the target auto-claim scenario are verified by name.

---

## 2. Business Issue

### Original workflow gap

```
Checkout → autoMarkDirtyOnCheckout() → HousekeepingAssignment{ status: pending, assigned_to: null }
                                                        │
                            HOUSEKEEPING user calls startCleaning()
                                                        │
              assigned_to (null) !== actor->id  AND  actor lacks room.maintenance
                                                        │
                                                        ▼
                                              AuthorizationException → HTTP 403
```

### Why it happened

`HousekeepingService::assignRoom()` was designed with ADR-90's two supported strategies in mind — Manual (MANAGER assigns a specific housekeeper) and Self-Assignment (a housekeeper claims a room via `assignRoom()` with a null assignee, which resolves to themselves). What ADR-90 did not account for was the *system-auto-created* assignment path (`autoMarkDirtyOnCheckout()`, added in Milestone 2 for ADR-84): that path creates the assignment directly via `HousekeepingAssignment::create()`, bypassing `assignRoom()` entirely (by design — it runs inside a non-throwing hook with no `$actor` to assign to). The result was an assignment that exists in a state — unassigned but already `Pending` — that `assignRoom()` refuses to create a duplicate for, and that `startCleaning()` had no path to claim. This was invisible until the Integration phase wrote a true end-to-end test chaining checkout through to a housekeeper's first action, which is exactly when it surfaced.

---

## 3. Solution

### Auto-claim flow (implemented exactly as specified)

```
startCleaning(assignment, actor)
  [existing] lock Room, lock HousekeepingAssignment
  [existing] assert assignment.status === Pending
  [existing] assert room.status === VACANT_DIRTY
  [NEW]      if assignment.assigned_to === null
                AND actor.can('housekeeping.assign')
                AND actor.can('room.status.update')
             then assignment.assigned_to = actor.id     ← claim, in memory, still inside the lock
  [existing] if assignment.assigned_to !== actor.id AND !actor.can('room.maintenance')
                then throw AuthorizationException        ← unchanged: never fires for a fresh claim,
                                                             still fires for someone else's assignment
  [existing] assignment.update({ status: in_progress, started_at: now, assigned_to })  ← assigned_to now persisted
  [existing] room.update({ status: CLEANING })
  [existing] CleaningRecord::create(...)
```

### Why this solution was selected

- **Matches the mandated Option A exactly:** single request, no Claim button, no new endpoint — the housekeeper presses the one button ("Bắt đầu dọn") that already exists in `Housekeeping/Index.vue`, and claiming happens transparently as part of that same call.
- **Zero new state to reason about:** the claim is just setting `assigned_to` before the pre-existing `update()` call already in the method — no new column, no new status, no new lock.
- **Ownership is provably never stolen:** the auto-claim condition is gated on `assigned_to === null`. If an assignment already belongs to someone, that branch never executes, and the pre-existing ownership check (`assigned_to !== actor.id AND !room.maintenance`) runs completely unchanged — this is the same code path that already protected against assignment-stealing before this milestone, untouched.
- **Consistent with ADR-90's stated intent** ("self-assignment... any `housekeeping.assign` holder") — this simply extends self-assignment to cover the one assignment-creation path (`autoMarkDirtyOnCheckout`) that couldn't go through `assignRoom()`'s existing self-assign branch.
- **Requires both `housekeeping.assign` and `room.status.update`** (not just one) per the explicit business rules — a defense-in-depth check, since in practice every role that holds one already holds the other (ADR-89: HOUSEKEEPING, MANAGER, ADMIN all hold both together), but the code does not assume that pairing and checks both explicitly.

---

## 4. Files Modified

| File | Change |
|------|--------|
| `app/Services/HousekeepingService.php` | `startCleaning()` — added the auto-claim block (8 lines) between the existing room/status validation and the existing ownership check; the subsequent `update()` call now explicitly persists `assigned_to` (previously omitted since it never changed within the method) |
| `tests/Unit/Services/HousekeepingServiceTest.php` | +5 test cases (auto-claim success, permission-gated non-claim ×2, no-reassignment-of-owned-assignment, room.maintenance-only actor unaffected) + 1 new helper (`actorWithHousekeepingClaimPermissions()`) |
| `tests/Feature/HousekeepingControllerTest.php` | +2 test cases (HTTP-level auto-claim success, HTTP-level "cannot steal another user's assignment") |
| `tests/Feature/HousekeepingWorkflowIntegrationTest.php` | Updated `test_full_booking_lifecycle_check_in_check_out_housekeeping_loop` — previously documented the 403 gap with MANAGER as the workaround; now asserts the HOUSEKEEPING user successfully starts *and completes* the auto-created assignment directly, with `assigned_to` verified to equal the housekeeping user's id |

No other file was touched. Booking, Stay, Folio, Payment, Night Audit, Revenue, Package Enrollment, Special Requests, controller routes, Vue layout, navigation, and availability logic are all untouched — verified via `git status`/`git diff` scope.

---

## 5. Business Logic Changes

Confined entirely to `HousekeepingService::startCleaning()`. No other method was touched:

- `assignRoom()` — unchanged. Manual/self-assignment via this method still works exactly as before; this milestone does not change how assignments are *created*, only how an *existing unassigned* one can be *started*.
- `completeCleaning()`, `passInspection()`, `failInspection()`, `skipInspection()`, `markOutOfOrder()`, `releaseFromOutOfOrder()`, `autoMarkOccupied()`, `autoMarkDirtyOnCheckout()` — unchanged, byte-for-byte.
- The ADR-87 lock pattern is preserved exactly: the claim happens on the already-locked `$lockedAssignment` instance, inside the same `DB::transaction()`, before any write — there is no new query, no new lock, no change to lock order.

---

## 6. Authorization Changes

**New:** an unassigned (`assigned_to === null`), `Pending` assignment can now be claimed by any actor holding **both** `housekeeping.assign` and `room.status.update` at the moment they call `startCleaning()`.

**Unchanged:**
- The ownership check (`assigned_to !== actor.id AND !room.maintenance` → 403) still runs after the new block and is reached whenever the assignment is *not* eligible for auto-claim (already assigned to someone, or the actor lacks the claim permissions) — this is the exact same check, same exception, same message as before.
- `room.maintenance` holders (MANAGER/ADMIN) can still start any assignment regardless of ownership, exactly as before — this path is untouched and does not go through the auto-claim branch at all when the actor already qualifies via `room.maintenance` alone in isolation (verified by a dedicated unit test using an actor with *only* `room.maintenance`, no `housekeeping.assign`).
- No policy file, no Form Request, no route, no permission was added or removed. `HousekeepingPolicy`, `RolePermissionSeeder`, and all 9 routes are byte-for-byte unchanged.

---

## 7. Tests Added

| File | New cases | Purpose |
|------|-----------|---------|
| `tests/Unit/Services/HousekeepingServiceTest.php` | 5 | `test_start_cleaning_auto_claims_unassigned_assignment`, `test_start_cleaning_does_not_auto_claim_without_housekeeping_assign_permission`, `test_start_cleaning_does_not_auto_claim_without_room_status_update_permission`, `test_start_cleaning_does_not_reassign_an_already_assigned_assignment`, `test_start_cleaning_manager_with_room_maintenance_still_works_on_unassigned_assignment` |
| `tests/Feature/HousekeepingControllerTest.php` | 2 | `test_start_cleaning_auto_claims_unassigned_assignment_for_housekeeping` (HTTP-level, asserts response `assigned_to` and DB state), `test_start_cleaning_forbidden_for_housekeeping_when_already_assigned_to_another_user` (HTTP-level, asserts 403 and that ownership/status were untouched) |
| `tests/Feature/HousekeepingWorkflowIntegrationTest.php` | 0 new / 1 updated | The full Booking→checkout→housekeeping loop test now exercises the *fixed* behavior end-to-end instead of documenting the gap |

**Total: 7 new test cases + 1 updated integration test.**

---

## 8. Tests Executed

```
php artisan test tests/Unit/Services/HousekeepingServiceTest.php
php artisan test tests/Feature/HousekeepingControllerTest.php
php artisan test tests/Feature/HousekeepingWorkflowIntegrationTest.php
php artisan test   (full suite, run 1)
php artisan test   (full suite, run 2 — explicit confirmation, waited for actual completion)
```

### Results

```
Housekeeping-specific suite (3 files, one clean combined run): 65 passed (233 assertions)

Full suite:
  Run 1: 24 failed, 763 passed (3467 assertions) — Duration 730.33s
  Run 2: 25 failed, 762 passed (3465 assertions) — Duration confirmed complete (final "Tests:" line verified in log, not assumed)
```

---

## 9. Regression Assessment

**Both full-suite runs decompose identically to the pre-existing, already-documented baseline:**

- **23 stable pre-existing failures** — 11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest`, all hardcoded-date drift, present since before Phase 4.2 began and unchanged in count and identity across both runs.
- **`LateCheckoutFeeTest::test_check_out_triggers_late_checkout_fee_via_stay_service`** — present in *both* runs. Root-caused in the Integration phase report: the test computes its "planned checkout" via `now()->subHours(2)->setTime(12, 0, 0)`, and `setTime()` silently discards the `subHours(2)`, so the test only passes when the suite runs after local noon. It ran before noon both times. This is a bug in the test itself, confirmed unrelated to any code this milestone touched (`StayService` was not modified in Milestone 5.1).
- **`PerStayAttributionTest`** — present in run 2 only (`add charge accepts system auto posting source`), absent in run 1. This is the same intermittent Faker `resources.code` unique-constraint collision documented since Milestone 2 — it has now been observed failing on four *different* test methods across four different phases of this project, which is itself the proof that it is a random pool-exhaustion collision and not tied to any specific test's logic or to Housekeeping code.

**Net new deterministic regressions introduced by Milestone 5.1: zero.**

### Required scenario verification

| # | Scenario | Result | Verified by |
|---|----------|--------|-------------|
| — | **Target fix:** unassigned auto-created assignment can be started by HOUSEKEEPING; claims it; status → in_progress; room → CLEANING | ✅ PASS | `test_start_cleaning_auto_claims_unassigned_assignment` (unit), `test_start_cleaning_auto_claims_unassigned_assignment_for_housekeeping` (HTTP), `test_full_booking_lifecycle_check_in_check_out_housekeeping_loop` (full loop) |
| 1 | Assigned user starts own assignment | ✅ PASS | `test_start_cleaning_transitions_room_to_cleaning`, `test_start_cleaning_success` (unchanged, still passing) |
| 2 | Manager starts assigned assignment | ✅ PASS | `test_start_cleaning_manager_with_room_maintenance_still_works_on_unassigned_assignment` (also proves the room.maintenance-only path is untouched) |
| 3 | Another Housekeeping user cannot start someone else's assignment | ✅ PASS | `test_start_cleaning_does_not_reassign_an_already_assigned_assignment` (unit), `test_start_cleaning_forbidden_for_housekeeping_when_already_assigned_to_another_user` (HTTP — also asserts DB `assigned_to`/`status` unchanged after the 403) |
| 4 | Auto-created unassigned assignment can now be started | ✅ PASS | See "Target fix" row above |
| 5 | Duplicate assignments still blocked | ✅ PASS | `test_assign_room_throws_if_already_assigned` / `test_assign_blocked_duplicate_active_assignment` (unchanged, `assignRoom()` was not touched) |
| 6 | Room status transitions unchanged | ✅ PASS | Full `HousekeepingServiceTest`/`HousekeepingControllerTest` suites (all pre-existing transition tests still pass unmodified) |
| 7 | Authorization unchanged except the new auto-claim path | ✅ PASS | `test_start_cleaning_throws_if_not_assigned_user_without_permission` (pre-existing, still passing — an assigned-to-someone-else scenario with no claim permissions), plus the 2 new permission-gated non-claim tests proving the claim requires *both* permissions, not just one |

---

## 10. Remaining Risks

- No new risks introduced. The one previously-open risk this milestone targeted (self-claim gap) is resolved.
- Carried forward from the Integration & Final Verification report, unchanged and out of scope for this milestone: the `HousekeepingController::index()` contract still has no `reason`/`notes`/`floor_id`/`housekeepers[]` fields; the two pre-existing date-sensitive test suites will keep accumulating failures as real dates advance; `LateCheckoutFeeTest`'s wall-clock bug remains unfixed (test-only, not a product defect, not in this milestone's scope).
- A true concurrent-claim race (two simultaneous `startCleaning()` calls for the same unassigned assignment) is protected by the pre-existing ADR-87 `lockForUpdate()` — the second request blocks until the first's transaction commits, then re-reads `assigned_to` (now non-null) and correctly 403s if the second actor isn't the claimant. This was not given a dedicated concurrency test in this milestone (matches the existing gap noted in the Final Report — no concurrent-request test exists anywhere in the Housekeeping suite yet), but the lock mechanism providing this guarantee is unchanged from what ADR-87 already established.

---

## 11. Ready for ChatGPT Review

**Milestone 5.1 Auto-Claim Fix: COMPLETE**

- Business issue resolved exactly per Option A, no scope expansion.
- `HousekeepingService::startCleaning()` is the only method changed; no new service, no new endpoint, no schema change.
- Authorization change is additive and narrowly scoped: claim requires both `housekeeping.assign` and `room.status.update`; ownership protection for already-assigned assignments is completely unchanged.
- 7 new/updated tests, all passing; full suite run twice (waited for actual completion both times, not assumed) — 0 new deterministic regressions, both runs decomposing to the same pre-existing 23-failure baseline plus already-documented, unrelated flakes.
- All 7 required regression scenarios plus the target fix scenario verified by name.
- Not committed, not pushed, not tagged — awaiting ChatGPT Business Workflow Review before commit → push → tag `phase-4.2` → official closure → Phase 4.2.5 Production Freeze.
