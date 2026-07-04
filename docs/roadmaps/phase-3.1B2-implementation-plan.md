# Phase 3.1B2 — Implementation Plan

**Status:** Ready for ChatGPT Final Implementation Review  
**Date:** 2026-07-01  
**Branch:** phase-3  
**Parent Document:** `phase-3.1B2-folio-checkout-ui.md` (Architecture v2)

---

## Pre-Implementation Code Audit Findings

Before designing the implementation, all relevant source files were read. Several architecture document assumptions were found to be **already resolved** in the existing codebase:

| Architecture Issue | Actual Code State | Impact |
|---|---|---|
| High #3 — `payment_summary` missing from payload | `BookingController::bookingPayload()` line 285: `'payment_summary' => $this->bookings->paymentSummary($booking)` | **RESOLVED — already implemented** |
| High #4 — No FlashMessage infrastructure | `HandleInertiaRequests::share()` lines 33-36 shares `flash.success` and `flash.error`. `AppLayout.vue` lines 95-100 renders both | **RESOLVED — already implemented** |
| Folio entries missing from payload | `BookingController::folioPayload()` lines 509-524 maps full entry list including `can_void` | **RESOLVED — but `is_system_entry` field is missing** |
| Critical #1 — OBE causes HTTP 500 | `OutstandingBalanceException::render()` exists — it returns `back()->withErrors(['checkout' => message])`. Not a 500. | **Partially mitigated — but wrong session key (withErrors not with('error'))** |

### Accurate Root Cause: Critical Issue #1

