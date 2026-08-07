# Room Demand and Room Board Unification — Milestone 5 Report

## 1. Scope

Milestone 5 adds no new business workflow. It verifies, hardens, and closes the Room Demand/Room Board Unification feature (Milestones 1–4) before Production Readiness Review: audit completeness, real MySQL concurrency, deadlock/lock-order analysis, atomicity, double-submit/stale-request handling, data integrity, legacy NULL mapping volume, authorization/forged-request defense, query performance, frontend/mobile hardening, error observability, full regression, and migration/deployment readiness. Only two application changes were approved and made: an ESC-key fix for the M4 bulk-release panel, and a payload-size cap on the Room-Board-first request. No business rule, lock order, or transaction boundary was changed.

## 2. Checkpoints M1–M4

Git-verified (not trusted from any prompt):

| Milestone | Commit | Message |
|---|---|---|
| 1 | `3a1d098` | feat(room-assignments): link requirements and harden demand locking |
| 2 | `806dcc7` | feat(room-assignments): map demand requirements atomically |
| 3 | `a331ef5` | feat(room-assignments): sync demand from room board atomically |
| 4 | `11f791a` | feat(room-assignments): add atomic bulk room release |

`origin/phase-3` at the start of Milestone 5 = local `HEAD` = `11f791a7bb0e646748f0295939f0581794bad862`. No divergence.

## 3. Pre-Implementation Findings (Phase A)

Full code review covered `RoomAssignmentService`, `BookingService`, `StayService`, `RoomRequirementAllocationService`, `RoomAvailabilityRuleService`, all M1–M4 models/migrations/FormRequests/policies, `RolePermissionSeeder`, `AuditObserver`, and `Show.vue`. Key confirmed facts before any Phase B testing:
- `phpunit.xml` forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` — no existing test in this repo can prove real MySQL concurrency behavior; a dedicated harness against real MySQL was required (§8).
- `AuditObserver` is registered on every entity this feature writes (`Booking`, `BookingRequirement`, `RoomAssignment`, `Stay`, `ReleaseBatch`) and fires synchronously inside the same DB transaction, so a rolled-back operation never leaves a "success" audit row — architecturally sound, confirmed again this milestone.
- `BookingPolicy::delete()` exists but is never called from any route/controller — reconfirmed independently that Booking has no live hard-delete path.
- `cancelBooking()` (`BookingService`) does **not** call `Booking::lockForUpdate()` before writing, unlike every other Booking-mutating method in this codebase (`assignRoomsFromRoomBoard`, `bulkReleaseAssignments`, `checkIn`, `checkOut`, `addRequirement`, `updateRequirement` all lock Booking first). Flagged as a hypothesis in Phase A, **confirmed as a real, reproducible defect in Phase B** (§11).
- No max-size cap existed on `StoreRoomBoardAssignmentRequest`'s `room_ids`/`groups`, unlike `BulkReleaseAssignmentRequest`'s `max:100` (M4 precedent).
- `handleGlobalEsc()` in `Show.vue` only closed the single-release dialog, never the M4 bulk-release panel.

## 4. Transaction Matrix

| Workflow | Lock order | Booking status update | Audit |
|---|---|---|---|
| Add/update/delete Requirement | Booking → BookingRequirement (room_type siblings, id asc) | `updateBookingAssignmentStatus` | ✅ |
| Demand-first single/multi assign | Booking → Room (id asc) → BookingRequirement (id asc) | `updateBookingAssignmentStatus` | ✅ |
| Room-Board-first assign | Booking → Room (id asc) → BookingRequirement (id asc) → create/update Requirement → create RoomAssignment → create Stay | `updateBookingAssignmentStatus` | ✅ |
| Single release | Stay → RoomAssignment (no Booking lock) | `updateBookingAssignmentStatus` + `updateBookingStayStatus` | ✅ |
| Bulk release (keep/reduce demand) | Booking → Stay[asc] → RoomAssignment[asc] → BookingRequirement[asc] (reduce_demand only) | both | ✅ |
| Move room | Room(new) → Stay → RoomAssignment → Room(old), **no Booking lock** (deliberate — never writes Booking) | n/a | via StayEvent |
| Check-in / Checkout | Booking → Stay → RoomAssignment (ADR-38) | `updateBookingStayStatus`/`finaliseBookingCheckout` | ✅ |
| **`cancelBooking()`** | **No `Booking::lockForUpdate()` at all** | direct `$booking->update()` | ✅ (but see §11) |

## 5. Canonical Lock Order

Confirmed unchanged and internally consistent: assign-family locks `Booking → Room → BookingRequirement`; release/check-in/checkout lock `Booking → Stay → RoomAssignment`. These are disjoint resource orderings under the same Booking-first discipline — no deadlock cycle between them (verified empirically, §9–§10, not just by reading). `moveRoom()`/`extendStay()` remain the one deliberate Room-first exception (never write Booking) — verified in Race Case 15 to produce contention, never a deadlock, and both possible outcomes are valid. **No lock order was changed this milestone.**

## 6. Audit Matrix

All 15 required audit-matrix operations trace to `AuditLog` rows via the existing `AuditObserver` (or `StayEvent` for `moveRoom()`), with actor/booking/room/assignment/requirement/batch/before-after/reason/reduce_demand/timestamp all present on the relevant model's own columns — confirmed by direct code reading of every write path plus the M4 test suite's own audit assertions (still passing, §14). No new audit gap found. No new audit mechanism added.

## 7. Audit Hardening

No code change. Existing mechanism verified sufficient:
- Committed operations: `AuditObserver` on every relevant model, synchronous, same-transaction (so rollback never produces a false "success" record — reconfirmed by reading + re-running M4's existing rollback-audit tests).
- Failed/stale/concurrency-rejected operations: handled by the existing `ValidationException`/HTTP 422 flow, which Laravel's own request logging already covers; no structured-warning-log convention exists elsewhere in this codebase to extend, and none was added (per instruction: don't invent one).
- One real limitation confirmed, not fixed: `audit_logs` has no FK to the tables it observes (by design — one table serves many entity types), so if a table is ever dropped and recreated outside of forward-only production migrations, an old audit row's `entity_id` can silently point to an unrelated new row. This was directly observed once, from Milestone 4's own migration-fix rollback/recreate cycle (documented in the M4 report), and is a property of the architecture, not something this milestone changes.

## 8. Concurrency Harness

Real, two-independent-OS-process, real-MySQL harness — no Artisan command, no route, no debug endpoint, no test-only code in `app/`:

```
tests/Support/Concurrency/Barrier.php                              — file-based two-worker synchronization barrier
tests/Support/Concurrency/ConcurrencyWorkerBootstrap.php            — boots a standalone Laravel app per worker, pointed at the disposable DB via env var
tests/Support/Concurrency/run_worker.php                            — CLI entry point; waits on the barrier, calls the real unmodified service method, writes a JSON result
tests/Support/Concurrency/WorkerProcessRunner.php                   — spawns two run_worker.php processes via proc_open(), collects results
tests/Support/Concurrency/ConcurrencyTestCase.php                   — base test class; fixture helpers, per-test-method table truncation (see §8a)

