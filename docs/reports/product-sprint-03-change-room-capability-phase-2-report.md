# Product Sprint 03 — Change Room Capability, Phase 2 (Operational Cross-Type Room Move)

## Status

- Sprint: Product Sprint 03 (implemented after Product Override)
- Capability: Change Room (extension of Product Sprint 02, NOT a new capability)
- Branch: phase-3
- Implementation status: **IMPLEMENTED** — same-room-type and cross-room-type physical Change Room, commercial data untouched
- No commit. No push. No tag.

---

## Product Override

Prior discovery (previous round) correctly identified that Night Audit and Payment Projection resolve rate via the Stay's live physical room type, and that a cross-type move would silently zero out room charges. ChatGPT/Product accepted that technical finding but overruled the "cannot proceed" conclusion: an Operational Cross-Type Room Move is not a Commercial Amendment, Rate Change, or Pricing Event — it only ever touches the physical side (room, physical type, `RoomAssignment`, room status, Room Board, Availability, Housekeeping, Audit). No price is ever decided automatically. Reception adds any surcharge/discount separately via existing Gói dịch vụ/Extra Charge mechanisms.

## Commercial Simplicity Principle

No proration engine, rate-amendment engine, automatic upgrade fee, automatic downgrade refund, automatic discount, or new rate-resolution *rule* was built. The only change made is a **source correction** — which existing, already-correct `BookingRequirement.room_price` a lookup reads from — not a new pricing rule.

## Commercial Source Principle

All financial data (Night Audit, Payment Projection) must key off the **Commercial Agreement** (`BookingRequirement`, via the commercial requirement slot the assignment fulfills), never off the live Physical Assignment. `BookingRequirement` is the commercial source; `Stay.room` / `RoomAssignment.room` is the physical operational source. The two are now permitted to diverge after a cross-type Change Room move.

## Technical Debt Identified

Recorded, not treated as a reason to reject the Product requirement: prior to this sprint, `RoomChargePostingJob::resolveUnitPrice()` and `PaymentProjectionService::resolveUnitPrice()` both kept the live physical room type (`$stay->room->room_type_id`), which happened to work only because physical and commercial room type were always identical before Change Room could cross types. This was a latent source-of-truth defect, now corrected (Section "Night Audit Source Correction" below).

---

## Pre-Flight Confirmation

| Concern | Current Source | Correct Source | Planned Change |
|---|---|---|---|
| Physical room | `Stay.room_id` / `RoomAssignment.room_id` | unchanged (this is exactly what a Change Room move updates) | `moveRoom()` repoints both, as before |
| Commercial room type | `BookingRequirement.room_type_id` / `RoomAssignment.room_type_id` | commercial source | preserved — `RoomAssignment.room_type_id` never written by `moveRoom()` |
| Night Audit rate | was: live physical room (`$stay->room->room_type_id`) | commercial room type (`RoomAssignment.room_type_id`, fallback to physical room if no assignment link) | source correction in `RoomChargePostingJob::resolveUnitPrice()` |
| Projection rate | was: live physical room (`$stay->room?->room_type_id`) | same commercial source as Night Audit | source correction in `PaymentProjectionService` (new `commercialRoomTypeId()` helper) |
| Assignment summary | `RoomAssignment.room_type_id` vs. `BookingRequirement.room_type_id` | preserve | no change — confirmed safe in the prior discovery round (RoomAssignment Semantic Review) |
| Dirty-room rule | `RoomAvailabilityRuleService::isRoomUnavailable()` — VacantDirty not rejected | VacantDirty blocked | **not changed this sprint — see "Dirty Room Rule" section** |

Confirmed: no migration, no `BookingRequirement` write, no pricing engine, no new service, no new business flow.

---

## Change Room Extension

`StayService::moveRoom()` (`app/Services/StayService.php`) — the same, single entry point from Sprint 02 — had its different-room-type rejection removed. No new method, no new service, no new controller action. Everything else (transaction, Booking→Stay→RoomAssignment lock-free ordering already used, room-scoped locking, `RoomAvailabilityRuleService::isRoomUnavailable()`/`findConflictForTimeChange()`, Housekeeping hooks, `StayEventType::RoomMove` audit) is reused verbatim. Only the physical room (`Room::with('roomType')` now eager-loaded for audit metadata) and the two locked-room queries changed shape; the write path (`$assignment->update(['room_id' => ...])`, `$lockedStay->update(['room_id' => ...])`) is unchanged.

## RoomAssignment Preservation

`RoomAssignment.room_type_id` is never written by `moveRoom()` — confirmed by the removed code review and by `test_cross_type_move_preserves_commercial_room_type_on_assignment`, which asserts the assignment's `room_type_id` stays equal to the original (Twin) type while `room_id` moves to the new (Double) room. Physical type is verified separately, via the `room` relation (`test_cross_type_move_updates_physical_room_type_via_relation`).

## Night Audit Source Correction

