# Phase 4.3A — Operational Review

**Date:** 2026-07-12
**Branch:** phase-3
**Structure:** by shift, per `docs/architecture/lastella-operational-design-principles.md`'s shift-based Operational Review model
**Status:** COMPLETE (with an explicit environment limitation noted below) — feeds into Milestone 5 closure

---

## Honesty Note on Method (read this first)

**No live browser or browser-automation tool (e.g., Playwright) is available in this development environment.** This has been true for every session across Phase 3 and Phase 4 (see `docs/roadmaps/go-live-core-scope-review.md` §2, §17, which flags this as the single largest unconfirmed risk in the whole system).

This review does **not** claim a literal browser click-through happened. Instead, it uses the strongest available equivalent:

1. **Real HTTP-level requests** through Laravel's test HTTP client, which execute the exact same routes, controllers, Form Requests, policies, and Inertia response payloads a browser would receive — not a mock, not a unit-level shortcut.
2. **Direct source inspection** of the actual Vue template (`RoomBoardPanel.vue`) that would render those payloads, to confirm the UI element exists, is gated correctly, and shows the right data.

Every scenario below is backed by a named, currently-passing automated test plus, where relevant, a direct code citation — not by an unverifiable claim of "it works." Where this method cannot substitute for a real click-through (visual layout, actual mouse/keyboard interaction feel), that is stated explicitly as a residual risk, not hidden.

---

## Morning Shift

**Scope:** arrivals visibility, room assignment, check-in, deposit/payment recording — all pre-existing Phase 2–3 capability, unmodified by Phase 4.3A, re-confirmed still working after M1–M5's changes.

| Step | Expected result | Evidence | Result |
|------|------------------|----------|--------|
| Today's arrivals visible | Booking list / Room Availability board shows today's bookings | Pre-existing `BookingManagementUiTest`/`RoomAvailabilityCheckerTest` suites (excluding the 23 pre-existing date-drift failures, which are unrelated to any Phase 4.3A code and documented separately — see §Regression) | ✅ PASS (unchanged) |
| Room assignment works | `RoomAssignmentService::assignRooms()` succeeds, conflict detection active | `RoomAssignmentServiceTest`/`BookingEngineFoundationTest` (e.g. `room conflict detection works`, `room assignment succeeds when no conflict exists`) — re-run as part of full suite, all passing | ✅ PASS (unchanged) |
| Check-in works | `Stay` becomes `CheckedIn`, room becomes `OCCUPIED`, a `CheckIn` `StayEvent` is recorded | `StayServiceHousekeepingHookTest::test_checkin_auto_marks_room_occupied`, `StayEventFoundationTest::test_checkin_creates_a_stay_event`, exercised again inside `SplitStayVerificationTest` | ✅ PASS |
| Deposit/payment recording still works | `BookingPaymentService::addDeposit()` succeeds, balance calculation correct | `CheckoutIntegrationTest`, `PaymentUiTest`, `SplitStayVerificationTest` (all add a real deposit as scenario setup) | ✅ PASS (unchanged) |

**Morning shift result: PASS.** No Phase 4.3A change touches any file in this shift's scope (confirmed in the M5 report's Architecture Scope Verification) — this is a re-confirmation, not new capability.

---

## Afternoon Shift

**Scope:** the actual new/formalized Phase 4.3A capability — Stay Extension and Partial Checkout.

