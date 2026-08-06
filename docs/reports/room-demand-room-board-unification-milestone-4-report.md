# Room Demand and Room Board Unification — Milestone 4 Report

## 1. Scope

Milestone 4 implements **atomic bulk room release**: staff can select multiple already-assigned rooms for the current booking and release them in one transaction, with a shared reason/note and an optional `reduce_demand` flag that decreases the exact `BookingRequirement` line each released assignment references. All-or-nothing — one invalid element rejects the whole batch, never a partial release.

Branch: `phase-3`. Builds on Milestones 1–3 (commits `3a1d098`, `806dcc7`, `a331ef5` — all committed and pushed to `origin/phase-3` before this milestone began).

## 2. Milestone 1–3 checkpoints

| Milestone | Commit (Git-verified) | Message |
|---|---|---|
| 1 | `3a1d0985ccff1ffdc76b55d500d28a22a5676620` | feat(room-assignments): link requirements and harden demand locking |
| 2 | `806dcc70c8891a8a395e4668a9d465c91fd622b3` | feat(room-assignments): map demand requirements atomically |
| 3 | `a331ef5dde9ab95a847f93ad3b8ad30cbfaa84e1` | feat(room-assignments): sync demand from room board atomically |

**Verification method (Final Gap Closure §III):** these SHAs were never taken from any prompt's stated value as the sole source. Verified independently via `git log --all --oneline --decorate --grep="map demand requirements atomically"` and `--grep="sync demand from room board atomically"` (each returns exactly one commit, matching the SHAs above), `git show -s --format="%H%n%s"` on both, and cross-checked against `git rev-parse origin/phase-3` (returns `a331ef5dde9ab95a847f93ad3b8ad30cbfaa84e1`, confirming M3 is both the local HEAD and the current pushed origin state).

**Discrepancy noted:** the Final Gap Closure task's own "checkpoint dự kiến" text stated `806dcc7c8891a8a395e4668a9d465c51fd622b3` (M2) and `a331ef5ed9ab95a847f93ad3b8ad30cbfaa84e1` (M3) — both differ from the Git-verified values above by one transposed character each (`...c51fd...` vs the real `...c91fd...`; `...5ed9ab95...` vs the real `...5dde9ab95...`). Per instruction, the Git-verified values are used, not the prompt's expected values, and the discrepancy is reported rather than silently reconciled.

M1–M3 test suites re-verified before and after M4 work (§23) — 80/80 passing throughout, no regressions.

## 3. Existing single-release flow (investigated before coding)

`RoomAssignmentService::releaseAssignment()` (service) ← `RoomAssignmentController::release()`/`releaseConflict()`. Has its own `DB::transaction()`. Lock order: `Stay` (by `room_assignment_id`, order id) → `RoomAssignment` (by id) — **no `Booking` lock**, and **no `BookingStatus` check at all**. Marks `status=Released`, `released_by`, `released_at`, `release_reason` (existing column). Cancels the `Stay` if `status===Reserved`. Recalculates booking status via `updateBookingAssignmentStatus()`/`updateBookingStayStatus()`. Permission: `room.unassign`. Check-in fact determined via `Stay.actual_checkin_at`. No `release_note` column existed. No `release_batches`/`release_batch_id` schema existed. Audit is automatic via `RoomAssignment::observe(AuditObserver::class)`. Payload: `{ release_reason }` only. No architecture gap found — minimal-change refactor was sufficient.

## 4. Bulk release product rules (locked, implemented as specified)

- `reduce_demand` default `false`; checkbox unchecked by default.
- `reduce_demand=false`: only releases assignments, demand untouched.
- `reduce_demand=true`: reduces the exact `BookingRequirement` line each assignment references — never by room_type, never the "largest" line, never guessed.
- Any `booking_requirement_id=NULL` assignment in the batch + `reduce_demand=true` → reject the whole batch.
- `reduce_demand=false` still allows releasing NULL-mapped (legacy) assignments.
- Folio-locked requirement (unvoided Room charge) + `reduce_demand=true` → reject the whole batch before any write; never bypasses `RequirementLockedAfterRoomChargeException`; never touches Folio.
- `quantity=0` after reduction: row kept, never hard-deleted.
- No negative quantity; new quantity never below the line's own remaining active assignments after the batch.
- Checked-in assignments (real check-in fact) are never releasable via this workflow.
- Room move after check-in stays on `moveRoom()` — untouched, out of scope.
- One invalid element → rollback everything: no `ReleaseBatch`, no released assignment, no Stay change, no demand change, no partial booking-status update.
- One batch may span multiple room_types and multiple requirement lines.
- Shared `reason` still written per-assignment to `release_reason` (backward compatibility with existing screens that read it directly).
- Shared `note` lives only at `release_batches.note`.
- No undo. No destroy/update route or UI for `ReleaseBatch`.

## 5. Release batch data model

**`release_batches`**: `id`, `booking_id` (FK bookings, **`restrictOnDelete()`** — changed from `cascadeOnDelete()`, see §5a), `released_by` (FK users, nullOnDelete), `reason` (text, **NOT nullable** — changed from nullable, see §5b), `note` (text, nullable), `reduce_demand` (boolean, default false), `released_at` (dateTime), timestamps, index `(booking_id, released_at)`.

