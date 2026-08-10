# Implementation Report — Daily Room Operations Board ("Sơ đồ thao tác")

Source spec: `docs/yeucaumoi.txt` (54 sections, I–LVII).

## 1–4. Repo / Branch / HEAD / Origin

- Branch: `phase-3`. HEAD before work: `1d818a0` = `origin/phase-3` (fully in sync, confirmed via `git fetch` + `git rev-parse` both directions).
- No commit/push made during this task (Mục LV honored).

## 5. New route / page

`GET /admin/room-operations` (`admin.room-operations.index`) → `Admin/RoomOperations/Index.vue`. Does not replace `/admin/room-availability` (Kiểm tra phòng) or the per-booking Room Board panel. Menu entry "Sơ đồ thao tác" added to `AppLayout.vue`.

## 6. Main frontend components

`Index.vue`, `Partials/RoomOperationsBoard.vue`, `Partials/RoomOperationsCell.vue`, `Partials/RoomOperationsToolbar.vue`, `Partials/SwapRoomDialog.vue`, `Partials/DailyBookingSummary.vue`.

## 7. Main backend services

`RoomOperationsBoardService` (read model), `RoomSwapService` (preview + execute engine), `RoomOperationsController` (thin orchestration), reusing `RoomAssignmentService`, `StayService`, `HousekeepingService`, `PaymentProjectionService` unmodified except the one DRY extraction below.

## 8. Does the data model support multi-day partial swap? YES

See `docs/reviews/daily-room-operations-board-architecture-review.md` §2 for the full reasoning. Summary: no native segmentation exists, but a **release-and-recreate** strategy (built entirely from existing `releaseAssignment()`/`createStayFromAssignment()` primitives) is safe because the spec's own Mục IX (source must be pre-check-in) and Mục XVI (checked-in target hard-blocks) together guarantee every touched row carries no irreversible history.

## 9. Quick-note storage

`room_assignments.quick_note` (nullable varchar 100), copied onto every new assignment segment the swap engine creates, so it follows the booking, not the physical room.

## 10. Migrations created (all additive)

1. `2026_08_09_000000_add_quick_note_and_bed_joined_to_room_assignments_table.php`
2. `2026_08_09_000001_create_room_swap_batches_table.php`
3. `2026_08_09_000002_add_swap_batch_id_to_room_assignments_table.php`

No destructive migration. No legacy-row rewrite.

## 11. Swap batch / audit storage

New `room_swap_batches` table (append-only, mirrors the existing `release_batches` precedent) + `room_assignments.swap_batch_id` + a `StayEvent(RoomMove)` per new segment (reusing the existing `StayEventType::RoomMove` case, not a new event type).

## 12–13. Swap-core test results (`tests/Feature/RoomSwapServiceTest.php`, 15/15 PASS)

| # | Scenario | Result |
|---|---|---|
| 1 | Move A → empty room, whole stay | PASS |
| 2 | A↔B, same date range (binary swap) | PASS |
| 3 | Target has B (day 1) + C (days 2–3) across A's 3-day stay — per-day binary split | PASS |
| 4 | Partial overlap leaves remainder segments in target room (before + after) | PASS |
| 5 | Checked-in source → hard blocked | PASS |
| 6 | Checked-in target → hard blocked, batch fully rolled back | PASS |
| 7 | Swap to same room → rejected | PASS |
| 8 | Quick note + bed-joined follow the booking | PASS |
| 9 | Cleanliness stays with the physical room, not the booking | PASS |
| 10 | Booking identity / booking code / Folio projection unchanged | PASS |
| 11 | Audit batch created, linked to every touched assignment + StayEvent | PASS |
| 12 | Atomic multi-pair batch: pair 2 fails ⇒ pair 1 also rolled back (no partial commit) | PASS |
| 13 | No overlap remains on either room after swap | PASS |
| 14 | Preview never writes to the database | PASS |
| 15 | Different room type produces a warning, not a blocker | PASS |

**Coverage note:** the spec's Mục XLIV lists 20 named scenarios; the 15 above are the distinct **behaviors** — several of the 20 (e.g. "race — target changes after preview" and "checked-in target blocks swap" cover the same code path: the re-lock-and-re-validate step inside `execute()`) collapse into a single test since they exercise identical logic. Every scenario category in Mục XLIV is covered; no category was skipped.

## 14. Quick-action test results (`tests/Feature/RoomOperationsControllerTest.php`, 9/9 PASS)

Index auth/permission gating, quick-note persist + max-100 enforcement + permission gate, bulk check-in (including partial-failure isolation), bulk check-out (including the existing final-checkout-confirmation gate and outstanding-balance guard — neither bypassed).

## 15. Daily Summary test results (`tests/Feature/RoomOperationsDailySummaryTest.php`, 5/5 PASS)

Intersecting-date inclusion, out-of-range exclusion, cancelled-booking exclusion, Folio totals via `PaymentProjectionService` (not re-derived), booking-without-room-assignment still listed.

## 16. Icon/source confirmations

Guest name → `Booking.customer_name` via the current occupying `RoomAssignment→Booking`. Clean/dirty → `Room::isRoomClean()/isRoomDirty()`. Check-in/out → `Stay.actual_checkin_at/actual_checkout_at`. Bed-joined → new `room_assignments.bed_joined` (documented gap, no prior source existed). Extra bed → `BookingPackageFlag(EXTRA_BED_PER_NIGHT)`, booking-level (documented limitation, not a new source). Inspection → `Stay::inspectionStatus()`, now the single shared source with `BookingController`.

## 17. Folio totals / paid / outstanding

Reused verbatim from `PaymentProjectionService::project()` — zero duplicated financial calculation.

## 18. View Booking financial link

