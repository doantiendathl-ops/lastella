# Product Sprint 03 — Change Room Capability, Phase 2 Final Closure Report

## Status

- Sprint: Product Sprint 03
- Capability: Change Room, Phase 2
- Branch: phase-3
- Final status: **OFFICIALLY CLOSED**
- Ready for operation within approved scope: **YES**

---

## Product Problem Solved

- Sprint 02 only allowed a same-room-type move (Room Move).
- Sprint 03 extends that same flow to also allow a different-room-type operational move (e.g. Twin → Double), without creating a new business flow and without the system ever deciding a new price.

---

## Approved Capabilities

- Same-type Room Move preserved
- Cross-type Operational Move
- Booking unchanged
- Stay unchanged
- No Child Booking
- No Child Stay
- RoomAssignment updated in place
- Commercial room type preserved
- Physical room/type updated
- `ROOM_MOVE` Stay Event reused
- Room Board / Availability / Housekeeping updated
- UI warning for manual pricing handling

---

## Locked Product Decisions

### Change Room Principle

One capability, multiple variants: Same Room Type → Room Move, Different Room Type → Operational Cross-Type Move. No `UpgradeService`/`DowngradeService`/`RoomTypeChangeService`/`CrossTypeRoomMoveService`/`UpgradeRoom()`/`DowngradeRoom()`/parallel controller action was created.

### Single Source of Truth

`StayService::moveRoom()` remains the only flow permitted to alter a Stay's room.

### Assignment Principle

`RoomAssignment` = Current Operational Assignment, not Occupancy History, not Audit History, not a Room Timeline, not a Child Assignment.

### Commercial Source Principle

Financial rate data comes from the Commercial Agreement (`BookingRequirement`, via `RoomAssignment.room_type_id` as the requirement slot the assignment fulfills), never from the live Physical Assignment (`Stay.room_id` / `RoomAssignment.room_id` / the `room` relation).

### Pricing Independence Principle

Room Change does not decide price. Any surcharge/discount is entered separately by Reception via existing Gói dịch vụ/Extra Charge mechanisms; if free, nothing is added.

### Financial Separation

Projection ≠ Folio ≠ Payment ≠ Adjustment — unaffected by this sprint, each remains independently computed.

---

## Commercial / Physical Separation

- `Stay.room_id` / `RoomAssignment.room_id` = physical current room.
- `RoomAssignment.room_type_id` = commercial requirement slot the assignment fulfills — never written by `moveRoom()`, including on a cross-type move.
- `BookingRequirement` = sold type/rate — never written by this capability.
- Physical and commercial type may now safely diverge after a cross-type move; nothing else in the system depended on them staying equal except the two rate resolvers corrected below.

---

## Night Audit Source Correction

`RoomChargePostingJob::resolveUnitPrice()` resolves commercial room type via `Stay.roomAssignment.room_type_id`, falling back to the physical room type only when no assignment link exists. No change to the pricing formula, posting date, posting key, idempotency check, or Folio semantics — this is a source correction, not new logic.

## Payment Projection Source Correction

`PaymentProjectionService` resolves the identical commercial source via a new `commercialRoomTypeId()` helper, mirroring `RoomChargePostingJob` exactly. A cross-type physical move does not alter the projected room rate or `expected_total`, and no automatic surcharge is ever added by Projection.

## BookingRequirement Preservation

Room type unchanged, room price unchanged, no commercial amendment — confirmed by dedicated regression coverage (`test_cross_type_move_does_not_alter_booking_requirement`).

---

## Financial Safety

- **Folio rewrite:** NO
- **Payment history modified:** NO
- **Adjustment created:** NO
- **Automatic Extra Charge created:** NO
- **BookingRequirement modified:** NO
- **Migration added:** NO
- **New service created:** NO

---

## UI Result

- Reuses the existing Change Room dialog — no new dialog.
- Same- or cross-type rooms are both selectable.
- Room number, room type, and status are visible per option.
- A short warning appears when a cross-type room is selected: pricing is handled separately via existing charge flows.
- One-step operation: chọn phòng → nhập lý do → xác nhận.

## Audit Result

`ROOM_MOVE` event reused (no new event type). Metadata bumped to `version: 2`, recording old/new room, old/new room type, reason. Actor and timestamp remain first-class `StayEvent` columns, as established in Phase 4.3A.

---

## Verification Summary

