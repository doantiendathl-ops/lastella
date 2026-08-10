# Implementation Plan — Daily Room Operations Board

Reflects the actual implementation (not a theoretical plan written before coding).

## Database (additive only)

1. `2026_08_09_000000_add_quick_note_and_bed_joined_to_room_assignments_table.php` — `quick_note` (nullable varchar 100), `bed_joined` (boolean, default false).
2. `2026_08_09_000001_create_room_swap_batches_table.php` — append-only swap audit header.
3. `2026_08_09_000002_add_swap_batch_id_to_room_assignments_table.php` — nullable FK linking any assignment touched by a swap batch back to it.

All three: nullable/defaulted, backward-compatible, no destructive changes, no legacy-row rewrite.

## Backend

| File | Role |
|---|---|
| `app/Models/RoomSwapBatch.php` | Append-only audit model, mirrors `ReleaseBatch`. |
| `app/Models/RoomAssignment.php` | +`quick_note`, `bed_joined`, `swap_batch_id` fillable/casts, `swapBatch()` relation. |
| `app/Models/Stay.php` | +`inspectionStatus()` (extracted from `BookingController`, now the single canonical source). |
| `app/Services/RoomOperationsBoardService.php` | Read model: `boardForDate()` (floor→room grouping, per-room icons/action flags), `dailySummaryForDate()` (all bookings intersecting the date, Folio totals via `PaymentProjectionService`). |
| `app/Services/RoomSwapService.php` | The swap engine: `preview()` (read-only conflict analyzer) and `execute()` (transactional, locked, all-or-nothing). |
| `app/Http/Controllers/Admin/RoomOperationsController.php` | Thin controller: `index`, `swapPreview`, `swapExecute`, `updateQuickNote`, `checkIn`, `checkOut`. |
| `app/Http/Requests/RoomOperations/*.php` | `SwapPreviewRequest`, `SwapExecuteRequest`, `UpdateQuickNoteRequest`, `BulkCheckInRequest`, `BulkCheckOutRequest` — permission + shape validation only. |
| `app/Http/Controllers/Admin/Booking/BookingController.php` | `inspectionStatusFor()` now delegates to `Stay::inspectionStatus()`. |

Routes (`routes/web.php`, all under the existing `admin.` auth group, reusing existing permissions):

```
GET   /admin/room-operations                                admin.room-operations.index
POST  /admin/room-operations/swap/preview                   admin.room-operations.swap.preview
POST  /admin/room-operations/swap/execute                   admin.room-operations.swap.execute
PATCH /admin/room-operations/assignments/{assignment}/quick-note   admin.room-operations.assignments.quick-note
POST  /admin/room-operations/check-in                        admin.room-operations.check-in
POST  /admin/room-operations/check-out                       admin.room-operations.check-out
```

Check-in/checkout/inspection/housekeeping/booking-view all reuse existing permission strings (`room.assign`, `stay.checkin`, `stay.checkout`, `checkout_inspection.perform`, `room.cleaning.update`, `housekeeping.view`, `checkout_inspection.view`) — no new permission was created.

## Frontend

```
resources/js/Pages/Admin/RoomOperations/
├── Index.vue                        (date selector, filters, selection state, toasts, orchestration)
└── Partials/
    ├── RoomOperationsBoard.vue      (floor grouping)
    ├── RoomOperationsCell.vue       (one room: icons, quick-note inline edit, selection)
    ├── RoomOperationsToolbar.vue    (sticky multi-select action bar)
    ├── SwapRoomDialog.vue           (target selection → preview → confirm)
    └── DailyBookingSummary.vue      (booking table, Folio totals, View Booking link)
```

Menu entry added to `resources/js/Layouts/AppLayout.vue`: "Sơ đồ thao tác" → `/admin/room-operations`, visible to anyone holding at least one operational permission.

## Swap Engine Algorithm (per pair)

