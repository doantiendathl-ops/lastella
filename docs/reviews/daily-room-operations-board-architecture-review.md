# Architecture Review — Daily Room Operations Board ("Sơ đồ thao tác")

**Status:** Active Pilot / Fast Implementation, per `docs/yeucaumoi.txt`.
**Scope:** All-rooms daily operations board consolidating Đổi phòng, Nhận phòng, Trả phòng, Kiểm đồ trả phòng, Dọn phòng.

## 1. Source of Truth (Mục II)

This feature is a pure orchestration/UI layer. No parallel business logic was introduced for any capability that already had a canonical service:

| Concern | Canonical source | Reused via |
|---|---|---|
| Check-in / check-out | `StayService::checkIn()` / `checkOut()` | `RoomOperationsController::checkIn()/checkOut()` call the same methods per-stay, with per-item isolation added only because the board's multi-select UX needs partial-success reporting that the existing per-stay HTTP routes (which redirect to Booking Show) don't provide. |
| Checkout inspection | `CheckoutInspection` model/flow | Board links out to the existing `/admin/checkout-inspections` screen; no duplicate inspection state. |
| Housekeeping clean/dirty | `HousekeepingService::markClean()/markDirty()` | Board calls the existing `admin.housekeeping.mark-clean/mark-dirty` routes directly, once per selected room. |
| Room move (checked-in guest) | `StayService::moveRoom()` | Untouched. The new swap engine explicitly refuses any assignment already `CheckedIn` (Mục IX/XVI), so the two flows can never overlap on the same row. |
| Cleanliness | `Room::isRoomClean()/isRoomDirty()` (`normalizedCleaningStatus()`) | Read directly, never re-derived. |
| Checked-in/out state | `Stay.actual_checkin_at` / `actual_checkout_at` | Read directly. |
| Checkout-inspection status | `BookingController::inspectionStatusFor()` | **Extracted** to `Stay::inspectionStatus()` (a genuine DRY fix — both screens now call the identical method, see Mục XXIV). |
| Extra bed | `BookingPackageFlag` where `package_key = EXTRA_BED_PER_NIGHT` | Read directly (booking-level, see §6 below — a documented, not invented, limitation). |
| Date-overlap semantics | Same predicate as `RoomAvailabilityRuleService::applyOverlap()` (`start_at < end AND end_at > start`) | Reused verbatim in `RoomOperationsBoardService` and `RoomSwapService`. |

## 2. Section XIV — Critical Data-Model Check

**Question:** Does `RoomAssignment`/`Stay` support representing a partial-interval room reassignment (a third-party booking losing only the *overlapping portion* of its stay to a swap, keeping the rest)?

**Finding:** No. `room_assignments` is one row = one continuous `(room_id, start_at, end_at)` range per booking; `stays.room_assignment_id` carries a **UNIQUE** constraint, confirming strict 1:1 Stay↔RoomAssignment. Neither model has any native sub-segmentation.

**Conclusion: DATA MODEL SUPPORTS SAFE IMPLEMENTATION = YES**, via a **release-and-recreate** strategy: the affected assignment(s) are released (`RoomAssignmentService::releaseAssignment()`, unmodified) and new assignment+stay rows are created per resulting date sub-segment (`StayService::createStayFromAssignment()`, unmodified). This produces the exact same effect as an in-place split without needing a schema change.

This is safe — not merely convenient — specifically because of two constraints the spec itself imposes:

- **Mục IX**: a swap source must not yet be checked in (`AssignmentStatus::Assigned` only).
- **Mục XVI**: any target-room assignment that *is* checked in is a hard block, never processed.

Together these guarantee every row the swap engine ever releases-and-recreates is still `Reserved`/`Assigned` with **no irreversible history** — no `actual_checkin_at`, no posted `FolioEntry` attribution (room charges post only after check-in via `RoomChargePostingJob`), no `CheckoutInspection` record. Releasing and recreating such a row loses nothing. No schema redesign, no migration beyond two small additive columns, was required.

## 3. Swap Semantics

- **Whole-Stay Rule (Mục XII):** a swap moves the source assignment for its full date range in one write, never a sub-range of the source's own stay.
- **Per-day binary swap, not a cycle (Mục XI):** the engine never processes A→B→C→A as one operation. It processes A↔(whichever booking currently overlaps the target room on each date), independently, which may resolve to different bookings across the source's own multi-day range (Mục XI's B-then-C example) — each still a binary displacement of exactly the overlapping sub-range.
- **Never touches (Mục XVIII):** `booking_id`, customer identity, `booking_requirement_id`, `room_type_id` (commercial requirement slot — copied verbatim from the released row, the same **Commercial Source Principle** `StayService::moveRoom()` already enforces), `FolioEntry`, `BookingPayment`.
- **Cleanliness (Mục VIII/XXV):** never touched by the swap engine — it is exclusively `Room`-level state.
- **Quick note / bed-joined (Mục VII/VIII/XXIX):** copied from the released assignment onto every new assignment segment it produces, so they travel with the booking.

