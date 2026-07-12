# Revenue / Folio Architecture — Gap Analysis

**Date:** 2026-07-06
**Branch:** phase-3
**Type:** Architecture Analysis only — no code changed, no migration created
**Status:** DRAFT FOR CHATGPT ARCHITECTURE REVIEW

---

## 1. Current Architecture

```
Booking (commercial contract: dates, customer, status)
  │
  ├── BookingRequirement (1..N)  — "what was quoted": room_type_id, quantity, room_price (FLAT, per booking, not per date)
  │
  ├── RoomAssignment (1..N)      — "which physical room, which dates": room_id, start_at, end_at, status
  │        │                        (Assigned → CheckedIn → CheckedOut, or → Released)
  │        │
  │        └── Stay (1:1)        — "what actually happened": planned_checkin_at/checkout_at,
  │                                 actual_checkin_at/checkout_at, status
  │                                 (Reserved → CheckedIn → CheckedOut, or → Cancelled/NoShow)
  │
  └── Folio (1:1)                — the ledger container: status (Open/Closed/Voided)
           │
           └── FolioEntry (0..N) — line items: charge_type, unit_price, amount, entry_date,
                                    posting_source (MANUAL/NIGHT_AUDIT/SYSTEM_AUTO), posting_key

BookingPayment (0..N)            — money actually received: Deposit/AdditionalDeposit/
                                    RoomPayment/ServicePayment/Refund/Adjustment

NightAuditRun                    — manually-triggered batch job that walks every CheckedIn
                                    Stay once per business date and posts one night's worth
                                    of charges via a chain of PostingJob classes
```

**Key relationship facts, verified by reading the code (not inferred):**

- `Booking.checkin_at`/`checkout_at` are the **original commercial dates** — used for availability-conflict checks and as the *default* `start_at`/`end_at` when a `RoomAssignment` is first created. They are **never read again** by anything that generates money.
- `BookingRequirement.room_price` is a **single flat number per room_type**, captured once at booking creation (or the last full requirement replace). It is **not** date-ranged and is **never recalculated** against the `RoomRate` table after creation.
- `RoomAssignment.start_at`/`end_at` are per-room planned dates, but `BookingService::updateBooking()`'s date-change path (`validateTimeChange()`) always updates **every active assignment on the booking to the same new range** — there is no code path anywhere that changes one room's dates independently of the others.
- `Stay.status` (specifically `CheckedIn`) — not `Booking` or `RoomAssignment` dates — is what actually drives revenue: `NightAuditPipeline::run()` selects `Stay::whereIn('status', [CheckedIn])`, full stop. A stay that is still checked in keeps accruing nightly room charges regardless of what `planned_checkout_at` says.
- `FolioEntry` rows with a non-null `posting_key` (every `ROOM`, `LATE_CHECKOUT`, `EARLY_CHECKIN` entry) are **system entries** — `FolioService::voidEntry()` throws `SystemEntryVoidException` unconditionally for these. **There is no code path, anywhere, that can alter, void, or reverse a posted room charge.**
- Revenue reporting (`RevenueReportService`) is a pure aggregation over `FolioEntry` rows grouped by date/charge_type/source. It has **zero knowledge** of `Booking`, `BookingRequirement`, or expected totals — it only ever sees what was actually posted.
- Night Audit is **manually triggered** (`NightAuditController::run`/`trigger` → `NightAuditService::runForDate()`). `routes/console.php` registers no scheduled command. If nobody runs it for a given business date, **no room revenue exists for that date**, independent of any Booking/Stay state.

---

## 2. Current Revenue Flow

```
FolioEntry rows (entry_date, charge_type, amount, posting_source)
        │
        ▼
RevenueReportService::dailySummary() / periodSummary() / revenueBySource() / exportRows()
        │
        ▼
Pure SUM(amount) GROUP BY charge_type / date / posting_source — no other input
```

Revenue is **100% derived from what was actually posted**, with no cross-check against what the Booking/BookingRequirement configuration says *should* have been posted. There is no variance or reconciliation report. If a room-charge posting is wrong (wrong price, wrong number of nights, missing because Night Audit was skipped for a date), Revenue silently reports the wrong number with no signal that anything is off.