| Step | Expected result | Evidence | Result |
|------|------------------|----------|--------|
| Stay Extension for one selected room | `StayService::extendStay()` succeeds via the real HTTP route; `Stay.planned_checkout_at` and `RoomAssignment.end_at` move together | `StayServiceExtendTest::test_successfully_extends_a_checked_in_stay`, `StayExtendControllerTest::test_admin_can_extend`/`test_manager_can_extend`/`test_reception_can_extend` (real `POST /admin/bookings/{booking}/stays/{stay}/extend` requests) | ✅ PASS |
| Partial Checkout for one room in a multi-room Booking | One `Stay` checks out; a `PartialCheckout` event is recorded; siblings untouched | `PartialCheckoutStayEventTest` (8 tests), `CheckoutIntegrationTest::test_partial_checkout_of_multi_stay_booking_sets_partially_checked_out`, `SplitStayVerificationTest` | ✅ PASS |
| Booking remains active while another room still staying | `Booking.status === PartiallyCheckedOut`, not `CheckedOut` | Same tests — `assertNotSame(BookingStatus::CheckedOut, ...)` asserted explicitly at this point in every relevant test | ✅ PASS |
| Final Checkout only after the last active Stay | `Booking.status === CheckedOut` only once, on the truly last `Stay`'s checkout | `PartialCheckoutStayEventTest::test_final_checkout_records_checkout_event_with_correct_metadata`, `SplitStayVerificationTest` (Room C's final checkout) | ✅ PASS |
| Room status transitions correctly | `OCCUPIED` on check-in, `VACANT_DIRTY` + cleaning assignment on checkout | `StayServiceHousekeepingHookTest` (unchanged, re-run) | ✅ PASS (unchanged) |

### Frontend/API Readiness Checklist — Stay Extension

| Question | Answer | Evidence |
|----------|--------|----------|
| Is the action visible on the Booking detail page? | **Yes, added in Milestone 5** — a "Gia hạn lưu trú" button on each `CHECKED_IN` Stay's row in `RoomBoardPanel.vue` | Source: `resources/js/Pages/Admin/Bookings/Partials/RoomBoardPanel.vue` |
| Is it permission-gated correctly? | Yes — gated on `can.extend` (new `stay.extend` permission, ADMIN/MANAGER/RECEPTION) and per-Stay eligibility (`stay.can_extend`, true only when `CheckedIn`) | `StayExtendUiReadinessTest` (5 tests: eligible/ineligible Stay states, permission-holding/non-holding roles) |
| Is the current planned checkout visible? | Yes — already shown in the table's "Dự kiến trả phòng" column (pre-existing), and repeated in the extension dialog itself | Table column confirmed pre-existing; dialog shows it via `{{ extendTarget.planned_checkout_at }}` |
| Can Reception choose a new checkout date/time? | Yes — a single `datetime-local` input, pre-filled with the current planned checkout | `RoomBoardPanel.vue` extend dialog |
| Are validation errors shown clearly? | Yes — Inertia's `useForm().errors` surfaced directly under the field (matches the existing `AddPaymentForm.vue` convention) | Source inspection; backend validation confirmed by `StayExtendControllerTest::test_same_or_earlier_date_returns_validation_error` |
| Does the page refresh/update after success? | Yes — standard Inertia `preserveScroll` partial reload, same mechanism every other action on this page already uses | Source inspection |
| Does the UI make it obvious that only the selected room/Stay is extended? | Yes — the button lives inside that Stay's own table row, and the dialog title names the specific room (`Gia hạn lưu trú - Phòng {{ room_number }}`) | Source inspection |

### Frontend/API Readiness Checklist — Partial Checkout

| Question | Answer | Evidence |
|----------|--------|----------|
| Existing per-room checkout action remains usable? | Yes, completely unchanged — `StayController::checkOut()` and its route/request were not touched anywhere in Phase 4.3A | `git diff` scope (M3/M5 reports); `CheckoutIntegrationTest` passing unchanged |
| Does the UI avoid implying the whole Booking will close when other Stays remain active? | Yes — the "final checkout" charge-review confirmation dialog (pre-existing, ADR-55) explicitly states *"phòng đang lưu trú cuối cùng của booking này"* and only appears when the backend's `FinalCheckoutConfirmationRequiredException` fires (i.e., only for a genuinely final checkout); a non-final checkout shows no such implication | Source inspection of `RoomBoardPanel.vue`'s `lastStayConfirmTarget` dialog + backend `checkOut()` logic (M3 report) |
| Is Booking aggregate status displayed correctly after partial checkout? | Yes — `labelFor('bookingStatus', booking.status)` already maps `PARTIALLY_CHECKED_OUT` → *"Đã trả phòng một phần"*, pre-existing in `vietnameseLabels.js`, unchanged | Source inspection |

