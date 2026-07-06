# Phase 4.2 Architecture Baseline

**Date:** 2026-07-06
**Branch:** phase-3
**Baseline Tag (previous):** phase-4.1
**Status:** DRAFT BASELINE — pending ChatGPT Architecture Review; commit/tag `phase-4.2` not yet created

---

## 1. Final Module Architecture

Phase 4.2 adds the Housekeeping Workflow domain to Lastella PMS: automatic room-status transitions on check-in/checkout, a housekeeping assignment/cleaning-record data model, a permission-gated service and controller layer, and a Housekeeping Board UI. The domain is additive and layered exactly like every prior Phase 4.x domain:

```
Migrations (3) → Enums (4) → Models (2) → Factories (2)   [M1]
        │
        ▼
HousekeepingService (9 methods)   ← StayService (2 non-throwing hooks)   [M2]
        │
        ▼
HousekeepingPolicy + RolePermissionSeeder revision + RoomPolicy OR-gate   [M3]
        │
        ▼
HousekeepingController (9 thin actions) + 6 Form Requests + 9 routes
        + RoomAvailabilityRuleService/CheckerService CLEANING branch   [M4]
        │
        ▼
Housekeeping/Index.vue + HousekeepingActionDialog.vue
        + roomStatusBadges.js + vietnameseLabels.js extension
        + RoomAvailability/Index.vue CLEANING badge + AppLayout.vue nav   [M5]
        │
        ▼
Integration & Final Verification: 5 new end-to-end tests,
        1 documented workflow gap, 2 unused-import cleanups   [this phase]
```

**Stack:** Laravel 11 / PHP 8.3 · Inertia.js · Vue 3 SFC (Composition API, `<script setup>`) · Spatie Laravel Permission — unchanged from Phase 4.1.

**Pattern:** Repository-free service layer, identical to `SpecialRequestService`'s precedent. `HousekeepingService` owns all business logic and locking. `HousekeepingController` delegates entirely to the service — verified thin by code review in the Integration phase (no business logic, no direct model writes). Validation lives exclusively in Form Request classes; authorization is checked in each Form Request's `authorize()` (calling the same `HousekeepingPolicy` ability the controller would), matching the `StoreBookingSpecialRequestRequest` convention from Phase 4.1.

---

## 2. ADR Compliance (ADR-84 → ADR-90)

| ADR | Decision | Compliance |
|-----|---------|-----------|
| **ADR-84** | Automatic room status: `checkIn()` → OCCUPIED, `checkOut()` → VACANT_DIRTY, both via non-throwing hooks inside the existing transaction | ✅ `HousekeepingService::autoMarkOccupied()`/`autoMarkDirtyOnCheckout()`, called from `StayService::checkIn()`/`checkOut()` after all existing DML. Both wrapped in try/catch, log on failure, never rethrow. Verified via `StayServiceHousekeepingHookTest` and this phase's full-loop integration test. |
| **ADR-85** | `housekeeping_assignments` as a separate table, not columns on `rooms` | ✅ Implemented in M1. Supports assignment history, priority, notes, and re-assignment without touching `rooms`. |
| **ADR-86** | `cleaning_records` as a typed table, not `audit_logs` JSON queries | ✅ Implemented in M1. `AuditObserver` still logs generic CRUD on both new models (M2) — the two systems coexist, not conflict. |
| **ADR-87** | Every status-transition method acquires `SELECT FOR UPDATE` on `Room` (and `HousekeepingAssignment` where relevant) inside `DB::transaction()`, re-validates the precondition under lock | ✅ All 8 mutating `HousekeepingService` methods follow this pattern exactly (verified by code inspection in M2 and re-confirmed unchanged through M4/M5/Integration — no method was touched after M2 except the M4 hardening additions, which preserve the lock pattern). |
| **ADR-88** | CLEANING blocks room assignment; gets a distinct `'cleaning'` availability label, not `'out_of_order'` | ✅ `RoomAvailabilityRuleService::isRoomUnavailable()` includes `RoomStatus::Cleaning` (M4). `RoomAvailabilityCheckerService::check()` branches on `RoomStatus::Cleaning` before the generic `isRoomUnavailable()` path, returning `'cleaning'` (M4). Frontend badge is visually distinct amber, not the gray `out_of_order` styling (M5). Verified end-to-end this phase via `HousekeepingWorkflowIntegrationTest::test_cleaning_room_is_unavailable_on_booking_room_assignment_board` (the one integration point — the Booking Room Assignment board — that had no dedicated test before this phase). |
| **ADR-89** | HOUSEKEEPING permission revision: remove `rooms.manage`/`room.assign`/`room.unassign`; add `housekeeping.view`/`housekeeping.assign`/`room.status.update`; keep `special_request.fulfill` | ✅ Implemented in M3, exact matrix verified by `RolePermissionSeederTest` (14 cases) and `HousekeepingPolicyTest` (29 cases). `RoomPolicy::viewAny()`/`view()` widened to `rooms.manage \|\| housekeeping.view`; CRUD methods unchanged. |
| **ADR-90** | Assignment strategy: Manual (MANAGER/ADMIN assigns to any user with `room.maintenance`) + Self-Assignment (any `housekeeping.assign` holder assigns to self) in Phase 4.2; Round Robin/Workload/Floor-based reserved for a future phase | ✅ `HousekeepingService::assignRoom()` implements exactly this: self-assign always allowed, cross-assign requires `room.maintenance`. No auto-assignment strategy implemented (by design). **New finding this phase:** the *auto-created* checkout assignment (via `autoMarkDirtyOnCheckout()`) is unassigned and currently has no self-claim path for HOUSEKEEPING-only users — see §9 Known Technical Debt. |

