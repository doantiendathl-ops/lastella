# Phase 4.3A — User Acceptance Review

**Date:** 2026-07-12
**Branch:** phase-3
**Status:** `PENDING BUSINESS USER SIGN-OFF`

---

## Honest Status Statement

**No real hotel staff (Reception or Manager) has reviewed or signed off on this phase in this environment.** This document is a structured, ready-to-use UAT checklist — every item below is backed by a concrete automated test or a direct source citation so a real reviewer can verify it quickly, but **no claim of actual user approval is made anywhere in this document.** This is explicit per instruction: do not falsely claim real-user approval.

---

## Reception Acceptance Checklist

Reception must be able to perform the following **without any knowledge of "Stay Events" as a concept** — it should feel like ordinary front-desk actions.

| # | Capability | Understandable without Stay Event knowledge? | Backing evidence | Sign-off |
|---|-------------|:---:|---|:---:|
| 1 | Check in a multi-room Booking | ✅ Same check-in button/flow as before Phase 4.3A, unchanged | `StayServiceHousekeepingHookTest`, `SplitStayVerificationTest` | ☐ PENDING |
| 2 | Extend one selected Stay | ✅ One button ("Gia hạn lưu trú") on that room's row, one date field, one confirm — no mention of "events" anywhere in the UI | `StayExtendControllerTest`, `StayExtendUiReadinessTest`, `RoomBoardPanel.vue` source | ☐ PENDING |
| 3 | Partially check out one room | ✅ Same "Trả phòng" button as before — the system decides partial-vs-final internally; Reception sees only "Đã trả phòng" | `PartialCheckoutStayEventTest`, `CheckoutIntegrationTest` | ☐ PENDING |
| 4 | Keep the remaining room(s) active | ✅ Automatic — no action required; other rows are simply untouched | `SplitStayVerificationTest` (full-attribute non-interference proof) | ☐ PENDING |
| 5 | Finally check out the last room | ✅ Same button; the existing charge-review confirmation dialog (already familiar from before Phase 4.3A) appears only because it *is* the last room, with no new UI concept introduced | `PartialCheckoutStayEventTest::test_final_checkout_records_checkout_event_with_correct_metadata` | ☐ PENDING |

**Reception acceptance criteria (from the approved implementation plan, §15) — status:**

- "Can extend a guest's stay in a few clicks, with no need to understand 'Stay Events'" — **met by design**, pending real-user confirmation.
- "Can check out one room of a multi-room booking without any confusing prompt implying the whole booking is closing" — **met by design** (§ Operational Review, Afternoon Shift, Frontend Readiness table), pending real-user confirmation.
- "Never encounters a 'child booking' or any UI element suggesting a second booking was created" — **met**, verified by `SplitStayVerificationTest`'s explicit `Booking::count() === 1` assertions at every checkpoint.

---

## Manager Acceptance Checklist

| # | Capability | Backing evidence | Sign-off |
|---|-------------|---|:---:|
| 1 | Confirm the correct Booking aggregate status at every point (`CheckedIn` → `PartiallyCheckedOut` → `CheckedOut`) | `SplitStayVerificationTest`, `CheckoutIntegrationTest`; displayed on-screen via the pre-existing `labelFor('bookingStatus', ...)` | ☐ PENDING |
| 2 | Review the event timeline (`CheckIn` → `ExtendStay` → `PartialCheckout`/`Checkout`, in `occurred_at ASC, id ASC` order) | Data exists and is correctly ordered (`SplitStayVerificationTest` timeline assertions) — **not yet exposed in any Manager-facing screen** (see Known Limitation below) | ☐ PENDING — **UI gap, not a data gap** |
| 3 | Confirm correct room-night billing (no double-post, no under-post) | `SplitStayVerificationTest`'s billing table: A=800k/1 night, B=800k/1 night, C=1.6M/2 nights, exact manual-calculation match | ☐ PENDING |
| 4 | Confirm no child Booking was created | `SplitStayVerificationTest` (`Booking::count() === 1` throughout) | ☐ PENDING |

**Known gap for Manager acceptance item #2:** there is currently no on-screen `StayEvent` timeline view. The underlying data is correct and fully tested, but a Manager today would need direct database/admin-tool access to review it — this was flagged as an accepted limitation as far back as the Milestone 2 report and was not addressed in Milestone 5 per this milestone's "thin integration only, no new capability" constraint (building a timeline view would be a new UI surface, not a fix to an already-approved capability). **This should be logged as a candidate for a small follow-up, not treated as a Phase 4.3A blocker**, since Manager can still verify billing/status correctness through the existing Booking detail screen and Night Audit screens.

---

## Pilot Candidate 1 Recommendation

Per `docs/implementation-plans/phase-4.3-restructured-master-plan.md`'s "Pilot Candidate N" checkpoint model:

**Recommendation: Phase 4.3A is a reasonable Pilot Candidate 1 for its own scope (Stay Extension + Partial Checkout), conditional on:**

1. This UAT checklist being walked through by real Reception and Manager staff before any pilot use — **not yet done, explicitly PENDING.**
2. Staff being briefed that the Manager-facing Stay Event timeline is not yet on-screen (item #2 above) — a verbal/manual workaround (asking a developer to check, or trusting the automated-test-verified correctness) is acceptable for a small supervised pilot, consistent with this project's Operational Design Principle of "Audit Over Workflow Rigidity."
3. The two known test-infrastructure flakes (`PerStayAttributionTest`, `LateCheckoutFeeTest`) being logged in the technical backlog (done — see the M5 backend report) since they affect CI/test determinism, not real operation.

**This recommendation is a readiness assessment, not a go-live decision** — the actual go/no-go for Pilot remains an explicit business decision by the people who commissioned this work, per the project's established practice of never treating a Claude-authored report as self-approving.