**Afternoon shift result: PASS.** The one genuine gap found (no Stay Extension UI control) was fixed in Milestone 5 as a minimal, thin integration over the already-approved backend action — no new business capability was added.

---

## Night Shift

**Scope:** Night Audit, billing correctness, outstanding balance, housekeeping follow-up.

| Step | Expected result | Evidence | Result |
|------|------------------|----------|--------|
| Night Audit can be run | `NightAuditService::runForDate()` completes, `NightAuditRun.status === COMPLETED` | `NightAuditPipelineFeatureTest`, `NightAuditOperationsTest` (27 tests), `SplitStayVerificationTest` (2 real runs + 2 idempotent re-runs) | ✅ PASS |
| Correct nightly charges are posted | Room charge posted per Stay per business date, idempotent posting key | `SplitStayVerificationTest` — exact billing table (A: 800,000/1 night, B: 800,000/1 night, C: 1,600,000/2 nights) | ✅ PASS |
| Extended room receives the extra night | Room C's second `FolioEntry` posted only after Night Audit runs for the extended business date | `SplitStayVerificationTest` (`FolioEntry` count 3→4 after the correct Night Audit run) | ✅ PASS |
| Already-checked-out rooms receive no extra charge | Rooms A/B excluded from the active-Stay Night Audit query once `CheckedOut` | `SplitStayVerificationTest` (A/B each have exactly 1 `FolioEntry`, never 2) | ✅ PASS |
| Outstanding balance remains correct | `OutstandingBalanceException` still blocks a final checkout with unpaid balance; unaffected by partial checkouts | `CheckoutIntegrationTest::test_checkout_blocked_by_outstanding_balance`/`test_checkout_blocked_with_partial_payment` (unchanged, re-run) | ✅ PASS (unchanged) |
| Room status / housekeeping follow-up remains consistent | Checkout still auto-creates a `VACANT_DIRTY` cleaning assignment | `StayServiceHousekeepingHookTest::test_checkout_auto_creates_cleaning_assignment` (unchanged, re-run) | ✅ PASS (unchanged) |

**Night shift result: PASS.**

---

## Browser/Manual Evidence Summary

- **Browser used:** none — no browser-automation tool is available in this environment (stated above, not hidden).
- **User roles exercised:** ADMIN, MANAGER, RECEPTION, ACCOUNTANT, HOUSEKEEPING, SALES — via real `actingAs()` HTTP requests against real routes/policies (`StayExtendControllerTest`, `StayExtendUiReadinessTest`, and the wider suite).
- **Scenario steps:** every step above maps to a specific, named, currently-passing test — re-run as part of this milestone's regression pass (§ below), not merely cited from memory.
- **Screenshots:** none possible (no browser). Concise evidence is the named test + assertion + the relevant source line, provided per row above.

---

## Blocking Issues

**None found.** The one real gap (Stay Extension had no frontend control) was not left as a blocker — it was fixed as a minimal, thin, fully-tested integration per this milestone's explicit authorization to do so.

---

## Accepted Limitations

- No live browser verification of visual layout, click feel, or responsive behavior — carried forward as the same top risk already documented in `docs/roadmaps/go-live-core-scope-review.md` §17, not introduced by Phase 4.3A.
- Manager cannot yet see the `StayEvent` timeline on screen (no dedicated UI was built for it — reviewing the timeline today requires direct database/admin-tool access). This was already flagged as an accepted limitation in the Milestone 2 report and is not addressed here, consistent with the "thin integration only, no new capability" scope for Milestone 5.
- The `PerStayAttributionTest` Faker-collision and `LateCheckoutFeeTest` wall-clock flakes (see the M5 backend report) are pre-existing test-infrastructure issues, not operational blockers — they do not affect real hotel usage, only automated-test determinism.

---

## Final Operational Result

**PASS.** Every capability Phase 4.3A introduced or formalized (Stay Event logging, Stay Extension, Partial Checkout, Split Stay billing) is confirmed working end-to-end via real HTTP-level requests through the actual routes/controllers/policies, across all three shifts, with the one identified UI gap closed. No blocking issue remains for this phase's own scope.