**All 6 ADRs are in full compliance except the newly-surfaced self-claim gap under ADR-90's self-assignment intent**, which is a coverage gap in the *auto-created* path specifically (manually-created assignments via `assignRoom()` fully support self-assignment as designed).

---

## 3. Service Relationships

```
HousekeepingService
├── depends on: (none — no constructor dependencies; pure domain logic + Eloquent)
├── consumed by: HousekeepingController (all 9 actions)
├── consumed by: StayService (2 hooks: autoMarkOccupied, autoMarkDirtyOnCheckout)
└── never touches: FolioService, BookingPaymentService, NightAuditPipeline,
                    RevenueReportService, ReconciliationService,
                    PackageEnrollmentService, SpecialRequestService

StayService (Phase 3/4.1, modified in M2 only)
├── existing dependencies: BookingService, FolioService, BusinessDateService,
│                          RoomChargePostingJob, LateCheckoutFeePostingJob,
│                          EarlyCheckinFeePostingJob, SpecialRequestService
└── + HousekeepingService (M2 addition — same App\Services namespace, no import needed)

RoomAvailabilityCheckerService (Phase 3, modified in M4 only)
└── depends on: RoomAvailabilityRuleService (existing — gained the CLEANING case in isRoomUnavailable())

RoomAssignmentService (Phase 3, NOT modified)
└── automatically inherits CLEANING-blocks-assignment behavior via the shared
    RoomAvailabilityRuleService::isRoomUnavailable() call already in getRoomBoard()
```

No new service-to-service dependency was introduced beyond `StayService → HousekeepingService` (a one-directional, non-throwing hook call — `HousekeepingService` has no reverse dependency on `StayService`).

---

## 4. Controller Responsibilities

`HousekeepingController` (`app/Http/Controllers/Admin/HousekeepingController.php`) — 9 actions, each responsible for exactly: resolve the Form Request (authorization + validation happen there), call one `HousekeepingService` method, return a response. No action contains a conditional business rule, a direct Eloquent write, or a query beyond `index()`'s read-only room list.

| Action | HTTP | Route name | Delegates to |
|--------|------|-----------|---------------|
| `index` | GET | `admin.housekeeping.index` | (read-only projection, no service call — no read-side service method exists) |
| `assign` | POST | `admin.housekeeping.assign` | `HousekeepingService::assignRoom()` |
| `startCleaning` | PATCH | `admin.housekeeping.start` | `HousekeepingService::startCleaning()` |
| `completeCleaning` | PATCH | `admin.housekeeping.complete` | `HousekeepingService::completeCleaning()` |
| `passInspection` | PATCH | `admin.housekeeping.inspect.pass` | `HousekeepingService::passInspection()` |
| `failInspection` | PATCH | `admin.housekeeping.inspect.fail` | `HousekeepingService::failInspection()` |
| `skipInspection` | PATCH | `admin.housekeeping.inspect.skip` | `HousekeepingService::skipInspection()` |
| `markOutOfOrder` | PATCH | `admin.housekeeping.out-of-order` | `HousekeepingService::markOutOfOrder()` |
| `releaseFromOutOfOrder` | PATCH | `admin.housekeeping.release` | `HousekeepingService::releaseFromOutOfOrder()` |

**Deliberate deviation from the original plan document:** `index()` returns `Inertia::render()` (M5) but the 8 mutation actions return raw `JsonResponse`, not Inertia redirects. This is intentional — the frontend calls them via `axios` against their JSON contract, avoiding any change to already-approved controller code across milestones. Documented in the M4 and M5 reports.

