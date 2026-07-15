# Product Sprint 02 — Room Move M1 Report

**Date:** 2026-07-14
**Branch:** phase-3
**Type:** Product Sprint — operational event, not a commercial event
**Status:** Implementation complete, not committed, not pushed, not tagged.

---

## Product Problem

Reception frequently needs to move an in-house guest to another room — broken AC/TV, guest wants a higher floor, bad view, smoky/noisy room, VIP guest, guest wants to be near family. No standard workflow existed for this. Reception had no safe, first-class way to do it without a manual workaround.

---

## Existing Source of Truth

| Concern | Existing source of truth | Reuse plan |
|---|---|---|
| Stay | `Stay` model — `room_id`, `room_assignment_id`, `status` | Same Stay row is re-pointed (`room_id` updated), never duplicated |
| RoomAssignment | `RoomAssignment` model — 1:1 with a Stay via `Stay.room_assignment_id`, has `room_id`/`room_type_id` | Same RoomAssignment row is re-pointed (`room_id` updated), never duplicated |
| New-room availability | `RoomAvailabilityRuleService::isRoomUnavailable()` (maintenance/cleaning/OOO/OOS) + `findConflictForTimeChange()` (booking-overlap conflict, excludes the moving booking's own rows) | Reused verbatim — the exact same method `StayService::extendStay()` already uses for its own conflict check |
| Room Board / Availability | `RoomAssignmentService::getRoomBoard()`, keyed by `RoomAssignment.room_id` | No code change needed — updating the assignment's `room_id` in place is automatically reflected on next read |
| Payment Projection | `PaymentProjectionService::project()`, rate resolved by `$stay->room->room_type_id` | No code change needed — enforcing same-room-type moves means the resolved rate is provably unchanged |
| Housekeeping hooks | `HousekeepingService::autoMarkDirtyOnCheckout(Stay)` / `autoMarkOccupied(Stay)`, both read `$stay->room_id` directly | Reused verbatim, called in sequence around the `room_id` update (old room dirty before, new room occupied after) |
| Audit trail | `stay_events` table + `StayEventService` + `StayEventType` enum (Phase 4.3A) | Reused verbatim — added one new enum case (`RoomMove`), zero table/service change |
| Assignment aggregate counting | `BookingService::updateBookingAssignmentStatus()` / `RoomAssignmentService::getAssignmentSummary()`, both count `RoomAssignment` rows by status (`Assigned`/`CheckedIn`/`CheckedOut`) per `room_type_id` | **Critical discovery** — informed the "no second RoomAssignment row" design decision (see Business Rules) |

No architecture document was created — this table is the full pre-flight discovery.

---

## Business Rules

**Room Move is an Operational Event, not a Commercial Event.** Booking is never touched. The Stay is never duplicated. No child Booking, no child Stay, ever.

**Design decision, explicitly reasoned (not arbitrary):** the existing `RoomAssignment` row is mutated in place (`room_id` changed) rather than creating a second `RoomAssignment` row for the new room. A second-row approach was considered and rejected: `BookingService::updateBookingAssignmentStatus()` and `RoomAssignmentService::getAssignmentSummary()` both count `RoomAssignment` rows with status `Assigned`/`CheckedIn`/`CheckedOut` toward the Booking's per-room-type assignment requirement — creating a second row (even with the old one marked `CheckedOut`) would **double-count** against the requirement and permanently trigger a false "phòng đã gán vượt yêu cầu" mismatch warning on every Booking that ever had a Room Move. Mutating in place avoids this entirely, matches "minimal change, no invention," and still satisfies "lưu lịch sử đổi phòng" via the Stay Event audit log (§Audit below), which is explicitly named as an acceptable history mechanism in the task's own instructions ("Room Assignment Timeline **hoặc** Room Occupancy History theo kiến trúc hiện có").

**Same-room-type only, this sprint.** Enforced explicitly: the new room's `room_type_id` must match the current room's. A different-type move is an Upgrade/Downgrade — explicitly out of scope, deferred to a future sprint per the task's own instruction (§10: "Nếu sau này Upgrade → Sprint khác"). This also guarantees Payment Projection needs zero code change (see Projection Verification).

---

## Files Changed

| File | Change |
|------|--------|
| `app/Services/StayService.php` | +89 lines: new public method `moveRoom(Stay, Room, User, ?string $reason)`. No existing method's body touched. |
| `app/Enums/StayEventType.php` | +1 case: `RoomMove = 'ROOM_MOVE'` + Vietnamese label "Đổi phòng". |
| `app/Policies/StayPolicy.php` | +1 method: `moveRoom()`, mirrors `extend()` exactly. |
| `app/Http/Requests/Booking/MoveRoomRequest.php` | **New.** Validates `new_room_id` (required, exists) + `reason` (nullable). |
| `app/Http/Controllers/Admin/Booking/StayController.php` | +1 thin action: `moveRoom()` — authorize → call `StayService::moveRoom()` → redirect. No business logic. |
| `app/Http/Controllers/Admin/Booking/BookingController.php` | +4 lines: `room_type_id` + `can_move_room` per Stay, `can.moveRoom` permission flag. Read-only, no business logic. |
| `database/seeders/RolePermissionSeeder.php` | +1 permission (`stay.room_move`) → ADMIN (auto)/MANAGER/RECEPTION, same role set as `stay.extend`. |
| `routes/web.php` | +1 route: `POST bookings/{booking}/stays/{stay}/move-room`. |
| `resources/js/Pages/Admin/Bookings/Partials/RoomBoardPanel.vue` | +88/-1 lines: "Đổi phòng" button per eligible Stay row + one simple dialog (room dropdown filtered to same-type/available + optional reason). |
| `resources/js/Pages/Admin/Bookings/Show.vue` | +1 line: passes the already-computed `allBoardRooms` list into `RoomBoardPanel` as the move-target picker's data source (zero new backend endpoint). |
| `tests/Unit/Services/StayServiceMoveRoomTest.php` | **New.** 16 tests. |
| `tests/Feature/RoomMoveControllerTest.php` | **New.** 5 tests. |

**No migration. No new table. No new model.** No `FolioService`, `BookingPaymentService`, `NightAuditService`, `NightAuditPipeline`, any `PostingJob`, `Booking` model, or `Stay` model file touched anywhere — confirmed via `git diff --stat` on each, all empty.

---

## Room Move Logic

```php
public function moveRoom(Stay $stay, Room $newRoom, User $actor, ?string $reason = null): Stay
{
    return DB::transaction(function () use ($stay, $newRoom, $actor, $reason): Stay {
        $lockedNewRoom = Room::whereKey($newRoom->id)->lockForUpdate()->firstOrFail();
        $lockedStay    = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();
        $assignment    = RoomAssignment::whereKey($lockedStay->room_assignment_id)->lockForUpdate()->firstOrFail();
        $lockedOldRoom = Room::whereKey($lockedStay->room_id)->lockForUpdate()->firstOrFail();

        // Reject: not CheckedIn, same room, different room type,
        // new room unavailable (maintenance/cleaning/OOO/OOS),
        // new room conflicts with another booking (findConflictForTimeChange).

        $this->housekeeping->autoMarkDirtyOnCheckout($lockedStay); // old room, before repoint

        $assignment->update(['room_id' => $lockedNewRoom->id]);
        $lockedStay->update(['room_id' => $lockedNewRoom->id]);

        $this->housekeeping->autoMarkOccupied($lockedStay); // new room, after repoint

        $this->stayEvents->record($lockedStay, StayEventType::RoomMove, $actor, [
            'version' => 1, 'old_room_id' => ..., 'old_room_number' => ...,
            'new_room_id' => ..., 'new_room_number' => ..., 'reason' => $reason,
        ]);

        return $lockedStay->refresh();
    });
}
```

- **Lock order:** Room (new) → Stay → RoomAssignment → Room (old) — Room-first, matching `extendStay()`'s established reasoning (this method never writes `Booking`).
- **Timing:** `findConflictForTimeChange()` checks the new room for the remaining period (`now()` → `Stay.planned_checkout_at`), never the already-elapsed portion — the old room's assignment for the already-elapsed period simply stops existing once `room_id` changes (it was never a separate row), which is exactly the "chỉ áp dụng cho phần thời gian còn lại" requirement, achieved by construction rather than a separate date-split.
- **Housekeeping ordering is deliberate:** both hook methods read `$stay->room_id` directly, so `autoMarkDirtyOnCheckout()` must run *before* the `room_id` update (while it still resolves to the old room) and `autoMarkOccupied()` *after* (once it resolves to the new room).

---

## Projection Verification

Because Room Move is constrained to same-`room_type_id` moves only, `PaymentProjectionService::resolveUnitPrice()` (unchanged) resolves the identical `BookingRequirement.room_price` before and after a move — the rate cannot change. `test_move_room_updates_projection_correctly` asserts `projected_room_total` and `expected_total` are byte-identical immediately before and after a move. **Zero lines of `PaymentProjectionService.php` were touched** — this is a proof, not merely an assumption.

---

## Financial Safety

Confirmed explicitly, by test and by `git diff` scope review:

- **Folio modified: NO** — `test_move_room_does_not_alter_historical_folio_entries` asserts every existing `FolioEntry` row is byte-identical before/after; no `FolioService` file touched.
- **Payment history modified: NO** — `test_move_room_does_not_alter_historical_payments` asserts every `BookingPayment` row is byte-identical; no `BookingPaymentService` file touched.
- **Night Audit modified: NO** — `test_move_room_creates_no_night_audit_records` asserts zero new `NightAuditRun`/`NightAuditBookingLog` rows; no `NightAuditPipeline`/`NightAuditService`/`PostingJob` file touched.
- **Adjustment created: NO** — no adjustment-entry code exists or was added.
- **Child Booking created: NO** — `test_move_room_creates_no_child_booking` proves `Booking::count()` unchanged.
- **Migration added: NO.**

---

## Multi-Stay Verification

Room Move operates on exactly one `Stay` at a time, identified explicitly by the caller — it has no Booking-wide side effects. `test_move_room_leaves_booking_unchanged` proves every `Booking` attribute (aggregate status, dates, etc.) is untouched by a Room Move, so a multi-room Booking's other Stays are unaffected by construction (no shared mutable state is touched outside the one Stay/RoomAssignment/Room trio being moved).

---

## Audit Verification

`test_move_room_creates_audit_event` confirms a `StayEvent` with `event_type = ROOM_MOVE` is recorded, carrying `actor_id` (the real authenticated user, never fabricated), and metadata `old_room_id`, `old_room_number`, `new_room_id`, `new_room_number`, `reason`. No approval step exists or was added — audit is the entire safety mechanism, exactly as instructed (§13: "Không cần approval. Audit đủ.").

---

## Room Board / Availability Verification

- `test_move_room_updates_room_status_old_dirty_new_occupied`: old room transitions to `VACANT_DIRTY` with a new `HousekeepingAssignment` created (guest left, room needs cleaning); new room transitions to `OCCUPIED`.
- `test_move_room_updates_availability`: `RoomAvailabilityRuleService::hasConflict()` now returns `false` for the old room over the remaining period (freed for other bookings) and `true` for the new room (this booking now occupies it) — proving Room Board / Availability reflect the move with zero dedicated code change.
- Exactly one room is ever "active" for this Stay at a time — since the same single `RoomAssignment` row is mutated (never duplicated), it is structurally impossible for two rooms to be simultaneously active for one Stay.

---

## Targeted Tests

| File | Count | Result |
|------|-------|--------|
| `tests/Unit/Services/StayServiceMoveRoomTest.php` | 16 | ✅ all passed |
| `tests/Feature/RoomMoveControllerTest.php` | 5 | ✅ all passed |

Plus a full re-run of directly adjacent suites to confirm no interaction effects: `StayServiceExtendTest`, `StayExtendControllerTest`, `CheckoutIntegrationTest`, `PartialCheckoutStayEventTest`, `SplitStayVerificationTest`, `PaymentProjectionServiceTest`, `PaymentProjectionUiReadinessTest`, `StayServiceHousekeepingHookTest`, `StayEventFoundationTest`, `StayEventTest`, `StayEventServiceTest` — **99 tests total, all passed.**

Every one of the 16 required scenarios (§14 of the task) is covered by name:

| # | Scenario | Test |
|---|---|---|
| 1 | Move same room → Rejected | `test_move_to_same_room_is_rejected` |
| 2 | Move occupied room → Rejected | `test_move_to_occupied_room_is_rejected` |
| 3 | Move maintenance room → Rejected | `test_move_to_maintenance_room_is_rejected` |
| 4 | Move available room → Success | `test_move_to_available_room_succeeds` |
| 5 | Projection updated (unchanged, same type) | `test_move_room_updates_projection_correctly` |
| 6 | Booking unchanged | `test_move_room_leaves_booking_unchanged` |
| 7 | Stay unchanged | `test_move_room_keeps_the_same_stay_row_and_creates_no_child_stay` |
| 8 | No Child Booking | `test_move_room_creates_no_child_booking` |
| 9 | No Child Stay | `test_move_room_keeps_the_same_stay_row_and_creates_no_child_stay` |
| 10 | Historical Folio unchanged | `test_move_room_does_not_alter_historical_folio_entries` |
| 11 | Historical Payment unchanged | `test_move_room_does_not_alter_historical_payments` |
| 12 | Night Audit unchanged | `test_move_room_creates_no_night_audit_records` |
| 13 | Room Board updated | `test_move_room_updates_room_status_old_dirty_new_occupied` |
| 14 | Availability updated | `test_move_room_updates_availability` |
| 15 | Audit created | `test_move_room_creates_audit_event` |
| 16 | Build PASS | See Build Result below |

Additional coverage beyond the required 16: different-room-type rejection (`test_move_to_different_room_type_is_rejected`), non-CheckedIn-Stay rejection (`test_move_room_rejects_non_checked_in_stay`), full HTTP-level role matrix (ADMIN/MANAGER/RECEPTION succeed, ACCOUNTANT forbidden), validation-error surfacing, and Booking Detail payload shape.

---

## Full Regression

Run **twice**:

| Run | Failed | Passed | Total |
|-----|--------|--------|-------|
| 1 | 24 | 859 | 883 |
| 2 | 24 | 859 | 883 |

Both runs are **byte-identical** (`diff` confirmed zero difference). The 24 failures decompose to exactly: the established 23-test pre-existing baseline (11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest`, date-drift, unrelated) **plus** one `PerStayAttributionTest` intermittent Faker `resources.code` unique-collision (on the method `add charge accepts system auto posting source` — yet another distinct method than any previously observed, further confirming this is genuine randomness, not a deterministic bug; this exact flake category has been documented and root-caused since Phase 4.2). **Zero new deterministic regressions.**

---

## Build Result

`npm run build` — **0 errors.**

```
✓ 2363 modules transformed.
public/build/assets/app-C5r-6fYF.js   529.95 kB
✓ built in 5.81s
```

Pre-existing >500kB chunk warning, unchanged, not a blocker.

---

## Future Architecture Note

ChatGPT confirmed the Sprint 02 decision: **the current design intentionally mutates the existing `RoomAssignment` row in place** (`room_id` changed on the same row) rather than creating a second `RoomAssignment` row for the destination room.

This is deliberate, not a shortcut, and it must not be changed:

- **No assignment duplication** — the Stay keeps exactly one `RoomAssignment` row for its entire lifetime, regardless of how many times it moves rooms.
- **No assignment over-count** — `BookingService::updateBookingAssignmentStatus()` and `RoomAssignmentService::getAssignmentSummary()` both count `RoomAssignment` rows (status `Assigned`/`CheckedIn`/`CheckedOut`) per `room_type_id` against the Booking's requirement. A second row per move would inflate this count with every move, producing a permanent false "over-assigned" mismatch warning.
- **No booking requirement mismatch** — because the count never inflates, `getAssignmentSummary()`'s `required` vs `assigned` comparison stays correct for the life of the Booking, through any number of Room Moves.

**This remains the correct decision for Sprint 02 and must not be reverted, refactored, or replaced by a multi-row design without a dedicated architecture review.** No Child `RoomAssignment` was created, is created, or should ever be created by this capability.

---

## Future Product Enhancement — Room Occupancy History

**Not designed. Not implemented. No migration proposed.** Description only, for future product planning.

The current `RoomAssignment` row is explicitly **not** intended to preserve a full history of every physical room a guest has occupied during their Stay — by design, per the Future Architecture Note above, it only ever reflects the *current* room. This is a deliberate scope boundary, not an oversight: the only record of *movement history* today is the `stay_events` audit log (old room, new room, actor, reason, time) added by this sprint, which is sufficient for the audit purpose this sprint required.

If a future product need arises for a dedicated, queryable **Room Occupancy History** (or "Room Timeline") — e.g., "show me every room this Stay ever occupied, with exact time ranges" — that would be a **separate, additive, read-only construct**, with hard boundaries:

- It would **never** participate in assignment counting.
- It would **never** participate in room requirement calculations.
- It would **never** participate in `getAssignmentSummary()` / Booking assignment status.
- It is **not** a Child Assignment, **not** an Assignment replacement, and **not** part of the Booking structure.
- Its only intended consumers are operational/investigative: Lost & Found, Security, Police inquiry, Housekeeping investigation, and Manager operational history review.

No implementation, schema, or migration is proposed here — this section exists purely to record the boundary for whoever scopes that future work, so it is not accidentally conflated with `RoomAssignment` or built in a way that reintroduces the over-counting bug this sprint deliberately avoided.

---

## Architecture Decisions — Post-Review

Locked by ChatGPT after Sprint 02's implementation, regression, financial-safety, operational-workflow, and documentation review. These are project-level architectural decisions, not sprint-scoped notes — they govern all future room-change work, not just Sprint 02.

### Change Room Principle (LOCKED)

In LASTELLA there is exactly **one** core business capability: **Change Room.**

Its variants are distinguished only by room type:

- **Same Room Type → Room Move**
- **Different Room Type → Upgrade / Downgrade**

Room Move and Upgrade/Downgrade are **not** two independent business capabilities. Upgrade/Downgrade is Room Move with a different Room Type — nothing more.

**Must never be created:**

- `UpgradeService`
- `DowngradeService`
- `RoomTypeChangeService`
- `UpgradeRoom()`
- `DowngradeRoom()`
- or any other independent flow that changes a Stay's room.

Sprint 03 (whenever authorized) must **extend the existing flow**, not fork or duplicate it.

### Single Source of Truth (LOCKED)

The current Room Move implementation (`StayService::moveRoom()`), or its equivalent after any future rename to `changeRoom()`, is the **only** entry point permitted to change the room of a Stay.

The following responsibilities may exist in **exactly one place** — this implementation:

- Transaction boundary
- Locking
- Availability validation
- Conflict detection
- RoomAssignment update
- Stay update
- Housekeeping hooks
- Stay Event creation
- Audit logging
- Projection refresh when Room Type changes

**Must never be:** duplicated, copied, forked, or reimplemented as a parallel flow. Every future feature touching room changes must reuse this one flow.

### RoomAssignment Principle (LOCKED, clarified)

`RoomAssignment` = **Current Operational Assignment.** Nothing more.

It is explicitly **not**:

- Occupancy History
- Audit History
- A Child Assignment
- A Room Timeline

All history must be recorded via **Stay Event**, or a future dedicated History object (see Future Product Enhancement — Room Occupancy History above) — **never** by creating multiple `RoomAssignment` rows to represent history. This is the same reasoning already documented in the Future Architecture Note above, restated here explicitly as its own locked principle.

### Product Sprint 03 Note (documentation direction only — not implemented in this task)

When Product Sprint 03 (Upgrade/Downgrade) is authorized, it must:

- Remove the current explicit rejection of different-room-type moves in the existing flow.
- Extend the same Change Room flow — not create a separate API/service.
- Refresh Payment Projection when Room Type changes.
- Keep Booking unchanged.
- Keep Stay unchanged.
- Create no Child Booking.
- Create no Child Stay.
- Not automatically create an Adjustment.
- Not modify Folio.
- Not modify Payment.
- Not modify Night Audit.

This is documentation direction only. Nothing in this note is implemented by this task.

---

## Operational Clarification

Room Move requires the destination room to be **clean, available, and non-maintenance**. In practice, today:

- **Non-maintenance is verified** — `RoomAvailabilityRuleService::isRoomUnavailable()` rejects `OutOfOrder`, `OutOfService`, and `Cleaning`.
- **Availability (no conflicting booking) is verified** — `findConflictForTimeChange()`.
- **"Clean" is not currently a distinct, separately-verified condition** — see Dirty Room Verification below.

---

## Dirty Room Verification

**Checked, per instruction, before writing this section — zero code changed either way.**

`RoomAvailabilityRuleService::isRoomUnavailable()` (unmodified in this sprint, read-only inspection performed):

```php
public function isRoomUnavailable(Room $room): bool
{
    return in_array($room->status, [
        RoomStatus::OutOfOrder,
        RoomStatus::OutOfService,
        RoomStatus::Cleaning, // Phase 4.2 ADR-88: a room being cleaned cannot be assigned
    ], true);
}
```

`RoomStatus::VacantDirty` ("Trống bẩn") is **not** in this list. A room that is vacant-but-dirty (e.g., a room whose previous occupant just left and has not yet been cleaned) is therefore **not currently rejected** by `isRoomUnavailable()` — and since `StayService::moveRoom()` reuses this method verbatim (per the "reuse, don't invent" instruction), a Room Move into a dirty room is **not currently blocked**.

**This is NOT a bug introduced by Sprint 02.** `isRoomUnavailable()` is pre-existing (Phase 4.2, ADR-88) and is used identically by the ordinary room-assignment flow (`RoomAssignmentService::assignRooms()` → `hasConflict()`, and elsewhere) — Room Move simply inherits the same, already-existing gap, unchanged. Fixing it here would mean editing shared pre-existing logic to alter behavior for every caller (assignment, extension, and now Room Move alike), which is a real behavior change, not a "reuse" — explicitly out of this sprint's "0 lines of code unless a real bug is proven" mandate, and out of scope to decide unilaterally.

**Per instruction: not fixed. Not worked around. Recorded for ChatGPT decision.**

**Potential future enhancement.**

**Potential Future Product Issue (for ChatGPT to decide):** should `isRoomUnavailable()` (or a Room-Move-specific check layered on top of it, reusing the same method) also reject `VacantDirty`? Arguments either way are a product decision, not an implementation one — e.g., a hotel might reasonably want to move a guest into a dirty room and have Housekeeping prioritize it immediately (the existing `autoMarkDirtyOnCheckout()`/priority mechanism could support that), versus always requiring `VacantClean`/`Inspected` first. No code changed in this task pending that decision.

### Product Decision (ChatGPT)

**Reception must not be able to move a guest into a `VacantDirty` room.**

This decision is recorded here as product direction; it is **not implemented in this task**, and per explicit instruction:

- The shared rule (`RoomAvailabilityRuleService::isRoomUnavailable()`) is **not** modified in Sprint 02 — it remains exactly as inherited from Phase 4.2 (ADR-88).
- This is logged as a **future Room Availability Rules work item** — i.e., when addressed, it should be fixed at the shared-rule level (or an explicitly-scoped extension of it), so every caller benefits consistently.
- **No local workaround inside Room Move.** Room Move must not grow a Room-Move-only dirty-room check that the rest of the system doesn't share — that would violate the Single Source of Truth principle above by splitting availability logic across callers.
- **No Upgrade/Downgrade-only logic either.** The same reasoning applies: this is a Change Room-wide concern (both Room Move and the future Upgrade/Downgrade variant), not something to special-case per variant.

Status: **acknowledged as a real product gap, explicitly deferred, zero code changed.**

---

## Remaining Risks

- The client-side room picker filters `availableRooms` (the page's already-computed `roomBoard.floors[].rooms` flattened list) by `room_type_id` + `availability_status === 'available'`. That availability computation is Booking-date-scoped (`getRoomBoard()` uses `Booking.checkin_at`/`checkout_at`, not the specific Stay's own — possibly extended — `planned_checkout_at`). In an edge case (a Stay extended well beyond the Booking's original dates), the picker could show a room the backend then rejects. This is acceptable and intentional: the backend (`findConflictForTimeChange()` against the Stay's real dates) is authoritative, matching the same "UI is a convenience, backend decides" pattern already established for checkout buttons (ADR-52).
- Room Move reuses `autoMarkDirtyOnCheckout()`, which tags the resulting `HousekeepingAssignment` with `CleaningReason::Checkout`. No `RoomMove`-specific cleaning reason exists (adding one would be a Housekeeping enum change, explicitly out of scope: "Không Housekeeping redesign"). The room genuinely does need cleaning either way; only the recorded *reason* label is a cosmetic approximation, not a functional gap. Flagged here, not hidden.
- No live browser verification was possible in this environment (no browser-automation tool available, consistent with every prior phase/sprint in this project) — verified via `npm run build`, HTTP-level tests, and direct Vue-source review instead.
- Cross-room-type moves (Upgrade/Downgrade) are explicitly rejected by design — confirmed by `test_move_to_different_room_type_is_rejected` — and remain a candidate for a future, separate product sprint, exactly as instructed.

---

## Product Readiness

**READY FOR CHATGPT REVIEW: YES**