## 4. Locking & Transactional Safety (Mục XIX/XXI)

`RoomSwapService::execute()`:
1. Locks every `Room` row referenced by the batch (source rooms ∪ target rooms), id ascending — the same "lock the Room row before writing any RoomAssignment for it" convention already used by `RoomAssignmentService::lockRoomRecheckAndCreateAssignment()`, held for the whole transaction.
2. Re-analyzes every pair from scratch under lock (`analyzePair(..., lock: true)`), which itself locks every candidate displaced `RoomAssignment` row (`lockForUpdate()`), closing the gap that a plain Room-row lock alone would leave against a concurrent single-assignment release call.
3. Rejects the **whole batch** on the first blocker found, before any write (Mục XXI) — genuinely all-or-nothing, not just per-pair.
4. Writes in two phases **per pair**: release everything vacating a room first (source + every displaced assignment), then create everything occupying a room (source's new segment, then each displaced booking's segments). This ordering was required — an earlier draft that interleaved release/create per-entity produced false self-conflicts (see Implementation Report §Errors Found And Fixed).

## 5. Audit (Mục XX)

A new `room_swap_batches` table (append-only, mirrors the existing `release_batches` precedent) records one header row per confirm click: actor, a JSON snapshot of the analyzed pairs, whether warnings were acknowledged, and timestamp. Every `RoomAssignment` row touched by the batch is tagged `swap_batch_id`. Every newly created `Stay` also gets a `StayEvent(RoomMove)` with `swap_batch_id` + from/to room metadata — reusing the existing `StayEventType::RoomMove` case and `StayEventService::record()`, not inventing a new event architecture.

## 6. Known, Documented Limitations

- **Quick note storage:** placed on `room_assignments` (not `stays`, not `Room`) because RoomAssignment is exactly the row the swap engine releases/recreates, and is created before its Stay in some existing flows (demand-first assignment defers `createStayFromAssignment()`).
- Superseded limitations from an earlier revision of this doc (extra bed being booking-level; `bed_joined` being a brand-new field) were corrected — see §8.

## 7. Financial Boundary

Unchanged. Room charge posting (`RoomChargePostingJob`) and package/service charge posting remain fully independent of room *allocation*. The swap engine never creates, voids, or re-evaluates a `FolioEntry`.

## 8. Room-Scoped Bed Operations Correction (superseding §6's original two limitations)

A later Product Owner review found the two "known limitations" above were not actually limitations — they were unfinished tracing.

**Extra Bed** was re-architected from `BookingPackageFlag(EXTRA_BED_PER_NIGHT)` (booking-level; `unique(booking_id, package_key)` — structurally cannot represent "which room") to `room_assignments.extra_bed_quantity` (room-level). This was not just a display gap: `NightAuditPipeline::run()` builds one `PostingContext` per Stay and runs every job against each one, so the old booking-level flag caused `ExtraBedPostingJob` to post once per room instead of once per enrollment — a 3-room booking with one enrolled bed was billed 3×. `ExtraBedPostingJob` now reads `$context->stay->roomAssignment->extra_bed_quantity`. `ServicePackage`/`ServicePackageRate` remain the sole price source — the new column only ever stores a quantity, never a price, matching the Commercial Source Principle already established for `room_assignments.room_type_id`.

**Ghép giường (bed-joined)** was traced to an EXISTING module this task's earlier revision missed: `BookingSpecialRequest` (category `bed_config`, request_type `twin_to_double`) already has a `stay_id` nullable FK, a full Pending→Acknowledged→Fulfilled/Cancelled lifecycle, and an existing auto-resolution helper (`SpecialRequestService::autoLinkSingleStayRequests()`) that already implements the exact "single-room resolves automatically, multi-room stays ambiguous rather than guessed" rule this task would otherwise have had to build from scratch. `room_assignments.bed_joined` — added in this doc's original §6 because the earlier trace concluded no source existed — was removed entirely (migration edited in place, since it had never been committed) in favor of reading this canonical source directly.

Full detail: `docs/reports/daily-room-operations-board-implementation-report.md`, "Room-Scoped Bed Operations Correction".