**Namespace/path deviation (flagged, not hidden, in M4/M5 reports):** the controller lives at `App\Http\Controllers\Admin\HousekeepingController` and the Vue page at `resources/js/Pages/Admin/Housekeeping/Index.vue`, not the flat paths given in early planning documents — matching the established convention that every other admin feature in this codebase uses the `Admin\` namespace / `Pages/Admin/` directory.

---

## 5. Authorization Model

```
                    HousekeepingPolicy (class-level abilities only — no model instance)
                    ├── view(User)          → housekeeping.view
                    ├── assign(User)        → housekeeping.assign
                    ├── updateStatus(User)  → room.status.update
                    ├── inspect(User)       → room.inspect
                    └── maintenance(User)   → room.maintenance

                    Registered: Gate::policy(HousekeepingAssignment::class, HousekeepingPolicy::class)
```

**Permission matrix (final, ADR-89):**

| Permission | ADMIN | MANAGER | SALES | RECEPTION | HOUSEKEEPING | ACCOUNTANT |
|-----------|:-----:|:-------:|:-----:|:---------:|:------------:|:----------:|
| `housekeeping.view` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ |
| `housekeeping.assign` | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ |
| `room.status.update` | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ |
| `room.inspect` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `room.maintenance` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `rooms.manage` (existing) | ✅ | ✅ | ❌ | ❌ | **removed** | ❌ |
| `room.assign` (existing) | ✅ | ✅ | ❌ | ✅ | **removed** | ❌ |
| `room.unassign` (existing) | ✅ | ✅ | ❌ | ✅ | **removed** | ❌ |
| `special_request.fulfill` (existing) | ✅ | ✅ | ❌ | ❌ | ✅ (kept) | ❌ |

**Additional in-service authorization (beyond the policy):** `HousekeepingService::assignRoom()`/`startCleaning()` enforce the ADR-90 self-assign-only rule for non-`room.maintenance` actors directly in the service (not the policy), throwing `AuthorizationException` (auto-renders as HTTP 403). This is intentional service-layer defense-in-depth for a rule that depends on runtime data (who the assignee is), not just the actor's static permission set.

**Frontend/backend parity:** `HousekeepingController::index()` computes the `can{}` object from the exact same `$user->can(ability, HousekeepingAssignment::class)` calls the mutation routes' Form Requests use for their own `authorize()`. There is no independent frontend permission source — `Housekeeping/Index.vue`'s button visibility and `AppLayout.vue`'s nav-item visibility both read this one `can{}`/`auth.user.permissions` data path, so they cannot structurally drift from the backend.

**`RoomPolicy` side effect:** `viewAny()`/`view()` widened to `rooms.manage \|\| housekeeping.view`; `create`/`update`/`delete`/`restore` untouched. HOUSEKEEPING and RECEPTION can read the room list/detail (needed for board data) but cannot CRUD rooms — verified at both the policy-unit and HTTP-route level.

---

## 6. Availability Model

```
Room.status (8 values) ──┐
                          ├──► RoomAvailabilityRuleService::isRoomUnavailable()
RoomAssignment (booking) ┘         │
                                    ├─ OUT_OF_ORDER, OUT_OF_SERVICE, CLEANING → true
                                    └─ everything else → false (assignment-conflict logic separate)

RoomAvailabilityCheckerService::check()  [Room Availability page]
  for each room:
    if room.status === CLEANING → availability = 'cleaning'          (NEW, ADR-88)
    else if isRoomUnavailable(room) → availability = 'out_of_order'
    else → resolveAvailability(checkedIn, reserved, ...) → available/reserved/occupied/overstay/overlap/multi_booking

RoomAssignmentService::getRoomBoard()  [Booking Room Assignment board]
  for each room:
    if isRoomUnavailable(room) → availability_status = 'unavailable'  (CLEANING included automatically —
                                                                        no code change needed here, same
                                                                        shared rule service)
    else if conflict → 'conflict'
    else if isCurrentBookingAssigned → 'current_booking'
    else → 'available'