`app/Services/Posting/RoomChargePostingJob.php::resolveUnitPrice()` now resolves the commercial room type as `$stay->roomAssignment?->room_type_id ?? $stay->room->room_type_id` — preferring the commercial requirement slot, falling back to the physical room only when no assignment link exists (preserves prior behavior for that edge case). No change to: number-of-nights calculation, posting date, posting key, idempotency check, Folio behavior, charge type, or the already-correct `BookingRequirement.room_price` value read once a match is found. This is a source correction, not new pricing logic — confirmed by `test_cross_type_move_does_not_make_night_audit_post_zero`, which directly invokes the job after a Deluxe(Twin)→Suite(Double)-equivalent move and asserts it posts the **original** Twin rate, not zero.

## Projection Source Correction

`app/Services/PaymentProjectionService.php` gained a `commercialRoomTypeId(Stay $stay)` helper with the identical resolution (`$stay->roomAssignment?->room_type_id ?? $stay->room?->room_type_id`), used in `projectStayRoomCharges()` in place of the old `$stay->room?->room_type_id`. `project()` now eager-loads `stays.roomAssignment` alongside `stays.room`. No change to the formula, to `expected_total` composition, or to any other Projection field. Confirmed unaffected by a cross-type move (`test_cross_type_move_does_not_change_projected_room_total`).

## BookingRequirement Preservation

Never read for writing by `moveRoom()`, `RoomChargePostingJob`, or `PaymentProjectionService`. Confirmed byte-for-byte unchanged before/after a cross-type move (`test_cross_type_move_does_not_alter_booking_requirement`).

## Financial Safety

- **Folio modified:** NO (only a normal Night Audit-style posting occurs, via the pre-existing, reused `RoomChargePostingJob`, exactly as it already did for same-type moves and ordinary check-ins — no new posting path).
- **Payment modified:** NO.
- **Adjustment created:** NO.
- **Automatic Extra Charge created:** NO.
- **BookingRequirement.room_price modified:** NO.
- **Migration added:** NO.
- **New service created:** NO.

## Assignment Summary Verification

`RoomAssignmentService::getAssignmentSummary()` after a cross-type move still reports the original (Twin) requirement as `required: 1, assigned: 1, remaining: 0` and shows no summary row at all for the new (Double) type, since no `BookingRequirement` exists for it — confirmed by `test_cross_type_move_does_not_create_false_assignment_mismatch`. No false over-assignment, no false missing-requirement.

## Dirty Room Rule

**Not changed this sprint.** `RoomAvailabilityRuleService::isRoomUnavailable()` is used in exactly 3 places (`RoomAssignmentService::getRoomBoard()`, `RoomAvailabilityCheckerService`, `StayService::moveRoom()`). A dedicated existing test — `RoomAvailabilityRuleServiceTest::test_vacant_dirty_room_is_available_for_assignment_purposes` — explicitly locks in the current behavior as a deliberate Phase 4.2 (ADR-88) decision ("VACANT_DIRTY/VACANT_CLEAN/INSPECTED remain available"), not an oversight. Flipping this shared rule would (a) break that existing, ADR-referencing test, and (b) have system-wide effect on Room Board display and the Room Availability Checker feature for every booking, not just Change Room. Per this task's own instruction ("Nếu shared-rule change có rủi ro lớn: STOP và báo lại"), this qualifies as too large/uncertain a change to bundle into this sprint — left untouched, still recorded as an open Product decision for a dedicated future task.

## UI Result

`RoomBoardPanel.vue`'s Change Room dialog (reused, no new dialog) now lists all available rooms regardless of type, showing room number, room type name, and status label per option. When the selected room's type differs from the Stay's current type, a short informational note appears: "Đổi loại phòng không tự động thay đổi giá. Phụ thu hoặc giảm giá, nếu có, được nhập riêng bằng gói dịch vụ/phí bổ sung." No automatic price, no price difference, no upgrade fee, and no projection-by-new-type is ever shown. Flow remains one step: chọn phòng → nhập lý do → xác nhận.

## Audit Verification

`ROOM_MOVE` Stay Event reused (no new event type). Metadata bumped to `version: 2` and now includes `old_room_type_id`, `old_room_type_name`, `new_room_type_id`, `new_room_type_name` alongside the existing `old_room_id`/`old_room_number`/`new_room_id`/`new_room_number`/`reason`. `actor_id` and the event timestamp remain first-class `StayEvent` columns (as in Phase 4.3A), not metadata fields. No "upgrade price"/"downgrade refund" fields exist anywhere in the metadata — confirmed by `test_cross_type_move_records_room_type_metadata`, which asserts both room-type fields are present and both forbidden keys are absent.

---

## Files Changed

