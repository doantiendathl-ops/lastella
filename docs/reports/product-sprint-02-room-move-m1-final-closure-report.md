# Product Sprint 02 — Room Move M1 Final Closure Report

## Status

- Sprint: Product Sprint 02
- Feature: Room Move M1
- Branch: phase-3
- Final status: **OFFICIALLY CLOSED**
- Ready for operation within approved scope: **YES**

---

## Product Problem Solved

- Reception previously had no standard workflow to move an in-house guest to another room (broken AC/TV, guest wants a higher floor, bad view, smoky/noisy room, VIP guest, guest wants to be near family).
- This sprint adds a safe, single-step, same-room-type Room Move.
- Booking and Stay identity are fully preserved — no commercial re-contracting, no duplication.

---

## Approved Capabilities

- Same-room-type Room Move
- Booking unchanged
- Stay unchanged
- Current `RoomAssignment` updated in place (never duplicated)
- No Child Booking
- No Child Stay
- `ROOM_MOVE` Stay Event with full audit metadata (old room, new room, actor, reason, time)
- Old room → `VACANT_DIRTY` (+ Housekeeping cleaning assignment)
- New room → `OCCUPIED`
- Room Board / Availability refresh (automatic, zero dedicated code — a direct consequence of mutating the single assignment row)
- Permission (`stay.room_move`) and Booking Detail UI ("Đổi phòng")

---

## Locked Architecture Decisions

### Assignment Principle

`RoomAssignment` = **Current Operational Assignment.** Not Occupancy History, not Audit History, not a Child Assignment, not a Room Timeline.

### Change Room Principle

- **Same Room Type → Room Move**
- **Different Room Type → Upgrade/Downgrade**

Room Move and Upgrade/Downgrade are not two independent business capabilities — Upgrade/Downgrade is Room Move with a different Room Type. No `UpgradeService`/`DowngradeService`/`RoomTypeChangeService`/`UpgradeRoom()`/`DowngradeRoom()` may ever be created.

### Single Source of Truth

Only one Change Room flow (`StayService::moveRoom()`, or its future `changeRoom()` equivalent) may alter a Stay's room. Locking, availability validation, conflict detection, RoomAssignment update, Stay update, Housekeeping hooks, Stay Event creation, audit logging, transaction boundary, and Projection refresh on Room Type change may exist in exactly one place — never duplicated, copied, or forked.

### Financial Separation

Projection ≠ Folio ≠ Payment ≠ Adjustment. Each remains a distinct, independently-computed concept; Room Move touches none of the posted/ledger layers.

### Operational Exception Principle (reaffirmed, not newly introduced by this sprint)

The system never automatically decides to charge extra, discount, refund, compensate, or grant a complimentary/VIP exception. A human decides; the system only supports that decision via projection and audit — consistent with every financial-safety guarantee already established in Phase 4.3A and Product Sprint 01.

---

## Room Occupancy History

- **Not implemented in this sprint.**
- The current `RoomAssignment` does not store full occupancy history — this is deliberate, not an oversight (see Assignment Principle above).
- The `stay_events` audit trail (with the new `ROOM_MOVE` event type) is the system of record for room-change history today.
- A future, dedicated **Room Occupancy History** (or "Room Timeline") object is described, not designed or scheduled. If built, it must be additive and read-only, and must **never** participate in assignment counting or Booking room requirement calculations.

---

## Financial Safety

- **Folio modified: NO**
- **Payment history modified: NO**
- **Night Audit modified: NO**
- **Adjustment created: NO**
- **Child Booking created: NO**
- **Child Stay created: NO**
- **Migration added: NO**

Confirmed by `git diff` scope review (zero touch to `FolioService`, `BookingPaymentService`, `PaymentProjectionService`, `NightAuditService`, `NightAuditPipeline`, any `PostingJob`, `Booking` model, `Stay` model, or any migration file) and by dedicated tests (`test_move_room_does_not_alter_historical_folio_entries`, `test_move_room_does_not_alter_historical_payments`, `test_move_room_creates_no_night_audit_records`, `test_move_room_creates_no_child_booking`, `test_move_room_keeps_the_same_stay_row_and_creates_no_child_stay`).

---

## Operational Result

- Move to the same room → rejected
- Move to an occupied room → rejected
- Move to a maintenance room → rejected
- Move to an available same-type room → succeeds
- Move to a different room type → **remains rejected in Sprint 02** (Upgrade/Downgrade deferred, per the Change Room Principle, to a future sprint that extends this same flow)
- Old room → `VACANT_DIRTY`, new room → `OCCUPIED`
- Room Board / Availability reflect the move immediately
- Payment Projection is provably unchanged for a same-room-type move (same rate resolution, verified by a dedicated before/after test)

---

## Verification Summary