```

Two independent read models (`RoomAvailabilityCheckerService` for the dedicated availability page, `RoomAssignmentService` for the per-booking room board) both consume the single `RoomAvailabilityRuleService::isRoomUnavailable()` predicate — CLEANING became "unavailable" everywhere the moment ADR-88 was implemented in M4, with zero additional code in `RoomAssignmentService`. Only `RoomAvailabilityCheckerService` needed a new branch, because it alone distinguishes *why* a room is unavailable with a dedicated label (`'cleaning'` vs `'out_of_order'`); `getRoomBoard()` only needs the binary "can I assign this room" answer.

**Frontend:** `resources/js/Support/roomStatusBadges.js` is the single source of truth for all 8 `RoomStatus` badge colors, consumed by both `Housekeeping/Index.vue` (keyed on raw `room.status`) and `RoomAvailability/Index.vue` (the `cleaning` availability style pulls its colors from this module rather than duplicating them).

---

## 7. Workflow Diagram

```
                         ┌─────────────────────────────────────┐
                         │         StayService::checkIn()       │
                         └───────────────────┬───────────────────┘
                                             │ autoMarkOccupied() [non-throwing]
                                             ▼
                                        OCCUPIED
                                             │
                         ┌───────────────────┴───────────────────┐
                         │        StayService::checkOut()         │
                         └───────────────────┬───────────────────┘
                                             │ autoMarkDirtyOnCheckout() [non-throwing]
                                             │ creates unassigned pending HousekeepingAssignment
                                             ▼
                                       VACANT_DIRTY ◄─────────────────────────────┐
                                             │                                    │
                              assignRoom()  │  (self or manager-assigned)        │ failInspection()
                                             ▼                                    │
                                     [assignment: pending]                       │
                                             │                                    │
                              startCleaning()│                                    │
                                             ▼                                    │
                                        CLEANING                                  │
                                             │                                    │
                             completeCleaning()                                   │
                                             ▼                                    │
                                        INSPECTED ─────────────┬───────────────────┘
                                             │                 │
                              passInspection()│  skipInspection()
                                             ▼                 ▼
                                       VACANT_CLEAN ◄───────────┘
                                     (bookable again)

                    markOutOfOrder() (any status ≠ CLEANING) ──► OUT_OF_ORDER
                    releaseFromOutOfOrder() (OUT_OF_ORDER only) ──► VACANT_DIRTY or VACANT_CLEAN
```

All transitions verified end-to-end this phase via `tests/Feature/HousekeepingWorkflowIntegrationTest.php` (pass path, fail path, skip path, and the full Booking→check-in→check-out→housekeeping→Vacant Clean loop), in addition to each individual transition's existing unit/feature coverage from M1–M4.

---

## 8. Regression Baseline

| Suite State | Count |
|-------------|-------|
| Total tests (run 2, cleanest) | 780 (756 passed + 24 failed) |
| Stable pre-existing failures (present before Phase 4.2) | 23 — 11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest`, all hardcoded-date drift |
| Additional pre-existing, non-deterministic flake | `LateCheckoutFeeTest` (1, deterministic given wall-clock hour, root-caused this phase — a bug in the test itself, unrelated to any Phase 4.2 code) and `PerStayAttributionTest` (0 or 1, Faker room-number pool collision, intermittent since M2) |
| New regressions attributable to Phase 4.2 | **0** |
| Phase-4.2-specific tests, all passing | 124 |
| Build | ✅ PASS (5.49s, pre-existing >500kB chunk warning unchanged) |

**These 23 stable pre-existing failures must NOT increase in any future phase. Any increase beyond 23 (setting aside the two documented flakes above) is a regression.**

---

## 9. Known Technical Debt

| Item | Source | Priority |
|------|--------|----------|
| Auto-created checkout assignment cannot be self-claimed by HOUSEKEEPING (only MANAGER/ADMIN can start/complete it) | M2 design (`autoMarkDirtyOnCheckout` creates unassigned), surfaced by Integration-phase end-to-end testing | **MEDIUM — operational impact at go-live**, see Final Report §Recommendation |
| `HousekeepingController::index()` contract has no `reason`/`notes`/`floor_id`/`housekeepers[]` fields | M4 contract design, explicitly frozen for M5 ("do not redesign API responses") | LOW — cosmetic board limitations (no reason/notes display, no floor grouping, no proper assignee dropdown) |
| 23 pre-existing test failures (date-sensitive) | Phases 2–3 | LOW — no functional impact, will keep growing as real dates advance past hardcoded fixtures |
| `LateCheckoutFeeTest`'s wall-clock-hour bug | Pre-existing (`setTime()` silently discards `subHours()`) | LOW — test-only bug, not a product defect; worth a 1-line fix in a future test-hygiene pass |
| JS bundle chunk size (521 kB) | Phase 1 SPA setup | LOW — acceptable for admin-only SPA, unchanged since Phase 4.1 |
| No frontend test runner (Vitest/Jest) configured in this repo | Pre-existing | LOW — Vue behavior verified indirectly via Inertia-prop feature tests |
| `routes/web.php` has a pre-existing unused `BookingPackageController` import | Unrelated module, predates Phase 4.2 | LOW — flagged, not fixed (out of scope) |

