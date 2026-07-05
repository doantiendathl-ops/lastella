# Phase 4.1 Backend Milestone 3
## HTTP Layer + Integration Hooks

**Date:** 2026-07-05
**Branch:** phase-3
**Milestone:** 3 of 4
**Status:** COMPLETE — READY FOR CHATGPT REVIEW

---

## Scope

Milestone 3 adds the HTTP layer (Form Request, Controller, Routes), the two integration hooks wired into existing services, and the Room Board pending count query. No frontend (Vue), no commits.

---

## Files Added

| File | Type | Description |
|---|---|---|
| `app/Http/Requests/Booking/StoreBookingSpecialRequestRequest.php` | Form Request | Validates category, request_type (catalog), quantity (1-99), note, stay_id (scoped to booking) |
| `app/Http/Controllers/Admin/Booking/BookingSpecialRequestController.php` | Controller | 5 actions: index, store, acknowledge, fulfill, destroy |
| `tests/Feature/SpecialRequestCrudTest.php` | Feature Tests | 24 tests covering validation, authorization, integration hooks, Room Board query |

---

## Files Modified

| File | Change | Scope |
|---|---|---|
| `routes/web.php` | Added 5 routes + `BookingSpecialRequestController` import | Additive only — no existing routes modified |
| `app/Services/BookingService.php` | Injected `SpecialRequestService` + added `autoCancelForBooking()` call inside `cancelBooking()` transaction | 5 lines added inside transaction body |
| `app/Services/StayService.php` | Injected `SpecialRequestService` + added `autoLinkSingleStayRequests()` call in `createStayFromAssignment()` | 4 lines added after `firstOrCreate` |
| `app/Models/BookingSpecialRequest.php` | Added `pendingCountByRoom()` static method + `DB`/`Collection` imports | Additive only |

No controller, policy, enum, migration, or Vue file from previous milestones was modified.

---

## Form Request Summary

**File:** `app/Http/Requests/Booking/StoreBookingSpecialRequestRequest.php`

### Authorization
```php
public function authorize(): bool
{
    return $this->user()?->can('special_request.create') ?? false;
}
```

Consistent with project pattern (`StoreFolioEntryRequest`, `StoreBookingPaymentRequest`).

### Validation Rules

| Field | Rules | Notes |
|---|---|---|
| `category` | required, string, `Rule::in(RequestCategory values)` | Enum-backed validation |
| `request_type` | required, string, `Rule::in(ALLOWED_REQUEST_TYPES)` | Catalog of 24 codes |
| `quantity` | required, integer, min:1, max:99 | |
| `note` | nullable, string, max:1000 | |
| `stay_id` | nullable, integer, `Rule::exists('stays', 'id')->where('booking_id', ...)` | Scoped to this booking — prevents cross-booking injection |

### ALLOWED_REQUEST_TYPES Catalog (24 codes)

```
bed_config:    twin_keep, twin_to_double, separate_beds, extra_bed
extra_item:    baby_cot, extra_pillow, non_feather_pillow, extra_blanket,
               extra_towel, welcome_fruit, welcome_amenity
decoration:    anniversary, honeymoon, birthday, vip_setup, flower_arrangement
accessibility: wheelchair, non_smoking_prep, ground_floor, near_elevator
general:       late_arrival, airport_pickup, connecting_room, other
```

---

## Controller Summary

**File:** `app/Http/Controllers/Admin/Booking/BookingSpecialRequestController.php`

| Action | Method | Authorization | Ownership Check |
|---|---|---|---|
| `index()` | GET | `special_request.*` (any) | — |
| `store()` | POST | `policy->create()` | Via FormRequest (scoped exists) |
| `acknowledge()` | PATCH | `policy->fulfill()` | `specialRequest->booking_id === booking->id` |
| `fulfill()` | PATCH | `policy->fulfill()` | `specialRequest->booking_id === booking->id` |
| `destroy()` | DELETE | `policy->cancel()` | `specialRequest->booking_id === booking->id` |

### Controller Contract
- No direct `BookingSpecialRequest::create()`, `->update()`, `->save()`, or `->delete()` calls.
- All writes delegate to `SpecialRequestService`.
- Redirects to `admin.bookings.show` with `tab=special_requests`.
- `ValidationException` from service is handled automatically by Laravel's exception handler (redirects back with errors).

---

## Routes Summary

**File:** `routes/web.php` — inside existing `Route::prefix('admin')->name('admin.')` group