- **Targeted tests (re-run immediately before this closure):** 99 passed, 0 failed — `StayServiceMoveRoomTest` (16), `RoomMoveControllerTest` (5), `StayServiceExtendTest` (8), `CheckoutIntegrationTest` (13), `PartialCheckoutStayEventTest` (8), `SplitStayVerificationTest` (1), `PaymentProjectionServiceTest` (25), `PaymentProjectionUiReadinessTest` (4), `StayServiceHousekeepingHookTest` (2), `StayEventFoundationTest` (2), `StayEventTest` (6), `StayEventServiceTest` (4), plus `StayExtendControllerTest`.
- **Full regression:** reused from the most recent 2 runs — confirmed valid to reuse because no `app/`, `database/`, `routes/`, `resources/js/`, or `tests/` file changed after that run completed (only the report/documentation was updated afterward, verified via file-timestamp comparison).

  | Run | Failed | Passed | Total |
  |-----|--------|--------|-------|
  | 1 | 24 | 859 | 883 |
  | 2 | 24 | 859 | 883 |

- **Build result:** `npm run build` — 0 errors (pre-existing >500kB chunk warning, unchanged, not a blocker).
- **New deterministic regressions: NONE.**

---

## Known Baseline Failures

- 23 pre-existing date-sensitive failures (11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest`) — unrelated to this sprint.
- `PerStayAttributionTest` — intermittent Faker unique-value collision (pre-existing, not a Room Move issue).
- `LateCheckoutFeeTest` — wall-clock/time-of-day dependency in the test's own date arithmetic (pre-existing, not a Room Move issue).

---

## Known Product Limitations

Recorded as **Future Product Enhancements / Accepted Scope Limitations**, not blockers:

- `VacantDirty` shared-rule gap — `RoomAvailabilityRuleService::isRoomUnavailable()` does not currently reject `VacantDirty`, so Room Move (inheriting this shared rule) does not currently block moving a guest into an uncleaned room. ChatGPT has locked the product decision that Reception must not be able to do this; the fix belongs at the shared-rule level (a future Room Availability Rules work item), not as a Room-Move-only or Upgrade/Downgrade-only workaround. Not fixed in this closure task, per explicit instruction.
- Room Occupancy History does not yet exist (see above).
- The client-side room picker (Booking-date-scoped) can, in a rare edge case (a Stay extended well beyond the Booking's original dates), show a room the backend then correctly rejects — the backend remains authoritative, matching the existing "UI is a convenience, backend decides" pattern (ADR-52).
- Housekeeping reuses the `Checkout` cleaning reason for the vacated room (no dedicated Room-Move reason exists; adding one would be a Housekeeping enum change, out of scope).
- No live browser verification was possible in this environment (no browser-automation tool available in any session to date).
- Cross-room-type change (Upgrade/Downgrade) is deferred to Product Sprint 03.

**Clarification:** Product Sprint 03 is a separate *planning and delivery* sprint, but it is **not** a separate *business flow* — per the Change Room Principle, it must extend the same `moveRoom()`/Change Room flow, not fork a new one.

---

## Git Information

- **Commit hash:** `765f0dbc49f9ac3b0280f96bede39ab60a97a059`
- **Commit message:** `feat(stay): add same-type room move workflow`
- **Branch pushed:** `phase-3` (`6e4c624..765f0db`)
- **Tag name:** `product-sprint-02-room-move-m1` (annotated), message: "Product Sprint 02 - Room Move M1"
- **Tag pushed:** yes — `origin/product-sprint-02-room-move-m1` created
- **Remote confirmation:** `git ls-remote --tags origin product-sprint-02-room-move-m1` → `b15c7c0a596c3a12d7403adde84ae7ae2d5916c3 refs/tags/product-sprint-02-room-move-m1`; tag locally verified to point at commit `765f0db` via `git rev-parse product-sprint-02-room-move-m1^{commit}`

---

## Recommended Next Product Sprint

**Product Sprint 03 — Upgrade/Downgrade as Change Room Variant** (proposal only — not implemented, no implementation plan created in this closure task).

Principles for that future sprint:

- Extend the same Change Room flow (`moveRoom()` / future `changeRoom()`) — never a separate service/API.
- Remove the current different-room-type rejection, replacing it with the Upgrade/Downgrade variant logic.
- Payment Projection refreshes to reflect the new room type's rate.
- No automatic Folio/Payment/Adjustment/Night Audit change.
- A human decides any extra charge, discount, or refund — the system only supports that decision via projection and audit.

---

## Closure Confirmation

- ✅ Product Sprint 02 **OFFICIALLY CLOSED**
- ✅ No Product Sprint 03 started
- ✅ No Upgrade/Downgrade implementation started
- ✅ No Room Availability Rules fix started
- ✅ No Adjustment Engine started
- ✅ No Forecast Charges Engine started
- ✅ Working tree clean (two unrelated, intentionally-uncommitted files remain from the prior Phase 4.3A and Product Sprint 01 closures — out of this sprint's scope, not touched)