---

## 3. Current Folio Flow

```
Booking created → Folio::create(status=Open)                          [BookingService::createBooking]
        │
        ├── addCharge()          — manual or system-posted line item, amount computed server-side
        ├── voidEntry()          — MANUAL entries only; throws for any posting_key entry (ADR-50)
        ├── closeFolio()         — manual, staff-triggered
        ├── autoCloseFolio()     — automatic, called by finaliseBookingCheckout() when balance = 0
        ├── reopenFolio()        — manual; blocked if booking is terminal
        └── voidFolioOnCancellation() — called by BookingService::cancelBooking();
                                        throws FolioHasActiveEntriesException if any
                                        non-voided entry exists (staff must void first)
```

Folio status is Open → Closed (normal) or Open → Voided (cancellation with zero activity). Once Closed, `addCharge()` throws `FolioClosedException` — no further charges can post to a closed folio at all, correctable only via `reopenFolio()` (blocked on terminal bookings).

---

## 4. Current Charge Generation

| Charge | Trigger | Frequency | Price source | Reversible? |
|--------|---------|-----------|---------------|-------------|
| `ROOM` | `StayService::checkIn()` posts night 1 immediately; `NightAuditPipeline` posts one more night per business-date run, for every `Stay` still `CheckedIn` | Once per stay-night, idempotent via `posting_key = ROOM_NIGHT_{stay_id}_{date}` | `BookingRequirement.room_price` matched by `room_type_id` — flat, frozen, **not date-aware** | **No** — system entry, `posting_key` set |
| `LATE_CHECKOUT` | `StayService::checkOut()`, condition-gated (actual checkout past grace period) | Once per stay | `RoomRate.late_checkout_price` looked up by actual checkout date | **No** — system entry |
| `EARLY_CHECKIN` | `StayService::checkIn()`, condition-gated | Once per stay | `RoomRate.early_checkin_price` looked up by actual check-in date | **No** — system entry |
| `BREAKFAST`/`CITY_TAX`/`EXTRA_PERSON`/`EXTRA_BED` | Night Audit pipeline (same per-stay-per-night model as `ROOM`) | Per stay-night | Various rate tables | Not inspected in this pass, same architecture family as `ROOM` |
| Manual (`MINIBAR`, `SPA`, `DAMAGE`, etc.) | `FolioEntryController::store()` → `FolioService::addCharge()` | Ad hoc | Staff-entered | **Yes** — `posting_key` is null, voidable while Folio is Open |

**The single most important fact in this analysis:** room revenue is generated **incrementally, night by night, keyed to `Stay.status === CheckedIn`**, not computed once from `Booking.checkin_at`/`checkout_at`. Nothing in the codebase ever asks "how many nights does this booking span" and multiplies by a rate — the total simply accumulates as calendar nights pass and Night Audit runs.

---

## 5. Gap Analysis

### What currently generates room charges? (Question 1)

Neither `Booking` nor `RoomAssignment` alone. **`Stay.status` (via Night Audit) and `StayService::checkIn()`** are what actually generate `ROOM` `FolioEntry` rows. `Booking`/`BookingRequirement` only supply the *unit price* (frozen, non-date-aware) and the *initial* date range for the first `RoomAssignment`.

### When Booking dates change, what should happen? (Question 2)

Today: **nothing financial happens.** `validateTimeChange()` updates `RoomAssignment.start_at`/`end_at` and `Stay.planned_checkin_at`/`planned_checkout_at` only. No Folio read, no Folio write, no call into `FolioService` or any `PostingJob` anywhere in `BookingService::updateBooking()`.

