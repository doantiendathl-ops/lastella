# Phase 3.1B2 Implementation Report
## Folio UI & Checkout UI

**Date:** 2026-07-01  
**Branch:** phase-3  
**Status:** COMPLETE — Awaiting ChatGPT architecture/code review before commit

---

## 1. Executive Summary

Phase 3.1B2 delivers the Folio UI and Checkout UI layers for the LASTELLA PMS booking show page. All wave-by-wave implementation requirements were fulfilled. The 6 critical ADRs (50–54 + flash routing) are enforced across PHP exception layer, Vue components, and integration tests.

**17 new tests added, all green. 0 new regressions.**

---

## 2. Scope

| Area | Files |
|------|-------|
| PHP exceptions (Wave 2) | 4 exceptions modified |
| BookingController (Wave 2) | 1 controller modified |
| Vue leaf components (Wave 3) | 5 new `.vue` files |
| Vue dependent components (Wave 4) | 3 new `.vue` files |
| FolioPanel assembly (Wave 5) | 2 new `.vue` files |
| Show.vue integration (Wave 6) | 1 existing `.vue` heavily modified |
| Tests (Wave 7) | 3 new test files, 1 existing test updated |

---

## 3. ADR Compliance Matrix

| ADR | Description | Implementation | Verified |
|-----|-------------|----------------|---------|
| **ADR-50** | System entry void is business invariant in `FolioService::voidEntry()`, not in policy | `is_system_entry` flag on payload; Void button hidden in `FolioEntryTable.vue`; `SystemEntryVoidException` thrown from service | `FolioUiTest::test_system_entry_with_posting_key_cannot_be_voided` |
| **ADR-51** | `paymentSummary` is single canonical financial source | All Vue components read from `paymentSummary` prop; `AddPaymentForm.vue` explicitly comments `// ADR-51: reads directly from paymentSummary prop — never recomputed` | `PaymentUiTest::test_booking_show_includes_payment_summary` |
| **ADR-52** | Checkout button disabled is UX only; `OutstandingBalanceException` is authoritative | `checkoutDisabled()` in `RoomBoardPanel.vue` with inline comment; confirmation modal for last-stay+zero-balance | `CheckoutUiTest::test_checkout_with_outstanding_balance_does_not_change_booking_status` |
| **ADR-53** | `OutstandingBalanceException` caught per-controller; redirects to `?tab=payments` | `StayController::checkOut()` catches OBE and redirects with `with('error', ...)` | `CheckoutUiTest::test_checkout_with_outstanding_balance_redirects_to_payments_tab_with_flash_error` |
| **ADR-54** | `can_reopen` computed once in controller including terminal check | `folioPayload()` in `BookingController`: `&& ! $booking->status->isTerminal()` | Manual inspection |
| **Flash routing** | `with('error', ...)` vs `withErrors()` separation | 4 exceptions changed from `withErrors()` to `with('error', ...)`; `RefundExceedsMaxException` and `NegativeAdjustmentExceedsPaidException` deliberately kept as `withErrors()` per §10.1 | `FolioUiTest::test_system_entry_void_sets_flash_error_not_validation_error` |

---

## 4. Wave Summary

### Wave 1 — PHP Critical Fixes
No Wave 1 changes required (pre-existing codebase was correct).

### Wave 2 — Audit & Exception Fixes

**Modified exception `render()` from `withErrors()` → `with('error', ...)`:**

| Exception | Reason |
|-----------|--------|
| `AlreadyVoidedException` | Void is via `router.patch()` — `withErrors()` invisible |
| `FolioClosedException` | Write operations are via `router.patch()` — `withErrors()` invisible |
| `FolioVoidedException` | Same reason |
| `BookingTerminalException` | Payment/checkout ops via `router.patch/delete()` — `withErrors()` invisible |

**Preserved `withErrors()` for:**
- `RefundExceedsMaxException` — field-level error in payment form (ADR §10.1)
- `NegativeAdjustmentExceedsPaidException` — field-level error in payment form (ADR §10.1)

**BookingController `folioPayload()`:**
```php
'can_reopen' => (request()->user()?->can('reopen', $folio) ?? false) && ! $booking->status->isTerminal(),
```
Added `&& ! $booking->status->isTerminal()` because `FolioPolicy::reopen()` does not check terminal status.

### Wave 3 — Vue Leaf Components

All in `resources/js/Pages/Admin/Bookings/Partials/`:

| File | Purpose |
|------|---------|
| `FolioStatusBadge.vue` | Status badge with Open/Closed/Voided color coding |
| `AddChargeForm.vue` | POST to folio entries; `useForm`; inline preview total |
| `VoidEntryDialog.vue` | `router.patch()` void with min-5-char reason |
| `PaymentSummary.vue` | 3-card summary + breakdown grid; reads only from `paymentSummary` prop (ADR-51) |
| `CheckoutReadinessBanner.vue` | Terminal/balance/ready states |

### Wave 4 — Vue Dependent Components

| File | Purpose |
|------|---------|
| `FolioEntryTable.vue` | `v-if="entry.can_void && !entry.is_system_entry"` for void button (ADR-50) |
| `AddPaymentForm.vue` | Deposit/payment/refund/adjustment; reads `refundableMax` from `paymentSummary` (ADR-51) |
| `PaymentTable.vue` | Delete via `router.delete()`; `const props = defineProps<...>()` for script access |

### Wave 5 — Panel Assembly