---

## 10. Future Extension Points

Phase 4.2 was designed for extensibility, matching the Phase 4.1 precedent:

1. **Assignment self-claim / reassignment.** Add a `HousekeepingService::claimAssignment(HousekeepingAssignment, User)` method (or relax `startCleaning()`'s authorization to allow any `housekeeping.assign` holder to claim an *unassigned* pending assignment specifically, distinct from claiming someone else's). Directly addresses the §9 technical debt item.
2. **Auto-assignment strategies (ADR-90 §Consequence).** `HousekeepingService::assignRoom()` already accepts an explicit `$assignee` — a future `resolveAssignee(Room): ?User` (Round Robin / Workload / Floor-based) can call `assignRoom()` with the resolved user, with zero controller or route changes.
3. **Housekeeping Dashboard (explicitly deferred, Phase 4.2 architecture review §16.1).** `cleaning_records` already has all fields needed for: average cleaning time, inspection fail rate, today's tasks, VIP/urgent room counts — no schema change required.
4. **Contract expansion for the Board.** Adding `reason`, `notes`, `floor_id`/`floor_name`, and `housekeepers[]` to `HousekeepingController::index()` would unlock cleaning-reason/notes display, floor-grouped board layout, and a real assignee dropdown in the Assign dialog — deferred in M5/Integration specifically because both milestones' instructions froze the contract.
5. **Race-condition / concurrent-claim test.** `ADR-87`'s `SELECT FOR UPDATE` pattern is implemented and the sequential duplicate-assignment case is tested, but a true concurrent-request race test (two simultaneous `assignRoom()` calls for the same room) was never added across M1–this phase — worth adding in a future hardening pass.
6. **Push notifications / real-time board updates.** Explicitly out of scope for Phase 4.2 (per every milestone's instructions); `Housekeeping/Index.vue` currently requires a manual `router.reload()` after each action with no auto-refresh — a natural Phase 5 candidate once WebSocket/Reverb infrastructure exists.

---

## 11. Phase 4.2 Constraints (govern all future Phase 4.x work touching Housekeeping)

1. **Financial isolation:** never write to `folio_entries`, `booking_payments`, `folios`, or any Night Audit/Revenue/Reconciliation/Package Enrollment table from Housekeeping code paths. Verified zero violations across all 6 milestones.
2. **ADR-87 lock pattern immutable:** any new `HousekeepingService` method that transitions `Room.status` must acquire `lockForUpdate()` on `Room` and re-validate the precondition under lock before writing.
3. **Non-throwing hooks:** `autoMarkOccupied()`, `autoMarkDirtyOnCheckout()`, and any future `StayService`-embedded Housekeeping hook must catch `Throwable`, log, and never propagate into the caller's transaction.
4. **Service delegation:** `HousekeepingController` must never write directly to `HousekeepingAssignment`/`CleaningRecord`/`Room` — always via `HousekeepingService`.
5. **Contract discipline:** `HousekeepingController::index()`'s Inertia contract (`rooms`, `can`) must not be silently expanded — any addition (see §10.4) requires an explicit architecture decision, not an incidental frontend-milestone change.
6. **ADR-89 permission boundary:** HOUSEKEEPING must never regain `rooms.manage`, `room.assign`, or `room.unassign` without a new, explicit ADR superseding ADR-89.

---

## 12. Ready for Phase 4.2 Closure

**Prerequisite checklist:**
- [x] All 5 backend/frontend milestones approved
- [x] Integration & Final Verification complete (this document + the accompanying final report)
- [x] Regression baseline documented and stable (23 pre-existing failures, reproduced identically across 2 full-suite runs)
- [x] Permission matrix stable and fully tested (ADR-89)
- [x] Architecture baseline established (this document)
- [ ] ChatGPT Architecture Review of the Integration phase — **pending**
- [ ] Commit, push, tag `phase-4.2` — **blocked until the review above completes**

---

## 13. Closure Statement

> **Phase 4.2 is NOT yet closed.**
>
> This document and `docs/reports/phase-4.2-final-report.md` are the complete Integration & Final Verification deliverables, submitted for ChatGPT Architecture Review per the stop conditions of this phase.
>
> No commit, push, or git tag has been created. Working tree changes remain uncommitted on branch `phase-3`.
>
> **Upon approval:** commit, push, tag `phase-4.2`, officially close Phase 4.2, and prepare Phase 4.2.5 Production Freeze — in that order, only after explicit ChatGPT approval of this baseline and the final report.