`OutstandingBalanceException::render()` redirects back using `withErrors(['checkout' => ...])`.  
This puts the error in `$page.props.errors.checkout` (Inertia's validation error sharing), not in `$page.props.flash.error` (which AppLayout renders).

Result: The checkout redirect succeeds, but **the error message is invisible to the user** — no Vue component reads `$page.props.errors.checkout`. From the user's perspective, the checkout button appears to have done nothing.

---

## Critical Issue 1 — OutstandingBalanceException Handling

### Current State

```php
// StayController::checkOut() — current
public function checkOut(CheckOutStayRequest $request, Booking $booking, Stay $stay): RedirectResponse
{
    $this->authorize('checkOut', $stay);
    abort_unless($stay->booking_id === $booking->id, 404);

    $this->stays->checkOut($stay, $request->validated('actual_checkout_at'));

    return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])
        ->with('success', 'Đã trả phòng.');
}
```

When `checkOut()` throws `OutstandingBalanceException`:
1. Laravel calls `OutstandingBalanceException::render()`
2. `render()` returns `back()->withErrors(['checkout' => $message])`
3. Inertia shares `$page.props.errors.checkout` — NOT `$page.props.flash.error`
4. AppLayout renders `flash.error` only → **error is invisible**
5. User sees the same page with no feedback

### Designed Sequence: Current (broken)

```
User clicks "Check Out"
  → POST /admin/bookings/{booking}/stays/{stay}/check-out
    → StayController::checkOut()
      → StayService::checkOut()
        → finaliseBookingCheckout()          [balance_due > 0]
          → throws OutstandingBalanceException($balanceDue)
        ← exception bubbles through checkOut()
        ← exception bubbles through StayController (unhandled)
      ← Laravel global handler calls OBE::render()
    ← back()->withErrors(['checkout' => message])
  ← HTTP 302 to referer (room_map tab)
→ Inertia GET /admin/bookings/{booking}?tab=room_map
  → $page.props.errors.checkout = message     ← written here
  → $page.props.flash.error = null            ← AppLayout reads THIS
  → AppLayout renders NO error message        ← SILENT FAILURE
```

### Designed Sequence: After Fix

```
User clicks "Check Out"
  → POST /admin/bookings/{booking}/stays/{stay}/check-out
    → StayController::checkOut() [with try/catch]
      → StayService::checkOut()
        → finaliseBookingCheckout()          [balance_due > 0]
          → throws OutstandingBalanceException($balanceDue)
      ← catch (OutstandingBalanceException $e)
        → redirect to bookings.show?tab=payments
          with('error', 'Không thể trả phòng: còn số dư ... — Vui lòng thanh toán trên tab Tài chính.')
    ← HTTP 302 to /admin/bookings/{id}?tab=payments
  → Inertia GET /admin/bookings/{id}?tab=payments
    → HandleInertiaRequests::share()
      → flash.error = session('error')        ← written here
    → AppLayout reads page.props.flash.error  ← renders red bar
  → User sees: payments tab + red error banner with amount ← VISIBLE, ACTIONABLE
```

### File Changes — Critical Issue 1

#### File 1: `app/Http/Controllers/Admin/Booking/StayController.php`

**Change:** Add `OutstandingBalanceException` import and try/catch in `checkOut()`.

```
BEFORE:
    $this->stays->checkOut($stay, $request->validated('actual_checkout_at'));

    return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])
        ->with('success', 'Đã trả phòng.');

AFTER:
    use App\Exceptions\OutstandingBalanceException;  ← add to imports

    try {
        $this->stays->checkOut($stay, $request->validated('actual_checkout_at'));
    } catch (OutstandingBalanceException $e) {
        return redirect()
            ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'payments'])
            ->with('error', 'Không thể trả phòng: ' . $e->getMessage()
                . ' Vui lòng thanh toán trên tab Tài chính.');
    }

    return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])
        ->with('success', 'Đã trả phòng.');
```

**Decision rationale:**
- Per-controller catch (ADR-53) — preserves context-specific message and tab redirect
- Uses `with('error', ...)` to flow through existing AppLayout flash infrastructure
- Redirects to `?tab=payments` — most actionable destination (user can immediately pay)
- `OutstandingBalanceException::render()` is preserved as the global fallback for non-web contexts

**What does NOT change:** `OutstandingBalanceException::render()` remains as-is — it handles non-controller invocations (Artisan commands, queue jobs, service tests). The controller catch shadows `render()` only for this HTTP request path.

---

## Critical Issue 2 — System Folio Entry Protection

### Current State

```php
// FolioService::voidEntry() — current
public function voidEntry(FolioEntry $entry, string $reason, User $voidedBy): void
{
    DB::transaction(function () use ($entry, $reason, $voidedBy): void {
        $folio = Folio::lockForUpdate()->find($entry->folio_id);

        if ($folio !== null && $folio->status !== FolioStatus::Open) {
            if ($folio->status === FolioStatus::Voided) {
                throw new FolioVoidedException();
            }
            throw new FolioClosedException();
        }

        $locked = FolioEntry::lockForUpdate()->findOrFail($entry->id);

        if ($locked->voided_at !== null) {
            throw new AlreadyVoidedException();
        }

        $locked->update([...]);    // ← NO posting_key guard → system entries can be voided
    });
}
```

**Exploit path:**
```
1. Direct API call: PATCH /admin/bookings/{id}/folio/entries/{systemEntryId}
   Body: { void_reason: "hack" }
2. FolioEntryPolicy::void() checks: user has charge.void permission + entry created today
   → PASSES (system entry posted at check-in, same day)
3. FolioService::voidEntry() called with system entry
4. No posting_key check → system room charge entry voided
5. calculateGuardedFolioTotal():
   → systemRoomPosted = false (entry now voided)
   → falls back to requirements_estimate
   → BUT: if non-room entries exist, they are still included in non-room sum
   → BALANCE CORRUPTION (voided room charge + non-room charges double-counted)
6. finaliseBookingCheckout() may now pass with incorrect balance_due
```

**Why this MUST be in the service, not the policy:**
- `FolioEntryPolicy::void()` answers: "Does this user have permission to perform a void?"
- The posting_key rule answers: "Is this action valid regardless of who is performing it?"
- System entry protection is a domain invariant (always false, for anyone, ever)
- Policies are bypassed by Artisan commands, queue jobs, and direct service calls
- Only the service is called in all code paths

### New Exception: `SystemEntryVoidException`

```
Class:     App\Exceptions\SystemEntryVoidException
Extends:   RuntimeException
Message:   "Không thể huỷ mục phí hệ thống. Mục này được tạo tự động và không thể xoá."
render():  back()->with('error', $this->getMessage())
```

**Design decisions:**
- Uses `with('error', ...)` → flows through AppLayout flash display automatically
- `render()` method handles the fallback for any non-controller invocation
- No controller-level catch needed — `render()` is the correct pattern here since
  `FolioEntryController::void()` already redirects to `?tab=payments` on success,
  and `back()` from the void request IS `?tab=payments` (where the button was rendered)

### Service Invariant Guard Design

```
POSITION: Before the DB::transaction block — pure read check, zero transaction overhead.

REASON: posting_key is set at entry creation and never mutated. Reading from the passed
        $entry object is safe. No lock is required to check an immutable field.

RULE:   If $entry->posting_key !== null → throw SystemEntryVoidException
        This fires before any DB lock, before any write, before any state check.
```

### Sequence Diagram: After Fix

```
User attempts void on system room charge (even via direct API call):
  → PATCH /admin/bookings/{id}/folio/entries/{systemEntryId}
    → FolioEntryController::void()
      → authorize('void', $entry)              ← policy: permission check PASSES
        (policy does not check posting_key — it is not the policy's concern)
      → FolioService::voidEntry($entry, ...)
        → if ($entry->posting_key !== null)    ← invariant guard fires FIRST
          → throw SystemEntryVoidException()   ← exits before any DB transaction
      ← SystemEntryVoidException bubbles up (no controller catch)
    ← Laravel calls SystemEntryVoidException::render()
  ← back()->with('error', message)
← HTTP 302 back to payments tab
→ AppLayout renders flash.error: "Không thể huỷ mục phí hệ thống."
```

### File Changes — Critical Issue 2

#### File 2: `app/Exceptions/SystemEntryVoidException.php` — NEW

```
Properties:
  - __construct(): calls parent with Vietnamese message
  - render(): RedirectResponse — back()->with('error', $this->getMessage())

Implements: RuntimeException
```

#### File 3: `app/Services/FolioService.php`

**Change:** Add guard as first statement in `voidEntry()`, before the DB::transaction.

```
BEFORE:
    public function voidEntry(FolioEntry $entry, string $reason, User $voidedBy): void
    {
        DB::transaction(function () use ($entry, $reason, $voidedBy): void {
            // ADR-12 canonical lock order ...
            $folio = Folio::lockForUpdate()->find($entry->folio_id);
            ...

AFTER:
    public function voidEntry(FolioEntry $entry, string $reason, User $voidedBy): void
    {
        // ADR-50: system entries are a domain invariant — never voidable by anyone.
        // This check belongs in the service, not the policy (policy handles authorization).
        // posting_key is immutable after creation — safe to read without a lock.
        if ($entry->posting_key !== null) {
            throw new SystemEntryVoidException();
        }

        DB::transaction(function () use ($entry, $reason, $voidedBy): void {
            // ADR-12 canonical lock order ...
            $folio = Folio::lockForUpdate()->find($entry->folio_id);
            ...
```

Add import: `use App\Exceptions\SystemEntryVoidException;`

#### File 4: `app/Http/Controllers/Admin/Booking/BookingController.php`

**Change:** Add `is_system_entry` field to `folioPayload()` entry map (1 line).

```
BEFORE (in folioPayload() entries map):
    'can_void'          => request()->user()?->can('void', $entry) ?? false,

AFTER:
    'is_system_entry'   => $entry->posting_key !== null,
    'can_void'          => request()->user()?->can('void', $entry) ?? false,
```

**Note:** `can_void` from the Policy is correct for authorization display. The `is_system_entry` flag is a separate display hint that also allows the Vue template to render a "Hệ thống" label in the posted_by column (per architecture §6.4). `FolioService::voidEntry()` is the authoritative guard regardless of what `can_void` returns.

---

## Phase 3.1B2 Full File-by-File Implementation Plan

### PHP Changes (5 files)

| # | File | Action | Scope |
|---|---|---|---|
| P-1 | `app/Exceptions/SystemEntryVoidException.php` | **CREATE** | New domain exception |
| P-2 | `app/Services/FolioService.php` | **MODIFY** | Add posting_key guard to `voidEntry()` |
| P-3 | `app/Http/Controllers/Admin/Booking/StayController.php` | **MODIFY** | Add OBE catch in `checkOut()` |
| P-4 | `app/Http/Controllers/Admin/Booking/BookingController.php` | **MODIFY** | Add `is_system_entry` to `folioPayload()`; verify `can.checkOut` is included in show payload |
| P-5 | `app/Http/Controllers/Admin/Booking/FolioEntryController.php` | **VERIFY** | No change needed — response format already correct (redirects to payments tab with flash) |

**Already complete (no changes needed):**
- `app/Http/Middleware/HandleInertiaRequests.php` — flash.success / flash.error already shared
- `app/Http/Controllers/Admin/Booking/FolioController.php` — close/reopen responses already correct
- `app/Http/Controllers/Admin/Booking/BookingPaymentController.php` — verify response format

### Vue Changes (11 files)

| # | File | Action | Parent |
|---|---|---|---|
| V-1 | `resources/js/Pages/Admin/Bookings/Partials/FolioStatusBadge.vue` | **CREATE** | Props: `status: string` |
| V-2 | `resources/js/Pages/Admin/Bookings/Partials/FolioEntryTable.vue` | **CREATE** | Props: `entries[]`, `bookingId` |
| V-3 | `resources/js/Pages/Admin/Bookings/Partials/VoidEntryDialog.vue` | **CREATE** | Props: `entry`, `bookingId`; Emits: `close` |
| V-4 | `resources/js/Pages/Admin/Bookings/Partials/AddChargeForm.vue` | **CREATE** | Props: `bookingId`, `chargeTypes[]` |
| V-5 | `resources/js/Pages/Admin/Bookings/Partials/PaymentSummary.vue` | **CREATE** | Props: `paymentSummary` |
| V-6 | `resources/js/Pages/Admin/Bookings/Partials/AddPaymentForm.vue` | **CREATE** | Props: `bookingId`, `paymentTypes[]`, `paymentMethods[]`, `paymentSummary` |
| V-7 | `resources/js/Pages/Admin/Bookings/Partials/PaymentTable.vue` | **CREATE** | Props: `payments[]`, `bookingId` |
| V-8 | `resources/js/Pages/Admin/Bookings/Partials/CheckoutReadinessBanner.vue` | **CREATE** | Props: `paymentSummary`, `bookingStatus` |
| V-9 | `resources/js/Pages/Admin/Bookings/Partials/FolioPanel.vue` | **CREATE** | Assembles V-1 through V-7 |
| V-10 | `resources/js/Pages/Admin/Bookings/Partials/RoomBoardPanel.vue` | **MODIFY** | Add balance gate + confirmation modal to checkout button |
| V-11 | `resources/js/Pages/Admin/Bookings/Show.vue` | **MODIFY** | Wire FolioPanel, CheckoutReadinessBanner; pass paymentSummary to RoomBoardPanel |

**Already complete (no changes needed):**
- `resources/js/Layouts/AppLayout.vue` — flash.success / flash.error already rendered (lines 95-100)

### Test Changes (3 files)

| # | File | Action |
|---|---|---|
| T-1 | `tests/Feature/Booking/FolioUiTest.php` | **CREATE** |
| T-2 | `tests/Feature/Booking/PaymentUiTest.php` | **CREATE** |
| T-3 | `tests/Feature/Booking/CheckoutUiTest.php` | **CREATE** |

---

## Exception Flow Reference

### Complete Domain Exception → UI Path

```
Exception                    │ Thrown In                    │ Caught In              │ Session Key  │ AppLayout?
─────────────────────────────┼──────────────────────────────┼────────────────────────┼──────────────┼───────────
OutstandingBalanceException  │ finaliseBookingCheckout()    │ StayController (AFTER) │ flash.error  │ YES ✓
SystemEntryVoidException     │ FolioService::voidEntry()    │ render() fallback      │ flash.error  │ YES ✓
FolioClosedException         │ FolioService::addCharge()    │ render() fallback      │ flash.error  │ NEEDS render()
FolioVoidedException         │ FolioService::addCharge()    │ render() fallback      │ flash.error  │ NEEDS render()
AlreadyVoidedException       │ FolioService::voidEntry()    │ render() fallback      │ flash.error  │ NEEDS render()
BookingTerminalException     │ FolioService::addCharge()    │ render() fallback      │ flash.error  │ NEEDS render()
RefundExceedsMaxException    │ BookingPaymentService        │ render() fallback      │ flash.error  │ NEEDS render()
```

**Design note:** Exceptions marked "NEEDS render()" must have a `render()` method added if they don't already have one. Each should return `back()->with('error', $this->getMessage())`. This is a pre-implementation audit task (verify which exceptions already have `render()` vs. which need it added).

---

## Sequence Diagrams

### Normal Checkout (happy path)

```
User: [balance = 0, has CheckedIn stay]
  → Click "Check Out" → confirmation modal → confirm
  → POST /stays/{stay}/check-out
    → StayController::checkOut()                         [try block]
      → StayService::checkOut($stay, $checkout_at)
        → Booking::lockForUpdate()                        [acquire Booking lock]
        → (last stay) finaliseBookingCheckout($booking)
          → balance_due = 0                               [passes]
          → autoCloseFolio($folio)
          → booking.status → CheckedOut
        → stay.status → CheckedOut, stay.actual_checkout_at = now
        → RoomAssignment.status → CheckedOut
    ← no exception
    → redirect bookings.show?tab=room_map
      with('success', 'Đã trả phòng.')
  ← HTTP 302 → GET /bookings/{id}?tab=room_map
    → flash.success rendered in AppLayout
    → booking.status = CheckedOut (page reflects terminal state)
```

### Failed Checkout (balance > 0)

```
User: [balance_due > 0, has CheckedIn stay]
  → (client-side) checkoutDisabled = true, button disabled with tooltip
  → User sends direct API request (or balance changed concurrently)
  → POST /stays/{stay}/check-out
    → StayController::checkOut()                         [try block]
      → StayService::checkOut($stay, $checkout_at)
        → finaliseBookingCheckout($booking)
          → balance_due > 0
          → throw OutstandingBalanceException($balanceDue)
      ← OutstandingBalanceException caught by StayController
      → redirect bookings.show?tab=payments
        with('error', 'Không thể trả phòng: 1.200.000 đ. Vui lòng thanh toán...')
  ← HTTP 302 → GET /bookings/{id}?tab=payments
    → flash.error rendered in AppLayout (red bar)
    → User sees payments tab, outstanding balance, knows what to do
```

### System Entry Void Attempt (bypass scenario)

```
User: [has charge.void permission, entry created today]
  → PATCH /folio/entries/{systemEntryId}
    Body: { void_reason: "attempt" }
    → FolioEntryController::void()
      → authorize('void', $entry)               [policy: PASSES — permission OK]
      → FolioService::voidEntry($entry, ...)
        → if ($entry->posting_key !== null)     [guard fires FIRST, pre-transaction]
          → throw SystemEntryVoidException()
      ← SystemEntryVoidException bubbles to framework
    ← framework calls SystemEntryVoidException::render()
  ← back()->with('error', 'Không thể huỷ mục phí hệ thống.')
← HTTP 302 → back to payments tab
  → flash.error rendered in AppLayout
  → System room charge entry unchanged
  → calculateGuardedFolioTotal() result unchanged
```

---

## Regression Risks (Updated)

| Risk | Severity | Mitigation | Status |
|---|---|---|---|
| R-01: calculateGuardedFolioTotal() bypassed by system entry void | CRITICAL | FolioService::voidEntry() posting_key guard (P-2) | ADDRESSED in this plan |
| R-02: OBE causes silent redirect (invisible error) | CRITICAL | StayController catch + flash.error redirect to payments tab (P-3) | ADDRESSED in this plan |
| R-03: posting_key or amount accepted from HTTP | HIGH | StoreFolioEntryRequest already prohibits — verify in pre-impl audit | VERIFY |
| R-04: Folio reopen on terminal booking | HIGH | can_reopen in folioPayload delegates to FolioPolicy::reopen() — verify policy checks isTerminal | VERIFY |
| R-05: Delete payment on terminal booking | HIGH | Verify BookingTerminalException thrown by deletePayment() service | VERIFY |
| R-06: System entry void via UI (before fix) | MEDIUM | is_system_entry flag hides button (display). Service guard (P-2) is authoritative | ADDRESSED in this plan |
| R-07: Multi-stay concurrent checkout race | MEDIUM | Booking::lockForUpdate() in finaliseBookingCheckout() — existing, correct | COVERED by ADR-38 |
| R-08: refundable_max client hint diverges from server | LOW | Hint only; server enforces via RefundExceedsMaxException | ACCEPTED |
| R-09: balance_due display stale after mutation | LOW | router.reload({ only: ['booking', 'paymentSummary'] }) after every mutation | IN SCOPE (V-11) |
| R-10: Other domain exceptions without render() | MEDIUM | Audit all exceptions in app/Exceptions/ for render() method before implementation | PRE-IMPL AUDIT |

---

## Test Cases

### T-1: FolioUiTest (Pest)

```
System Entry Protection (Critical #2):
  ✗ void_system_entry_is_rejected_by_service()
    → Arrange: CheckedIn booking, system room charge entry (posting_key = ROOM_CHARGE_*)
    → Act: PATCH /folio/entries/{systemEntryId} with valid void_reason, by admin
    → Assert: HTTP 302, flash.error = "Không thể huỷ mục phí hệ thống."
    → Assert: entry.voided_at IS NULL in database

  ✗ void_system_entry_does_not_corrupt_folio_total()
    → Arrange: CheckedIn booking, system room charge posted, balance_due = 1200000
    → Act: attempt void of system entry (direct service call, bypassing policy)
    → Assert: SystemEntryVoidException thrown before any DB write
    → Assert: calculateGuardedFolioTotal() unchanged

Folio Ledger:
  ✗ admin_can_add_charge_to_open_folio()
  ✗ charge_rejected_when_folio_is_closed()    → FolioClosedException → flash.error
  ✗ charge_rejected_when_folio_is_voided()    → FolioVoidedException → flash.error
  ✗ charge_rejected_on_terminal_booking()     → BookingTerminalException → flash.error
  ✗ void_entry_with_valid_reason_succeeds()
  ✗ void_entry_with_reason_under_5_chars_fails_validation()
  ✗ void_already_voided_entry_returns_error() → AlreadyVoidedException → flash.error
  ✗ admin_can_close_open_folio()
  ✗ non_admin_without_permission_cannot_close_folio()
  ✗ admin_can_reopen_closed_folio_on_nonterminal_booking()
  ✗ admin_cannot_reopen_folio_for_terminal_booking()
  ✗ non_admin_cannot_reopen_folio()
```

### T-2: PaymentUiTest (Pest)

```
  ✗ admin_can_add_deposit_booking_transitions_to_deposited()
  ✗ admin_can_add_room_payment()
  ✗ admin_can_add_refund_up_to_paid_total()
  ✗ refund_exceeding_paid_total_is_rejected()  → RefundExceedsMaxException → flash.error
  ✗ terminal_booking_blocks_payment_addition()  → BookingTerminalException → flash.error
  ✗ admin_can_delete_any_payment()
  ✗ non_admin_can_delete_own_payment_created_today()
  ✗ non_admin_cannot_delete_payment_from_yesterday()
```

### T-3: CheckoutUiTest (Pest)

```
OutstandingBalanceException Handling (Critical #1):
  ✗ checkout_with_outstanding_balance_redirects_to_payments_tab_with_error()
    → Arrange: CheckedIn booking, balance_due = 1200000
    → Act: POST /stays/{stay}/check-out
    → Assert: HTTP 302 to /admin/bookings/{id}?tab=payments
    → Assert: session('error') contains "Không thể trả phòng"
    → Assert: session('error') contains "1.200.000"
    → Assert: booking.status remains CheckedIn
    → Assert: folio.status remains Open

  ✗ checkout_with_zero_balance_succeeds()
    → Arrange: CheckedIn booking, balance_due = 0
    → Act: POST /stays/{stay}/check-out
    → Assert: HTTP 302 to /admin/bookings/{id}?tab=room_map
    → Assert: session('success') = 'Đã trả phòng.'
    → Assert: booking.status = CheckedOut
    → Assert: folio.status = Closed

  ✗ checkout_non_last_stay_succeeds_regardless_of_balance()
    → Arrange: booking with 2 CheckedIn stays, balance_due > 0
    → Act: POST /stays/{firstStay}/check-out
    → Assert: HTTP 302 to room_map with success (intermediate checkout, balance not checked)

  ✗ flash_error_message_is_shared_via_inertia_and_visible()
    → Arrange: failed checkout (balance > 0)
    → Act: follow redirect GET /bookings/{id}?tab=payments
    → Assert: $page.props.flash.error is not null (confirms HandleInertiaRequests shares it)

  ✗ concurrent_checkout_race_only_one_wins()
    → Arrange: booking with 1 CheckedIn stay, balance = 0
    → Act: two simultaneous checkout requests
    → Assert: exactly one succeeds (202), one fails (booking already CheckedOut)
```

---

## Implementation Order (Dependency-Aware)

```
WAVE 1 — Critical fixes (unblock all UI error flows):
  P-1  Create SystemEntryVoidException
  P-2  FolioService::voidEntry() posting_key guard
  P-3  StayController::checkOut() OBE catch
  P-4  BookingController::folioPayload() add is_system_entry field

WAVE 2 — Pre-Vue audit:
  Audit: Verify all domain exceptions in app/Exceptions/ have render()
  Audit: Verify FolioPolicy::reopen() checks isTerminal (R-04)
  Audit: Verify deletePayment() throws BookingTerminalException (R-05)
  Audit: Verify BookingPaymentController response format (redirects + flash)

WAVE 3 — Vue leaf components (no dependencies between them):
  V-1  FolioStatusBadge.vue
  V-4  AddChargeForm.vue
  V-3  VoidEntryDialog.vue
  V-5  PaymentSummary.vue
  V-8  CheckoutReadinessBanner.vue

WAVE 4 — Vue components with leaf dependencies:
  V-2  FolioEntryTable.vue   (uses VoidEntryDialog)
  V-6  AddPaymentForm.vue    (uses paymentSummary for refund cap)
  V-7  PaymentTable.vue

WAVE 5 — Vue assembly:
  V-9  FolioPanel.vue        (assembles folio ledger + payments section)
  V-10 RoomBoardPanel.vue    (add balance gate + confirmation modal)

WAVE 6 — Page wiring:
  V-11 Show.vue              (wire FolioPanel, CheckoutReadinessBanner, pass paymentSummary to RoomBoardPanel)

WAVE 7 — Tests:
  T-1  FolioUiTest
  T-2  PaymentUiTest
  T-3  CheckoutUiTest
```

---

## Pre-Implementation Audit Checklist

Before writing a single line of production code, verify:

- [ ] Do all domain exceptions in `app/Exceptions/` have `render()` returning `back()->with('error', ...)` ?
  Check: `AlreadyVoidedException`, `FolioClosedException`, `FolioVoidedException`,
  `BookingTerminalException`, `RefundExceedsMaxException`, `NegativeAdjustmentExceedsPaidException`
- [ ] Does `FolioPolicy::reopen()` check `!$booking->status->isTerminal()` ?
  If not, the service-layer guard in `reopenFolio()` is the safety net, but `can_reopen` flag in the UI may be incorrect
- [ ] Does `BookingPaymentService::deletePayment()` throw `BookingTerminalException` when booking is terminal ?
- [ ] Does `BookingPaymentController::store()` and `destroy()` use `redirect()->back()->with('success', ...)` consistently ?
- [ ] Does `FolioEntryPolicy::void()` NOT check `posting_key` ? (Correct behavior — policy should not check this)

---

## Summary

**Critical Issues (2 — Addressed in this plan):**

1. **`OutstandingBalanceException` causes silent redirect** — `StayController::checkOut()` has no try/catch. The exception's `render()` method uses `withErrors()` which writes to `$page.props.errors.checkout` (not `flash.error`). AppLayout only renders `flash.error` → error is invisible. **Fix:** Per-controller catch, redirect to `?tab=payments` with `with('error', ...)`.

2. **`FolioService::voidEntry()` has no system entry guard** — system room charge entries (posting_key != null) can be voided via a direct API call, corrupting `calculateGuardedFolioTotal()`. **Fix:** Add `SystemEntryVoidException` and posting_key guard as the first statement in `voidEntry()`, before any DB transaction.

**High Issues (2 — Already resolved in codebase, confirmed by audit):**

3. ~~`payment_summary` missing from payload~~ — **RESOLVED.** `BookingController::bookingPayload()` line 285 already includes `$this->bookings->paymentSummary($booking)`.

4. ~~No FlashMessage infrastructure~~ — **RESOLVED.** `HandleInertiaRequests::share()` already shares `flash.success`/`flash.error`. `AppLayout.vue` lines 95-100 already renders both. No `FlashMessage.vue` component is needed; flash is rendered inline in AppLayout.

**Medium Issues (2 — In scope for Phase 3.1B2 implementation):**

5. **Refund max cap hint not shown** — Addressed by `AddPaymentForm.vue` reading `paymentSummary.paid_total` and `paymentSummary.total_charges` for the advisory hint display.

6. **Client-side amount preview uses JS floating-point** — Documented and accepted. Preview is advisory only, labelled as such. Server value is authoritative (ADR-16).

**Ready For ChatGPT Final Implementation Review:** Yes — 2 critical issues fully designed with sequence diagrams and precise file changes. 2 high issues confirmed resolved by code audit. Full file-by-file plan with 5 PHP changes, 11 Vue changes, 3 test files, and implementation wave order defined. Pre-implementation audit checklist included.
