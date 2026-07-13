# Product Sprint 01 — Payment Projection M1 Report

**Date:** 2026-07-12
**Branch:** phase-3
**Type:** Product Sprint (not an architecture phase) — read-only forward projection
**Status:** Implementation complete, not committed, not pushed, not tagged.

---

## Product Problem

Reception can extend a Stay (Phase 4.3A) or amend a Booking's dates, but the amount shown to the guest — "Expected Total" / outstanding balance — did not reflect that change until Night Audit actually posted the extra night. Concretely: a booking originally `04/07 → 05/07`, amended or extended to `04/07 → 06/07`, kept showing the OLD total on the Booking Detail screen, because the only existing "expected total" (`BookingService::paymentSummary()['expected_total']`) is an alias for **already-posted** Folio charges (`FolioService::getFolioTotal()`), not a forward-looking calculation. This risks Reception quoting the wrong amount to a guest.

---

## Existing Source of Truth

| Concern | Existing source of truth | Reuse plan |
|---|---|---|
| Stay duration | `Stay.planned_checkin_at` / `planned_checkout_at` / `actual_checkin_at` / `actual_checkout_at` | Read directly, state-dependent (see Multi-Stay Handling) |
| Room rate | `BookingRequirement.room_price` (frozen snapshot, resolved at booking-creation/edit time — matches exactly what `RoomChargePostingJob::resolveUnitPrice()` already uses) | Reused verbatim — same lookup by `room_type_id`, no live `RoomRate` re-query |
| Deposit | `PaymentType::Deposit` + `PaymentType::AdditionalDeposit` on `booking_payments` (no separate "deposit requirement/policy" concept exists anywhere in the codebase — confirmed by search) | Reused via `BookingService::paymentSummary()`'s existing `total_deposit` classification, not re-implemented |
| Payment deduction | `BookingService::paymentSummary()`: `paid_total = total_deposit + total_payment + total_adjustment - total_refund` | Reused verbatim via the same method call |
| Existing "expected total" | `paymentSummary()['expected_total']` = alias for posted `FolioService::getFolioTotal()` (historical, not forward-looking) | **Not reused for the new value** — this is precisely the gap being closed; a genuinely new, additive `expected_total` is computed instead, while `paymentSummary()`'s own posted `total_charges`/`balance_due` remain completely untouched |
| Non-room extra charges (breakfast, extra bed, etc.) | Only posted via Night Audit's per-charge-type jobs (`BreakfastPostingJob`, etc.) — no existing "projected extra charge" utility exists to reuse | Included only to the extent already posted to `FolioEntry` (non-`ROOM` charge types), never forward-projected — see Known Limitations |

**Deposit clarification (resolved without needing a Clarification Note STOP):** the codebase has no concept of a "required" or "target" deposit — `Deposit`/`AdditionalDeposit` exist purely as `PaymentType` values on already-recorded `BookingPayment` rows. There is nothing to invent; `paymentSummary()` already defines this precisely and correctly, so this was reused as-is.

---

## Calculation Definition

- **Expected Total** = Σ(projected room charges per active Stay, or per `BookingRequirement` before any Stay exists) + (already-posted, non-voided, non-`ROOM` `FolioEntry` charges).
- **Expected Deposit** = `total_deposit` from the existing `paymentSummary()` (i.e., `PaymentType::Deposit + PaymentType::AdditionalDeposit`, already recorded).
- **Expected Balance** = `Expected Total − paid_total` (the same `paid_total` `paymentSummary()` already computes: deposit + payment + adjustment − refund).

---

## Product Semantics Review

ChatGPT's review correctly identified that `expected_total` blends two different temporal semantics without saying so. Reviewed explicitly, value by value:

