# Phase 3.1B1 – Checkout Integration Implementation Report

**Date**: 2026-06-30  
**Branch**: phase-3  
**Status**: Implementation complete — awaiting ChatGPT code review before commit

---

## Summary

Phase 3.1B1 implements the full checkout pipeline (ADR-38 → ADR-49) with canonical lock ordering, transaction ownership contracts, outstanding-balance enforcement, and terminal-booking guards across all four service layers.

Test results: **311 passed, 0 failed, 1917 assertions** (`php artisan test --no-coverage`).

---

## ADR Implementation Map

| ADR | Rule | File(s) changed |
|-----|------|-----------------|
| ADR-38 | Booking lock first in checkIn AND checkOut | `StayService.php` |
| ADR-39 | `finaliseBookingCheckout` runs in caller's transaction | `BookingService.php` |
| ADR-40 | Outstanding balance blocks checkout (`OutstandingBalanceException`) | `BookingService.php`, new exception |
| ADR-41 | Folio auto-closes atomically at last-stay checkout | `BookingService.php` → `FolioService::autoCloseFolio` |
| ADR-42 | `autoPostRoomCharge` called as idempotency guard in finalise | `BookingService.php` |
| ADR-43 | `paymentSummary` uses `calculateGuardedFolioTotal` | `BookingService.php` |
| ADR-44 | Single `BookingTerminalException` as service-layer terminal guard | new exception, `BookingPaymentService.php`, `FolioService.php` |
| ADR-45 | `reopenFolio` blocked on terminal bookings | `FolioService.php` |
| ADR-46 | All payment mutations acquire Booking lock first | `BookingPaymentService.php` |
| ADR-48 | `finaliseBookingCheckout` has no own transaction | `BookingService.php` |
| ADR-49 | Active stay = Reserved OR CheckedIn | `StayService.php` |

---

## Files Created

### `app/Exceptions/BookingTerminalException.php`
- ADR-44: single terminal guard exception for all service-layer mutations on terminal bookings.
- `render()` returns `back()->withErrors(['booking' => ...])` for HTTP context.

### `app/Exceptions/OutstandingBalanceException.php`
- ADR-40: thrown by `finaliseBookingCheckout` when `balanceDue > 0`.
- Carries `getBalanceDue(): float` for caller inspection.
- `render()` returns `back()->withErrors(['checkout' => ...])`.

### `tests/Feature/CheckoutIntegrationTest.php`
- 11 integration tests covering ADR-38 to ADR-49.
- Tests: full checkout with zero balance, folio auto-close, outstanding balance blocks (no payment / partial payment), blocked checkout leaves state unchanged, all 4 payment mutations blocked on terminal booking, folio charge blocked, folio reopen blocked, reserved stay prevents premature finalisation, partial checkout sets `PartiallyCheckedOut`.

---

## Files Modified

### `app/Services/StayService.php`

**`checkIn()`** — ADR-38 Booking-first lock:
```php
// ADR-38: Booking lock FIRST — canonical order: Booking → Stay → RoomAssignment.
$lockedBooking = Booking::whereKey($stay->booking_id)->lockForUpdate()->firstOrFail();
$lockedStay    = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();
$assignment    = RoomAssignment::whereKey($lockedStay->room_assignment_id)->lockForUpdate()->firstOrFail();
```

**`checkOut()`** — ADR-38, ADR-39, ADR-49:
```php
$lockedBooking = Booking::whereKey($stay->booking_id)->lockForUpdate()->firstOrFail();
// ... stay + RA locks ...

// ADR-49: Active stay = Reserved OR CheckedIn (DML already visible within transaction).
$remainingActive = Stay::where('booking_id', $lockedBooking->id)
    ->whereIn('status', [StayStatus::Reserved, StayStatus::CheckedIn])
    ->count();

if ($remainingActive === 0) {
    $this->bookings->finaliseBookingCheckout($lockedBooking); // ADR-48: no own transaction
} else {
    $this->bookings->updateBookingStayStatus($lockedBooking);
}
```

### `app/Services/BookingService.php`

**New method `finaliseBookingCheckout(Booking $booking, ?User $user = null): void`** (ADR-39, ADR-40, ADR-41, ADR-42):
- Does NOT open its own transaction (ADR-48); caller must hold Booking lock (ADR-39).
- Calls `autoPostRoomCharge()` as idempotency guard (ADR-42).
- Locks Folio, then locks all BookingPayments via `lockForUpdate()`.
- Computes `balanceDue = totalCharges − paidTotal` using bcmath.
- Throws `OutstandingBalanceException` if `balanceDue > 0` (ADR-40).
- Calls `autoCloseFolio` under Folio lock (ADR-41).
- Sets `booking.status = BookingStatus::CheckedOut`.

**`paymentSummary()`** — ADR-43: replaced `getFolioTotal()` with `calculateGuardedFolioTotal()`.

**`updateBookingStayStatus()`** — removed `CheckedOut` arm (finalise exclusively owns that transition per ADR-39):
```php
$status = match (true) {
    $checkedOut > 0       => BookingStatus::PartiallyCheckedOut,
    $checkedIn === $total => BookingStatus::CheckedIn,
    $checkedIn > 0        => BookingStatus::PartiallyCheckedIn,
    default               => $booking->status,
};
```