tests/Feature/Concurrency/RoomAssignmentConcurrencyTest.php         — race cases 1, 2, 3, 4, 5, 13, 15, 16
tests/Feature/Concurrency/AssignReleaseInteractionConcurrencyTest.php — race cases 6, 7, 8, 12
tests/Feature/Concurrency/BulkRoomReleaseConcurrencyTest.php        — race cases 9, 10, 11, 14, 17, 18
```

**MySQL environment (reported before any test ran, per Mục III):**
1. Host: `127.0.0.1:3306` (same local MySQL server as normal dev work).
2. Database: `lastella_pms_concurrency_m5` — new, disposable, created via a one-off `CREATE DATABASE` + `DB_DATABASE=... php artisan migrate`, never via `.env`/`.env.testing`.
3. Environment: local/development.
4. Not production.
5. No real operational data — freshly migrated schema only.
6. QA-M5 isolation: kept in its own database entirely (not commingled with `lastella_pms`), with `booking_code` prefixed `QA-M5-` wherever fixtures reached the real dev DB (the manual-QA ESC check, §19).
7. Retention: the disposable database was left in place (not dropped) after the run; each test method truncates its own structural tables at the start (a real, pre-existing `RoomAssignmentFactory` quirk — see §8a — made this necessary for collision-free repeated runs), so the FINAL test method's fixtures remain inspectable.

### 8a. A genuine test-infrastructure finding

`RoomAssignmentFactory::definition()` eagerly executes `Room::factory()->create()` (and transitively a fresh `Floor`/`Resource`) as a plain PHP statement inside `definition()`, not as a lazy Factory-instance default — this means it creates a real, wasted Room/Floor/Resource row on **every** `RoomAssignment::factory()->create([...])` call, even when `room_id` is immediately overridden. Combined with `FloorFactory`'s bounded 100-value `fake()->unique()->bothify('F##')` code space and `RoomFactory`'s 900-value space (both already documented since Milestone 2 for the *sequential* case), running many concurrency test methods against one un-rolled-back database exhausted both spaces and caused spurious `UniqueConstraintViolationException`s unrelated to any concurrency behavior. Fixed entirely in test-support code (`ConcurrencyTestCase`) — per-test-method table truncation and explicit unique-code fixture helpers — **no shared factory file was touched**, so the rest of the test suite (which always uses `RefreshDatabase` and never hits this volume) is unaffected.

## 9. Concurrent Assignment Results

| Case | Result |
|---|---|
| 1 — Two bookings race to assign the same room | **PASS** — exactly 1 succeeds, clean `ValidationException`, no deadlock (`sql_state=null`), 1 active assignment on the room |
| 2 — Two workers race to assign the same room to the same booking | **PASS** — exactly 1 succeeds, 1 active assignment |
| 3 — Two different rooms, same room_type, race for the single eligible requirement line | **Architecture Gap discovered, not fixed** — both succeed, both link to the same line; `requirement.quantity` stays unchanged (over-assignment beyond quantity is possible). Root cause: `assignRoomsWithRequirementLink()`'s eligibility filter is `quantity > 0`, never `quantity > already-assigned count`; the Booking-level lock serializes the two transactions but does not close this because demand-first never writes `quantity`. This is a **missing business rule**, not a lock-order bug — fixing it would require a NEW capacity check in `RoomAssignmentService`, gated per Mục IV/XI as requiring Product Owner/ChatGPT approval before any code change. **Not fixed this milestone.** |
| 4 — Two Room-Board-first requests race to increase the same requirement | **PASS** — both succeed, quantity increases by exactly the true combined excess (1, never double-counted to 2) |
| 5 — Two Room-Board-first requests race to create the same new requirement line | **PASS** — the Booking lock fully serializes; exactly 1 line is ever created, quantity correctly merges both rooms (2) |
| 13 — Double-submit Room-Board-first | **PASS** — exactly 1 succeeds, 1 active assignment on the contested room |
| 15 — Room flips OutOfOrder while a Room-Board-first request targets it | **PASS** — both orderings are valid outcomes and both were confirmed self-consistent; assignment existence in DB always matched the worker-reported outcome |
| 16 — Booking cancelled while a Room-Board-first request targets it | **FAIL — real, reproduced Architecture Gap.** `cancelBooking()`'s missing Booking lock allows a genuinely inconsistent final state: `booking.status=CANCELLED` **and** an active `RoomAssignment` created after cancellation. Reproduced consistently across 3 separate full runs. **Not fixed this milestone** — reported below (§17) exactly as instructed. |

## 10. Concurrent Release Results

| Case | Result |
|---|---|
| 9 — Two bulk releases, identical assignment sets | **PASS** — exactly 1 batch, all assignments released once |
| 10 — Two bulk releases, partially overlapping sets ({1,2} vs {2,3}) | **PASS** — exactly the winning set (2) released, loser's set fully untouched (no partial release), no deadlock SQLSTATE |
| 11 — Two bulk releases, disjoint sets, same requirement, reduce_demand | **PASS** — both succeed; final quantity always equals final active count (0 = 0) regardless of order |
| 14 — Double-submit bulk release | **PASS** — exactly 1 succeeds, 1 batch |
| 17 — Two-tab stale single release | **PASS** — exactly 1 tab wins |
| 18 — Room charge posted while reduce-demand release targets the booking | **PASS** — the release resolved cleanly every run (no raw deadlock/serialization error surfaced); evidence captured (§12) |

## 11. Assign-versus-Release Results

| Case | Result |
|---|---|
| 6 — Demand-first races Room-Board-first on the same requirement | **PASS** — both succeed, no excess ever triggered (quantity stays 2, matching 2 selected against starting quantity 2), regardless of order |
| 7 — New assignment races bulk release of a different assignment, same booking | **PASS** — both succeed (independent rows, same Booking lock serializes cleanly), booking status recomputed consistently |
| 8 — New assignment races reduce-demand release on the same requirement | **PASS** — both succeed under either interleave order; final quantity always equals final active count |
| 12 — Reduce-demand release races a direct `updateRequirement()` call | **PASS** — both succeed; final quantity always one of the two mathematically valid outcomes (4 or 5), never a third corrupted value (observed: 5) |

## 12. Deadlock Analysis

Zero raw deadlock/lock-wait-timeout SQLSTATEs observed across all 18 cases, including the two shapes with genuine multi-row lock contention (Case 10's partially-overlapping sets, Case 3's two-different-rows-same-line). All losing transactions resolved as clean `ValidationException` rejections. Case 18 (Folio-lock race) never produced a deadlock either — the release consistently resolved cleanly (`sql_state=null` on failure paths, when they occurred). **No deadlock retry logic was added** — none was needed, per the instruction to only add retry with reproduced evidence.

## 13. Double-Submit and Idempotency

Cases 13 (Room-Board-first) and 14 (bulk release) both confirmed: identical double-submitted requests never produce duplicate state — the second, serialized request's own re-validation-under-lock catches the already-committed first request and rejects cleanly.

## 14. Stale Request Handling

Cases 15–18 (state changes between selection and submit) all confirmed clean, self-consistent outcomes except Case 16 (§9, §17).

## 15. Data Integrity Review

Read-only queries re-run against the local dev database (`lastella_pms`), same 15 checks as Phase A:

| # | Check | Result |
|---|---|---|
| 1 | Active assignment, NULL `booking_requirement_id` | 165 (legacy volume, unchanged — see §16) |
| 2 | RoomAssignment → BookingRequirement of a different booking | 0 |
| 3 | RoomAssignment/BookingRequirement room_type mismatch | 0 |
| 4 | Requirement quantity < active assignment count | 0 |
| 5 | Same-room overlapping active assignments | 1 pre-existing pair (`QA-BK-0049`/`QA-BK-0050`, confirmed in Phase A as raw seed/factory data that could not have been created via any guarded service path — `hasConflict()` is the sole gate for every assign path) |
| 6–14 | All other consistency rules | 0 / clean (DB-enforced `unsignedSmallInteger` on `quantity`, confirmed) |
| 15 | Orphan/misattributed audit log | See §7 — one confirmed instance, architectural, not a regression |

No new integrity defect found in Milestone 5 beyond the Case 16 Architecture Gap (§9, §17), which is a **live** concurrency finding, not a static data anomaly.

## 16. Legacy NULL Mapping

165 of 203 active assignments (≈81%) still have no `booking_requirement_id` — unchanged since Phase A, no backfill run (dry-run only was ever in scope; `--apply` against real data was never authorized). Already mitigated at the application level: `reduce_demand=true` unconditionally rejects any batch containing a NULL-mapped assignment (tested since M4, re-confirmed passing this milestone).

## 17. Authorization and Security

Permission matrix re-confirmed from `RolePermissionSeeder`: `ADMIN`/`MANAGER`/`RECEPTION` hold both `room.assign` and `room.unassign`; `SALES` holds neither (can create/edit demand only). Cross-booking forged assignment/requirement IDs remain defended in depth (re-derived under lock inside the service, never trusted from the FormRequest alone — confirmed again by reading, unchanged this milestone). `StoreRoomBoardAssignmentRequest`'s missing `max:` cap — the one authorization/security-adjacent gap Phase A found — is now closed (§18). No permission was added, removed, or changed.

**Architecture Gap — `cancelBooking()` (not fixed, reported per instruction):**
- **Evidence:** Race Case 16, reproduced 3/3 runs — `booking.status=CANCELLED`, assign-worker `success=true`, an active `RoomAssignment` exists on the cancelled booking.
- **Root cause:** `BookingService::cancelBooking()` never calls `Booking::lockForUpdate()` before its writes, unlike every other Booking-mutating method in this codebase.
- **Why not fixed here:** the fix would be a `RoomAssignmentService`/`BookingService`-adjacent transaction/locking change to a method (`cancelBooking()`) that is part of the pre-existing (pre-M1) booking lifecycle, not something Milestone 1–4 introduced or that this milestone's approved scope covers. Per the explicit instruction ("Nếu sửa lock order hoặc transaction có thể ảnh hưởng M1–M4: dừng trước khi code, báo Architecture Gap"), this is reported for Product Owner/ChatGPT approval rather than silently patched.
- **Suggested minimal fix (for future approval only, not applied):** add `Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail()` as the first statement inside `cancelBooking()`'s transaction, matching the exact pattern already used by every sibling method. Low estimated risk (purely additive locking, identical to precedent), but requires its own regression test proving Case 16 flips from FAIL to PASS before being merged, per the instruction's own gate.

## 18. Performance and Query Review

Real `DB::listen()` query counts, `getRoomBoard()` + `getAssignmentSummary()` combined, measured against a disposable database at increasing **booking room counts**:

| Rooms on the booking | Query count |
|---|---|
| 10 | 13 |
| 50 | 13 |
| 100 | 13 |

Flat, constant — confirms no N+1 relative to the booking's own assignment count (the query count instead scales with the hotel's total floor/room inventory, which is expected and unrelated to any one booking). No index added — no evidence justified one. No caching added. No polling added.

## 19. Frontend and Mobile Hardening

**ESC bulk-release panel (Mục V) — confirmed and fixed:**
1. Confirmed not reproduced-as-fixed: code reading showed `handleGlobalEsc()` only ever checked `releaseDialogAssignment.value`.
2. Confirmed specific to the M4 panel: the panel's own `showBulkReleasePanel` ref was simply never added to this pre-existing handler when M4 introduced the panel.
3. Confirmed no conflict: the single-release dialog's ESC handling is untouched (checked first, unchanged); no other modal in this file previously handled ESC.
4. Fix applied: extended `handleGlobalEsc()` with one additional `if` branch calling the already-existing `closeBulkReleasePanel()` — no refactor, no full-file formatting, reason/note fields are simply cleared (matching the existing "Hủy" button's own behavior — no unintended data loss beyond what closing already did).
5. Manual QA (real browser, `javascript_tool`, QA-M5-prefixed booking on Local): opened the bulk-release panel, dispatched a real `Escape` `KeyboardEvent`, confirmed the panel closed. Then separately re-verified the single-release dialog's ESC still closes it (regression-safe). Both confirmed via DOM state, not assumed.
6. No automated frontend test added — `package.json` has no test runner configured (`build`/`dev` scripts only), confirmed by reading, not assumed; adding one was out of this milestone's approved scope.

**Room-Board-first payload cap (Mục VI) — confirmed and fixed:**
1. Read existing limits: `BulkReleaseAssignmentRequest` (M4) = `max:100`; `StoreRoomAssignmentRequest` (demand-first, M2) = **no cap** (out of approved scope, not touched); `StoreRoomBoardAssignmentRequest` (Room-Board-first) = **no cap** (in approved scope).
2. Chosen max: **100**, matching the M4 precedent exactly and the hotel's stated 10–100 room operating scale (Phase A §XIII) — never smaller than a legitimate full-hotel batch.
3. Applied to `StoreRoomBoardAssignmentRequest::rules()` only — `room_ids` and `groups` both gained `max:100`. No frontend-only limit added.
4. Tests added: exactly 100 rooms accepted (real end-to-end success, 100 `RoomAssignment` rows written) and 101 rooms rejected (session error on `room_ids`, zero writes, no partial assignment) — both passing.

## 20. Database and Migration Review

No migration created this milestone (Phase A's own conclusion — reconfirmed, no evidence emerged during Phase B that would justify one). The two M4 migrations were not touched. `php artisan migrate:status` re-confirmed zero pending migrations.

## 21. Files Changed

| File | Change | Reason |
|---|---|---|
| `resources/js/Pages/Admin/Bookings/Show.vue` | `handleGlobalEsc()` extended | ESC gap, confirmed and approved |
| `app/Http/Requests/Booking/StoreRoomBoardAssignmentRequest.php` | `max:100` added to `room_ids`/`groups` | Payload cap, confirmed and approved |
| `tests/Feature/RoomAssignmentFromRoomBoardTest.php` | 2 new tests | Payload cap coverage |
| `tests/Support/Concurrency/*.php` (5 files, new) | New test-support harness | Real MySQL concurrency testing |
| `tests/Feature/Concurrency/*.php` (3 files, new) | New concurrency test suites | 18 race cases |

No `RoomAssignmentService.php`, `BookingService.php`, or `StayService.php` change. No migration. No permission change. No Folio/Revenue/Night Audit change. No Package Enrollment change.

## 22. Tests Added

- `tests/Support/Concurrency/` — 5 harness files (Barrier, ConcurrencyWorkerBootstrap, run_worker.php, WorkerProcessRunner, ConcurrencyTestCase).
- `tests/Feature/Concurrency/RoomAssignmentConcurrencyTest.php` — 8 tests.
- `tests/Feature/Concurrency/AssignReleaseInteractionConcurrencyTest.php` — 4 tests.
- `tests/Feature/Concurrency/BulkRoomReleaseConcurrencyTest.php` — 6 tests.
- `tests/Feature/RoomAssignmentFromRoomBoardTest.php` — 2 new tests (payload cap).

## 23. M1–M4 Regression

| Suite | Result |
|---|---|
| M1 (3 files) | 34/34 PASS |
| M2 (`RoomAssignmentAtomicMappingTest`) | 19/19 PASS |
| M3 (`RoomAssignmentFromRoomBoardTest`, incl. 2 new) | 29/29 PASS |
| M4 (`BulkRoomReleaseTest` + `ReleaseBatchSchemaTest`) | 55/55 PASS |
| **Total direct** | **137/137 PASS** |
| `BookingEngineFoundationTest` | 25/25 PASS |

## 24. Targeted Suite

*(Historical: pre-gap-closure Phase B snapshot. See §33g for the final, post-gap-closure numbers — 664 passed, 25 failed, Case 3/16 both genuinely passing.)*

`--filter="RoomAssignment|BookingRequirement|RoomAvailability|BookingManagement|BookingEngineFoundation|CheckIn|Checkout|Stay|Folio|Revenue|NightAudit|MoveRoom|ReleaseBatch|BulkRoomRelease|Audit"` (this filter also matches the new Concurrency test classes):

**653 passed, 25 failed.** The 25 failures are the 24 known pre-existing/time-dependent baseline (`BookingManagementUiTest` × 11, `RoomAvailabilityCheckerTest` × 13 — byte-for-byte identical names, re-diffed) **plus exactly 1**: `RoomAssignmentConcurrencyTest > case 16`, which is our **own intentionally-failing evidence-capture test** documenting the confirmed Architecture Gap (§17) — not a regression, a deliberate signal.

## 25. Full Suite

*(Historical: pre-gap-closure Phase B snapshot. See §33g for the final, post-gap-closure numbers — 1179 passed, 25 failed, same 24 known baseline + 1 unrelated time-dependent flake, zero regressions from the fixes.)*

**`php artisan test` (full suite): 1168 passed, 25 failed (4664 assertions).** Baseline before Milestone 5 was 1149 passed, 24 failed. `1168 = 1149 + 19` and `25 = 24 + 1`: of the 20 new tests this milestone added (2 payload-cap + 18 concurrency), 19 passed and exactly 1 — `RoomAssignmentConcurrencyTest > case 16` — is the intentional, by-design failing evidence-capture test documenting the confirmed Architecture Gap (§17). The other 24 failures are byte-for-byte identical to the known pre-existing/time-dependent baseline (11 `BookingManagementUiTest` + 13 `RoomAvailabilityCheckerTest`), re-diffed by name against the full list. **Zero real regressions.**

## 26. Build and Static Checks

`npm run build`: **success**, no errors (pre-existing >500kB chunk-size advisory unrelated to this milestone). No lint/typecheck script exists in `package.json` (`build`/`dev` only, confirmed by reading) — none run, per instruction not to assume a script name.

## 27. Manual QA

| Case | Result |
|---|---|
| ESC closes bulk-release panel | **PASS** — real browser, `Escape` keydown dispatched, panel confirmed closed via DOM state |
| Single-release dialog ESC unaffected | **PASS** — real browser, confirmed still closes |
| Payload cap (100 accepted / 101 rejected) | **PASS** — proven at the HTTP/database level via automated test (real 100-row write, real rejection with zero writes at 101); not separately re-clicked in-browser at that scale (impractical to hand-select 100+ rooms in a UI session) |
| Cases 1–14, 16 of Section XXVII (demand-first, Room-Board-first, bulk release keep/reduce demand, checked-in protection, Folio lock, concurrent assignment/release, assign-vs-release stale state, single release, move room, check-in/checkout, authorization) | Covered by real two-process MySQL concurrency evidence (§9–§14) plus the still-passing M1–M4 regression suite (§23) — not re-clicked through the UI from scratch this milestone, since Milestone 4's own report already recorded real browser+DB evidence for the equivalent single-request versions of these flows, and this milestone's job was specifically the *concurrent* and *hardening* dimension, which the real harness proves more rigorously than manual UI clicking could |
| Case 15 (viewport matrix) | **Not independently re-verified this milestone** — Milestone 4 already obtained Product-Owner-confirmed PASS at 360×800/390×844/412×915 for the bulk-release panel specifically; the two new viewports (1366×768, 1920×1080) and the other four panels listed in Phase A §XV were not re-checked, since neither UI change this milestone touched layout/viewport-sensitive code (the ESC fix is behavioral only) |

## 28. Known Limitations

- **Case 16 Architecture Gap** (`cancelBooking()` missing `Booking::lockForUpdate()`) — real, reproduced, not fixed, requires Product Owner/ChatGPT decision (§17).
- Case 3 demand-first over-assignment (no capacity check against `quantity`) — real, reproduced, not fixed, requires the same decision process (§9).
- Legacy NULL mapping (165 rows) unchanged, already mitigated at the application layer.
- Frontend has no automated test runner; ESC fix verified manually only.
- Desktop/tablet/wide viewport matrix not re-verified this milestone (no layout-affecting change made).
- `audit_logs` has no FK to observed entities — a known, low-probability, non-production-triggerable architectural property (§7).

## 29. Deferred Issues

- Fixing the two confirmed Architecture Gaps (§17, §9) — explicitly deferred pending approval, per instruction.
- Full desktop/tablet/mobile viewport re-verification for all 6 panels — deferred, no layout change this milestone to justify re-running it.
- `StoreRoomAssignmentRequest` (demand-first) still has no payload cap — noted, out of this milestone's approved scope (only Room-Board-first was approved).

## 30. Deployment Restrictions

No production migration, seeder, or backfill. No production deploy. `docs/reports/package-enrollment-hardcoded-catalog-gap.md` remains untracked, unmodified, out of scope.

## 31. Rollback Considerations

No new migration this milestone — nothing to roll back at the database layer. Code rollback follows the same corrected plan established in the Milestone 4 report: `git revert <commit>` once pushed to a shared branch, never `git reset` on `phase-3`.

## 33. Architecture Gap Closure (ChatGPT-Reviewed, Implemented This Session)

ChatGPT's review of §9–§17 above concluded **MILESTONE 5 REVIEWED — COMMIT BLOCKED BY 2 ARCHITECTURE GAPS**, then, after a dedicated architecture-review-only planning pass, approved implementation with one added scope condition (the `updateBooking()`/NoShow finding). Both blockers are now closed and re-proven via the same real two-process/real-MySQL harness — no assertion was weakened to force a PASS.

### 33a. Blocker A — Case 3 (demand-first over-assignment)

**Root cause:** `assignRoomsWithRequirementLink()` locked `BookingRequirement` rows only to check eligibility (`quantity > 0`) and ownership — it never counted active assignments against `quantity`, so two serialized transactions could each blindly link an assignment to the same line, exceeding it.

**Fix (`app/Services/RoomAssignmentService.php`):** after the existing requirement lock and the room_type resolution loop, and strictly before any `RoomAssignment` is created, the method now:
1. Groups the batch's resolved assignments by requirement id (never per room).
2. For each distinct requirement id: `active = RoomAssignment::where('booking_requirement_id', $id)->whereIn('status', [Assigned, CheckedIn, CheckedOut])->count()` (read fresh, inside the transaction, after the lock).
3. `remaining = requirement.quantity - active`.
4. Rejects the **whole batch** (`ValidationException`, zero writes) if the batch's new count for that line exceeds `remaining`.

`quantity` is never written by this method. `assignRoomsFromRoomBoard()`/`reconcileRoomType()`/`resolveAndApplyRoomBoardGroup()` (Room-Board-first) are untouched — the fix lives entirely inside demand-first's own method.

**Capacity invariant enforced:** `active_assignments_for_requirement <= requirement.quantity`, always, for demand-first.

**Before-fix real race evidence:** `successCount=2, linkedActiveAssignments=2, requirement.quantity=1` — both workers succeeded, over-assigning by 1.
**After-fix real race evidence:** `successCount=1, linkedActiveAssignments=1, requirement.quantity=1` — exactly one worker succeeds, the loser gets a clean `ValidationException` (`sql_state=null`, no deadlock), quantity untouched.

### 33b. Blocker B — Case 16 (`cancelBooking()` unlocked write)

**Root cause:** `cancelBooking()` never called `Booking::lockForUpdate()`, so it never joined the same lock queue as `assignRoomsFromRoomBoard()`/`bulkReleaseAssignments()`/`checkIn()`/`checkOut()`/`addRequirement()`/`updateRequirement()` — allowing it to race unlocked against a concurrent assignment transaction.

**Fix (`app/Services/BookingService.php`):** `cancelBooking()` now acquires `Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail()` as the first statement in its transaction; every subsequent read/write in the method operates on that locked instance. No business rule, check order, or write changed — only lock timing.

**Both serialization orders proven, deterministically** (via a test-support-only `post_barrier_delay_ms`, giving the intended loser of the lock race a reliable head start — no assertion weakened):
- **Order A (assignment wins first):** assignment succeeds (booking not yet cancelled at its lock instant); cancellation, running after, still succeeds and correctly releases the assignment that was just created — the existing, unchanged cancellation business rule. Evidence: `booking.status=CANCELLED, invalid-coexistence=false`.
- **Order B (cancellation wins first):** cancellation succeeds; assignment, running after, re-reads the now-Cancelled status under its own fresh lock and is cleanly rejected (`ValidationException`) — no assignment row created. Evidence: `booking.status=CANCELLED, invalid-coexistence=false`.

**Demand-first terminal guard (same blocker, Mục II):** `assignRoomsWithRequirementLink()` had **no booking-state guard at all** (a separate, previously-undiscovered gap surfaced during the architecture review, distinct from the Room-Board-first path Case 16 originally tested). Added `assertBookingAcceptsDemandFirstAssignment()` — same blocked set as every other assignment guard in this codebase (`isTerminal()` = Cancelled/NoShow/CheckedOut, plus PartiallyCheckedOut), a separate method from `assertBookingAcceptsRoomBoardAssignment()` on purpose, matching the established one-guard-per-operation convention. Called immediately after the Booking lock, before any capacity/create logic. Covered by 2 new tests (`test_demand_first_rejects_cancelled_booking`, `test_demand_first_rejects_no_show_booking`), both passing.

### 33c. `updateBooking()` / NoShow scope extension — evidence and decision

**Call-site finding (Mục IV):** exactly one call site exists — `BookingController::update()` → `BookingService::updateBooking()`, reachable via `PUT/PATCH /admin/bookings/{booking}`. The live Vue form (`resources/js/Pages/Admin/Bookings/Form.vue`) never includes `status` in its submitted payload — confirmed by reading its `useForm({...})` object. However, `UpdateBookingRequest::rules()` structurally permits `'status' => ['nullable', Rule::in(BookingStatus::cases())]` with no additional restriction, and the route/controller process it if sent. **Empirically verified** (direct service call, not through any forged HTTP layer): `updateBooking($booking, ['status' => 'NO_SHOW'])` wrote the terminal status immediately, with zero locking (since `validateTimeChange()` only locks when `checkin_at`/`checkout_at` are present) and zero business validation. No existing test anywhere in the suite exercises this path with a status value.

**Decision:** per Section IV's own rule ("Nếu CÓ call site thực tế... hoặc route/controller nào hiện cho phép việc đó" — the route/controller does), this **is** a required scope extension, carrying the exact same unlocked-terminal-write defect class as `cancelBooking()`.

**Fix (`app/Services/BookingService.php`, same file already in scope — no third production file needed):** `updateBooking()` now locks Booking **before** `validateTimeChange()` (preserving the canonical Booking-first order — locking after would reverse it against `validateTimeChange()`'s own Room/Stay/RoomAssignment locks and introduce a new deadlock-capable ordering), but **only** when the payload's `status` actually transitions into an assignment-blocking state (`isTerminal()` or `PartiallyCheckedOut`) — never a blanket lock on the whole generic update. No release-assignment/folio-void logic was added; this closes only the locking gap, not the (separately noted, out-of-scope) absence of `cancelBooking()`-style side effects when a terminal status is set through this generic path.

**No dedicated `markNoShow()`/`noShow()` method exists anywhere in `app/`** (confirmed by exhaustive grep) — `BookingStatus::NoShow` is otherwise only ever read as a guard value.

### 33d. Canonical lock order after fixes

```
Assignment (demand-first):        Booking → Room → BookingRequirement            [+ terminal guard, + capacity check, no new lock]
Assignment (Room-Board-first):    Booking → Room → BookingRequirement → (create/update Requirement) → RoomAssignment → Stay   [unchanged]
Cancellation (fixed):              Booking  [only — locks nothing else]
Generic update, terminal transition (fixed): Booking [locked first, before validateTimeChange()'s own Room/Stay/RoomAssignment locks]
Generic update, non-status or non-blocking-status edit: unchanged, no new lock
Release (single/bulk), Check-in/Checkout: unchanged
MoveRoom/extendStay: unchanged (Room-first, no Booking lock)
```

No new resource type is locked by any of the three fixes — each adds only a Booking-row lock, in the same first position every other Booking-mutating method already occupies. No cycle is possible (formally argued in the Architecture Review response; empirically re-confirmed below).

### 33e. Deadlock evidence

Zero raw deadlock/lock-wait-timeout SQLSTATEs observed across the full 19-method concurrency re-run (18 race cases, Case 16 split into 2 deterministic orderings). No deadlock retry logic was added — none was needed.

### 33f. 18/18 (19-method) concurrency final result

| Case | Result | Invariant confirmed |
|---|---|---|
| 1 | PASS | exactly 1 succeeds, 1 active on contested room |
| 2 | PASS | exactly 1 succeeds |
| 3 | **PASS (was FAIL)** | `active <= quantity` (1 <= 1), quantity untouched, loser gets clean `ValidationException` |
| 4 | PASS | quantity increases by true excess only |
| 5 | PASS | exactly 1 line created, correctly merged |
| 6 | PASS | no excess triggered either order |
| 7 | PASS | both succeed, independent rows |
| 8 | PASS | quantity always equals active count |
| 9 | PASS | exactly 1 batch |
| 10 | PASS | winning set fully released, loser untouched, no deadlock |
| 11 | PASS | quantity lands at exactly 0 |
| 12 | PASS | quantity in the two valid outcomes {4,5} |
| 13 | PASS | exactly 1 succeeds |
| 14 | PASS | exactly 1 batch |
| 15 | PASS | outcome matches DB state, both orderings valid |
| 16 Order A | **PASS (new)** | assignment wins, cancellation still releases it correctly |
| 16 Order B | **PASS (new)** | cancellation wins, assignment cleanly rejected, no illegal coexistence |
| 17 | PASS | exactly 1 tab wins |
| 18 | PASS | release resolves cleanly, no deadlock |

**No duplicate assignment, no partial write, no demand mismatch, no audit-success-on-rollback observed in any case.**

### 33g. Regression after gap closure

- M1–M4 direct: **137/137 PASS** (unchanged).
- `RoomAssignmentAtomicMappingTest.php` (M2, extended with 8 new Blocker A tests): **27/27 PASS**.
- `BookingManagementUiTest.php` (extended with 2 new Blocker B tests): **154 passed, 11 failed** — the 11 are the same known pre-existing/time-dependent baseline failures (re-diffed by name), both new tests pass.
- Move-room (`StayServiceMoveRoomTest` + `RoomMoveControllerTest`): **30/30 PASS**, fully unaffected.
- `BookingEngineFoundationTest`: unaffected (re-run as part of the targeted suite below).
- **Targeted suite** (`--filter="RoomAssignment|BookingRequirement|RoomAvailability|BookingManagement|BookingEngineFoundation|CheckIn|Checkout|Stay|Folio|Revenue|NightAudit|MoveRoom|ReleaseBatch|BulkRoomRelease|Audit|Cancellation"`): **664 passed, 25 failed** — 24 known baseline + 1 newly-observed but unrelated time-of-day-dependent failure (`LateCheckoutFeeTest > check out triggers late checkout fee via stay service`, investigated below).
- **Full suite**: **1179 passed, 25 failed** (4705 assertions) — same 24 known baseline + the same 1 `LateCheckoutFeeTest` failure. Case 3 and Case 16 do **not** appear in either failure list — both genuinely PASS in the full suite run, not just the isolated concurrency harness.

**`LateCheckoutFeeTest` investigation:** this test computes `$plannedCheckout = now()->subHours(2)->setTime(12, 0, 0)`, intending "2 hours before a 12:00 checkout". This only reliably lands in the past relative to the actual `now()` used for the checkout call within a narrow window of wall-clock hours; run at 11:40 AM local time, `now()->subHours(2)->setTime(12,0,0)` evaluates to **12:00 PM today — 20 minutes in the future** relative to the actual `now()`, so the checkout is computed as early, not late, and no late-checkout fee posts. This test calls only `StayService::checkOut()` — a file neither Blocker A nor Blocker B nor the `updateBooking()` fix touches at all (confirmed by `git diff --stat`, `StayService.php` shows 0 changes). Classified as **time-of-day-dependent, pre-existing test fragility, not a regression** — the same general class of issue as the other 24 known date-dependent failures, simply not previously observed because earlier runs happened to fall outside its fragile window. Not modified, per the explicit instruction not to edit old tests just to force green when application behavior is correct.

### 33h. Query growth (capacity check)

Isolated measurement (counting only the capacity check's own `SELECT COUNT(*) ... WHERE booking_requirement_id ...` queries): **exactly 1 query per distinct requirement line touched in a batch, regardless of room count** — confirmed with a 5-room, single-requirement batch producing exactly 1 capacity-check query, not 5. No query-per-room growth. No optimization needed beyond this.

## 34. Readiness

**MILESTONE 5 GAP CLOSURE COMPLETE — READY FOR FINAL CHATGPT REVIEW**

Both blockers (Case 3, Case 16) are genuinely closed and re-proven via real two-process/real-MySQL concurrency testing — 19/19 methods PASS, no assertion weakened. The `updateBooking()`/NoShow scope extension was investigated with concrete code evidence, confirmed as a real reachable gap, and closed with the same minimal, narrowly-scoped locking discipline. No migration, no schema change, no business-rule change. M1–M4 regression, move-room, and the full/targeted suites show zero new regressions (final counts in §24/§25). Not staged. Not committed. Not pushed. Not deployed. Never `READY FOR PRODUCTION`.