| Giá trị hiện tại | Thành phần thực tế | Có bao gồm tương lai không? | Có bao gồm posted không? | Tên UI phù hợp |
|---|---|---|---|---|
| `projected_room_total` | Σ nights × frozen room rate, per active Stay — nights span **past + future** relative to the Stay's *current plan* (`actual_checkin_at` → `planned_checkout_at` while `CheckedIn`) | **YES** — includes nights not yet arrived, per the current plan | NO — computed independently, never read from posted `FolioEntry` | "Chi phí lưu trú dự kiến" |
| `posted_non_room_total` | Sum of already-posted, non-voided, non-`ROOM` `FolioEntry` rows | **NO** — only what has been posted to date; a booking enrolled in a recurring package (e.g. breakfast) whose future nights haven't been Night-Audited yet contributes **nothing** for those future nights | YES — 100% posted-only | "Phí khác đã ghi nhận" |
| `expected_total` | `projected_room_total + posted_non_room_total` | **MIXED** — the room component is future-inclusive, the non-room component is posted-to-date only | **MIXED** — partially yes (non-room), partially no (room) | Needs an explicit qualifier — see UI Naming Decision |
| `expected_deposit` | `paymentSummary()['total_deposit']` (already-collected Deposit/AdditionalDeposit payments) | NO — inherently a to-date figure, no ambiguity | YES | "Đã đặt cọc/đã ghi nhận" — unchanged, no issue found |
| `expected_balance` | `expected_total − paid_total` | Inherits `expected_total`'s mixed semantic | Inherits | "Còn dự kiến" — kept, but now sits under a correctly-qualified total and a caveat note |

**Conclusions:**

1. **The current value does NOT represent a clean "final total if the guest stays through the full plan."** It answers neither product question A ("if the guest checked out right now") nor B ("if the guest stays the full current plan") in isolation — it is a genuine hybrid: *room cost through the current plan* + *other costs incurred so far*. This must be stated, not hidden behind a generic label.
2. **"Tổng dự kiến" alone is too strong a claim** for a hybrid figure — it reads as a settled, final number. Renamed to **"Tổng dự kiến hiện tại"** ("currently projected total") to signal it is a live, current-state snapshot, not a locked-in final figure.
3. **Breakdown should be shown.** `projected_room_total` and `posted_non_room_total` are now both surfaced as distinct rows above the combined total, so Reception can see the two components rather than only trusting one blended number.
4. **A caveat about non-forward-projected recurring services is now mandatory** in the UI, not optional — added directly under the breakdown.

**Current projection is not a final invoice and does not include unposted future recurring service charges.**

---

## UI Naming Decision