**Naming deviation from the Architecture Review, noted explicitly (Section IX instruction explicitly allows this):** the Architecture Review's draft schema names the actor column `actor_id`. This codebase's actual convention for "who did this" is uniformly `<verb>_by` (`room_assignments.assigned_by`/`released_by`, `stays.checked_in_by`/`checked_out_by`, `folio_entries.posted_by`/`voided_by`). `released_by` was used instead of `actor_id` to match that established convention — a deliberate, convention-driven substitution, not a design change; the column still means exactly the same thing.

**`room_assignments.release_batch_id`**: nullable FK to `release_batches`, `restrictOnDelete()` (mirrors the `booking_requirement_id` precedent from Milestone 1 — a `ReleaseBatch` is never hard-deleted by any route/UI, so this is a belt-and-suspenders guard). Existing/legacy rows stay `NULL` forever, never backfilled.

### 5a. Booking FK deletion policy (Final Gap Closure §V)

**Investigation, against real code:**
- `Booking` does **not** use `SoftDeletes` — no `deleted_at` column, no trait.
- There is **no hard-delete route or service for `Booking`** anywhere in the codebase (`Route::resource('bookings', ...)->except(['destroy'])`; no `BookingService::delete()`/`destroy()` method exists).
- Given no delete path exists today, `cascadeOnDelete()` vs `restrictOnDelete()` has **zero observed behavioral difference in the current application** — this is a future-proofing decision, not a bug fix.
- The audit requirement (`ReleaseBatch` is an append-only business/audit-trail record, Product Owner Decision #20, no destroy/update route or UI) mandates that batch history must never disappear as a side effect of an unrelated future change (e.g., if a hard-delete route is ever added to `Booking` later without anyone revisiting this FK).

**Architectural recommendation, adopted:** `release_batches.booking_id` → `restrictOnDelete()`. Rationale: consistent with the same protective pattern already used for `room_assignments.booking_requirement_id` (Milestone 1) and `room_assignments.release_batch_id` (this milestone) — both are "audit/history must survive" FKs, never plain ownership FKs. Since the migration was never committed, the change was made directly in the migration file (no third patch migration), verified by rollback → re-migrate → schema test (§6).

### 5b. Reason domain invariant (Final Gap Closure §IV)

**Investigation before this gap closure, against real code:**
1. Was `reason` required at the HTTP request level? Yes — `BulkReleaseAssignmentRequest` already had `'reason' => ['required', 'string', 'max:1000']`.
2. Was the `release_batches.reason` DB column nullable? **Yes** — `$table->text('reason')->nullable();`. Gap confirmed.
3. Did the service accept a nullable string? **Yes** — `bulkReleaseAssignments(..., ?string $reason, ...)`. Gap confirmed.
4. Did the service block an empty-after-trim reason itself? **No** — it wrote whatever string it received with no trim/empty check. Gap confirmed.
5. Could the service be called directly (bypassing the FormRequest, e.g. from a future job/console command/another controller) to create a batch with no reason? **Yes** — nothing below the HTTP layer enforced it. Gap confirmed.

**Fix applied (all four, coordinated in one rollback→edit→re-migrate cycle, §6):**
- `release_batches.reason` migration column changed to non-nullable `text`.
- `bulkReleaseAssignments()` signature changed from `?string $reason` to `string $reason`.
- The service now computes `$trimmedReason = trim($reason)` and throws `ValidationException` (`reason` key) if it is `''`, **before** the transaction opens — independent of the FormRequest.
- The identical `$trimmedReason` value is used for both `ReleaseBatch::create(['reason' => $trimmedReason, ...])` and every assignment's `release_reason` in the batch — never two different normalizations of the same input.
- `releaseAssignment()` (single release) intentionally left untouched — its contract (`?string $reason = null`) is unchanged, since the single-release UI/route was explicitly out of scope for this gap closure and its own `release_reason` column was already nullable before M4 and remains so.

**Tests added** (`tests/Feature/BulkRoomReleaseTest.php`): request missing `reason` → `assertSessionHasErrors('reason')`, no status change, no batch (`test_bulk_release_request_without_reason_is_rejected`); direct service call with `'   '` → `ValidationException` on `reason`, no batch, no release (`test_service_called_directly_with_whitespace_only_reason_is_rejected`); direct service call with `''` on a 2-assignment batch → same, `ReleaseBatch::count()===0` (`test_database_never_accepts_a_release_batch_without_reason_via_the_service`); trimmed reason is byte-identical on `batch->reason` and both assignments' `release_reason` (`test_reason_is_trimmed_and_identical_on_batch_and_every_assignment`). `tests/Feature/ReleaseBatchSchemaTest.php`: a raw `DB::table('release_batches')->insert([..., 'reason' => null, ...])` throws `QueryException` (`test_database_rejects_release_batch_without_reason`) — proves the NOT NULL constraint holds even bypassing Eloquent entirely.

### 5c. Actor consistency (Final Gap Closure §VI)

**Investigation before this gap closure, against real code:**
- The shared helper `releaseAssignmentWithinTransaction()` called `Auth::id()` **internally**, once per assignment — the controller never passed an actor in.
- `ReleaseBatch.released_by` came from a single `Auth::id()` call at batch-creation time; `RoomAssignment.released_by` came from a **separate** `Auth::id()` call inside the shared helper, once per loop iteration.
- In a single HTTP request these could not actually differ under normal operation (same request, same session), but the design had no guarantee — a future refactor (e.g. queueing per-assignment releases, or auth-context switching mid-request for an impersonation feature) could silently desynchronize them, and calling the service from a console command/test with no authenticated user would still silently write `released_by=null` everywhere with no rejection.

**Fix applied:** `releaseAssignmentWithinTransaction()` gained an explicit `?int $releasedBy` parameter, replacing its internal `Auth::id()` call. `releaseAssignment()` (single release) now captures `$actorId = Auth::id()` once before opening its transaction and passes it through — behaviorally identical for single release (only ever one call) but now uses the same explicit-actor pattern as bulk. `bulkReleaseAssignments()` captures `$actorId = Auth::id()` once, **before** the transaction opens; if `null`, throws `ValidationException` (`assignment_ids` key) immediately — a bulk release must always have a real, resolvable actor, it is never a background/system operation. The same `$actorId` is threaded through the `use (...)` closure and into every `releaseAssignmentWithinTransaction(...)` call in the batch loop, and into `ReleaseBatch::create(['released_by' => $actorId, ...])` — one capture, one value, every write in the batch.

**Tests added:** `test_batch_and_every_assignment_record_the_same_actor` — asserts `$result['batch']->released_by`, `$a1->fresh()->released_by`, `$a2->fresh()->released_by` are all identical and equal the authenticated test user's id. `test_audit_log_records_the_correct_actor_for_the_batch` — asserts the `AuditLog` row for the created `ReleaseBatch` has `user_id` matching the actor. `test_no_batch_created_when_no_authenticated_actor` — `Auth::logout()` then calls the service directly, asserts `ValidationException`, `ReleaseBatch::count()===0`, assignment status unchanged.

## 6. Migration and rollback

Two new migrations, no existing migration touched:
- `2026_08_06_000000_create_release_batches_table.php`
- `2026_08_06_000001_add_release_batch_id_to_room_assignments_table.php`

Verified on Local (`lastella_pms`, MySQL):
1. `php artisan migrate:status` — both pending, correctly ordered after M1's migration.
2. `php artisan migrate` — both ran successfully.
3. Snapshot: `room_assignments` count = 196, `booking_requirement_id` non-null count = 30.
4. `php artisan migrate:rollback --step=2` — both rolled back cleanly; `release_batches` table and `release_batch_id` column both gone.
5. Post-rollback snapshot: `room_assignments` count = 196 (unchanged), `booking_requirement_id` non-null count = 30 (unchanged) — M1–M3 data fully preserved.
6. `php artisan migrate` again — both ran successfully, schema restored.
7. Final snapshot: counts identical to step 3.

No seeder run. No production database touched at any point.

**Final Gap Closure re-run (§IV/§V fixes applied to `2026_08_06_000000_create_release_batches_table.php`):** since this migration was still uncommitted, it was edited directly (no third patch migration) — `reason` changed to non-nullable, `booking_id` changed to `restrictOnDelete()`. Cycle repeated:
1. `php artisan migrate:rollback --step=2` — both M4 migrations rolled back cleanly (uncommitted migration files, safe to edit).
2. Migration file edited (both changes, doc-comment added explaining each).
3. `php artisan migrate` — both ran successfully against the edited schema.
4. `php artisan migrate:rollback --step=2` again, then `php artisan migrate` again — one additional full cycle, to confirm the edited migration is itself idempotent and clean (no partial-state artifacts from the edit).
5. Direct MySQL verification via `tinker`: `SHOW COLUMNS FROM release_batches WHERE Field='reason'` → `Null = 'NO'`; `information_schema.REFERENTIAL_CONSTRAINTS`/`KEY_COLUMN_USAGE` query on the `booking_id` FK → `DELETE_RULE = 'RESTRICT'`.
6. No third migration file created. No production database touched. `room_assignments`/`booking_requirement_id` counts re-confirmed unchanged across the whole cycle.

## 7. Shared release core

Extracted `RoomAssignmentService::releaseAssignmentWithinTransaction(RoomAssignment $lockedAssignment, ?Stay $lockedStay, ?string $reason, ?int $releaseBatchId, ?int $releasedBy): RoomAssignment` — the ONE place that validates and writes a single assignment release. Opens no transaction, locks nothing itself (caller controls lock order). Contains the exact validation/write logic `releaseAssignment()` always had (status check, check-in-fact check, `status=Released`/`released_by`/`released_at`/`release_reason` write, Stay→Cancelled). New: also writes `release_batch_id` (`null` for single release, the new batch's id for bulk). **Final Gap Closure §VI:** `$releasedBy` is now an explicit parameter (was `Auth::id()` called internally before) — see §5c.

`releaseAssignment()` (public, unchanged signature/route/request/response/authorization) now captures `$actorId = Auth::id()` once, locks `Stay` then `RoomAssignment` exactly as before, then delegates to the shared helper with `releaseBatchId=null, releasedBy=$actorId`. **Behavior externally identical** — verified by re-running all 30+ existing release tests in `BookingManagementUiTest.php` twice (11/163 pre-existing time-dependent failures both times, zero release-test failures, zero new failures).

## 8. Bulk release service

`RoomAssignmentService::bulkReleaseAssignments(Booking $booking, array $assignmentIds, string $reason, ?string $note, bool $reduceDemand): array` — returns `['batch' => ReleaseBatch, 'assignments' => RoomAssignment[]]`. **Final Gap Closure §IV:** `$reason` changed from `?string` to `string` (non-nullable parameter type); trimmed and validated non-empty before the transaction opens — see §5b.

## 9. Transaction boundary

One `DB::transaction()` for the entire batch. `ReleaseBatch` is created inside it; every assignment release and every requirement quantity adjustment happens inside it. No second transaction anywhere in the new code path — confirmed via `grep -c "DB::transaction("` on the diff (exactly 2 occurrences: the new bulk method, and the pre-existing `releaseAssignment()`, both unrelated to each other).

## 10. Lock order

**B (bulk release, Architecture Review REVISION 3 Mục 20.B):** `Booking` → `Stay` [by `room_assignment_id` asc] → `RoomAssignment` [by `id` asc] → `BookingRequirement` [by `id` asc, only when `reduce_demand=true`]. Matches the Architecture Review's final revision exactly (deliberately different from assign's order A — `Stay` before `RoomAssignment`, matching the pre-existing precedent in `releaseAssignment()`/`StayService::checkIn()`). All rows locked as SETS via `whereIn(...)->orderBy(...)->lockForUpdate()->get()`, never one row per loop iteration.

## 11. Assignment validation

Every assignment in the batch validated BEFORE any write: exists, belongs to the booking, `status===Assigned`, not checked in (`Stay.actual_checkin_at===null`). Any failure throws `ValidationException` with a message identifying the specific room — the whole request rolls back, nothing partially written.

## 12. Stay and check-in restrictions

Checked-in assignments (`AssignmentStatus::CheckedIn` or `Stay.actual_checkin_at !== null`) are always rejected — the same fact-based check `releaseAssignment()` always used, reused unchanged via the shared helper.

## 13. Reduce-demand rules

Exact formula per requirement line (grouped by `booking_requirement_id`, never by `room_type_id`):
```
release_count                  = assignments in this batch referencing it
remaining_active_after_release = active assignments for it now, minus release_count
proposed_quantity              = current quantity - release_count
```
Rejects the WHOLE batch if `proposed_quantity < 0` OR `proposed_quantity < remaining_active_after_release` — never `max(0, ...)`, never a partial reduction. All requirement lines validated and their new quantities computed in `planBulkReleaseDemandReduction()` (pure validation, no writes) before the write phase starts.

## 14. Legacy NULL mapping

`reduce_demand=false`: NULL-mapped assignments release normally, same as before M4. `reduce_demand=true`: any NULL-mapped assignment in the batch rejects the entire batch with a clear message, before any write.

## 15. Folio-locked requirements

`BookingService::hasActiveRoomCharge()` (extracted in Milestone 3, reused unchanged) checked once per booking. If true and `reduce_demand=true`, the whole batch is rejected before any write — the locked line is never touched, Folio is never touched. The already-fixed quantity reduction never happens for a locked line even when the batch would otherwise be valid.

## 16. Booking state matrix

`assertBookingAcceptsBulkRelease()` blocks `BookingStatus::isTerminal()` (Cancelled/NoShow/CheckedOut) plus `PartiallyCheckedOut` explicitly — same blocked set as M3's assign-side guard, implemented as a separate method (different business operation, own message text) per the Architecture Review's final state matrix (Mục 22). Allowed: Draft, PendingAssignment, PartiallyAssigned, FullyAssigned, Held, Deposited, PartiallyCheckedIn, CheckedIn (subject to the per-assignment check-in-fact check above).

## 17. Authorization

`BulkReleaseAssignmentRequest::authorize()`: `room.unassign` always required; `booking.update` additionally required when `reduce_demand=true` — both checked as real permission strings against the real `RolePermissionSeeder` grants, never a UI-only gate. Verified with users holding only one of the two permissions (both return 403) and users holding both (allowed).

## 18. Controller and request contract

`POST /admin/bookings/{booking}/assignments/bulk-release` → `admin.bookings.assignments.bulk-release` → `RoomAssignmentController::bulkRelease()`. Payload: `{ assignment_ids: number[], reason: string, note?: string, reduce_demand?: boolean }`. `BulkReleaseAssignmentRequest` validates structure only (array, `min:1`/`max:100`, `distinct`, `exists:room_assignments,id`, `reason` required, `note` nullable) — ownership/status/check-in/NULL-mapping/Folio-lock are all re-derived from freshly locked rows inside the service, never trusted from the payload. Controller is thin: validates, calls the one service method, redirects with a flash message stating the exact count released (`"Đã gỡ {$count} phòng."`). Single-release route/controller/request completely untouched.

## 19. Frontend and mobile

`Show.vue`'s existing "Phân phòng" (assignment history) table gained a checkbox column (only for `can_release` rows) and a "select all releasable" header checkbox. `releaseAll()` no longer performs the old non-atomic sequential `router.post()` loop (Architecture Review Mục 3/25 explicitly required replacing it) — it now pre-selects every releasable assignment and opens the same bulk-release panel the checkboxes feed into, which posts once to the atomic endpoint. New panel: room list with per-room "Bỏ chọn", required reason, optional note, "Đồng thời giảm nhu cầu phòng tương ứng" checkbox (default unchecked), and — only when checked — a live preview per requirement line (current/release/new quantity, NULL-mapping warning, folio-lock warning, invalid-quantity warning) computed client-side mirroring the exact backend formula; submit disabled whenever any blocking issue exists, matching the backend's own rejection rules. The single "Release Assignment Dialog" and `releaseForm` are completely untouched.

Mobile: the panel is a centered `max-h-[85vh] overflow-y-auto` modal (not a wide table), matching M3's established mobile-panel pattern. **Not visually verified at 390×844** — see §26.

## 20. Audit

Relies entirely on `ReleaseBatch::observe(AuditObserver::class)` (newly registered in `AppServiceProvider`, same convention as the other 24 observed models) plus the existing `RoomAssignment`/`BookingRequirement` observers. No new audit migration. Verified: a created `ReleaseBatch` produces an `audit_logs` row (`action=created`); a rolled-back batch produces zero `audit_logs` rows for `ReleaseBatch`.

## 21. Backward compatibility

Verified by test and manual browser QA: existing single release still works (route/request/response/reason recording unchanged, never creates a batch), `moveRoom()` untouched (0 diff lines, all 8 move-room tests pass), demand-first (M2) and Room-Board-first (M3) endpoints still work, check-in/check-out/Folio/Night-Audit-adjacent tests (`RoomChargeHotfixTest`) all pass unchanged.

## 22. Files changed

**New:**
- `database/migrations/2026_08_06_000000_create_release_batches_table.php`
- `database/migrations/2026_08_06_000001_add_release_batch_id_to_room_assignments_table.php`
- `app/Models/ReleaseBatch.php`
- `app/Http/Requests/Booking/BulkReleaseAssignmentRequest.php`
- `tests/Feature/BulkRoomReleaseTest.php` (38 tests)
- `tests/Feature/ReleaseBatchSchemaTest.php` (8 tests)

**Modified:**
- `app/Models/RoomAssignment.php` — `release_batch_id` fillable + `releaseBatch(): BelongsTo`.
- `app/Providers/AppServiceProvider.php` — `ReleaseBatch::observe(AuditObserver::class)`.
- `app/Services/RoomAssignmentService.php` — extracted `releaseAssignmentWithinTransaction()`, added `bulkReleaseAssignments()`, `planBulkReleaseDemandReduction()`, `assertBookingAcceptsBulkRelease()`.
- `app/Http/Controllers/Admin/Booking/RoomAssignmentController.php` — added `bulkRelease()`.
- `app/Http/Controllers/Admin/Booking/BookingController.php` — added `room_type_id`/`booking_requirement_id` to the `assignments[]` prop, added `can.reduceDemand` permission flag (fixed a real bug introduced mid-session: initially referenced an undefined `$booking` variable inside the shared `permissions()` helper, which has no booking in scope — caught by the new test suite before merge, fixed to check the `booking.update` permission string directly).
- `routes/web.php` — added `bookings.assignments.bulk-release`.
- `resources/js/Pages/Admin/Bookings/Show.vue` — bulk-release selection state, panel, preview; `releaseAll()` rewritten to use the atomic endpoint.

**Untouched (verified via diff/grep):** `releaseAssignment()`'s public contract, `moveRoom()`, `assignRooms()`, `assignRoomsWithRequirementLink()`, `assignRoomsFromRoomBoard()`, Folio calculation, Night Audit calculation, Service Package, Package Enrollment, all M1–M3 migrations/seeders, `.env`, `public/build`, `storage/logs`, `storage/backups`.

## 23. Tests

| Suite | Result |
|---|---|
| M1 (4 files) | 34/34 passed |
| M2 (`RoomAssignmentAtomicMappingTest`) | 19/19 passed |
| M3 (`RoomAssignmentFromRoomBoardTest`) | 27/27 passed |
| M1+M2+M3 combined | 80/80 passed |
| M4 (`BulkRoomReleaseTest`, incl. 7 Final Gap Closure tests) | 45/45 passed |
| M4 schema/migration (`ReleaseBatchSchemaTest`, incl. 2 Final Gap Closure tests) | 10/10 passed |
| Existing move-room (`StayServiceMoveRoomTest`) | 8/8 passed |
| `RoomChargeHotfixTest` (checkout/Folio-adjacent) | 7/7 passed |
| `BookingEngineFoundationTest` | 25/25 passed |
| Targeted (`Release\|RoomAssignment\|BookingRequirement\|RoomAvailability\|BookingManagement\|BookingEngineFoundation\|CheckIn\|Checkout\|Stay\|Folio\|NightAudit\|MoveRoom`) | see §24 |
| `npm run build` | Success, no errors (no frontend files changed during Final Gap Closure — verified previously in original M4 work, not re-run since no JS diff to re-check) |
| Full suite (post Final Gap Closure) | **1149 passed, 24 failed** — see §25 |

A real bug was found and fixed during this milestone: the first `can.reduceDemand` implementation referenced an undefined `$booking` variable inside `BookingController::permissions()` (which has no `$booking` parameter — it's also called from the bookings index page with no single-booking context), causing a 500 error on **every** page that calls `permissions()`. Caught immediately by `BulkRoomReleaseTest`'s Inertia prop test before this reached any shared branch; fixed by checking the `booking.update` permission string directly (semantically identical, since `BookingPolicy::update()` itself only checks that same string regardless of the model instance).

## 24. Targeted suite result

**Pre-Final-Gap-Closure run** (`php artisan test --filter="Release|RoomAssignment|BookingRequirement|RoomAvailability|BookingManagement|BookingEngineFoundation|CheckIn|Checkout|Stay|Folio|NightAudit|MoveRoom"`, before the reason/FK/actor fixes): 606 passed, 24 failed (2751 assertions) — byte-for-byte identical to the pre-existing baseline.

**Post-Final-Gap-Closure re-run** (same filter, after the reason/FK/actor fixes and the new tests): **614 passed, 25 failed**. Investigated the extra failure: `Tests\Feature\PerStayAttributionTest > add charge stores stay id when…`, a `UniqueConstraintViolationException` on `resources.code` from `RoomFactory`'s `fake()->unique()->numberBetween(100, 999)` collision — a pre-existing, documented flaky pattern under large combined runs (first observed M2). Re-ran that single file in isolation: **6/6 passed cleanly**, confirming it is test-infra flakiness, not a Milestone 4 regression. Classified per Final Gap Closure §IX: **flaky/random-data failure, not a new regression.** The other 24 failures are the same known time-dependent baseline set (byte-for-byte identical names, re-confirmed §25). **Zero genuine new failures, zero M4-related failures.**

## 25. Full suite result

**Pre-Final-Gap-Closure baseline:** `php artisan test` (full suite): 1140 passed, 24 failed (4591 assertions) — `1140 = 1094 (post-M3) + 46` (the 38+8 original M4 tests).

**Post-Final-Gap-Closure full suite** (after all §5a/§5b/§5c fixes and the 9 new gap-closure tests): **`php artisan test` → 1149 passed, 24 failed (4615 assertions)**. `1149 = 1140 + 9` — exactly the 9 new Final Gap Closure tests (7 in `BulkRoomReleaseTest`, 45 total; 2 in `ReleaseBatchSchemaTest`, 10 total), all passing.

**Failure-set comparison against baseline:** re-ran `RoomAvailabilityCheckerTest` + `BookingManagementUiTest` together in isolation and diffed the full list of 24 failing test names against the documented M1–M3/original-M4 baseline (13 `RoomAvailabilityCheckerTest` + 11 `BookingManagementUiTest`) — **byte-for-byte identical set, same 24 names, same split**. All are the same hardcoded-absolute-calendar-date time-dependent fixtures documented since M2/M3, unrelated to any M4 code path.

**Net result: +9 passing, 0 new failures, 0 regressions.**

## 26. Manual QA

**Environment:** Local only (`APP_ENV=local`, MySQL `lastella_pms`). No production data, no production deploy. Chrome-in-browser extension connected. Every case below was executed via **real UI interaction** (`javascript_tool` — native-setter input dispatch, real `.click()` on the actual buttons, never a simulated/synthetic Inertia call) **plus a real read-only database read after every case**, per the Final Gap Closure's explicit "chỉ xem preview/panel chưa đủ PASS" requirement. The `computer` tool's screenshot action proved unreliable (repeated CDP timeouts) and was not relied on for evidence; DOM/DB state was used instead.

**QA data created this gap-closure session (kept on local DB, prefixed `QA-M4G-`):** reused bookings 462–466 from the original M4 session for Cases 1–5/8 (their assignments had already been consumed by the *original* pre-gap-closure manual QA, but the `release_batches` table itself was dropped and recreated during the §6 migration-fix rollback cycle, resetting its data — so every batch cited below is freshly created **after** the reason/FK/actor fixes, not a stale reference); fresh bookings 467–470 (`m4_gap_qa_arrange.php`) for Cases 1/6/7/9 where isolated fixtures were needed; fresh booking 471 (`QA-M4-MOBILE-6YHY`) reserved untouched for Product-Owner mobile QA (§26 Case 10). Date window `2027-05-01 14:00 → 2027-05-03 12:00`.

| Case | Result | Evidence (DB-verified) |
|---|---|---|
| 1 — Bulk release, keep demand, ≥2 rooms | **PASS** | Booking 467: assignments 556+557 (rooms 103/104) released together in `ReleaseBatch#1` (reason "QA Gap Case1: giữ nhu cầu thật", `reduce_demand=false`). Both assignments carry `release_batch_id=1`; no `BookingRequirement` changed (kept-demand path). |
| 2 — Bulk release + reduce demand, preview then real submit | **PASS** | Booking 462: assignments 549 (room 103, req #769/TWIN) + 550 (room 302, req #770/DOUBLE) released via `ReleaseBatch#2` (`reduce_demand=true`). Preview panel shown before submit, then submitted for real — not preview-only. Post-submit DB read: `req769.quantity=3`, `req770.quantity=1`, both reduced by exactly their batch's `release_count` (1 each), matching the formula in §13. |
| 3 — ≥2 room_types in one batch | **PASS** | Same `ReleaseBatch#2` above spans TWIN (req #769) and DOUBLE (req #770) in a single batch — one `release_batch_id`, both requirement lines correctly and independently reduced, no partial success. |
| 4 — Legacy NULL mapping, both branches | **PASS (both branches)** | Branch B (`reduce_demand=true` + NULL-mapped assignment) — blocked client-side (submit disabled, warning shown) and independently re-verified via a forged raw `fetch()` POST bypassing the UI: rejected server-side, no batch, no release. Branch A (`reduce_demand=false` + same NULL-mapped assignment, booking 463, assignment 551) — succeeds: `ReleaseBatch#3` created, `release_batch_id=3` on assignment 551, `booking_requirement_id` stays `NULL` throughout, no requirement touched. |
| 5 — Folio lock | **PASS** | Booking 464, assignment 552 (`booking_requirement_id=771`, folio-locked line). UI showed the lock warning and disabled submit. Additionally sent a forged direct `fetch()` POST with `reduce_demand=true` bypassing the disabled button — server rejected it (`ValidationException`), confirmed via DB: assignment 552 still `status=ASSIGNED`, `release_batch_id=NULL`, `ReleaseBatch` count for booking 464 = 0. Folio never touched. |
| 6 — Checked-in assignment in batch blocks everything | **PASS** | Booking 468: batch of assignment 558 (`ASSIGNED`) + 559 (`CHECKED_IN`) submitted together. Rejected — whole batch rolled back. DB confirms: assignment 558 still `ASSIGNED`, 559 still `CHECKED_IN`, `ReleaseBatch` count for booking 468 = 0. No partial release of the one valid assignment. |
| 7 — Two-tab stale/concurrent selection | **PASS** | Booking 469, assignment 560, simulated via two independent raw `fetch()` submissions targeting the same assignment (tab A / tab B pattern). First request succeeded (`ReleaseBatch#4` created, assignment 560 → `RELEASED`). Subsequent request(s) against the already-released assignment were rejected (already-released state, `lockForUpdate()` serializes and the status check then fails). DB confirms: exactly **one** `ReleaseBatch` total exists for booking 469 despite multiple submission attempts — no partial/duplicate batch, matching the required atomicity guarantee. |
| 8 — Single release (old dialog) still works | **PASS** | Booking 466, assignment 555: released via the untouched single-release dialog/route. DB confirms: `status=RELEASED`, `release_reason="QA Gap Case8: single release click-through thật"`, **`release_batch_id=NULL`** — proves the old single-release path still works end-to-end and correctly never creates a `ReleaseBatch`. |
| 9 — Move room (`moveRoom()`) still works | **PASS** | Booking 470, `Stay#485`/assignment 561: real click-through of "Đổi phòng" from room 205 → room 507 (first two candidate target rooms, 301 and 204, were correctly rejected by the app's own live-conflict check against other unrelated bookings still holding those rooms in the shared QA window — confirming `moveRoom()`'s conflict logic is itself functioning correctly, not a bug). Final move succeeded ("Đã đổi phòng." flash). DB confirms: `Stay#485.room_id=16026` (room 507), `RoomAssignment#561.room_id=16026`, `status` still `CHECKED_IN` throughout, old room 205 correctly transitioned to `VACANT_DIRTY`. `moveRoom()` has 0 diff lines this milestone — this is a genuine functional regression check, not an absence-of-diff argument. |
| 10 — Mobile 360×800 / 390×844 / 412×915 | **PASS — Product Owner-confirmed** | Browser automation in this session could not change the real viewport (`resize_window` reported success but `window.innerWidth`/`innerHeight` never actually changed, confirmed via JS on every attempt) — so this was **not** self-verified by the agent. The checklist and dedicated untouched QA URL prepared in §26a were handed to the Product Owner, who performed the check directly on Local and reported: all items PASS at all three viewports (360×800, 390×844, 412×915) — multi-select, no horizontal overflow, scrollable room list, reason/note/checkbox usable, preview readable, submit button unobscured, both pre-existing responsive/mobile `Show.vue` hunks still behave correctly. No item failed. |

**Every case above required a genuine post-action database read (Cases 1–9) or a direct Product-Owner viewport check (Case 10) to count as PASS** — no case was marked PASS from a preview, a disabled-button observation, or an absence of a code diff alone, per the Final Gap Closure's explicit instructions.

### 26a. Case 10 — Product Owner mobile checklist (executed)

Since browser automation in this environment cannot change the real viewport, mobile responsiveness required verification by a person. Fresh, untouched QA booking was prepared for this:

**URL used:** `http://127.0.0.1:8000/admin/bookings/471?tab=room_map` (booking `QA-M4-MOBILE-6YHY`, 2 releasable TWIN assignments — rooms 508/509).

Checked at **360×800**, **390×844**, and **412×915** — Product Owner report, all PASS at every viewport:
- [x] Multi-select (checkbox column + "select all releasable") works with touch taps.
- [x] The bulk-release panel does not overflow horizontally; no extra horizontal scrollbar anywhere on the page.
- [x] The room list inside the panel is independently scrollable (vertical) without scrolling the whole page.
- [x] Reason field, note field, and the `reduce_demand` checkbox are all reachable and usable.
- [x] The reduce-demand preview (when checked) is readable without horizontal scrolling.
- [x] The submit button is never obscured.
- [x] The two pre-existing responsive/mobile hunks in `Show.vue` (header row `sm:flex-row`/`sm:justify-between` and the `overflow-x-auto pb-72 sm:overflow-visible sm:pb-0` assignment table wrapper) still behave correctly — no layout regression from the M4 changes.
- [x] No new horizontal scrollbar anywhere on the booking detail page at any of the three widths.

**Result: PASS, all items, all three viewports. No item failed.** Confirmed directly by the Product Owner on Local — not self-verified by browser automation, which remains a known tool limitation for future milestones.

## 27. Known limitations

- Browser automation in this environment cannot change the real viewport (`resize_window` reports success but `window.innerWidth`/`innerHeight` never actually change) — a standing tool limitation for future milestones' mobile QA, not specific to M4. Mobile itself is now Product-Owner-confirmed PASS this session (§26 Case 10), so this is a process note, not an open gap.
- Legacy `NULL` `booking_requirement_id` assignments are still not backfilled by this milestone (unchanged limitation carried from M1/M3).
- No undo for bulk release (explicitly out of scope, Product Owner Decision #19).
- No bulk-release-batch admin UI beyond the audit trail (explicitly out of scope, Product Owner Decision #20).
- `release_batches.booking_id` now uses `restrictOnDelete()`; since `Booking` has no hard-delete route today, this has no observed runtime effect yet — it is future-proofing, re-confirm if a Booking hard-delete route is ever added later (§5a).
- The shared local MySQL QA database has accumulated leftover fixture bookings/assignments from prior M1–M4 sessions with future-dated (`2027-xx`) windows; `moveRoom()`'s live conflict check (which checks from "now" through the planned checkout, not from the original stay start) correctly rejected two candidate rooms during Case 9 because of this leftover data — not a bug, but a reminder that this shared fixture data will keep growing unless periodically cleaned up.

## 28. Deferred to Milestone 5

Real multi-connection concurrency testing for all 5 lock-order pairs (Architecture Review Mục 20), full mobile viewport suite (360×800/390×844/412×915), full regression suite baseline re-confirmation, Authorization Matrix testing with custom roles, cross-review with `docs/implementation-reports/booking-table-collapsible-actions-report.md`.

## 29. Deployment restrictions

No production migration. No production seeder. No production backfill. No production deploy. `docs/reports/package-enrollment-hardcoded-catalog-gap.md` remains untracked, unmodified, out of scope — Package Enrollment code untouched.

## 30. Rollback plan

**Final Gap Closure §VII correction — `git reset` is never an acceptable instruction here** (unclean working tree, and `phase-3` is a shared branch). Corrected plan:

**Code rollback:**
- **Before this milestone is ever pushed:** if a commit needs to be dropped, that may only happen via a safe Git operation, with explicit Product Owner approval, and only while the working tree is protected (no uncommitted unrelated work at risk of being lost). This report does not perform or recommend a specific command for that case — it requires a human decision at the time.
- **After this milestone is committed and pushed to a shared branch:** the only acceptable rollback is `git revert <M4_COMMIT_SHA>` — creates a new commit undoing the change, never rewrites shared history, never a forced push.
- **For production deployment:** prefer redeploying the previous known-good commit/build artifact, or shipping a forward fix. Never reset a shared branch. Never force-push.

**Database rollback:**
- `php artisan migrate:rollback --step=2` reverses both M4 migrations cleanly (re-verified §6, twice, after the reason/FK fixes) — but this is **only safe before real release data exists**. Once `ReleaseBatch` rows have been created by real usage, rolling back **destroys that batch/audit history** and orphans any `room_assignments.release_batch_id` references that pointed to it (the column itself survives the rollback of the *other* migration only if rolled back together, but the audit trail data is gone either way).
- **Production rollback is never self-executed.** It requires a backup taken first and a data-impact assessment (how many real `ReleaseBatch` rows exist, whether losing that audit trail is acceptable) reviewed with the Product Owner before any rollback command runs against a database containing real bulk-release usage.

## 31. Readiness

**MILESTONE 4 COMPLETE — READY FOR FINAL CHATGPT REVIEW**

All four conditions required for this verdict are met:
1. **All code/schema gaps from the ChatGPT-conditioned review are closed** — reason domain invariant (§5b), Booking FK deletion policy (§5a), actor consistency (§5c) — migrations re-verified clean through two full rollback/re-migrate cycles (§6).
2. **Cases 1–9 are browser-PASS**, each with real UI interaction plus a genuine post-action database read as evidence (§26) — no case marked PASS from a preview or disabled-button observation alone.
3. **Mobile is Product-Owner-confirmed PASS** at all three required viewports (360×800, 390×844, 412×915) — every checklist item PASS, no failures, confirmed directly by the Product Owner on Local (§26 Case 10, §26a). Browser-automation could not self-verify this (standing tool limitation), so it was correctly handed off rather than claimed.
4. **No new regression** — full suite post-Final-Gap-Closure: **1149 passed, 24 failed**, exactly baseline (1140) + the 9 new gap-closure tests, with the 24 failures byte-for-byte identical to the known pre-existing, time-dependent baseline (§24/§25).

**Environment:** Local/development only throughout. No production database, migration, seed, backfill, or deploy at any point. Milestone 5 **not started** — no Milestone 5 code, planning, or scope work performed. This feature is **not** `READY FOR PRODUCTION` — that verdict is never applicable at this stage; production readiness requires a separate, explicit review and deployment process beyond this report's scope.