What *should* happen depends on stay phase:
- **Pre-arrival** (no `actual_checkin_at` yet): there is no charge to correct yet — the only real issue is that the "expected total" shown to staff (if any UI shows one) doesn't reflect the new night count, because **no such projection exists anywhere in the system** (see Q3).
- **Mid-stay** (checked in, not checked out): extending is financially self-correcting (Night Audit will simply keep posting nights as long as `Stay.status` stays `CheckedIn` — it never looks at `planned_checkout_at`). Shortening is **not** self-correcting: nights already posted for dates beyond the new, earlier checkout are permanent (see Q4).
- **Post-checkout**: `BookingService::validateTimeChange()` already hard-blocks this (`Stay.actual_checkout_at !== null` → `ValidationException`) — correctly recognized as a closed book today.

### Should Folio recalculate? If yes, how? (Question 3)

**There is currently no "recalculation" concept at all** — Folio only ever *accumulates* what posting jobs create; nothing ever re-derives a total from Booking configuration and compares it. Introducing recalculation would mean: (a) a formal "expected charges" projection computed from `BookingRequirement` × night count × `RoomAssignment` date range (which does not exist today), compared against (b) actual posted entries, surfacing the delta as a reconciliation view — additive to the ledger, never a silent rewrite of posted amounts (see Q6).

### Can historical folio entries be edited safely? (Question 4)