- `app/Services/StayService.php` — removed the different-room-type rejection in `moveRoom()`; added `roomType` eager-loading for old/new room; enriched `StayEventType::RoomMove` metadata (room type fields, version bump); updated class docblock.
- `app/Services/Posting/RoomChargePostingJob.php` — `resolveUnitPrice()` now resolves commercial room type via `RoomAssignment.room_type_id` (fallback to physical room type).
- `app/Services/PaymentProjectionService.php` — added `commercialRoomTypeId()` helper with the identical resolution; `project()` eager-loads `stays.roomAssignment`.
- `resources/js/Pages/Admin/Bookings/Partials/RoomBoardPanel.vue` — widened the Change Room room picker to allow any type, added room type/status display and the cross-type informational warning.
- `tests/Unit/Services/StayServiceMoveRoomTest.php` — replaced the now-obsolete "different room type rejected" test with 8 new tests covering cross-type success, commercial-type preservation, physical-type update, `BookingRequirement` preservation, projection preservation, assignment-summary correctness, Night Audit correctness, and audit metadata.
- `tests/Feature/RoomMoveControllerTest.php` — added an HTTP-level cross-type success test.
- `tests/Feature/BackfillPerNightChargesCommandTest.php` — fixed a pre-existing, unrelated test-factory bug (see "Remaining Risks" below) that the source correction exposed.

**Not changed:** `BookingRequirement` model, `FolioService`, `BookingPaymentService`, `NightAuditService`, `NightAuditPipeline`, any `PostingJob` other than `RoomChargePostingJob`'s rate-source line, `Booking` model, `Stay` model schema, any migration.

---

## Targeted Tests

112 tests, all passing: `StayServiceMoveRoomTest` (24, incl. 8 new), `RoomMoveControllerTest` (7, incl. 1 new), `PaymentProjectionServiceTest` (25), `PaymentProjectionUiReadinessTest` (4), `NightAuditPipelineFeatureTest` (10), `NightAuditOperationsTest` (23), `RoomChargeHotfixTest` (8), `RoomAvailabilityRuleServiceTest` (8, confirms the VacantDirty decision was correctly left untouched), plus `BackfillPerNightChargesCommandTest` (4, re-verified after the test-data fix).

## Full Regression

Run 3 times total. The first run surfaced 2 unexpected failures in `BackfillPerNightChargesCommandTest` — investigated immediately (see Remaining Risks), fixed as a test-data correction (not production code), then re-verified with two further full runs:

| Run | Failed | Passed | Total | Notes |
|---|---|---|---|---|
| 1 (before test-data fix) | 27 | 864 | 891 | 23 baseline + 2 known flakes (LateCheckoutFeeTest, PerStayAttributionTest) + 2 from the exposed test bug |
| 2 (after test-data fix) | 24 | 867 | 891 | 23 baseline + 1 known flake (LateCheckoutFeeTest) |
| 3 (after test-data fix) | 24 | 867 | 891 | Byte-identical test list to run 2 |

**New deterministic regressions: NONE.** All 23 baseline failures (11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest`, date-drift) are present in every run. `LateCheckoutFeeTest > check out triggers late checkout fee via stay service` is the documented pre-existing wall-clock flake — confirmed unrelated to this sprint's changes by reading `LateCheckoutFeePostingJob::resolveUnitPrice()`, which resolves via `RoomRate` keyed on `$stay->room->room_type_id` directly, a file this sprint never touched. `PerStayAttributionTest`'s Faker unique-collision flake appeared in run 1 only, consistent with its documented intermittency.

## Build Result

`npm run build` — 0 errors (pre-existing >500kB chunk warning, unchanged, not a blocker).

---

## Remaining Risks

**Pre-existing test-factory bug found and fixed (not a production risk):** `BackfillPerNightChargesCommandTest::makeCheckedInStay()` built its `RoomAssignment` via `RoomAssignment::factory()->for($booking)->for($room)->create([...])`. Laravel's `for()` only overrides the `room_id` foreign key matching the pinned relationship — it does not recompute other attributes the factory's own `definition()` sets, so `room_type_id` was silently populated from a separate, throwaway `Room::factory()->create()` inside `RoomAssignmentFactory::definition()`, unrelated to the real room used in the test. This was invisible before this sprint because Night Audit ignored `RoomAssignment.room_type_id` entirely; the correct source-of-truth resolution now surfaced it. Fixed by explicitly passing `'room_type_id' => $roomType->id` in the test, matching how every other test/production call site constructs a `RoomAssignment`. Checked `EarlyCheckinFeeTest`/`LateCheckoutFeeTest`, which have the same `->for($room)` pattern without explicit `room_type_id` — they don't assert on `ChargeType::Room` postings (only `EarlyCheckin`/`LateCheckout`, resolved via a different, untouched code path keyed on the physical room), so they are not affected and were left as-is.

**VacantDirty gap** — unchanged from Sprint 02, explicitly not addressed this sprint (see Dirty Room Rule section).

**No live browser verification** performed (no browser-automation tool available in this environment, consistent with every prior sprint's report).

---

## Product Readiness

**READY**, within the scope implemented: same-room-type Room Move (Sprint 02 behavior, fully preserved) and cross-room-type Operational Move (new), both leaving Booking, Stay, BookingRequirement, Folio, Payment, and Night Audit posting logic untouched in substance — only the previously-incorrect rate-resolution *source* was corrected, which was necessary for Night Audit to actually keep its existing promise (posting the pre-existing commercial rate) once physical and commercial room type are allowed to diverge.

---

## READY FOR CHATGPT REVIEW: YES