| File | Purpose |
|------|---------|
| `FolioPanel.vue` | Assembles all folio/payment sub-components; close/reopen via `router.patch()` |
| `RoomBoardPanel.vue` | Extracted stays table; `checkoutDisabled()` UX only (ADR-52); last-stay confirmation modal |

### Wave 6 — Show.vue Integration

**Removed from `Show.vue` (~300 lines cleaned):**
- Inline payments tab section (replaced by `<FolioPanel>`)
- Inline stays table section (replaced by `<RoomBoardPanel>`)
- Void entry modal
- Checkout payment warning modal
- `showAddChargeForm`, `showVoidModal`, `voidingEntry`, `voidReasonInput`, `checkoutWarningStay` refs
- `useForm` instances: `paymentForm`, `chargeForm`
- Functions: `submitPayment`, `checkIn`, `checkOut`, `checkInAll`, `checkOutAll`, `closeCheckoutWarning`, `confirmCheckoutWithWarning`, `deletePayment`, `submitCharge`, `openVoidModal`, `closeVoidModal`, `confirmVoid`, `closeFolio`, `reopenFolio`, `folioStatusLabel`
- Computed: `activeStays`, `chargeBreakdown`, `roomChargeBreakdown`, `chargeFormTotal`
- Unused icons: `Banknote`, `LogIn`, `LogOut`, `Unlock`

**Added:**
```js
const hasActiveStays = computed(() =>
    (props.booking.stays ?? []).some((stay) => stay.status !== 'CANCELLED' && !stay.is_released)
);
```

### Wave 7 — Tests

| File | Tests | Key Scenarios |
|------|-------|---------------|
| `tests/Feature/FolioUiTest.php` | 7 | ADR-50 system entry void guard; flash error vs. `withErrors()` separation; is_system_entry payload; folio close/reopen |
| `tests/Feature/PaymentUiTest.php` | 5 | Deposit add; amount validation; delete; terminal booking flash error; payment_summary in payload |
| `tests/Feature/CheckoutUiTest.php` | 5 | OBE → payments tab redirect (ADR-53); status unchanged on OBE; zero-balance success; auth; 404 for nonexistent stay |

**Updated:**
- `tests/Feature/FolioCrudTest.php` line 123: `assertSessionHasErrors()` → `assertSessionHas('error')` (consequence of Wave 2 `AlreadyVoidedException` fix)

---

## 5. Test Results

```
Tests: 17 passed (new Phase 3.1B2 tests)
Tests: 303 passed, 25 failed (full suite)
```

**25 pre-existing failures** — all in `RoomAvailabilityCheckerTest` and `RoomBoardTest`. Confirmed pre-existing by running those test files against the base commit before Phase 3.1B2 stash. **Zero new regressions introduced.**

---

## 6. Known Constraints & Non-Changes

- **`FolioEntryPolicy::create()`** gates add-charge on folio status (`Open`). Policy returns false for closed folios → 403. `FolioClosedException` from service layer is a secondary defense never reached via the controller path. The test `charge_cannot_be_added_to_closed_folio` correctly asserts 403.
- **`SystemEntryVoidException`** was created in a prior session (not Wave 2) and is referenced by `FolioService::voidEntry()`.
- `StayController.php` is in the modified list (Wave 2 area — `OutstandingBalanceException` per-controller catch for ADR-53 redirect).
- `FolioService.php` is in the modified list (system entry void guard ADR-50).

---

## 7. Files Changed

### PHP — Modified
- `app/Exceptions/AlreadyVoidedException.php`
- `app/Exceptions/BookingTerminalException.php`
- `app/Exceptions/FolioClosedException.php`
- `app/Exceptions/FolioVoidedException.php`
- `app/Http/Controllers/Admin/Booking/BookingController.php`
- `app/Http/Controllers/Admin/Booking/StayController.php`
- `app/Services/FolioService.php`
- `tests/Feature/FolioCrudTest.php`

### PHP — New
- `app/Exceptions/SystemEntryVoidException.php`

### Vue — New
- `resources/js/Pages/Admin/Bookings/Partials/FolioStatusBadge.vue`
- `resources/js/Pages/Admin/Bookings/Partials/AddChargeForm.vue`
- `resources/js/Pages/Admin/Bookings/Partials/VoidEntryDialog.vue`
- `resources/js/Pages/Admin/Bookings/Partials/PaymentSummary.vue`
- `resources/js/Pages/Admin/Bookings/Partials/CheckoutReadinessBanner.vue`
- `resources/js/Pages/Admin/Bookings/Partials/FolioEntryTable.vue`
- `resources/js/Pages/Admin/Bookings/Partials/AddPaymentForm.vue`
- `resources/js/Pages/Admin/Bookings/Partials/PaymentTable.vue`
- `resources/js/Pages/Admin/Bookings/Partials/FolioPanel.vue`
- `resources/js/Pages/Admin/Bookings/Partials/RoomBoardPanel.vue`

### Vue — Modified
- `resources/js/Pages/Admin/Bookings/Show.vue`

### Tests — New
- `tests/Feature/FolioUiTest.php`
- `tests/Feature/PaymentUiTest.php`
- `tests/Feature/CheckoutUiTest.php`

---

## 8. Pending Before Commit

- [ ] ChatGPT architecture/code review
- [ ] Address any CRITICAL or HIGH review findings
- Only then: `git add` + `git commit`
