# Product Sprint 01 — Payment Projection M1 Final Closure Report

## Status

- Sprint: Product Sprint 01
- Feature: Payment Projection M1
- Branch: phase-3
- Final status: **OFFICIALLY CLOSED**
- Ready for operation within approved scope: **YES**

---

## Product Problem Solved

- Stay Extension and Booking Amendment previously did not update the amount shown to guests — the only "expected total" that existed was an alias for already-posted Folio charges, not a forward-looking figure.
- This left Reception at risk of quoting the wrong amount when a guest's stay changed.
- This sprint adds a read-only, forward-looking Payment Projection on the Booking Detail page, computed independently from Folio/Payment/Night Audit, that reflects the Stay's current plan.

---

## Approved Capabilities

- `projected_room_total`
- `posted_non_room_total`
- `expected_total`
- `expected_deposit`
- `recognized_paid_total`
- `expected_balance`
- Multi-Stay support (each Stay's cost computed independently, Split Stay divergence never flattened)
- Stay Extension support (reads the Stay's current planned checkout automatically, no special-casing)
- Booking Amendment support (reads `Booking`/`RoomAssignment`/`Stay`'s current dates automatically)
- Booking Detail UI breakdown, reconciled and clearly worded

---

## Financial Invariants

```
recognized_paid_total = paymentSummary()['paid_total']
expected_balance      = expected_total − recognized_paid_total
```

Both reused verbatim from the existing, already-tested `BookingService::paymentSummary()` — never re-derived, never re-implemented.

---

## Product Semantics

- "Tổng dự kiến hiện tại" is **not** a final invoice.
- The room component includes the Stay's current planned stay (past nights actually stayed + future nights per the current plan).
- The non-room component includes **only already-posted** charges — it is not a forecast.
- Future recurring charges (breakfast, extra bed, city tax, and other per-night packages not yet Night-Audited) are **not** forecast by this feature.

---

## Financial Safety

- **Folio modified: NO**
- **Payment history modified: NO**
- **Night Audit modified: NO**
- **Adjustment created: NO**
- **Child Booking created: NO**
- **Migration added: NO**

Confirmed by `git diff` scope review (zero touch to `FolioService`, `BookingPaymentService`, `NightAuditService`, any `PostingJob`, `StayService`'s business logic, or any migration file) and by dedicated tests (`test_projection_creates_no_folio_entry`, `test_projection_creates_no_payment`, `test_projection_creates_no_night_audit_records`, `test_projection_creates_no_child_booking`).

---

## UI Result

Final approved wording, on the existing Booking Detail "Tài chính" tab (no new page/tab/dashboard):

- Chi phí lưu trú dự kiến
- Phí khác đã ghi nhận
- Tổng dự kiến hiện tại
- Đã thanh toán/khấu trừ
- Còn dự kiến
- Trong đó tiền đặt cọc (secondary breakdown line, never the reconciliation counterpart)

Plus a caveat stating this is not a final invoice, that "Đã thanh toán/khấu trừ" is a composite figure (not literal cash received), and that future recurring services are not forecast.

---

## Verification Summary

- **Targeted tests (re-run immediately before this closure):** 64 passed, 0 failed — `PaymentProjectionServiceTest` (25), `PaymentProjectionUiReadinessTest` (4), `PaymentUiTest` (5), `StayServiceExtendTest` (8), `CheckoutIntegrationTest` (13), `PartialCheckoutStayEventTest` (8), `SplitStayVerificationTest` (1).
- **Full regression:** reused from the most recent 2 runs (both prior to this closure's final, purely-additive `recognized_paid_total` change) — confirmed valid to reuse because the diff since that run is limited to one additive field exposure and UI wording, with zero calculation/domain-behavior change (verified via `git diff` scope review).

  | Run | Failed | Passed | Total |
  |-----|--------|--------|-------|
  | 1 | 23 | 828 | 851 |
  | 2 | 23 | 828 | 851 |

- **Build result:** `npm run build` — 0 errors (pre-existing >500kB chunk warning, unchanged, not a blocker).
- **New deterministic regressions: NONE.**

---

## Known Baseline Failures

- 23 pre-existing date-sensitive failures (11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest`) — unrelated to this sprint.
- `PerStayAttributionTest` — intermittent Faker unique-value collision (pre-existing, not a Payment Projection issue).
- `LateCheckoutFeeTest` — wall-clock/time-of-day dependency in the test's own date arithmetic (pre-existing, not a Payment Projection issue).

---

## Known Product Limitations

Recorded as **Future Product Enhancements / Accepted Scope Limitations**, not blockers:

- Future recurring non-room charges (breakfast, extra bed, city tax, other per-night packages) are not forecast — only already-posted amounts are counted.
- `BookingRequirement.room_price` is a frozen snapshot, consistent with what Night Audit will actually post; it does not reflect live `RoomRate` changes made after booking creation.
- No live browser verification was possible in this environment (no browser-automation tool available) — verified via `npm run build`, targeted HTTP-level tests, and direct Vue-source review instead.
- Negative `expected_balance` (guest in credit / to be refunded) has no dedicated wording yet — it currently just renders as a non-coral (pine-colored) number with no explicit "khách đang dư" / "khách được hoàn" label.

---

## Git Information

- **Commit hash:** `6e4c6248e3b9823df554d5d2aae1bf7ac7d70654`
- **Commit message:** `feat(payment): add read-only payment projection summary`
- **Branch pushed:** `phase-3` (`a91f239..6e4c624`)
- **Tag name:** `product-sprint-01-payment-projection-m1` (annotated), message: "Product Sprint 01 - Payment Projection M1"
- **Tag pushed:** yes — `origin/product-sprint-01-payment-projection-m1` created
- **Remote confirmation:** `git ls-remote --tags origin product-sprint-01-payment-projection-m1` → `1d96a007ff34c212da6b7e949b3849be8c14da86 refs/tags/product-sprint-01-payment-projection-m1`; tag locally verified to point at commit `6e4c624` via `git rev-parse product-sprint-01-payment-projection-m1^{commit}`

---

## Recommended Next Product Sprint

**Room Move** (proposal only — not implemented, no implementation plan created in this closure task).

Future goal:

- Move a guest to another room.
- Stay remains the same Stay (or a clearly linked continuation) — no new commercial contract.
- Booking remains the same Booking.
- No Child Booking, ever.
- Payment Projection updates automatically to reflect the new room (reusing this sprint's existing Stay-driven projection logic, not a new engine).
- Historical postings (already-posted Folio entries for nights before the move) remain unchanged.

---

## Closure Confirmation

- ✅ Product Sprint 01 **OFFICIALLY CLOSED**
- ✅ No Room Move started
- ✅ No Adjustment Engine started
- ✅ No Forecast Charges Engine started
- ✅ Working tree clean (one unrelated, intentionally-uncommitted file remains from the prior Phase 4.3A closure task — `docs/reports/phase-4.3a-final-closure-report.md` — out of this sprint's scope, not touched)