- `expected_total` → displayed as **"Tổng dự kiến hiện tại"** (was "Tổng dự kiến"). The underlying JSON key is unchanged (`expected_total`) to avoid an unnecessary breaking payload rename; only the **label** changed, since the review found a wording problem, not a calculation problem.
- New breakdown rows added above it: **"Chi phí lưu trú dự kiến"** (`projected_room_total`) and **"Phí khác đã ghi nhận"** (`posted_non_room_total`).
- `expected_deposit` ("Đã đặt cọc/đã ghi nhận") and `expected_balance` ("Còn dự kiến") labels are unchanged — the review found no issue with these two.
- The caveat paragraph was rewritten to explicitly name what is and isn't included, replacing the previous generic sentence:

  > "Số liệu trên gồm chi phí phòng theo thời gian lưu trú hiện tại và các khoản phí khác đã ghi nhận. Các dịch vụ định kỳ hoặc phí chưa phát sinh (ăn sáng, giường phụ, thuế lưu trú...) chưa được dự báo. Đây không phải hóa đơn cuối cùng và chưa thay thế số liệu đã ghi sổ."

  (Explicitly states: not a final invoice, does not include unposted future recurring service charges — matching the exact required statement, translated and made concrete with real examples from this codebase's own `ChargeType` list.)

---

## Projection Breakdown

Yes — breakdown is now exposed. `PaymentProjectionService::project()` gained two new **top-level** keys (previously these existed only nested inside the internal/debug `calculation_context`, per M1's original "not required in UI" design — now promoted since the UI genuinely needs them):

```php
[
    'projected_room_total'   => ...,  // NEW top-level (was calculation_context.room_total)
    'posted_non_room_total'  => ...,  // NEW top-level (was calculation_context.posted_non_room_total)
    'expected_total'         => ...,  // unchanged formula: projected_room_total + posted_non_room_total
    'expected_deposit'       => ...,  // unchanged
    'expected_balance'       => ...,  // unchanged
    'calculation_context'    => [...], // unchanged, kept for backward-compat with existing tests
]
```

**No formula changed.** Both new fields are the exact same already-computed local variables (`$roomTotal`, `$postedNonRoomTotal`) that already fed `$expectedTotal` in M1 — this is purely an exposure change, not a calculation change. No new query, no new side effect, no domain-behavior change.

---

## Non-room Forecast Limitation

Explicitly **not** addressed by this task, per its own scope boundary:

- Breakfast, extra bed, city tax, and other recurring per-night packages are **not** forward-projected for nights that haven't been Night-Audited yet.
- Doing so would require a genuine **Forecast Charges Engine** — reimplementing each `PostingJob`'s (`BreakfastPostingJob`, `ExtraBedPostingJob`, `ExtraPersonPostingJob`, `CityTaxPostingJob`) rate-resolution logic in a parallel, non-posting, forward-looking form.
- This is explicitly out of scope for this task (no new `PostingJob`, no Forecast Charges Engine, no Adjustment Engine) and is instead handled honestly: the UI breaks the total into its two real components and states plainly, in Vietnamese, that recurring/unposted services are not included.

---

## Final User-facing Meaning

After this change, "Tổng dự kiến hiện tại" means, precisely: *room cost for the Stay's current plan (past nights actually stayed + future nights per the current planned checkout) plus whatever non-room charges have already been posted to the Folio* — explicitly **not** a final invoice, and explicitly **not** inclusive of recurring services not yet posted. This is now stated directly in the UI caveat, not left implicit.

---

## Payment Reconciliation Review

A second, separate issue was found after the semantics round above: even with correct labels, the UI's **numbers didn't reconcile with each other**. `expected_balance = expected_total − paid_total`, but the row shown as its visual counterpart was `expected_deposit = total_deposit` — a strict subset of `paid_total` (which also includes `RoomPayment`/`ServicePayment`/`Adjustment`, minus `Refund`). A user could never verify "Tổng dự kiến − Đã đặt cọc = Còn dự kiến" by hand, because the middle number wasn't the one actually subtracted.

**Example that exposed it:** Tổng dự kiến 3.000.000, Đã đặt cọc 1.000.000, Còn dự kiến 500.000 — the 1.500.000 gap between "đã đặt cọc" and what was actually deducted (2.500.000) had no visible source on screen.

**Fix:** expose the actual subtrahend as its own field, `recognized_paid_total`, and make it — not `expected_deposit` — the number the UI shows directly across from `expected_balance`. `expected_deposit` is kept, but demoted to a secondary "trong đó" (of which) breakdown line, never presented as the reconciliation counterpart.

---

## Recognized Paid Total

```php
'recognized_paid_total' => round($paymentSummary['paid_total'], 2),
```

Where `$paymentSummary = $this->bookings->paymentSummary($booking)` — the exact same call already used (unmodified) to derive `expected_deposit` and `expected_balance`. **Not re-derived, not re-calculated** — the same `paid_total` value (`total_deposit + total_payment + total_adjustment − total_refund`) that `expected_balance` was already silently using internally is now also exposed directly, under its own honest name, at the same rounding precision (`round(..., 2)`) as every other projection field.

---

## UI Reconciliation Decision

- **"Đã thanh toán/khấu trừ"** now occupies the position directly between "Tổng dự kiến hiện tại" and "Còn dự kiến", bound to `recognized_paid_total`.
- **"Đã đặt cọc/đã ghi nhận"** was removed from that position and replaced by a small secondary line, **"Trong đó tiền đặt cọc"**, bound to `expected_deposit`, placed directly below the three-column row — visually subordinate, never claiming to be the reconciliation counterpart.
- The caveat paragraph was extended with one sentence clarifying that "Đã thanh toán/khấu trừ" is a composite (deposit + payment + adjustment − refund), not a claim that the guest paid 100% in cash.
- No wording anywhere implies "final invoice," "final checkout amount," "full package forecast," or "posted ledger balance" — reviewed line by line against the explicit banned-phrasing list.

---

## Financial Invariant

Stated explicitly, tested explicitly, unchanged from before this task (only now visibly reconcilable on screen):

```
recognized_paid_total = paymentSummary()['paid_total']
expected_balance      = expected_total − recognized_paid_total
```

Verified by `test_recognized_paid_total_equals_payment_summary_paid_total` and `test_expected_balance_equals_expected_total_minus_recognized_paid_total` — the latter asserts the subtraction directly against the two returned fields, not against a re-derived value, so any future drift between the two would fail this test immediately.

`expected_deposit` remains deliberately **not** equal to `recognized_paid_total` whenever a non-deposit payment exists — proven by `test_expected_deposit_remains_only_the_deposit_subset`, which is the explicit regression guard against ever silently re-merging the two concepts.

---

## Files Changed

| File | Change |
|------|--------|
| `app/Services/PaymentProjectionService.php` | Modified (this round) — added 1 more top-level key, `recognized_paid_total = $paymentSummary['paid_total']` (already-computed value, same rounding convention as every other field). Zero formula change; `expected_total`/`expected_deposit`/`expected_balance`/`projected_room_total`/`posted_non_room_total` all byte-identical to the prior round. |
| `app/Http/Controllers/Admin/Booking/BookingController.php` | Unchanged this round (no controller change needed — the new field flows through the existing `payment_projection` payload key automatically). |
| `resources/js/Pages/Admin/Bookings/Partials/PaymentProjectionSummary.vue` | Modified (this round) — "Đã đặt cọc/đã ghi nhận" replaced by "Đã thanh toán/khấu trừ" bound to `recognized_paid_total` as the direct reconciliation counterpart; deposit demoted to a secondary "Trong đó tiền đặt cọc" line; caveat extended with one clarifying sentence. |
| `resources/js/Pages/Admin/Bookings/Partials/FolioPanel.vue` | Modified (this round) — prop type extended with `recognized_paid_total`. |
| `resources/js/Pages/Admin/Bookings/Show.vue` | Unchanged this round. |
| `tests/Unit/Services/PaymentProjectionServiceTest.php` | Modified (this round) — added 7 new tests covering the reconciliation invariant, deposit/additional-deposit/payment/adjustment inclusion, refund reduction, and the expected_deposit-vs-recognized_paid_total distinction. 25 tests total (was 18). |
| `tests/Feature/PaymentProjectionUiReadinessTest.php` | Modified (this round) — added 1 new test asserting `recognized_paid_total` and `expected_deposit` both appear correctly, and distinctly, in the Booking Detail payload. 4 tests total (was 3). |

**No migration. No model changed. No existing method's formula changed** — every round of this sprint (implementation, semantics wording, reconciliation) has been additive exposure or UI wording only.

---

## Expected Total Logic

For each active `Stay` (excluding `Cancelled`/`NoShow`):

- `CheckedOut` → use `actual_checkin_at` → `actual_checkout_at` (the real, final, already-known duration — not open-ended).
- `CheckedIn` → use `actual_checkin_at` → `planned_checkout_at` (the **current** plan, already reflecting any Stay Extension or Booking amendment — no special-casing needed, it just reads the current column value).
- `Reserved` → use `planned_checkin_at` → `planned_checkout_at`.

Nights = calendar-date difference (`startOfDay()` diff), not raw hour difference — this is an explicit, documented assumption (a 14:00→12:00-next-day stay must count as 1 night, not 0), verified correct against the exact same 1/1/2-night pattern Milestone 4's `SplitStayVerificationTest` already established for real billing.

Unit price = `BookingRequirement.room_price` matched by `room_type_id` — the exact same frozen-snapshot lookup `RoomChargePostingJob` already uses, so the projection can never diverge from what Night Audit will actually post.

**Before any Stay/RoomAssignment exists** (pure `PendingAssignment`/`Draft` Booking): falls back to `Booking.checkin_at`/`checkout_at` × each `BookingRequirement`'s quantity × room_price — the only source of truth available at that stage, and it stops being used the moment any Stay is created (so Split Stay divergence is never flattened by a simplistic Booking-wide multiply, per the explicit instruction).

Non-room charges: summed directly from already-posted, non-voided `FolioEntry` rows where `charge_type != ROOM` — never forward-projected (see Known Limitations), and never double-counted with the room total since room charges are computed independently by nights × rate, not re-read from posted entries.

---

## Expected Deposit Logic

Directly equals `BookingService::paymentSummary($booking)['total_deposit']` — zero new logic, zero re-implementation, guaranteeing this can never drift from the existing, already-tested classification.

---

## Expected Balance Logic

`expected_total - paymentSummary($booking)['paid_total']`. Since `paid_total` already correctly adds deposit/payment/adjustment and subtracts refund (and a deleted payment is simply absent — the only "cancellation" mechanism this domain has), refund/deletion handling required zero new code.

---

## Multi-Stay Handling

- Each Stay's contribution is computed independently from its own dates — proven by `test_one_stay_extended_others_unchanged` (3 rooms, one extended, the breakdown shows 1/1/2 nights respectively, matching Milestone 4's Split Stay proof exactly).
- Partial Checkout: `test_partial_checkout_does_not_drop_other_stay_costs` confirms a checked-out Stay's actual cost is still counted (via its `actual_checkin_at`/`actual_checkout_at`) alongside the other Stays' still-projected costs.
- Final Checkout: `test_final_checkout_does_not_overwrite_projection_with_posted_ledger` confirms the projection and the posted Folio total are computed independently — checkout does not mutate one into the other; they merely happen to agree when nothing unusual occurred, which is the correct behavior, not a hidden alias.

---

## Stay Extension Verification

`test_stay_extension_increases_expected_total`: a 1-night Stay extended by 2 more nights (via the existing, unmodified `StayService::extendStay()`) correctly raises `expected_total` from 1× to 3× the room rate — with zero change to `StayService`, `extendStay()`, or any Stay Extension file from Phase 4.3A.

---

## Booking Amendment Verification

`test_booking_amendment_changes_expected_total`: calling the existing, unmodified `BookingService::updateBooking()` to change `checkout_at` on a not-yet-checked-in Stay correctly raises `expected_total` from 1 to 2 nights — because `updateBooking()` already persists the new dates onto both `Booking` and (where a Stay exists) `RoomAssignment`/`Stay`, and the projection simply reads whichever is current. Zero change to `BookingService::updateBooking()` or `validateTimeChange()`.

---

## Financial Safety Verification

Confirmed explicitly, by test and by `git diff` scope review:

- **Folio modified: NO** — no `FolioService`/`Folio`/`FolioEntry` file touched; `test_projection_creates_no_folio_entry` proves zero new rows from calling `project()`.
- **Payment history modified: NO** — no `BookingPaymentService`/payment file touched; `test_projection_creates_no_payment` proves zero new rows.
- **Night Audit modified: NO** — no `NightAuditPipeline`/`NightAuditService`/posting-job file touched; `test_projection_creates_no_night_audit_records` proves zero new `NightAuditRun`/`NightAuditBookingLog` rows.
- **Adjustment created: NO** — no adjustment-entry code exists or was added anywhere.
- **Child Booking created: NO** — `test_projection_creates_no_child_booking` proves `Booking::count()` is unchanged by calling `project()`.
- **Migration added: NO** — the entire feature is derived/read-only; no new column or table was needed or created.

The projection is never consulted by the checkout outstanding-balance guard (`BookingService::finaliseBookingCheckout()`), which continues to use only the posted `FolioService::getFolioTotal()` exactly as before — confirmed by `git diff` showing zero changes to that method.

---

## UI Result

Final state (same location, existing Booking Detail "Tài chính" tab, directly below the posted-ledger `PaymentSummary`) — breakdown + reconciled figures + caveat, per both the Product Semantics Review and this Payment Reconciliation Review:

```
DỰ KIẾN THANH TOÁN

Chi phí lưu trú dự kiến:      x.xxx.xxx đ
Phí khác đã ghi nhận:         x.xxx.xxx đ

Tổng dự kiến hiện tại:        x.xxx.xxx đ
Đã thanh toán/khấu trừ:       x.xxx.xxx đ
Còn dự kiến:                  x.xxx.xxx đ

Trong đó tiền đặt cọc: x.xxx.xxx đ

"Số liệu trên gồm chi phí phòng theo thời gian lưu trú hiện tại và các khoản phí khác đã ghi nhận.
'Đã thanh toán/khấu trừ' gồm đặt cọc, thanh toán, điều chỉnh và đã trừ hoàn tiền — không có nghĩa toàn bộ số tiền đều là tiền mặt đã thu.
Các dịch vụ định kỳ hoặc phí chưa phát sinh (ăn sáng, giường phụ, thuế lưu trú...) chưa được dự báo.
Đây không phải hóa đơn cuối cùng và chưa thay thế số liệu đã ghi sổ."
```

- Still no new page, tab, dashboard, or wizard — same block, same tab, extended in place.
- No technical terms ("Projection Engine", "Forecast", etc.) shown to Reception.
- Distinctly labeled from the existing posted "Tổng phí phát sinh" / "Còn lại" block — never sharing the same "Còn phải trả" wording.
- "Tổng dự kiến" renamed to **"Tổng dự kiến hiện tại"** so it no longer reads as a settled final figure.
- **"Đã thanh toán/khấu trừ" (bound to `recognized_paid_total`) is now the number directly across from "Còn dự kiến"** — the two literally subtract to produce it, verifiable by hand. Deposit is shown only as a secondary "Trong đó tiền đặt cọc" line, never as the reconciliation counterpart.
- The caveat names the excluded future-recurring categories (breakfast, extra bed, city tax), states this is not a final invoice, and now also clarifies "Đã thanh toán/khấu trừ" is a composite figure, not literal cash received.
- Currency formatting unchanged (`Intl.NumberFormat('vi-VN', ...)`).
- Responsive within the existing tab layout — no layout change elsewhere on the page.

---

## Targeted Tests

**Prior rounds** (unchanged, still valid): M1's 16+2, and the Semantics Review's 18+3. See Full Regression below for M1's 2 full-suite runs, which remain the valid baseline.

**Payment Reconciliation Review pass** (this round — 1 new read-only field, no formula change):

| File | Count | Result |
|------|-------|--------|
| `tests/Unit/Services/PaymentProjectionServiceTest.php` | 25 (18 prior + 7 new) | ✅ all passed |
| `tests/Feature/PaymentProjectionUiReadinessTest.php` | 4 (3 prior + 1 new) | ✅ all passed |
| `StayServiceExtendTest` | 8 | ✅ all passed |
| `CheckoutIntegrationTest` | 13 | ✅ all passed |
| `PartialCheckoutStayEventTest` | 8 | ✅ all passed |
| `SplitStayVerificationTest` | 1 | ✅ all passed |
| `PaymentUiTest` | 5 | ✅ all passed |

**Total this round: 64 passed, 0 failed.**

---

## Full Regression

**Not re-run this round** — per instruction, full regression is only required when the backend calculation or domain behavior changes. This round exposes exactly one new read-only field (`recognized_paid_total`), reusing an already-computed value (`$paymentSummary['paid_total']`, itself unchanged) verbatim. `expected_total`/`expected_deposit`/`expected_balance`/`projected_room_total`/`posted_non_room_total` are byte-for-byte identical to the prior round's formulas — confirmed by inspection of the diff, which is a single additive array key.

The most recent full regression remains M1's original 2 runs, both byte-identical:

| Run | Failed | Passed | Total |
|-----|--------|--------|-------|
| 1 | 23 | 828 | 851 |
| 2 | 23 | 828 | 851 |

That result remains valid for this round since no domain/calculation code changed since it ran — this round's 64 targeted tests (above) are the evidence that the additive change didn't disturb anything.

---

## Build Result

`npm run build` — **0 errors** (re-run after this round's Vue changes).

```
✓ 2363 modules transformed.
public/build/assets/app-Bp8ai7nC.js   526.58 kB
✓ built in 28.90s
```

Pre-existing >500kB chunk warning, unchanged, not a blocker.

---

## Known Baseline Failures

**A. Known deterministic baseline (23):** 11 × `BookingManagementUiTest` + 12 × `RoomAvailabilityCheckerTest` — pre-existing, date-sensitive, unrelated to this sprint. Identical in both full-regression runs.

**B. Known intermittent failures:** neither `PerStayAttributionTest` (Faker collision) nor `LateCheckoutFeeTest` (wall-clock dependency) appeared in either full-regression run this time — both are pre-existing, already root-caused in Phase 4.3A's closure reports, and remain unfixed per this sprint's explicit "do not fix unless strictly necessary" instruction (neither was necessary here).

---

## New Deterministic Regressions

**NONE.**

---

## Remaining Risks

- Recurring package charges (breakfast, extra bed, city tax, etc.) are included in Expected Total only to the extent already posted — they are **not** forward-projected for nights not yet audited. Building that would require reimplementing each `PostingJob`'s rate-resolution logic in projection form, which is new invention beyond this sprint's minimal, reuse-only scope. Flagged here as a candidate for a future, explicitly-scoped follow-up, not hidden.
- `BookingRequirement.room_price` is a frozen snapshot (pre-existing, documented gap from `revenue-folio-gap-analysis.md`) — if a hotel changes its live `RoomRate` after a booking is created, the projection (like Night Audit itself) will not reflect the new rate. This is intentional consistency with what will actually be posted, not a bug, but is worth noting.
- No live browser verification was possible in this environment (same pre-existing limitation documented since Phase 4.3A's Operational Review) — the UI was verified via `npm run build` succeeding and direct Vue-source review, not an actual click-through.
- The `expected_total`/`expected_deposit`/`expected_balance` JSON keys were deliberately **not** renamed (only their UI labels changed) to avoid an unnecessary breaking payload change for a wording fix — if ChatGPT prefers the payload keys themselves renamed to make the hybrid nature explicit at the API level too (e.g. `current_projected_total`), that is a one-line follow-up, not a redesign.
- **Resolved this round:** the reconciliation gap (UI's visible "đã đặt cọc" not matching what `expected_balance` actually subtracted) is fixed by exposing `recognized_paid_total` and making it, not `expected_deposit`, the number shown directly across from `expected_balance`. `expected_deposit` remains available as a secondary breakdown for anyone who specifically wants the deposit-only figure.

---

## Product Readiness

**READY FOR CHATGPT REVIEW: YES**