1. **Analyze** (`analyzePair`): validate source (must be `Assigned`, booking not terminal), validate target room (not the same room, not unavailable), find every `Assigned`/`CheckedIn` assignment on the target room overlapping the source's date range ("displaced"). Any `CheckedIn` displaced row is a hard blocker. Compute `overlap = [max(displaced.start, source.start), min(displaced.end, source.end)]` per displaced row.
2. **Release phase** (execute only): release the source assignment, then release every displaced assignment — all vacating writes before any occupying write, in that order, for the whole pair.
3. **Create phase**: create the source's new assignment on the target room for its full range; then, per displaced row, create up to three new segments — remainder before the overlap (stays in the target room), the swapped segment (moves onto the source's vacated room), remainder after the overlap (stays in the target room). Only segments with non-zero duration are created.
4. Every new segment gets a `Stay` (`createStayFromAssignment`) and a `StayEvent(RoomMove)` audit row, tagged with the batch id.

Batch-level: lock every Room referenced (ascending), analyze every pair under lock, abort the whole transaction on the first blocker (no writes happen until every pair has passed validation), then write pair-by-pair.

## Deviations From a Literal Reading of the Spec

- Swap **preview/execute IDs are `source_assignment_id`**, not `room_id` — a room can have more than one assignment across a date range (same-day turnover), so the assignment id is the only unambiguous handle for "this specific occupancy".
- **Bulk check-in/check-out** got two new, thin orchestration endpoints instead of reusing the existing per-stay routes directly — those routes redirect to Booking Show (a different page), which would defeat the board's "don't leave the map" goal. No business logic was duplicated; both endpoints call `StayService::checkIn()/checkOut()` per stay, with per-item try/catch isolation added at the orchestration layer only.
- **Kiểm đồ (inspection)** is a navigation link to the existing `/admin/checkout-inspections` screen, not an inline action — the existing inspection flow (draft/save/complete) is a multi-step process with its own UI; duplicating it inline would risk a second inspection state.

## Room-Scoped Bed Operations Correction (added after initial delivery)

- `PackageEnrollmentService::extraBedRoomBreakdown()` / `updateExtraBedRoomQuantities()` — new, room-scoped enrollment path for Extra Bed, alongside (not replacing) the generic booking-level `enroll()`/`unenroll()` used by every other package. `enroll()` now explicitly rejects `EXTRA_BED_PER_NIGHT` so a forged request can't recreate the old ambiguous row.
- `PackageEnrollmentController::updateExtraBedRooms()` + `PATCH admin/bookings/{booking}/packages/extra-bed-rooms` — new endpoint; `Packages.vue` special-cases the Extra Bed card into a per-room quantity table instead of the generic toggle.
- `app/Console/Commands/BackfillExtraBedRoomQuantity.php` — dry-run-by-default backfill for legacy `BookingPackageFlag(EXTRA_BED_PER_NIGHT)` rows, mirroring the existing `BackfillBookingRequirementLinks` pattern (single-room maps, multi-room ambiguous left untouched and reported).
- `RoomOperationsBoardService` — `extraBedBookingIds()` removed; room cells now read `room_assignments.extra_bed_quantity` directly. New `bedJoinRequestsByStayId()` batch-queries `BookingSpecialRequest` (category `bed_config`, request_type `twin_to_double`) keyed by `stay_id`, excluding Cancelled. `dailySummaryForDate()` gained `extra_bed_summary`, `bed_join_rooms`, `bed_join_unresolved_count` per booking.
- `RoomSwapService` — `createSplitSegment()` now returns the new `Stay` and copies `extra_bed_quantity` (was `bed_joined`); new `relinkSpecialRequests()` calls the existing `SpecialRequestService::linkToStay()` to rebind any non-cancelled special request from a released assignment's old Stay onto the new one it just created — for both the source booking's move and (specifically) the displaced booking's "swap" segment.
- The `2026_08_09_000000` migration (never committed) was edited in place to drop `bed_joined` before it was ever shipped, rather than adding a follow-up drop migration; a new `2026_08_09_000004` migration adds `extra_bed_quantity`.