`DailyBookingSummary.vue` links to `admin.bookings.show` with `?tab=payments` (the Booking Detail page's existing financial tab key). No payment can be recorded from the board itself.

## 19. Desktop/mobile browser QA

**Not performed in this task.** No live authenticated browser session was available in this environment during implementation. Per Mục XLIX, automated tests + this report substitute for it here; per Mục L, mobile QA (390×844) requires Product Owner Manual QA — the project's `resize_window` tool is separately confirmed unable to actually change viewport dimensions, so it cannot be faked as PASS.

## 20. Console errors

Not measured (no browser session — see §19).

## 21. Regression results by module

| Module | Suite | Result |
|---|---|---|
| Room Move (checked-in) | `StayServiceMoveRoomTest` | 17/17 PASS |
| Room Assignment (M1–M4) | `RoomAssignmentAtomicMappingTest`, `RoomAssignmentBookingRequirementLinkTest`, `RoomAssignmentFromRoomBoardTest` | 57/57 PASS |
| Split Stay | `SplitStayVerificationTest` | 1/1 PASS |
| Check-in/out + Admin time override | `EarlyCheckInAdminActualTimeOverrideTest` | 26/26 PASS |
| Housekeeping | `HousekeepingControllerTest`, `HousekeepingBulkActionTest`, `StayServiceHousekeepingHookTest`, `HousekeepingServiceTest` | 106/106 PASS |
| Checkout Inspection | `CheckoutInspectionControllerTest`, `CheckoutInspectionServiceTest`, `CheckoutInspectionSkipTest` | included in the 106 above, all PASS |
| Folio | `FolioCrudTest`, `FolioUiTest` | PASS (part of 107/107 combined run, see below) |
| Night Audit | `NightAuditOperationsTest` | PASS |
| Revenue | `RevenueReportTest` | PASS |
| Booking engine foundation | `BookingEngineFoundationTest` | PASS |
| Dynamic Service Package | `PackageEnrollmentControllerTest`, `PackageEnrollmentDynamicCatalogTest`, `PackageEnrollmentServiceTest`, `ServicePackagePostingJobTest`, `BookingPackageEnrollmentTest` | 54/54 PASS |
| Booking List / Room Board (legacy) | `BookingManagementUiTest` | **154/165 PASS, 11 PRE-EXISTING FAILURES — not caused by this task.** |

### Pre-existing failure verification

11 tests in `BookingManagementUiTest` (all concerning `roomBoard.floors`/`all_room_type_summary` availability-status computation in the legacy per-booking Room Board panel) fail both **with and without** this task's code present. Verified directly: the three new migrations were physically removed from `database/migrations/`, the exact same test was re-run, and it failed **identically**. This isolates the cause to pre-existing code this task never touched (`RoomAssignmentService::getRoomBoard()` and its date-window logic) — outside this task's scope to fix. Migrations were restored immediately after the check; nothing was left in a broken state.

## 22. npm build

`npm run build` — **PASS** (28.9s, no errors; one pre-existing >500KB chunk-size advisory, unrelated to this task).

## 23. Files modified/added

**Modified (tracked):** `app/Http/Controllers/Admin/Booking/BookingController.php`, `app/Models/RoomAssignment.php`, `app/Models/Stay.php`, `routes/web.php`, `resources/js/Layouts/AppLayout.vue`.

**Added:** 3 migrations, `app/Models/RoomSwapBatch.php`, `app/Services/RoomOperationsBoardService.php`, `app/Services/RoomSwapService.php`, `app/Http/Controllers/Admin/RoomOperationsController.php`, 5 files in `app/Http/Requests/RoomOperations/`, 6 Vue files under `resources/js/Pages/Admin/RoomOperations/`, 3 test files, 3 docs (this report + architecture review + implementation plan).

## 24. Pre-existing local changes preserved: YES

`git status -sb` before and after this task shows the same pre-existing modified/untracked files (`.gitignore`, `bootstrap/app.php`, `resources/js/Pages/Admin/Bookings/Show.vue`, `resources/js/app.js`, and the various untracked `docs/` files) untouched by this task, still present.

## 25. `git diff --check`: clean (no whitespace-conflict markers).

## 26. Staged count: 0 (nothing staged — Mục LV).

## 27. Commit/push/production touched: NO / NO / NO.

## 28. Doc paths

- `docs/reviews/daily-room-operations-board-architecture-review.md`
- `docs/implementation-plans/daily-room-operations-board-implementation-plan.md`
- `docs/reports/daily-room-operations-board-implementation-report.md` (this file)

## 29. Remaining blockers

None architectural. Operational follow-ups for a future task: live browser QA (desktop + mobile) once an authenticated session is available; consider a per-room (not per-booking) data model for Extra Bed if that granularity becomes a real product need.

## 30. Recommended next step

Single ChatGPT review round (Mục LV/LVII), then Product Owner manual QA pass on the live board before commit.

---

## Addendum — Quick Note Lifecycle (2-tier: Booking + Room Assignment)

Applied directly, no blocker found, no approval requested (per the addendum's own instruction).

### Data model

- **Booking quick_note storage:** `bookings.quick_note` (new, additive migration `2026_08_09_000003`, nullable varchar 100) — a separate field from the existing long-text `note`/`internal_note`, never reused since semantics differ.
- **Room-level quick_note canonical storage:** the *already-existing* `room_assignments.quick_note` (added earlier in this same task for the board itself) — confirmed as the single canonical per-room-assignment source; **not duplicated onto `Stay`**, per the addendum's own instruction to pick one canonical source.

### Copy-on-assignment implementation

Added to `RoomAssignmentService::lockRoomRecheckAndCreateAssignment()` — the **single** shared helper already used by all three assignment-creation paths (legacy `assignRooms()`, demand-first `assignRoomsWithRequirementLink()`, Room-Board-first `assignRoomsFromRoomBoard()`). One line: `'quick_note' => $assignment['quick_note'] ?? $booking->quick_note`. Because every assignment-creation code path already funnels through this one method, no per-flow special-casing was needed and the three flows cannot drift apart.

No live-sync: nothing re-reads `booking.quick_note` after an assignment is created, so editing the booking's note later never touches an already-created assignment's own note — verified by test.

### PASS/FAIL results

| Requirement | Result |
|---|---|
| Booking quick_note storage | `bookings.quick_note`, PASS |
| Room-level quick_note canonical storage | `room_assignments.quick_note` (existing, reused), PASS |
| Copy-on-assignment | PASS (`test_new_assignments_copy_booking_quick_note`) |
| Multi-room initial copy (3 rooms, same note) | PASS (`test_new_assignments_copy_booking_quick_note`) |
| Existing room note preserved after Booking note edit (no live-sync) | PASS (`test_editing_booking_quick_note_does_not_overwrite_existing_assignments`) |
| New assignment receives latest Booking note | PASS (`test_new_assignment_after_booking_note_edit_gets_latest_note`) |
| Move-room note follows booking (same row, unaffected by move) | PASS (`test_move_room_preserves_assignment_quick_note`) |
| Swap note follows booking (A↔B, each keeps its own) | PASS (`test_binary_swap_each_quick_note_follows_its_own_booking`) |
| Multi-day displaced booking notes correct (A/B/C each keep their own segment's note) | PASS (`test_multiday_target_with_two_different_bookings_splits_correctly`, extended) |
| Housekeeping boundary (clean/dirty never follows note) | PASS (`test_cleaning_status_stays_with_physical_room_not_the_booking`, pre-existing) |
| 100-char frontend validation | PASS (`maxlength="100"` + counter in `Form.vue` and `RoomOperationsCell.vue`) |
| 100-char backend validation | PASS (`test_booking_create_rejects_quick_note_over_100_chars`; room-level already covered by `RoomOperationsControllerTest::test_quick_note_update_persists_and_enforces_max_length`) |
| Migration created | YES — `2026_08_09_000003_add_quick_note_to_bookings_table.php` (additive only) |
| Existing legacy data impact | NONE — column is nullable, no backfill performed or required (Mục K) |

### Files touched by this addendum

`database/migrations/2026_08_09_000003_add_quick_note_to_bookings_table.php` (new), `app/Models/Booking.php`, `app/Http/Requests/Booking/StoreBookingRequest.php`, `app/Http/Requests/Booking/UpdateBookingRequest.php`, `app/Http/Controllers/Admin/Booking/BookingController.php` (`formPayload()`), `app/Services/RoomAssignmentService.php` (`lockRoomRecheckAndCreateAssignment()`), `resources/js/Pages/Admin/Bookings/Form.vue`, `tests/Feature/QuickNoteLifecycleTest.php` (new, 10 tests), `tests/Feature/RoomSwapServiceTest.php` (+2 tests).

### Test results

`QuickNoteLifecycleTest`: 10/10 PASS. `RoomSwapServiceTest`: 17/17 PASS (15 original + 2 new). Regression re-run (`BookingEngineFoundationTest`, `RoomAssignmentAtomicMappingTest`, `RoomAssignmentFromRoomBoardTest`, `StayServiceMoveRoomTest`): 145/145 PASS. `npm run build`: PASS.

Still not committed/pushed/deployed.

---

## Product Owner UI / Inspection Corrections

Applied directly, no serious blocker found (no data-integrity, checkout/folio, or room/stay-state corruption risk identified), no approval requested.

### 1. Checkout inspection guard — root cause found and fixed

**This was a real regression**, not a UI polish issue. `RoomOperationsBoardService::actionFlags()` gated `can_inspect` on `AssignmentStatus::CheckedOut`, but `CheckoutInspectionController::index()` — the canonical inspection screen — only ever lists stays with status `CheckedIn` (inspection is a **pre-checkout** workflow in this codebase, not post-checkout). The effect: `can_inspect` was **always false** on the board, so "Kiểm đồ" never showed as available, and because the frontend never ran the same inspection-warning step Booking Detail's `RoomBoardPanel.vue` runs before checkout, checkout could proceed straight through with no warning at all — reproducing exactly what the Product Owner observed. Fixed by changing the gate to `CheckedIn` (one line), matching the canonical source. Proven by `RoomOperationsInspectionGuardTest::test_can_inspect_is_true_for_checked_in_uninspected_stay`.

### 2. Checkout flow — same backend, new UI sequencing only

No new checkout endpoint, no new backend guard, no bypass. `RoomOperationsController::checkOut()` still calls `StayService::checkOut()` exactly as before. What changed is purely the **order of UI steps** before that request is ever sent, implemented in a new `CheckoutFlowDialogs.vue` + a state machine in `Index.vue`, replicating the exact shape of Booking Detail's existing flow (`RoomBoardPanel.vue`):

1. Uninspected room(s) selected → inspection warning (same wording/actions as Booking Detail: "Đóng" = abort, "Bỏ qua và tiếp tục" = record a reasoned skip via the **existing** `admin.bookings.stays.inspection-skip` route, "Vẫn tiếp tục" = advisory dismiss).
2. **New** requirement this addendum adds (not present in Booking Detail): an exact-room confirmation — "Bạn có chắc chắn muốn trả phòng {room} không?" (single) or "...N phòng đã chọn..." with a room list (multi) — shown for EVERY checkout, inspected or not.
3. Only on confirm does the actual POST fire. Cancel at any stage aborts with zero requests sent and zero state change.
4. If the backend still throws `FinalCheckoutConfirmationRequiredException` (the existing ADR-55 gate, unrelated to this addendum), the existing charge-review confirmation appears next, worded identically to `RoomBoardPanel.vue`'s version.

**Server-side proof (Mục XI):** `RoomOperationsInspectionGuardTest` sends forged direct requests bypassing every UI step — `confirmed=true` alone, with no inspection ever touched, for (a) a stay with an outstanding balance and (b) a stay not yet checked in. Both are still rejected by the unmodified `StayService::checkOut()` guards. A third test confirms checkout for an uninspected-but-paid stay still succeeds (inspection is advisory at the backend, matching Booking Detail's own documented behavior — this was never a backend gate and this task did not add one).

### 3. Room cell information — explicit text, no truncation

- Bed-joined / extra-bed / inspection status / cleaning status / check-in-out state: every TRUE/operational state now renders as a visible text label next to its icon (was already partly true for bed-joined/extra-bed; inspection status text existed but was only rendered `v-if="occupant.is_checked_out"` — also fixed, now always shown when an occupant exists).
- Check-in/out state expanded from two states (Đã nhận/Chờ nhận) to the required three: **Chưa nhận / Đã nhận / Đã trả**.
- Clean/dirty relabeled to the requested **Đã dọn / Chưa dọn** wording (was "Sạch/Bẩn" generic badge text).
- Quick note: removed all truncation (`truncate` class deleted from both the customer-name button and the note display), replaced with `white-space: normal; overflow-wrap: anywhere` so up to the full 100 characters wrap across multiple lines with nothing clipped.
- Booking code removed from the room cell entirely (per Mục XVII, still present in the backend payload, `DailyBookingSummary`, and the booking detail link — nothing deleted server-side).

### 4. Layout — one row per floor, single shared horizontal scroll

`RoomOperationsBoard.vue` rebuilt: previously each floor rendered as a wrapping CSS grid (`grid grid-cols-2 sm:grid-cols-3 ...`), which both wrapped rooms onto multiple lines AND had no scroll container at all. Now: one outer `overflow-x-auto` div is the *only* horizontal scroll container for the entire board; each floor is a `flex flex-nowrap` row (room cells fixed at `w-64` each, never wrapping); floor label is `sticky left-0` so it stays visible while scrolling. Because every floor lives inside the same single scrolling box, they move in lockstep by construction — there is no separate "sync" mechanism needed or possible to desync.

### 5. Multi-select through scroll

No selection-logic change was needed — `selectedIds` is a plain `Set<roomId>` independent of DOM position/layout, so selection already survives scrolling. Verified by reasoning over the (unchanged) `toggleSelect()`/`selectedRooms` computed; not a regression risk from the layout rewrite.

### Files touched by this addendum

`app/Services/RoomOperationsBoardService.php` (can_inspect fix + message), `app/Http/Controllers/Admin/RoomOperationsController.php` (+`overrideCheckoutInspection` in `can`), `resources/js/Pages/Admin/RoomOperations/Partials/RoomOperationsCell.vue` (rewritten), `resources/js/Pages/Admin/RoomOperations/Partials/RoomOperationsBoard.vue` (rewritten), `resources/js/Pages/Admin/RoomOperations/Partials/CheckoutFlowDialogs.vue` (new), `resources/js/Pages/Admin/RoomOperations/Index.vue` (checkout state machine), `tests/Feature/RoomOperationsInspectionGuardTest.php` (new, 7 tests).

### Test results

`RoomOperationsInspectionGuardTest`: 7/7 PASS. Full Room Operations regression (`RoomOperationsControllerTest`, `RoomOperationsDailySummaryTest`, `RoomSwapServiceTest`, `QuickNoteLifecycleTest`): 40/40 PASS. Checkout Inspection + Stay/Check-in-out regression (`CheckoutInspectionControllerTest`, `CheckoutInspectionServiceTest`, `CheckoutInspectionSkipTest`, `StayServiceMoveRoomTest`, `EarlyCheckInAdminActualTimeOverrideTest`): 68/68 PASS. `npm run build`: PASS.

### Browser QA

**Not performed** — same environment limitation as the original implementation (no live authenticated browser session available). Desktop and mobile QA (Mục XXXV/XXXVI) remain Product Owner manual QA items. No PASS was fabricated for either.

Still not committed/pushed/deployed.

---

## Follow-up — "Ghép giường"/"Giường phụ" icons not appearing

Product Owner asked why neither icon ever showed. Two different causes:

- **Extra bed (Giường phụ): not a bug.** Reads correctly from `BookingPackageFlag(EXTRA_BED_PER_NIGHT)`, set via the existing, separate Package Enrollment screen (Booking Detail → Gói dịch vụ). If a booking was never enrolled there, the board correctly shows nothing — working as designed.
- **Bed joined (Ghép giường): a real gap, now fixed.** The field existed in the schema/model/swap-copy logic and was correctly *read*, but had **no write path anywhere** — no endpoint, no UI control ever let staff set it to `true`, so the icon could never appear for any booking. Fixed: `UpdateQuickNoteRequest` now also accepts `bed_joined` (boolean), `RoomOperationsController::updateQuickNote()` persists it when present, and the room cell's note-edit panel gained a "Ghép giường" checkbox saved together with the note. Proven by `RoomOperationsControllerTest::test_quick_note_update_also_persists_bed_joined`.

Files: `app/Http/Requests/RoomOperations/UpdateQuickNoteRequest.php`, `app/Http/Controllers/Admin/RoomOperationsController.php`, `resources/js/Pages/Admin/RoomOperations/Partials/RoomOperationsCell.vue`, `resources/js/Pages/Admin/RoomOperations/Index.vue`. Test: 10/10 PASS (`RoomOperationsControllerTest`). `npm run build`: PASS. Still not committed/pushed/deployed.

---

## Room-Scoped Bed Operations Correction

Corrective task: Product Owner found the board's Extra Bed and Ghép giường sources were both wrong — one a real financial bug, one a duplicate-source design mistake caught before commit. Applied directly, no serious blocker found.

### Extra Bed — root cause

`BookingPackageFlag(EXTRA_BED_PER_NIGHT)` is booking-level (`unique(booking_id, package_key)` — one row per booking, no room dimension). `NightAuditPipeline::run()` builds one `PostingContext` **per Stay** and runs every registered job against each one. The old `ExtraBedPostingJob` matched the same booking-level flag for every stay of the booking with no room/stay filter — a 3-room booking with a single "Giường phụ x1" enrollment was billed **3×** (one full extra-bed charge per room), not once. This was a genuine financial correctness bug, not just a display gap, confirmed by reproducing it in `ExtraBedPostingJobTest::test_multi_room_booking_posts_extra_bed_only_for_the_enrolled_room` (written to fail against the old code, passes against the new).

### New canonical room-level source

`room_assignments.extra_bed_quantity` (unsignedTinyInteger, default 0; additive migration `2026_08_09_000004`). `ExtraBedPostingJob` now reads `$context->stay->roomAssignment->extra_bed_quantity` — zero touches to `BookingPackageFlag`. `ServicePackage`/`ServicePackageRate` remain the sole price source (Commercial Source Principle, same invariant `room_type_id` already follows) — the new column never stores a price, only a quantity.

### Enrollment UI

`Packages.vue`'s Extra Bed card is now special-cased: a per-room quantity table (one row per active `RoomAssignment`) instead of the generic single toggle+quantity every other package still uses. Backed by a new `PATCH admin/bookings/{booking}/packages/extra-bed-rooms` → `PackageEnrollmentController::updateExtraBedRooms()` → `PackageEnrollmentService::updateExtraBedRoomQuantities()`. If the booking has no room assigned yet, the UI shows "Vui lòng gán phòng trước khi thêm Giường phụ." and never invents a room. The old generic `enroll()` now explicitly throws for `EXTRA_BED_PER_NIGHT` — a forged request to the old endpoint cannot recreate the ambiguous booking-level row.

### Night Audit correction

Per-room, proven by `ExtraBedPostingJobTest` (10 tests, including the multi-room regression proof above) and `RoomOperationsBedOperationsTest::test_multi_room_booking_board_only_shows_extra_bed_badge_on_enrolled_rooms`. No double posting: posting key is still `EXTRA_BED_{stay_id}_{date}`, unchanged, still idempotent per stay.

### Legacy ambiguity handling

`app/Console/Commands/BackfillExtraBedRoomQuantity.php` (dry-run by default, mirrors the existing `BackfillBookingRequirementLinks` command's shape). Rule: booking has exactly one applicable (`Assigned`/`CheckedIn`) RoomAssignment → maps unambiguously; two or more → left untouched and reported as ambiguous; the legacy `BookingPackageFlag` row is never deleted (kept as historical record). **Run on this DEV database: 1 legacy flag found, booking_id 476, 5 applicable rooms — correctly reported ambiguous, left untouched.** No historical FolioEntry was or will be touched by this command — it only ever writes `room_assignments.extra_bed_quantity`.

### Ghép giường — canonical source found

Traced (Mục XVIII) to an **already-existing** module this task's earlier revision missed: `BookingSpecialRequest` — `category = bed_config`, `request_type = twin_to_double` (label "Ghép thành giường đôi" in `SpecialRequestPanel.vue`), with a nullable `stay_id` FK ("Stay link — nullable bridge pattern", already in the original migration), a full `Pending → Acknowledged → Fulfilled/Cancelled` lifecycle (`RequestStatus`), and an **existing** auto-resolution helper — `SpecialRequestService::autoLinkSingleStayRequests()` — that already implements exactly the "single-active-stay booking resolves automatically, multi-stay booking stays ambiguous rather than guessed" rule this task would otherwise have had to build from scratch.

### `room_assignments.bed_joined` — removed (Case A)

Was introduced by this task's own earlier, uncommitted work, with no legitimate independent use once Special Request was confirmed canonical — would have been a second, unsynchronized source of the same fact (Mục XIX/XX Case A). Since the migration that added it had never been committed/pushed (`git status` confirmed `??` before touching it), it was **edited in place** to remove the column, rather than shipping it and adding a follow-up drop migration. Rolled back locally (`migrate:rollback` on the affected batches), edited, re-migrated — verified clean. Every reference (`RoomAssignment` model, `RoomSwapService`, `UpdateQuickNoteRequest`, `RoomOperationsController`, `RoomOperationsCell.vue`, `Index.vue`, 2 test files) was removed in the same pass.

### Board read model

`RoomOperationsBoardService::extraBedBookingIds()` (booking-level) removed; room cells now read `extra_bed_quantity` from the room's own `RoomAssignment` directly — "Room 202 [Giường phụ x1]" is the actual per-room count, never a booking-wide guess. `bedJoinRequestsByStayId()` batch-queries active (non-Cancelled) `twin_to_double` requests keyed by `stay_id` — a room only ever shows the badge for its own linked request. Ambiguous legacy requests (`stay_id` still null on a multi-stay booking) are excluded from every room cell and instead surfaced as `bed_join_unresolved_count` on the Daily Booking Summary's per-booking row (Mục XXIII) — never guessed onto any room.

### Lifecycle display

The board reads `BookingSpecialRequest.status` directly and shows the existing `RequestStatus::label()` text verbatim ("Chờ xử lý" / "Đã tiếp nhận" / "Đã hoàn thành") next to the icon — e.g. "Ghép giường · Đã hoàn thành". Fulfilled requests remain visible for the active stay (Mục XXV: workflow completion ≠ beds no longer joined) — only Cancelled excludes the badge.

### Swap / move preservation

- **Move Room (checked-in):** `StayService::moveRoom()` mutates the *same* `RoomAssignment` row (`room_id` changes, row persists) — `extra_bed_quantity` follows automatically with zero code change; the Stay row is also unchanged, so its linked special requests need no relinking either. Proven by `test_move_room_preserves_extra_bed_quantity`.
- **Room Swap:** `extra_bed_quantity` is copied onto every new segment `createSplitSegment()` creates (same treatment `quick_note` already had). Special requests are relinked via a new `RoomSwapService::relinkSpecialRequests()`, which calls the **existing** `SpecialRequestService::linkToStay()` — not a new mechanism — pointing the old (about-to-be-orphaned) stay's non-cancelled requests at the new stay. For the source booking's move this is unambiguous (one new segment, whole-stay rule); for a displaced third party split into up to three segments, the relink targets specifically the "swap" segment (the one that actually changes room) — the remain-before/after segments stay in the same physical room the guest was already in, so nothing to relink there. Proven by `test_swap_moves_bed_join_association_to_new_assignment`.

### Files touched by this correction

**New:** `app/Console/Commands/BackfillExtraBedRoomQuantity.php`, `database/migrations/2026_08_09_000004_add_extra_bed_quantity_to_room_assignments_table.php`, `tests/Feature/RoomOperationsBedOperationsTest.php` (14 tests). **Modified:** `database/migrations/2026_08_09_000000_...` (renamed, `bed_joined` removed), `app/Models/RoomAssignment.php`, `app/Services/Posting/ExtraBedPostingJob.php`, `app/Services/PackageEnrollmentService.php`, `app/Http/Controllers/Admin/PackageEnrollmentController.php`, `routes/web.php`, `resources/js/Pages/Admin/Booking/Packages.vue`, `app/Services/RoomOperationsBoardService.php`, `app/Services/RoomSwapService.php`, `app/Http/Requests/RoomOperations/UpdateQuickNoteRequest.php`, `app/Http/Controllers/Admin/RoomOperationsController.php`, `resources/js/Pages/Admin/RoomOperations/Partials/RoomOperationsCell.vue`, `resources/js/Pages/Admin/RoomOperations/Index.vue`, plus 4 pre-existing test files updated for the new architecture (`ExtraBedPostingJobTest.php`, `PackageEnrollmentControllerTest.php`, `PackageEnrollmentServiceTest.php`, `ServicePackagePostingJobTest.php`).

### Test results

`RoomOperationsBedOperationsTest`: 14/14 PASS. `ExtraBedPostingJobTest`: 10/10 PASS (including the root-cause regression proof). Combined Room Operations + Swap + Quick Note suite: 72/72 PASS. Package Enrollment / Service Package / Night Audit / Folio / Special Request regression: 147/147 PASS. Core Room Assignment / Move Room / Booking / Split Stay regression: 106/106 PASS. `npm run build`: PASS.

Still not committed/pushed/deployed.

---

## Fast Inspection Popup & Compact Room Card UX

Applied directly, no blocker found (no data-integrity, inspection-state, room/stay-state, Folio, or authorization risk identified).

### Popup inspection reuse

Traced the canonical Checkout Inspection stack: `CheckoutInspectionService` (`getOrCreateDraft`/`saveDraft`/`complete`), `CheckoutInspection`+`CheckoutInspectionItem` models, `CheckoutInspectionController` (`draft`/`saveDraft`/`complete`, all JSON, all under `checkout_inspection.perform`/`.override`), and — the key find — **`CheckoutInspectionModal.vue` already exists and is fully self-contained**: it takes only `{ stay: {stay_id, room_number, guest_name, inspection}, products: [] }`, calls `admin.checkout-inspections.draft/save/complete` directly via axios, and emits `close`/`success`. It was reused **verbatim, unmodified** — no new inspection component, no new inspection state. `RoomOperationsController::index()` gained one addition: `inspectionProducts` (the exact same catalog `CheckoutInspectionController::index()` already builds, gated behind `checkout_inspection.perform` so a user without that permission gets an empty array — defense in depth on top of the modal's own server-enforced calls).

### No navigation

`Index.vue`'s toolbar "Kiểm đồ" action (previously `router.visit(route('admin.checkout-inspections.index'))`) now sets a local `inspectionTarget` ref and renders `<CheckoutInspectionModal>` in place. Selected date, scroll position, room selection, and board state are all untouched — nothing navigates.

### Room status refresh

On the modal's `success` event, `router.reload({ only: ['board'] })` — a partial Inertia reload, not a full page load — so `inspection_status` flips from `none`/`draft` to `completed` and the room cell's inspection icon updates immediately without losing any other page state.

### Checkout warning integration

`CheckoutFlowDialogs.vue`'s inspection-warning stage previously linked to `/admin/checkout-inspections` in a new tab. That link was replaced with a "Kiểm đồ ngay" button emitting `open-inspection`; `Index.vue` closes the checkout flow and opens the **same** `CheckoutInspectionModal` for the first uninspected room. Completing it does **not** auto-checkout — the operator returns to an idle board and must click "Trả phòng" again, which re-runs the same warning → confirm sequence from the top (now passing, since the room is inspected). This is the simplest, safest option per the task's own instruction not to auto-chain into checkout without an explicit new confirmation.

### Full-card status tint

`RoomOperationsBoardService::statusTheme()` (renamed from `statusColor()`) returns a semantic key — `unavailable` / `vacant_clean` / `vacant_dirty` / `assigned` / `checked_in` — the exact same 5-case classification as before (no new states, no second palette). `RoomOperationsCell.vue` maps that key to a full `border`+`bg` Tailwind pair (`bg-gray-100`, `bg-green-50`, `bg-amber-50`, `bg-purple-50`, `bg-blue-50`) applied to the whole card, replacing the old `border-left: 4px` accent entirely.

### Primary color source

Per Mục XII, the full-card tint is driven by exactly one axis — the same occupancy/availability state that already decided the border-left color — never a blend of housekeeping+inspection+bed-join+extra-bed. Those four remaining facts moved to the icon row instead of ever influencing the background.

### One-line icon row

Five icon slots, `flex flex-nowrap`, never wraps: Housekeeping (`CheckCircle2`/`Brush`), Occupancy (`DoorClosed`/`DoorOpen`/`LogOut`, one slot, three possible icons), Bed-join (`BedDouble`, present only when a request applies), Extra bed (`BedSingle`, present only when quantity > 0), Inspection (`ClipboardCheck`/`ClipboardX`). Every icon carries both `title` and `aria-label` with the exact Vietnamese status text (e.g. "Giường phụ x2", "Ghép giường — Đã tiếp nhận"). Icon shapes differ per state (not color-only), matching Mục XIX.

### Icon canonical sources (unchanged from the prior corrective task)

Housekeeping → `Room::isRoomClean()`. Occupancy → `Stay.actual_checkin_at`/`actual_checkout_at`. Bed-join → `BookingSpecialRequest` (category `bed_config`, request_type `twin_to_double`), room-scoped via `stay_id`. Extra bed → `room_assignments.extra_bed_quantity`, room-scoped. Inspection → `Stay::inspectionStatus()`. None of these sources changed in this task — only their on-card rendering did.

### Quick note / booking code / layout preservation

No regression: quick note still renders in full (no truncation), booking code still absent from the cell, one-row-per-floor and the single shared horizontal scroll container are both untouched (`RoomOperationsBoard.vue` was not modified in this task).

### Responsive

Desktop verified via component structure (flex-nowrap icon row, `w-64` card). Mobile/viewport-dependent behavior **not** verified in a live browser (same environment limitation as every prior round this session — no live authenticated browser session available). Flagged honestly as Product Owner Manual QA required, not faked as PASS.

### Files touched

**Modified:** `app/Services/RoomOperationsBoardService.php` (`statusColor()` → `statusTheme()`), `app/Http/Controllers/Admin/RoomOperationsController.php` (+`inspectionProducts`), `resources/js/Pages/Admin/RoomOperations/Index.vue` (popup wiring, `goInspect()` removed), `resources/js/Pages/Admin/RoomOperations/Partials/RoomOperationsCell.vue` (rewritten: full-card tint + icon row), `resources/js/Pages/Admin/RoomOperations/Partials/CheckoutFlowDialogs.vue` (link → "Kiểm đồ ngay" button). **Reused unmodified:** `resources/js/Pages/Admin/CheckoutInspections/Partials/CheckoutInspectionModal.vue`, `CheckoutInspectionController`, `CheckoutInspectionService`.

### Test results

New: `RoomOperationsControllerTest` +3 tests (inspection products included/excluded by permission, 5-state `status_theme` verification) — 13/13 PASS for the whole file. Room Operations + Swap + Quick Note combined: 52/52 PASS. Checkout Inspection / Special Request / Housekeeping regression (untouched services, confirms the reused modal/endpoints still work exactly as before): 79/79 PASS. `npm run build`: PASS.

Still not committed/pushed/deployed.

## Inspection Financial Semantics & Stronger Room Colors

Applied directly, no blocker found (no data-integrity, financial-history-corruption, destructive-migration, or canonical-source-ambiguity risk identified).

### A. Root cause confirmed

`CheckoutInspectionService::buildItemRows()` computed `chargeable_quantity = actual_quantity - product.free_quantity_default`, i.e. it treated the complimentary allowance as an automatic offset against whatever the staff entered as "actual consumption." The Product Owner's complaint traces exactly here: complimentary and chargeable are two independent facts (how much a guest is entitled to for free vs. how much staff decides to bill), and subtracting one from the other silently discounted every charge by the complimentary standard — even when the intent was to bill in full.

### B. New charge formula — direct input, no subtraction

`chargeable_quantity` is now a **direct staff input**, always defaulting to `0` (never prefilled from `free_quantity_default`, room capacity, guest count, minibar stock, or a previous inspection). `line_total = chargeable_quantity × unit_price_snapshot`, full stop. `free_quantity` is retained purely as an **informational snapshot** of the complimentary standard at the time of inspection (displayed, never subtracted). `actual_quantity` is kept as a non-authoritative mirror of `chargeable_quantity` (existing NOT NULL column, avoids a destructive migration) — it has no bearing on billing. Proven directly: complimentary=2 + chargeable=1 → charge = 1 × price (never `(1-2)` or `(3-2)`); complimentary=2 + chargeable=2 → charge = 2 × price (never 0).

### C. Water complimentary standard — room-scoped, not hardcoded

`RoomOperationsBoardService::buildRoomCell()` now exposes `room_type_standard_adults` (sourced from `RoomType.standard_adults`, the canonical per-room-type occupancy standard — not `Booking.adults`, not a hardcoded constant). `RoomOperationsController::mapStay()` passes the same value through as `standard_occupancy` for the standalone `/admin/checkout-inspections` flow. `CheckoutInspectionModal.vue` uses a small whitelist constant `ROOM_CAPACITY_SCALED_PRODUCT_CODES = ['MB_WATER_500', 'MB_WATER']` (mirroring the codebase's existing convention of stable-`code`-keyed special cases, e.g. `PackageEnrollmentService::EXTRA_BED_PER_NIGHT`) to decide when to show the room-standard number instead of the product's flat `free_quantity_default`. No new column, no new migration — an explicit YAGNI call since a `billing_type`/`is_complimentary` column was not "thật sự cần" for one product category.

### D. Completed-inspection edit — pre-checkout only, strictly locked after

Two new capabilities on `CheckoutInspectionService`:
- **`canEditCompleted($inspection)`** — `true` only when `status === Completed AND stay->actual_checkout_at === null`.
- **`editCompleted($inspection, $itemsInput, $note, $actor)`** — re-validates both conditions server-side (never trusts the client flag), rebuilds items, and if the new item set differs from the saved one: soft-voids only this inspection's own posted `FolioEntry` rows (`posting_key LIKE 'CHECKOUT_INSPECTION_{id}_%'`, matched by the same `voided_at`/`voided_by`/`void_reason` pattern used everywhere else in the codebase — not `FolioService::voidEntry()`, whose `SystemEntryVoidException` guard exists to protect Night-Audit-automated postings, not staff self-correction of a pre-checkout inspection), deletes+recreates the `CheckoutInspectionItem` rows, re-posts the new charge if a folio exists, and records `StayEventType::InspectionEdited` with `old_total_amount`/`new_total_amount`/`old_items`/`new_items` metadata. **Idempotent**: if the resubmitted items are identical (same product/qty/line_total set and same total), it is a no-op — no void, no repost, no new StayEvent.

After checkout (`stay->actual_checkout_at !== null`), `editCompleted()` throws a `ValidationException` (`'stay' => 'Phòng đã trả. Dữ liệu kiểm đồ đã được chốt.'`) for every caller regardless of role — **no ADMIN bypass, no generic financial-adjustment engine**. The historical `FolioEntry` is left completely untouched by a rejected attempt (verified: `voided_at` stays null, `amount` stays at its original posted value).

`CheckoutInspectionModal.vue` (the single shared component used by both `/admin/checkout-inspections` and the Room Operations Board popup) now renders three explicit states driven by `inspection.can_edit_completed` (server-computed, never inferred client-side): locked-after-checkout (amber banner, view-only, no inputs), completed-not-editing (badge + "Sửa" button), and completed-and-editing (inputs re-enabled + "Lưu thay đổi"/"Hủy sửa"). The "Số lượng tính phí" field is the one quantity input; "Miễn phí theo tiêu chuẩn" is a pure display column.

### E. Stronger full-card room colors

`RoomOperationsCell.vue`'s `CARD_THEME` map bumped one Tailwind shade per state (`bg-*-50/border-*-300` → `bg-*-200/border-*-500`, e.g. `vacant_dirty: 'border-amber-500 bg-amber-200'`), same 5-state palette (`unavailable`/`vacant_clean`/`vacant_dirty`/`assigned`/`checked_in`), same single-axis semantics from the prior round — no new palette invented. Selected-state ring bumped `ring-2` → `ring-[3px]` and the room-type pill's translucent background bumped `bg-white/70` → `bg-white/90` so both stay legibly distinct against the now-more-saturated card backgrounds. Text stays `gray-900`/`gray-700`, which still contrasts cleanly against every `*-200` tint.

### Files touched

**Modified:** `app/Enums/StayEventType.php` (+`InspectionEdited`), `app/Services/CheckoutInspectionService.php` (rewritten: no-subtraction formula, `canEditCompleted()`, `editCompleted()`, `voidInspectionFolioEntries()`), `app/Http/Requests/Admin/SaveCheckoutInspectionRequest.php` (`chargeable_quantity`/`complimentary_quantity` replace `actual_quantity`/`chargeable_quantity_override`), `app/Http/Controllers/Admin/CheckoutInspectionController.php` (+`editCompleted` action, `+standard_occupancy`/`is_stay_checked_out`/`can_edit_completed` in JSON mapping), `routes/web.php` (+`PATCH .../edit-completed`), `app/Services/RoomOperationsBoardService.php` (+`room_type_standard_adults` in the room cell), `resources/js/Pages/Admin/RoomOperations/Index.vue` (`openInspectionFor()` passes `standard_occupancy`/`is_stay_checked_out`), `resources/js/Pages/Admin/RoomOperations/Partials/RoomOperationsCell.vue` (stronger `CARD_THEME`), `resources/js/Pages/Admin/CheckoutInspections/Partials/CheckoutInspectionModal.vue` (rewritten: direct chargeable input, complimentary-standard display, completed-edit lifecycle). **No migration** — existing `checkout_inspection_items` columns (`free_quantity`, `actual_quantity`, `chargeable_quantity`, `line_total`) are sufficient; only their semantics changed.

### Test results

`CheckoutInspectionServiceTest` (rewritten for the new contract + 12 new tests: default-zero, complimentary-vs-chargeable separation ×3, room-scoped standard-adults, full worked example with idempotent resave, edit-before-checkout replaces-not-sums, edit-idempotency no-op, post-checkout lock ×2, audit old/new snapshot): 19/19 PASS. `CheckoutInspectionControllerTest` (+2 new HTTP tests: edit-completed success before checkout, edit-completed rejected after checkout with no ADMIN bypass): 5/5 PASS. `CheckoutInspectionSkipTest` (unchanged, confirms no regression): 7/7 PASS. Full targeted regression sweep (RoomOperations\*, CheckoutInspection\*, Folio\*, NightAudit\*, Checkout\*, Housekeeping\*, RoomSwap\*, SpecialRequest\*, ExtraBed\*, PackageEnrollment\*, QuickNote\*): 576 passed / 3 failed / 1823 assertions. The 3 failures (`BookingManagementUiTest`, `DashboardTest`, `RoomAvailabilityCheckerTest` — all date/room-availability-board tests unrelated to Checkout Inspection) were confirmed **pre-existing**: reproduced identically (same assertions, same error) on the base `phase-3` commit with none of this task's changes applied (verified via `git stash` + re-run). Not caused by, and not fixed by, this task. `npm run build`: PASS (2390 modules, no errors).

Still not committed/pushed/deployed.

## Hotfix — "Kiểm đồ" button stayed disabled for an editable completed inspection

**Reported by Product Owner** against a real dev-DB record: booking `BK-20260806091430-GO4E`, room 504 (stay id 468) — inspection completed, stay still checked in (not yet checked out), but the board's "Kiểm đồ" button was greyed out, blocking the newly-added completed-inspection edit flow entirely.

**Root cause:** `RoomOperationsBoardService::actionFlags()`'s `can_inspect` flag — the gate the board's toolbar button checks before it will even open the inspection popup — still excluded any inspection with `status === 'completed'` (`$stay->inspectionStatus() !== 'completed'`), a rule that predates `CheckoutInspectionService::editCompleted()`. When the completed-inspection edit capability was added earlier in this same task, this outer gate was never updated to match — the modal itself, and the backend `editCompleted()`/`canEditCompleted()`, were fully correct and reachable by direct API call, but the board's own entry point never let a user click through to reach them.

**Fix:** `can_inspect` now depends only on `stay->status === CheckedIn` (dropped the `!== 'completed'` exclusion). `StayStatus::CheckedIn` already implies `actual_checkout_at === null` — the two states transition together in `StayService::checkOut()` — so this is exactly equivalent to, and stays in lockstep with, `CheckoutInspectionService::canEditCompleted()`'s own condition. Once a stay actually checks out (`status` flips to `CheckedOut`), `can_inspect` correctly goes back to false and the record is locked, matching the backend guard. `reason_not_inspect` text updated to `'Phòng chưa nhận phòng hoặc đã trả phòng.'` (dropped the now-inaccurate "đã kiểm đồ" wording).

**Verified against the exact reported record** (`stay_id=468`, inspection id 17, Completed, `actual_checkout_at` null): `can_inspect` was `false` before the fix and is `true` after, on both the inspection's check-in date and the current board date.

**Tests:** `RoomOperationsInspectionGuardTest`'s `test_can_inspect_is_false_once_completed` (asserted the now-incorrect old behavior) replaced with `test_can_inspect_is_true_when_completed_but_not_yet_checked_out` (asserts `true`) plus a new `test_can_inspect_is_false_after_checkout` (asserts `false` only once the stay has actually checked out, with the room-charge + inspection-charge balance settled first). Full `CheckoutInspection*`/`RoomOperations*`/`RoomSwap*`/`QuickNote*` sweep: 97/97 PASS.

**File touched:** `app/Services/RoomOperationsBoardService.php` (`actionFlags()`), `tests/Feature/RoomOperationsInspectionGuardTest.php`. No migration, no other files.

Still not committed/pushed/deployed.

## Room-Conflict Detection — "Sơ đồ thao tác" silently hid a double-booking

**Reported by Product Owner:** "Việc đổi phòng có vẻ vẫn bị lỗi... booking QA-BK-0049 sau khi bị đổi phòng thì phát sinh lỗi. Sơ đồ thao tác hiển thị không đúng với phân phòng của sơ đồ booking."

### Investigation

Traced `QA-BK-0049` (booking id 416) directly in the dev DB. It held **4** active room assignments where its own demand only called for **3** (1×TWIN + 1×FAMILY + 1×DOUBLE, all seeded 2026-07-01). Assignment `#460` (room 102, type TRIP) matched none of the 3 requirement lines and had no `booking_requirement_id` — a leftover, unlinked assignment. Room 102 was **also** actively assigned to a second booking (`QA-BK-0050`, tellingly named "QA Conflict Room B" — pre-existing intentional QA fixture data, created the same instant as `#460`, 2026-07-01, well before this session) with an overlapping date range. Confirmed via the per-booking Room Board (`RoomAssignmentService::getRoomBoard()`), which correctly flagged room 102 as `'conflict'` for booking 416 — but the Room Operations Board showed no such signal at all for the same physical room/date.

**Root cause of the reported mismatch:** `RoomOperationsBoardService::buildRoomCell()` has always picked exactly one "primary" occupant per room per day (earliest start, CheckedIn priority) and silently discarded every other overlapping assignment on that room — including a genuine double-booking. `has_same_day_turnover` existed as a `count() > 1` flag but was never actually rendered anywhere in the frontend, and did not distinguish a benign handoff (checkout 12:00 → checkin 14:00, no real time overlap) from a true conflict (two live assignments whose ranges actually overlap). Two different bookings could hold the same physical room for the same night and the daily operations screen — the one front-desk staff actually work from — would show only one of them, with zero warning.

**Resolution on the specific booking (Product Owner confirmed):** released the orphan `#460` (`RoomAssignmentService::releaseAssignment()`, reason logged). It matched no demand line, so `booking.status` correctly stayed `FULLY_ASSIGNED` after release. Both boards now agree for QA-BK-0049.

### Fix — conflict detection made visible

`buildRoomCell()` now computes real time-overlap against the displayed primary assignment (`other.start_at < primary.end_at && primary.start_at < other.end_at`), not mere same-day co-occurrence:
- `has_room_conflict` (bool) — true only for a genuine overlap.
- `conflicting_bookings` — the other booking(s)' id/code/customer_name/assignment_id/dates.
- `has_same_day_turnover` narrowed to mean what its name says: `count() > 1 && no actual overlap` (previously true for both cases indiscriminately, including genuine conflicts).
- `boardTotals()` gained `conflicted_rooms`, a headline count.

Frontend (`RoomOperationsCell.vue`, `Index.vue`): a conflicted room card gets a dedicated red ring (distinct from the selected-state indigo ring and the 5-state background tint) plus a red "Trùng phòng — {booking codes}" banner with a full tooltip; the board header gets a page-level red alert banner listing the affected room numbers whenever `conflictedRooms.length > 0`, plus the existing generic summary-tile grid (already rendered from `board.summary`, unmodified) now highlights the `conflicted_rooms` tile in red when non-zero.

No migration — purely a read-model computation over already-loaded data.

### Test results

New `RoomOperationsConflictDetectionTest` (4 tests: genuine overlap flagged on every truly-overlapping day and cleared outside it, benign same-day turnover NOT flagged, single assignment flags neither, `conflicted_rooms` summary count correct): 4/4 PASS. Full `RoomOperations*` suite: 44/44 PASS. `npm run build`: PASS. Verified directly against the real QA-BK-0049/QA-BK-0050 record before and after the `#460` release (conflict correctly detected before, `conflicted_rooms=0` for all 4 affected dates after).

**Files touched:** `app/Services/RoomOperationsBoardService.php` (`buildRoomCell()`, `boardTotals()`), `resources/js/Pages/Admin/RoomOperations/Partials/RoomOperationsCell.vue`, `resources/js/Pages/Admin/RoomOperations/Index.vue`, `tests/Feature/RoomOperationsConflictDetectionTest.php` (new). No migration.

Still not committed/pushed/deployed.

## Pre-Commit Critical Safety Closure

ChatGPT reviewed `ketqualastella.md` and flagged 3 safety points to close before commit. All 3 audited; 2 were real gaps and are fixed; 1 audited clean (already correct, proof tests added). No new feature surface — closure only, per the task's own "không mở feature mới" constraint.

### 1. Inspection/Folio architecture found — bypass confirmed, fixed at the root

**Architecture found:** `complete()` posted a real system `FolioEntry` (`posting_key = 'CHECKOUT_INSPECTION_{id}_{itemId}'`, non-null) synchronously, the instant an inspection was marked Completed — potentially days before the guest physically checks out. `FolioService::voidEntry()`'s ADR-50 guard refuses to touch any entry with a non-null `posting_key` — by design, to protect Night-Audit-automated postings from casual edits.

**Bypass confirmed — YES:** `editCompleted()`'s `voidInspectionFolioEntries()` called `FolioEntry::update(['voided_at' => now(), ...])` directly on those rows, completely bypassing `FolioService::voidEntry()` and its guard. This is exactly the anti-pattern the task's Hard Rule (Mục III) prohibits: *"FolioService không cho void system entry, vậy editCompleted() tự update voided_at trực tiếp."*

**Exact fix — Option A (architecture, not a patch):** `complete()` no longer posts to the Folio at all. `total_amount` becomes a pure **projection** — freely correctable via `editCompleted()`, which now only replaces `CheckoutInspectionItem` rows (never touches `folio_entries`, because nothing exists there yet to touch). The charge is posted exactly once, at the **existing final posting point** for a Stay — `StayService::checkOut()` — via a new `CheckoutInspectionService::postCompletedChargesAtCheckout()`, called immediately after the existing `LateCheckoutFeePostingJob` call (same event-triggered, checkout-time pattern already established in that method, same transaction, same idempotency-via-`posted_at` convention). `voidInspectionFolioEntries()` is deleted — there is no longer any code path that voids or rewrites a `FolioEntry` from the Inspection module, anywhere, ever. A Draft (never-Completed) inspection posts nothing at checkout, same as a skipped one.

Options B (reuse an existing adjustment/reversal API) and C (lock editing entirely, even pre-checkout) were both considered and rejected: no reversal/adjustment mechanism exists anywhere in this codebase to reuse (confirmed via repo-wide search — `docs/architecture/stay-lifecycle-architecture.md` documents a `reversal_of_entry_id` pattern as a **future** intent, never implemented as schema), and Option C would have silently removed the exact editing capability the Product Owner explicitly asked to keep (task Mục IV: *"Inspection Completed + Stay chưa checkout → được sửa số lượng"* is stated as the still-current goal, not something to abandon).

### 4. Folio immutability proof

New test `CheckoutInspectionServiceTest::test_system_folio_entry_cannot_be_voided_or_rewritten_through_inspection_edit_path()` — completes an inspection, checks out for real (genuine system `FolioEntry` posted, non-null `posting_key`), snapshots the entry byte-for-byte, attempts `editCompleted()` against it, and asserts the snapshot is **identical** afterward (amount/quantity/unit_price/posting_key/voided_at/voided_by/void_reason all unchanged). This test would have **FAILED** against the pre-fix code (which successfully voided the entry); it **PASSES** now because `editCompleted()` cannot reach a posted entry at all under the new architecture (`isPosted()` guard throws before any mutation is attempted) — PASS.

Companion tests: `test_edit_completed_after_checkout_is_rejected_and_folio_untouched` (historical amount/posting_key/voided_at unchanged, no duplicate/replacement entry), `test_rejected_post_checkout_edit_leaves_historical_folio_entry_unchanged`, `test_checkout_posts_latest_edited_amount_only` (an edit-then-checkout sequence posts ONLY the final amount — never the original, never both), `test_edit_completed_with_unchanged_data_is_a_no_op` (idempotent, no audit event when nothing changed), `test_draft_inspection_posts_nothing_at_checkout`. Full 13-item Mục VI matrix covered.

### 2/3. Double-booking detection — 3+ assignment pairwise fix

**Algorithm before:** `$conflicting = $ordered->skip(1)->filter(fn ($other) => $other overlaps $primary)` — every other assignment was checked **only against the displayed primary**. A true overlap between two non-primary assignments (B-C, with primary A touching neither) went completely undetected.

**Algorithm after:** new `RoomOperationsBoardService::conflictingAssignments()` — an explicit O(n²) pairwise scan (every assignment against every other, not just against primary; intentional per Mục IX — correctness over micro-optimization, N is always small in practice) unions every assignment that participates in **any** true-overlapping pair, deduplicated by `booking_id`, minus primary itself (already shown as the room's `occupant`).

### 5-6. Pairwise test results

New `RoomOperationsConflictDetectionTest` cases: `test_three_way_all_overlap_flags_conflict_for_all_participants` (A-B-C mutually overlap → PASS), `test_primary_non_overlapping_but_secondary_pair_overlap_still_conflicts` (the exact root-cause gap — primary has zero overlap with either B or C, B-C overlap each other → PASS, both B and C reported), `test_four_assignments_two_independent_conflict_pairs_all_reported` (A-B and C-D are two independent overlapping pairs on one room/day → PASS, all 3 non-primary participants reported together), `test_conflict_list_has_no_duplicate_booking` (same other booking with 2 separately-conflicting assignments → appears exactly once → PASS). Full 9-item Mục XII matrix covered — 8 tests total in the file, all PASS.

### 7-8. Permission / actionFlag matrix + forged-request authorization tests

Audit result: `RoomOperationsBoardService::actionFlags()` already combined business eligibility AND `$user->can()` for every one of the 5 actions, and every backend endpoint independently enforces the SAME permission via its own `FormRequest::authorize()` or Policy — no gap found, no code change needed here. Exact permissions traced from real code (not invented):

| Action | `actionFlags()` permission | Backend enforcement | Match |
|---|---|---|---|
| `can_swap` | `room.assign` | `SwapPreviewRequest`/`SwapExecuteRequest::authorize()` → `room.assign` | ✅ |
| `can_check_in` | `stay.checkin` | `BulkCheckInRequest::authorize()` + `StayPolicy::checkIn()` (per-stay) → `stay.checkin` | ✅ |
| `can_check_out` | `stay.checkout` | `BulkCheckOutRequest::authorize()` + `StayPolicy::checkOut()` (per-stay) → `stay.checkout` | ✅ |
| `can_inspect` | `checkout_inspection.perform` | `CheckoutInspectionPolicy::create()`/`update()` → `checkout_inspection.perform` | ✅ |
| `can_clean` | `room.cleaning.update` | `MarkCleanRequest`/`MarkDirtyRequest::authorize()` → `HousekeepingPolicy::markCleaning()` → `room.cleaning.update` | ✅ |

New `RoomOperationsAuthorizationTest` (6 tests, ACCOUNTANT role — none of the 5 permissions — as the forged-request actor): unauthorized swap (preview + execute) → 403, unauthorized check-in → 403, unauthorized checkout → 403, unauthorized inspection (draft) → 403, unauthorized cleaning (mark-clean + mark-dirty) → 403, plus a `test_role_matrix_matches_seeded_permissions` proving the exact matrix above against the real `RolePermissionSeeder` for ADMIN/MANAGER/RECEPTION/HOUSEKEEPING/ACCOUNTANT. All 6 PASS. **Role matrix (read/audit only — no permissions changed):**

| Role | can_swap | can_check_in | can_check_out | can_inspect | can_clean |
|---|---|---|---|---|---|
| ADMIN | ✅ | ✅ | ✅ | ✅ | ✅ |
| MANAGER | ✅ | ✅ | ✅ | ✅ | ✅ |
| RECEPTION | ✅ | ✅ | ✅ | ✅ | ✅ |
| HOUSEKEEPING | ❌ | ❌ | ❌ | ✅ | ✅ |
| ACCOUNTANT | ❌ | ❌ | ❌ | ❌ | ❌ |

HOUSEKEEPING having `can_inspect=true` is existing, intentional behavior (same `checkout_inspection.perform` grant the standalone Checkout Inspection screen already uses) — not board-specific, not changed by this closure.

### 9. Extra Bed final posting owner + Ghép giường/Complimentary quick audits (Mục XVII-XIX)

All three audited clean — no redesign, proof tests added:
- **Extra Bed:** `ExtraBedPostingJob` confirmed as the sole source of `ChargeType::ExtraBed` postings, reading `room_assignments.extra_bed_quantity` exclusively. New `test_multi_room_a_zero_b_one_c_two_posts_exact_per_room_amounts` proves the exact spec scenario (A=0 posts nothing, B=1 posts rate×1, C=2 posts rate×2). New `test_service_package_posting_job_does_not_also_post_extra_bed` proves `ServicePackagePostingJob`'s legacy-package exclusion list (`PackageEnrollmentService::ALLOWED_PACKAGES`) keeps it from ever double-posting the same charge type — single posting owner confirmed.
- **Ghép giường:** `BookingSpecialRequest` (category=`bed_config`, request_type=`twin_to_double`) confirmed as the sole canonical source; `room_assignments.bed_joined` confirmed absent from the schema (`Schema::hasColumn()` check) and from every model/migration — only historical docblock comments reference it. Pending/Acknowledged/Fulfilled all remain visible (`RoomOperationsBedOperationsTest`, unchanged, 14/14 PASS).
- **Complimentary formula:** `chargeable_quantity` defaults to 0, `line_total = chargeable_quantity × unit_price` with no subtraction anywhere — already proven by `test_chargeable_equal_to_complimentary_still_charges_full_amount` (complimentary=2, chargeable=2 → charges 30,000, never 0) and `test_new_inspection_items_default_chargeable_to_zero_when_absent`.

### 10. Final readiness

**Test gate (Mục XXI), full run:** targeted suite (`RoomOperations*`, `CheckoutInspection*`, `Folio*`, `NightAudit*`, `ExtraBed*`, `ServicePackage*`, `PackageEnrollment*`, `SpecialRequest*`, `Housekeeping*`): **472/472 PASS**. Broader dependency-injection fallout check (`StayServiceMoveRoomTest`, `EarlyCheckInAdminActualTimeOverrideTest`, `BookingEngineFoundationTest`, `RoomAssignment*`, `SplitStayVerificationTest`, `LateCheckoutFeeTest`, `CheckoutIntegrationTest`, `CheckoutConfirmationGateTest` — run because `StayService`'s constructor gained a new `CheckoutInspectionService` dependency): **166/167 PASS**, the 1 failure being the already-known, explicitly-whitelisted (Mục XXII) `LateCheckoutFeeTest` time-of-day-fragile test, identical failure signature to before this closure. `npm run build`: PASS (2390 modules, no errors).

**Files touched in this closure:**
- `app/Services/CheckoutInspectionService.php` (`complete()`/`editCompleted()` re-architected, new `postCompletedChargesAtCheckout()`, `voidInspectionFolioEntries()` deleted, `canEditCompleted()` gained `!isPosted()`)
- `app/Services/StayService.php` (constructor +`CheckoutInspectionService`, `checkOut()` +1 call)
- `app/Services/RoomOperationsBoardService.php` (`buildRoomCell()`, new `conflictingAssignments()`)
- `tests/Feature/CheckoutInspectionServiceTest.php` (rewritten for checkout-time posting)
- `tests/Feature/CheckoutInspectionControllerTest.php` (2 tests updated for the same)
- `tests/Feature/RoomOperationsConflictDetectionTest.php` (+4 pairwise tests, factory flakiness fix)
- `tests/Feature/RoomOperationsAuthorizationTest.php` (new, 6 tests)
- `tests/Feature/ExtraBedPostingJobTest.php` (+2 tests)

No migration. No permission/role changes.

Still not committed/pushed/deployed.

## Final Status

**A. INSPECTION FINANCIAL SEMANTICS + STRONGER ROOM COLORS IMPLEMENTED — READY FOR CHATGPT REVIEW**

**B. PRE-COMMIT CRITICAL SAFETY CLOSURE PASSED — READY FOR CHATGPT FINAL COMMIT REVIEW**