| Method | URI | Name | Action |
|---|---|---|---|
| GET | `/admin/bookings/{booking}/special-requests` | `admin.bookings.special-requests.index` | `index()` |
| POST | `/admin/bookings/{booking}/special-requests` | `admin.bookings.special-requests.store` | `store()` |
| PATCH | `/admin/bookings/{booking}/special-requests/{specialRequest}/acknowledge` | `admin.bookings.special-requests.acknowledge` | `acknowledge()` |
| PATCH | `/admin/bookings/{booking}/special-requests/{specialRequest}/fulfill` | `admin.bookings.special-requests.fulfill` | `fulfill()` |
| DELETE | `/admin/bookings/{booking}/special-requests/{specialRequest}` | `admin.bookings.special-requests.destroy` | `destroy()` |

Route model binding: `{booking}` → `Booking`; `{specialRequest}` → `BookingSpecialRequest`.

All 5 routes verified via `php artisan route:list --name=special-requests`.

---

## Integration Hook Summary

### Hook 1: `BookingService::cancelBooking()` — inside `DB::transaction`

**Location:** After `$booking->update([...])`, before `voidFolioOnCancellation()`.

```php
// Phase 4.1: auto-cancel all pending/acknowledged special requests.
// autoCancelForBooking is a single UPDATE — no extra locks, no financial tables.
$this->specialRequests->autoCancelForBooking($booking, Auth::id());
```

**SpecialRequestService** injected via constructor:
```php
public function __construct(
    private readonly FolioService $folios,
    private readonly RoomAvailabilityRuleService $rules,
    private readonly SpecialRequestService $specialRequests, // NEW
) {}
```

**Why inside transaction:** If `cancelBooking()` rolls back (e.g., FolioHasActiveEntriesException), the request status changes also roll back — maintaining consistency between booking status and request statuses.

---

### Hook 2: `StayService::createStayFromAssignment()` — after `firstOrCreate`

**Location:** After `Stay::firstOrCreate(...)` returns, before `return $stay->load(...)`.

```php
// Phase 4.1: auto-link unlinked requests when booking has exactly one active stay.
// autoLinkSingleStayRequests never throws; any failure is logged and silently skipped.
$this->specialRequests->autoLinkSingleStayRequests($assignment->booking, $stay);
```

**SpecialRequestService** injected via constructor:
```php
public function __construct(
    // ... existing ...
    private readonly SpecialRequestService $specialRequests, // NEW
) {}
```

**Why non-throwing:** `createStayFromAssignment()` has no wrapping transaction. `autoLinkSingleStayRequests()` already wraps all logic in `try/catch` and logs failures — Stay creation is always safe.

---

## Room Board Query Summary

**Location:** `app/Models/BookingSpecialRequest::pendingCountByRoom()` (static method)

```php
public static function pendingCountByRoom(array $roomIds): Collection
{
    if (empty($roomIds)) {
        return collect();
    }

    return self::query()
        ->join('stays', 'stays.id', '=', 'booking_special_requests.stay_id')
        ->whereIn('booking_special_requests.status', ['pending', 'acknowledged'])
        ->whereIn('stays.room_id', $roomIds)
        ->groupBy('stays.room_id')
        ->select('stays.room_id', DB::raw('COUNT(*) as pending_count'))
        ->pluck('pending_count', 'room_id')
        ->map(fn ($count) => (int) $count);
}
```

- **No N+1**: single JOIN query grouped by room_id
- **Skips unlinked**: only counts requests with `stay_id` set (null requests excluded by JOIN)
- **Returns**: `Collection<room_id, int>` — ready to pass as prop to Room Board component
- **Frontend wiring**: not done in M3 (Room Board badge is frontend work)

---

## Tests Added

### `tests/Feature/SpecialRequestCrudTest.php` — 24 tests

| Group | Tests |
|---|---|
| **Form Request / Validation** | invalid category rejected, invalid request_type rejected, quantity < 1 rejected, stay_id from different booking rejected, terminal booking rejected |
| **Controller / Routes — store** | ADMIN can create, RECEPTION can create, HOUSEKEEPING cannot create (403) |
| **Controller / Routes — acknowledge** | ADMIN can acknowledge, HOUSEKEEPING can acknowledge, RECEPTION cannot (403) |
| **Controller / Routes — fulfill** | ADMIN can fulfill, HOUSEKEEPING can fulfill |
| **Controller / Routes — cancel** | ADMIN can cancel, RECEPTION cannot (403) |
| **Ownership guard** | Request from different booking rejected on acknowledge |
| **Integration Hook 1** | cancelBooking auto-cancels pending/acknowledged, does not touch fulfilled/cancelled |
| **Integration Hook 2** | createStayFromAssignment auto-links on single active stay, does not link on multiple stays, stay creation not aborted |
| **Room Board Query** | pending+acknowledged counted, fulfilled+cancelled excluded, keyed by room_id |

---

## Tests Executed