**No — and today they structurally cannot be edited at all.** Any entry with a `posting_key` (every `ROOM`/`LATE_CHECKOUT`/`EARLY_CHECKIN` row) is permanently un-voidable by design (ADR-50, enforced in code, not just convention). This is a deliberate financial-integrity guarantee, but it also means: **if a room charge is wrong for any reason (stale price, extra night that shouldn't have posted, shortened stay), there is no existing mechanism to correct it.** This is the sharpest, most concrete gap found in this analysis.

### Should adjustment entries be used? (Question 5)

Yes — and today they **only exist on the payment side** (`BookingPayment.payment_type = Adjustment`), not on the charge side. There is no `ChargeType` or `FolioEntry` concept equivalent to "correction of a prior system entry." Given ADR-50 correctly forbids voiding/deleting system entries, the only safe path forward is **additive correction**: a new entry type (or a `reversal_of_entry_id` reference) that nets against a specific prior entry without altering or removing it — preserving the audit trail while fixing the customer-facing balance.

### Should room charges become immutable after posting? (Question 6)

**They already are — today, unconditionally, with no correction path.** The real question is not "should they be immutable" (they already are) but **"should an immutable entry be correctable via a new, linked, additive entry"** — i.e., immutability of the *original* record should be preserved, while still allowing the *net financial position* to be corrected. This is the standard accounting pattern (never edit a posted ledger line; post a reversing/adjusting line instead) and the codebase has all the pieces to do this except the adjustment entry type itself.

### Should Night Audit own room charge generation? (Question 7)

**It already effectively does** (jointly with the check-in-time first-night post) — and this is architecturally sound: it's the only place that has both "which stays are still active" and "what business date are we posting for" in one pass, with proper idempotency (`posting_key`) and an audit trail (`NightAuditBookingLog`). The gap is not *who* generates charges — it's that **nothing else in the system (Booking edits, room moves, stay extensions) informs Night Audit's inputs correctly**, because those inputs (`Stay` status/dates, `RoomAssignment` room/dates) can't be changed independently per room today (see Scenarios 2, 5, 6 below).

### Can the current architecture support stay extension, partial checkout, split stay, room upgrade, room downgrade, room move — without redesign? (Question 8)

| Capability | Supported today? | Evidence |
|-------------|:---:|----------|
| Stay extension (guest actually stays longer, same room) | **Partially** — self-corrects financially via Night Audit as long as the guest hasn't checked out, but the *planned* checkout date can only be changed at the whole-booking level, not per room | `validateTimeChange()` applies to all active assignments uniformly |
| Partial checkout (some rooms out, others continue) | **Yes, for the actual/ground-truth side** — `Stay`/`RoomAssignment` are already per-room; Night Audit iterates per-`Stay` | `NightAuditPipeline` selects individual `Stay` rows, not `Booking` rows |
| Split stay (one room's dates diverge from the booking's dates) | **No** — no per-assignment date-edit method exists anywhere | `RoomAssignmentService` has only `assignRooms`, `releaseAssignment`, `checkRoomConflict`, `getAssignmentSummary`, `getRoomBoard` — nothing else |
| Room upgrade / downgrade | **No** | No rate-change method exists on any assignment; `BookingRequirement.room_price` is keyed by `room_type_id`, not by individual `RoomAssignment`, so even if a room could be swapped, there's no per-assignment price override |
| Room move (mid-stay) | **No** | `releaseAssignment()` explicitly throws if `Stay.actual_checkin_at !== null` — a checked-in assignment can never be released, and no "transfer" operation exists |

**Conclusion: 3 of 6 listed capabilities require genuine redesign; the other 3 are already correctly handled at the ground-truth (`Stay`) level but not at the planning (`Booking`/`RoomAssignment`) level.**

---

## 6. Business Scenarios

### Scenario 1 — Booking dates edited before/after the fact (04→05 Jul, then extended to 04→06 Jul)

**Current behavior:** Booking updates; Folio/Payment do not. **Root cause:** `updateBooking()` never calls into `FolioService` or any posting job — confirmed by direct code inspection, not by absence of a test. If the guest hasn't checked in yet, the extra night will *eventually* bill correctly once the guest checks in and Night Audit runs for each subsequent night — but nothing in the system reflects the new expected total the moment the date is changed, because no "expected total" projection exists at all. Staff have no way to know, from the system, that the deposit/expected balance should change the moment this edit is saved.

### Scenario 2 — 3-room booking, 1 night; Room A and B check out on time, Room C stays an extra night

**Can the architecture calculate correctly?** For the *actual* billing of each room independently — **yes**, because Night Audit already posts per-`Stay`, and Room C's `Stay` simply stays `CheckedIn` longer, accruing more nights, while A and B stop the moment they're `CheckedOut`. **What it cannot do** is let staff *plan* for Room C's extra night in advance — there is no way to edit only Room C's `RoomAssignment`/`Stay` dates without cascading the same new date to Rooms A and B, since the only date-edit path is `BookingService::updateBooking()`, which is booking-wide.

### Scenario 3 — Guest extends stay after check-in: Booking or Stay as source of truth?

**`Stay` already is the de facto financial source of truth** — `Stay.status` is the sole input to Night Audit's stay selection, and `Stay.actual_checkin_at`/`actual_checkout_at` gate the late-checkout/early-checkin fee jobs. `Booking.checkin_at`/`checkout_at` are commercial-intent fields used only for conflict-checking and as an initial default. **Recommendation: make this explicit and formal** rather than leaving it as an emergent property of how Night Audit happens to query the data — see §9.

### Scenario 4 — Guest shortens stay: update, reverse, or adjust existing folio entries?

**None of the three is currently possible.** `ROOM` entries already posted cannot be voided (system entry) or edited (no update endpoint exists at all for `FolioEntry`). If a guest shortens their stay after 2 of 3 planned nights have already been audited, the 3rd night's charge — if already posted — is **permanently stuck on the folio** with no corrective mechanism. The only viable answer, given ADR-50, is **adjustment entries** (§5, Question 5) — additive, auditable, non-destructive.

### Scenario 5 — Guest changes room during stay

**Entirely unsupported.** `releaseAssignment()` refuses once `actual_checkin_at` is set. There is no "move" concept — ending the stay in the old room would trigger the full checkout flow (balance check, possible folio auto-close, possible booking finalization if it's the last active stay), which is the wrong semantics for "the same guest is continuing their visit in a different room."

### Scenario 6 — Guest upgrades room

**Entirely unsupported**, for two independent reasons: (1) no room-move mechanism exists (same as Scenario 5), and (2) even if the room changed, pricing is resolved by `room_type_id` against `BookingRequirement`, which has no concept of "this specific assignment now costs more because it moved to a better room type."

### Scenario 7 — Guest splits payment

**This is the one scenario the current architecture already handles well.** `BookingPayment` is already a 1-to-many ledger of discrete payment events (`Deposit`, `AdditionalDeposit`, `RoomPayment`, `ServicePayment`, `Refund`, `Adjustment`), and `paymentSummary()`/`finaliseBookingCheckout()` simply sum across however many rows exist. Splitting a payment across cash + transfer, or across multiple visits, requires no architecture change — it is a **non-gap**, called out here explicitly so it is not mistakenly bundled into the redesign scope.

### Scenario 8 — Multi-room booking with different checkout dates per room

Same conclusion as Scenario 2: **the *actual*, ground-truth divergence is already handled correctly** (each room is its own `Stay`, audited independently). **The *planned* divergence is not** — booking-level date edits are all-or-nothing across every active assignment.

---

## 7. Risks

| Risk | Severity | Evidence |
|------|----------|----------|
| Room charges cannot be corrected once posted, under any circumstance | **HIGH** | `SystemEntryVoidException` unconditional for `posting_key` entries; no update endpoint; no adjustment charge type |
| No "expected total" projection exists — Booking date/room changes have zero visible financial effect until Night Audit catches up | **HIGH** | `paymentSummary()`/`getFolioTotal()` sum only actual posted entries; nothing computes forward |
| Night Audit is manually triggered with no scheduled fallback | **MEDIUM** | `routes/console.php` has no scheduled command; a skipped business date means silently missing room revenue for that date, with no alert |
| Per-room independent date/room/rate changes are structurally impossible | **MEDIUM–HIGH** | No service method exists at any layer for this; would require new capability, not a bug fix |
| `BookingRequirement.room_price` is frozen and not date-aware | **MEDIUM** | Confirmed by `RoomChargePostingJob::resolveUnitPrice()` — reads the flat stored value, never queries `RoomRate` by date |
| Revenue reporting has no reconciliation against expected charges | **MEDIUM** | `RevenueReportService` only aggregates actuals; a systemic under- or over-posting bug would be invisible in reporting |
| Room move / upgrade / downgrade mid-stay has no supported path at any layer | **HIGH (operational)** | `releaseAssignment()` hard-blocks on checked-in stays; no transfer operation exists anywhere |

---

## 8. Architecture Options

### Option A — Booking remains master

Keep `Booking.checkin_at`/`checkout_at` as the single authoritative date range; any edit triggers a reconciliation pass that posts/adjusts folio entries to match.

- **Advantages:** simplest mental model for staff; one edit surface; smallest conceptual change.
- **Disadvantages:** doesn't naturally support per-room divergence (Scenarios 2, 5, 6, 8) without *also* introducing per-assignment overrides — which in practice means this option can't stay "pure" and collapses toward Option B/D anyway. Would require either relaxing ADR-50 (risky) or bolting on an adjustment-entry engine while still lacking a per-room editing surface.
- **Migration complexity:** Low for the recalculation engine itself, but doesn't close the highest-risk gaps (Scenarios 2/5/6) without further work.

### Option B — Stay becomes the financial source of truth

Formalize what already happens implicitly: `Stay` (status + actual timestamps + its own editable planned dates) drives all billing; each `Stay` can carry its own rate (not just a shared `BookingRequirement.room_price` by room type).

- **Advantages:** directly enables independent per-room extension/shortening (Scenario 2), and gives room moves/upgrades a clean home (end one `Stay`, start a linked one against the same booking, each with its own rate) — Scenarios 5, 6, 8.
- **Disadvantages:** touches Phase 3 core (`StayService`, `NightAuditPipeline`, rate resolution) — the largest regression surface of any option. Requires a real rate-resolution step at `Stay` creation/extension time instead of the current one-time snapshot.
- **Migration complexity:** Medium–High.

### Option C — Room Assignment becomes the billing unit

Similar to B, but pins editability/pricing to `RoomAssignment` rather than `Stay`.

- **Advantages:** covers the *pre-arrival* editing gap cleanly (a `RoomAssignment` can be re-dated/re-typed before check-in with no `Stay` yet in existence).
- **Disadvantages:** since `Stay` is already 1:1 with `RoomAssignment` in this schema, this option is nearly equivalent to B for anything post-arrival — it doesn't independently solve the post-checkin room-move problem (Scenario 5) any better than B does, because the *Stay* is still what Night Audit reads.
- **Migration complexity:** Medium.

### Option D — Hybrid (Booking = contract, RoomAssignment = pre-arrival planning unit, Stay = post-arrival financial source of truth)

- `Booking` stays the commercial container and aggregate status owner — unchanged role.
- `RoomAssignment` becomes the **pre-arrival** editable unit: dates, room, and rate can change per assignment, independently, up to check-in — directly fixes Scenario 2 and enables pre-arrival upgrades (part of Scenario 6) with no new concept, just a new per-assignment update method.
- `Stay` becomes the **formally documented** post-arrival financial source of truth (this is already true in practice — Option D makes it official and adds the missing pieces): its own rate snapshot (so upgrades/downgrades reprice correctly going forward without touching already-posted nights), and a `transferred_to_stay_id`/`transferred_from_stay_id` link pair so a room move mid-stay is modeled as "close this Stay, open a linked Stay in the new room" rather than an ad hoc workaround — Scenario 5.
- A new `FolioEntry` **adjustment** mechanism (`reversal_of_entry_id` reference or a dedicated `ADJUSTMENT` charge type, additive only, never destructive) closes Scenario 4 without touching ADR-50.

- **Advantages:** closes every identified gap; each piece is additive to the existing schema (new nullable columns / new charge type / new service methods) rather than a redesign of `Booking`, `Folio`, or the lock-ordering already established in ADR-38/ADR-48; smallest blast radius for the amount of capability gained.
- **Disadvantages:** more moving pieces than A or B alone (three concepts to reason about instead of one); requires careful ADR authorship for the new "stay transfer" concept so it doesn't quietly violate the existing checkout/finalization invariants.
- **Migration complexity:** Medium (spread across several small, additive changes rather than one large one).
- **Future scalability:** best of the four — a per-`RoomAssignment`/per-`Stay` rate model is also the natural foundation for future rate plans, promotions, and multi-currency support, none of which fit cleanly into a single flat `BookingRequirement.room_price`.

---

## 9. Recommended Architecture

**Option D (Hybrid).** Reasoning:

1. It requires the **least disruption** to already-approved, tested architecture (Phase 3 lock ordering, ADR-38/40/41/44/48/50 all remain intact — nothing here proposes relaxing any of them).
2. It **codifies a fact that is already true in the running system** (`Stay` already drives billing) rather than introducing a new paradigm — lower conceptual risk for the team.
3. It is the only option that closes **all** eight scenarios, not a subset.
4. Each piece (assignment-level pre-arrival editing, stay-level rate snapshot + transfer link, adjustment entries) can be implemented and tested **independently and incrementally**, matching this project's established milestone-by-milestone delivery pattern (Phase 4.1, 4.2).

---

## 10. Migration Strategy

All changes additive; no existing column, table, or method removed or renamed.

1. **Adjustment entries first** (closes Scenario 4, the highest-severity, simplest gap): add an `ADJUSTMENT` `ChargeType` case and an optional `reversal_of_entry_id` (nullable FK to `folio_entries.id`) column on `FolioEntry`. New `FolioService::postAdjustment()` method — does not touch `voidEntry()`/ADR-50 at all.
2. **Per-assignment pre-arrival date/rate editing** (closes Scenario 2, partially 6): new `RoomAssignmentService::updateAssignment()` method, gated to `status = Assigned` (mirrors the existing `releaseAssignment()` guard pattern), independent of `BookingService::updateBooking()`.
3. **Rate-aware `Stay`** (closes remaining half of Scenario 6): add a nullable `rate_override`/`room_price_snapshot` column to `Stay` (or `RoomAssignment`), resolved once at assignment/check-in time; `RoomChargePostingJob::resolveUnitPrice()` prefers this over the shared `BookingRequirement.room_price` when present.
4. **Stay transfer / room move** (closes Scenario 5): new `transferred_to_stay_id` / `transferred_from_stay_id` nullable self-referencing columns on `Stay`; a new `StayService::transferStay()` method that atomically closes the old `Stay` (without triggering full booking-checkout finalization) and opens a new linked one.
5. **Expected-total projection** (closes the Scenario 1/3 UX gap): a read-only projection method (e.g. `FolioService::projectedTotal(Booking)`) computed from `RoomAssignment` date ranges × resolved rate — additive, display-only, never posts anything itself.

Each step ships as its own milestone with its own regression baseline check, exactly as Phase 4.1/4.2 did.

---

## 11. Regression Risk

| Area | Risk if Option D is implemented | Mitigation |
|------|----------------------------------|------------|
| Existing `ROOM`/`LATE_CHECKOUT`/`EARLY_CHECKIN` posting | LOW — `resolveUnitPrice()` gains a *preferred* override, falls back to existing behavior when absent | Every existing test that doesn't set the new override field continues to exercise the exact current path |
| Checkout finalization (`finaliseBookingCheckout`) | MEDIUM — `transferStay()` must not accidentally trigger this | New method explicitly bypasses the "last active stay" finalization check; needs its own ADR and dedicated tests |
| Folio immutability (ADR-50) | NONE — adjustment entries are strictly additive; no existing void/delete logic touched | New charge type, new column, both nullable/optional |
| Booking-wide date editing (`validateTimeChange`) | NONE — untouched; per-assignment editing is a new, separate method | No existing test path changes |
| Revenue reporting | LOW — new `ADJUSTMENT` charge type will appear in aggregates; needs a label and a review of whether it should net separately | Additive `ChargeType` case only |

**Overall regression risk: LOW–MEDIUM**, concentrated entirely in the new `transferStay()` capability (Scenario 5), which is the one piece that must coexist carefully with the existing checkout/finalization state machine.

---

## 12. Estimated Implementation Complexity

| Piece | Complexity | Rationale |
|-------|-----------|-----------|
| Adjustment entries | **Low** | One enum case, one nullable column, one new service method, no lock-order changes |
| Per-assignment pre-arrival editing | **Low–Medium** | Mirrors `releaseAssignment()`'s existing guard pattern; no new tables |
| Rate-aware Stay/Assignment | **Medium** | Touches the rate-resolution path used by Night Audit; needs careful test coverage across existing pricing tests |
| Stay transfer (room move) | **Medium–High** | New state-machine concept; must be proven not to interfere with checkout finalization, folio auto-close, or the ADR-49 "active stay" definition |
| Expected-total projection | **Low** | Read-only, additive, no write path at all |

**Overall: Medium.** No single piece is architecturally large; the aggregate is non-trivial mainly because it touches five distinct surfaces (enum, two models, three services) rather than because any one piece is deep.

---

## 13. Recommended Roadmap

1. **Phase 4.3.1 — Adjustment Entries** (lowest risk, unblocks Scenario 4 immediately)
2. **Phase 4.3.2 — Per-Assignment Pre-Arrival Editing** (unblocks Scenario 2, half of Scenario 6)
3. **Phase 4.3.3 — Rate-Aware Stay/Assignment** (completes Scenario 6)
4. **Phase 4.3.4 — Stay Transfer / Room Move** (unblocks Scenario 5 — schedule last; highest interaction risk with checkout finalization)
5. **Phase 4.3.5 — Expected-Total Projection & Revenue Reconciliation View** (closes the Scenario 1/3 UX gap and the "no reconciliation" risk from §7)

Each phase should follow the established pattern from Phase 4.1/4.2: Architecture Review → Implementation Plan → Milestone-by-milestone backend/frontend delivery → Integration & Final Verification → Closure.

---

## Analysis Scope Note

This report is **analysis only**. No source file, migration, test, or configuration was modified in the course of producing it. All findings are grounded in direct reading of `BookingService`, `FolioService`, `StayService`, `RoomAssignmentService`, `NightAuditPipeline`, `NightAuditService`, `RevenueReportService`, the `Posting\*Job` classes, and the `Booking`/`BookingRequirement`/`RoomAssignment`/`Stay`/`Folio`/`FolioEntry` models as they exist on `phase-3` at the time of writing (post `phase-4.2` tag).