### `app/Services/BookingPaymentService.php`

Complete rewrite — ADR-44 + ADR-46 applied to all 4 mutations:

- **`addDeposit`**: `DB::transaction` → `Booking::lockForUpdate()` → `assertBookingNotTerminal()` → insert → conditional Deposited status update.
- **`addPayment`**: `DB::transaction` → `Booking::lockForUpdate()` → `assertBookingNotTerminal()` → insert.
- **`addRefund`**: `DB::transaction` → `Booking::lockForUpdate()` → `assertBookingNotTerminal()` → `calculatePaidTotal()` with `lockForUpdate` on payments → validate amount → insert.
- **`deletePayment`**: `DB::transaction` → `Booking::lockForUpdate()` → `assertBookingNotTerminal()` → `BookingPayment::lockForUpdate()` → delete.

New private helpers:
- `assertBookingNotTerminal(Booking $booking): void` — throws `BookingTerminalException` if `$booking->status->isTerminal()`.
- `insertPayment(Booking $booking, array $data): BookingPayment` — canonical payment creation via relation.
- `calculatePaidTotal(Booking $booking): float` — uses `lockForUpdate()` on payments query.

### `app/Services/FolioService.php`

**`addCharge()`** — ADR-44/ADR-45: after Folio lock, loads Booking and throws `BookingTerminalException` before folio status checks.

**`reopenFolio()`** — ADR-45: wrapped in `DB::transaction`, locks Folio, loads Booking, throws `BookingTerminalException` if terminal.

**`autoCloseFolio()`** — signature changed from `User $closedBy` to `?User $closedBy` to support null user in `finaliseBookingCheckout`.

---

## Test Fixes

### Existing tests updated

**`tests/Feature/FolioCrudTest.php`**  
`test_balance_due_equals_total_charges_minus_paid_total`: replaced `FolioEntry::factory()->create(...)` with `autoPostRoomCharge()`. Reason: factory creates `ChargeType::Other` entries (no `posting_key`), which causes `calculateGuardedFolioTotal` to double-count (estimate + non-room entry). After `autoPostRoomCharge`, `systemRoomPosted = true` → raw sum = 800000 ✓.

**`tests/Feature/PaymentCrudTest.php`**  
`test_deposit_blocked_on_checked_out_booking` (renamed from `test_deposit_does_not_downgrade_checked_out_booking_status`): now asserts `BookingTerminalException` instead of status non-downgrade.

**`tests/Feature/BookingEngineFoundationTest.php`**  
`test_booking_does_not_finalize_checkout_when_balance_remains`: updated to `$this->expectException(OutstandingBalanceException::class)` — ADR-40 rolls back the entire transaction rather than setting `PartiallyCheckedOut`.

**`tests/Feature/BookingManagementUiTest.php`**  
Added `payInFull(Booking $booking, int $amount = 1800): void` helper (direct Eloquent insert, bypasses service locks for test convenience).  
7 tests updated to call `$this->payInFull($booking)` between check-in and check-out (room_price=1800 → charge posted at check-in via `autoPostRoomCharge`, balance must be cleared for checkout to succeed):
- `test_admin_can_check_out_stay`
- `test_checked_out_assignment_cannot_be_released`
- `test_release_action_hidden_for_checked_out_assignment`
- `test_checked_out_assignment_cannot_check_in_again`
- `test_checked_out_assignment_cannot_check_out_again`
- `test_checked_out_current_booking_assignment_not_in_active_board`
- `test_checkout_succeeds_when_balance_is_zero`

**`tests/Feature/CheckoutIntegrationTest.php`**  
Added `$this->travelTo('2026-07-01 14:00:00')` to `setUp()` so that `checkIn()` with `planned_checkin_at = 2026-07-01 14:00:00` does not fail the "not yet time" guard.

---

## Canonical Lock Order (ADR-38)

```
Booking → Stay → RoomAssignment → Folio → FolioEntry → BookingPayment
```

All service methods that touch multiple of these resources acquire locks in this order to prevent deadlocks.

---

## Transaction Ownership Contract (ADR-39/ADR-48)

`finaliseBookingCheckout` is a nested operation: it executes within the transaction opened by `StayService::checkOut`. The caller is responsible for:
1. Opening the outer `DB::transaction`.
2. Acquiring the Booking lock before calling `finaliseBookingCheckout`.

`finaliseBookingCheckout` must NOT open its own transaction (that would silently start a nested savepoint, breaking the rollback guarantee needed by ADR-40).

---

## Known Design Decisions

1. **`payInFull` uses direct Eloquent** in tests rather than `BookingPaymentService` to avoid the terminal guard interfering with test setup (the guard is correct behavior in production; bypassing it in test fixtures is intentional and scoped to the private helper).

2. **`autoCloseFolio` nullable user** — folio closure at checkout uses `Auth::id()` as fallback when no user is provided, consistent with other service patterns.

3. **bcmath for balance computation** — avoids floating-point precision errors on currency amounts.

---

## Test Run Results

```
Tests:    311 passed (1917 assertions)
Duration: 93.82s
Failures: 0
```

All pre-existing tests pass. 11 new integration tests added covering ADR-38 → ADR-49 end-to-end.

---

*Awaiting ChatGPT code review before commit.*