### Milestone 3 New Tests

```
Tests:    24 passed (39 assertions)
Failed:   0
Duration: ~6s
```

All 24 new M3 tests PASS. One bug caught and fixed during testing: test payload used `twin_beds` (not in catalog); corrected to `twin_keep`.

### Full Test Suite

```
Tests:    618 passed, 26 failed (2896 assertions)
Duration: 194.74s
```

26 failures = same pre-existing failures from before Phase 4.1. Zero new regressions.

---

## Test Results

### Regression Comparison (final)

| Metric | Phase 3 Baseline | After M1 | After M2 | After M3 | Delta from baseline |
|---|---|---|---|---|---|
| Tests passed | 553 | 553 | 594 | **618** | +65 new tests |
| Tests failed | 26 | 26 | 26 | **26** | **0** — same pre-existing |
| New regression | — | 0 | 0 | **0** | ✅ |

---

## Regression Risk

### Financial Foundation: ZERO IMPACT

`BookingService::cancelBooking()` modification:
- `autoCancelForBooking()` is a single `UPDATE booking_special_requests WHERE status NOT IN ('fulfilled', 'cancelled')` — it does not touch `folio_entries`, `booking_payments`, or any financial table.
- Runs inside the existing `DB::transaction` — rolls back cleanly if `FolioHasActiveEntriesException` is thrown.
- Does not acquire new row locks (no `lockForUpdate()`).

`StayService::createStayFromAssignment()` modification:
- `autoLinkSingleStayRequests()` is a `UPDATE booking_special_requests WHERE stay_id IS NULL` — it does not touch folio, payments, or room assignments.
- Never throws.
- Does not wrap `createStayFromAssignment()` in a transaction.

| Module | Status |
|---|---|
| `FolioService` | ✅ Not touched |
| `BookingPaymentService` | ✅ Not touched |
| `NightAuditPipeline` | ✅ Not touched |
| `RevenueReportService` | ✅ Not touched |
| `ReconciliationService` | ✅ Not touched |
| `PackageEnrollmentService` | ✅ Not touched |
| `PostingTimelineService` | ✅ Not touched |
| `folio_entries` table | ✅ Not modified |
| `booking_payments` table | ✅ Not modified |

---

## Scope Control

### What was implemented

| Item | Status |
|---|---|
| `StoreBookingSpecialRequestRequest` (5 rules + catalog) | ✅ Done |
| `BookingSpecialRequestController` (5 actions) | ✅ Done |
| 5 routes registered | ✅ Done |
| Integration Hook 1: `cancelBooking()` → `autoCancelForBooking()` | ✅ Done |
| Integration Hook 2: `createStayFromAssignment()` → `autoLinkSingleStayRequests()` | ✅ Done |
| `pendingCountByRoom()` static method | ✅ Done |
| 24 feature tests | ✅ Done |

### What was NOT implemented

| Item | Reason |
|---|---|
| Vue — "Yêu cầu" tab in `Booking/Show.vue` | Milestone 4 (Frontend) |
| Add Request form frontend | Milestone 4 |
| HOUSEKEEPING restricted mode UI | Milestone 4 |
| Room Board badge frontend | Milestone 4 |
| Stay summary inline list | Milestone 4 |
| Any financial module changes | Never (Phase 3 closed) |

---

## Remaining Work

### Milestone 4 — Frontend

- "Yêu cầu" tab in `resources/js/Pages/Admin/Booking/Show.vue`
- Add Request form (ADMIN, MANAGER, RECEPTION only)
- HOUSEKEEPING restricted mode (view + acknowledge + fulfill, no create/cancel)
- Request status badges (color-coded)
- Room Board pending badge per room (uses `pendingCountByRoom()` backend query)
- Stay summary inline request list

---

## Ready for ChatGPT Review

**YES**

| Deliverable | Status |
|---|---|
| `StoreBookingSpecialRequestRequest` (catalog + scoped stay_id) | ✅ |
| `BookingSpecialRequestController` (5 actions, policy authorize, ownership guard) | ✅ |
| 5 routes registered and verified | ✅ |
| `BookingService::cancelBooking()` integration hook wired | ✅ |
| `StayService::createStayFromAssignment()` integration hook wired | ✅ |
| `pendingCountByRoom()` static method (no N+1) | ✅ |
| 24 new feature tests — all PASS | ✅ |
| Zero regression (full suite: 618 passed / 26 failed) | ✅ |
| Zero code outside M3 scope | ✅ |
| Financial modules untouched | ✅ |
| Vue / frontend NOT implemented | ✅ |
| No commit | ✅ |

**Awaiting ChatGPT review before proceeding to Milestone 4 (Frontend).**