- **Targeted tests (re-run immediately before this closure):** 116 passed, 0 failed — `StayServiceMoveRoomTest`, `RoomMoveControllerTest`, `PaymentProjectionServiceTest`, `PaymentProjectionUiReadinessTest`, `NightAuditPipelineFeatureTest`, `NightAuditOperationsTest`, `RoomChargeHotfixTest`, `BackfillPerNightChargesCommandTest`, `RoomAvailabilityRuleServiceTest`.
- **Full regression:** reused from the two most recent runs (confirmed valid — every touched source/test file's last modification time precedes the completion time of the reused run; no code or test changed afterward, only documentation).

  | Run | Failed | Passed | Total |
  |-----|--------|--------|-------|
  | 2 | 24 | 867 | 891 |
  | 3 | 24 | 867 | 891 |

  Failure list byte-identical between the two runs: 23 known baseline + 1 known `LateCheckoutFeeTest` wall-clock flake.
- **Build result:** `npm run build` — 0 errors (pre-existing >500kB chunk warning, unchanged, not a blocker).
- **New deterministic regressions: NONE.**

---

## Known Baseline Failures

- 23 pre-existing date-sensitive failures (11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest`) — unrelated to this sprint.
- `PerStayAttributionTest` — intermittent Faker unique-value collision (pre-existing).
- `LateCheckoutFeeTest` — wall-clock/time-of-day dependency in the test's own date arithmetic (pre-existing; confirmed unrelated to this sprint by reading `LateCheckoutFeePostingJob::resolveUnitPrice()`, which resolves via `RoomRate` keyed on the physical room directly — a file this sprint never touched).

---

## Known Product Limitations

Recorded as **Future Product Enhancements / Accepted Scope Limitations**, not blockers:

- VacantDirty shared availability behavior unresolved — `RoomAvailabilityRuleService::isRoomUnavailable()` does not reject `VacantDirty`, and an existing ADR-88 test locks in the current "available for assignment" behavior as deliberate. ChatGPT's product decision (Reception must not move a guest into a VacantDirty room) still stands but is deferred to a dedicated future Room Readiness / Availability Rules review, since fixing the shared rule affects Room Board, Room Availability Checker, and Change Room system-wide and was judged too large/uncertain to bundle into this closure.
- No Room Occupancy History (unchanged from Sprint 02).
- Pricing adjustments for a cross-type move are handled manually via Gói dịch vụ/Extra Charge — no automated upgrade-fee calculation exists or was requested.
- No Commercial Amendment Engine.
- No live browser verification performed (no browser-automation tool available in this environment, consistent with every prior sprint's report).

---

## Future Work Item

**Room Readiness / Availability Rules Review** (proposal only — not implemented, no implementation plan created in this closure task).

Goals for that future review:
- Decide explicitly whether `VacantDirty` should block room assignability.
- If so, fix at the shared `RoomAvailabilityRuleService::isRoomUnavailable()` level — never a local workaround inside Change Room or any single caller.
- Re-verify Room Board, Assignment Summary, Room Availability Checker, and Change Room together, since all three currently share this one rule.

---

## Git Information

- **Commit hash:** `38cee8be64c0181baa658c2905b14d160172814c`
- **Commit message:** `feat(stay): support operational cross-type room changes`
- **Branch pushed:** `phase-3` (`765f0db..38cee8b`)
- **Tag name:** `product-sprint-03-change-room-phase-2` (annotated), message: "Product Sprint 03 - Change Room Capability Phase 2"
- **Tag pushed:** yes — `origin/product-sprint-03-change-room-phase-2` created
- **Remote confirmation:** `git ls-remote --tags origin product-sprint-03-change-room-phase-2` → `f2af51da76d9f470ea666327a817a09358068f30 refs/tags/product-sprint-03-change-room-phase-2`; tag locally verified to point at commit `38cee8b` via `git rev-parse product-sprint-03-change-room-phase-2^{commit}`

---

## Closure Confirmation

- ✅ Product Sprint 03 **OFFICIALLY CLOSED**
- ✅ No automatic upgrade fee implemented
- ✅ No Commercial Amendment implemented
- ✅ No VacantDirty shared-rule fix implemented
- ✅ No Adjustment Engine started
- ✅ No Forecast Charges Engine started
- ✅ Working tree clean (three unrelated, intentionally-uncommitted files remain from the prior Phase 4.3A, Sprint 01, and Sprint 02 closures — out of this sprint's scope, not touched)
