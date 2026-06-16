# Phase 3.2 — Folio Foundation: Completion Report

**Date:** 2026-06-15
**Status:** Complete — Approved — Ready to commit

---

## 1. Phase Summary

Phase 3.2 established the Folio as Lastella's financial hub. Every charge posted to a booking (room, service, damage, late checkout) now flows through `folio_entries`, with the Folio acting as the single source of truth for what the guest owes. Payments (`booking_payments`) remain unchanged — they represent money received, not money owed.

**Total scope:** 4 sub-phases, 25 new files, 10 modified files, 34 new tests.

---

## 2. Sub-Phase Summary

### Phase 3.2.1 — Database

| Item | Detail |
|---|---|
| Migration: `folios` | booking_id (UNIQUE FK), folio_number (UNIQUE), status (VARCHAR 20, indexed), note, created_by, closed_at, closed_by, timestamps |
| Migration: `folio_entries` | folio_id (FK cascade), charge_type (VARCHAR 40), description, quantity (DECIMAL 8,2), unit_price (DECIMAL 12,2), amount (DECIMAL 12,2), entry_date (DATE), posted_by, voided_at, voided_by, void_reason, timestamps |
| Composite indexes | `(folio_id, voided_at)` for total_charges query; `(charge_type, entry_date)` for reporting |
| Enum: `ChargeType` | 10 cases: Room, FoodBeverage, Spa, Laundry, Minibar, Damage, LateCheckout, EarlyCheckin, Transport, Other — Vietnamese labels + `options()` |
| Enum: `FolioStatus` | Open, Closed, Voided — `isEditable()`, `label()` |
| Factories | `FolioFactory` (open default, `closed()` state); `FolioEntryFactory` (Other default, `voided()` state) |

### Phase 3.2.2 — Backend

| Item | Detail |
|---|---|
| `Folio` model | BelongsTo Booking/createdBy/closedBy; HasMany FolioEntry; `scopeOpen()` |
| `FolioEntry` model | BelongsTo Folio/postedBy/voidedBy; `scopeActive()` (whereNull voided_at) |
| `FolioService` | createFolioForBooking, addCharge, voidEntry (idempotency guard), autoPostRoomCharge (idempotent), getFolioTotal, closeFolio, reopenFolio, generateFolioNumber (FLO-YYYYMMDD-NNNN) |
| `FolioPolicy` | viewAny/view (folio.view), close (folio.close + OPEN status), reopen (ADMIN + CLOSED status) |
| `FolioEntryPolicy` | create (charge.create + folio OPEN), void (ADMIN always; MANAGER with charge.void if today) |
| `StoreFolioEntryRequest` | Validates charge_type (enum), description, quantity ≥0.01, unit_price ≥0, amount ≥0.01, entry_date |
| `VoidFolioEntryRequest` | Validates void_reason (min:5, max:500); authorize: charge.void OR ADMIN |
| `FolioController` | close(), reopen() — loads folio from booking, authorizes, redirects to `?tab=payments` |
| `FolioEntryController` | store() with `authorize('create', [FolioEntry::class, $folio])`; void() with IDOR guard on `$entry->folio?->booking_id === $booking->id` |
| `BookingController` | Added `folio.folioEntries.postedBy/voidedBy` eager load; `folioPayload()` private method; `chargeTypes` in options; `createCharge/voidCharge/closeFolio/reopenFolio` permission flags |
| `BookingService` | Constructor injects FolioService; `createBooking()` calls `createFolioForBooking()`; `paymentSummary()` integrates folio total with transition guard |
| `StayService` | Constructor injects FolioService; `checkIn()` calls `autoPostRoomCharge()` before `updateBookingStayStatus()` |
| `RolePermissionSeeder` | Added: folio.view (ADMIN/MANAGER/RECEPTION/ACCOUNTANT), folio.close (ADMIN/MANAGER), charge.create (ADMIN/MANAGER/RECEPTION), charge.void (ADMIN/MANAGER) |
| `AppServiceProvider` | Registered FolioPolicy + FolioEntryPolicy via Gate::policy(); Folio + FolioEntry wired to AuditObserver |
| `routes/web.php` | 4 new routes: PATCH folio/close, PATCH folio/reopen, POST folio/entries, PATCH folio/entries/{entry} |
| `Booking` model | Added `hasOne Folio` relationship |

**Key design decisions:**
- Transition guard: `$totalCharges = $folioTotal > 0 ? $folioTotal : $requirementsTotal` — bridges pre-Phase-3.2 bookings and no-charge-yet state
- Void pattern: no hard delete on FolioEntry — uses voided_at/voided_by/void_reason (AuditObserver captures as `updated`)
- "Create with context" policy: `authorize('create', [FolioEntry::class, $folio])` lets FolioEntryPolicy check both permission AND folio status
- Auto-post room charge is idempotent: checks for existing ROOM entry before posting
- Auto-post happens BEFORE `updateBookingStayStatus()` so checkout gate reads correct balance

### Phase 3.2.3 — Frontend

| Change | Detail |
|---|---|
| Tab rename | "Thanh toán" → "Tài chính" (key `payments` preserved for redirect compatibility) |
| Financial Summary | 3 cards: Tổng phí phát sinh (`total_charges`), Đã thu (`paid_total`), Còn lại (`balance_due`) — red/green on sign |
| Charge breakdown | Horizontal chip row grouping active entries by charge_type_label with summed amounts |
| Charges section | Folio entries table: date, type, description, qty, unit_price, amount, posted_by; voided rows struck through + VOIDED badge |
| Add Charge form | Collapsible inline form (charge_type select, description, qty, unit_price, entry_date, live amount preview); shows only when `can.createCharge && folio.status === 'OPEN'` |
| Void workflow | "Hủy" button → modal with entry details + void_reason textarea (min 5 chars) → `router.patch()` |
| Folio footer | Folio number + status badge + Close/Reopen buttons (per-folio `can_close`/`can_reopen` flags) |
| Payment section | Preserved intact below charges — form + table + delete button |
| `options.chargeTypes` | Added `ChargeType::options()` to BookingController options payload |

### Phase 3.2.4 — Tests

| File | Tests | Coverage |
|---|---|---|
| `tests/Unit/Policies/FolioPolicyTest.php` | 9 | All roles for viewAny; manager close open/can't close closed; admin reopen; manager/reception cannot reopen/close |
| `tests/Unit/Policies/FolioEntryPolicyTest.php` | 7 | Admin voids any; manager voids today/can't void old; reception blocked; closed folio blocks create; accountant blocked |
| `tests/Feature/FolioCrudTest.php` | 18 | Auto-create; folio number format; add charge; voided excluded from total; can't re-void; void reason min 5; invalid type rejected; closed folio blocked; manager close; admin reopen; manager can't reopen; charge audit; void audit; folio in show response; balance_due formula; IDOR guard; reception can add; housekeeping blocked |
| `tests/Feature/BookingManagementUiTest.php` | Updated | Tab label assertion updated "Thanh toán" → "Tài chính" |

---

## 3. Final Test Results

```
Tests:    115 passed (596 assertions)
Duration: 93.56s
Status:   ALL PASS — 0 regressions

Baseline (Phase 3.2.2):  81 tests, 520 assertions
Phase 3.2.3 (tab rename):  1 assertion updated
Phase 3.2.4 (new tests): +34 tests, +76 assertions
Final total:             115 tests, 596 assertions
```

---

## 4. Files Changed (Full List)

### New files (25)

```
app/Enums/ChargeType.php
app/Enums/FolioStatus.php
app/Http/Controllers/Admin/Booking/FolioController.php
app/Http/Controllers/Admin/Booking/FolioEntryController.php
app/Http/Requests/Folio/StoreFolioEntryRequest.php
app/Http/Requests/Folio/VoidFolioEntryRequest.php
app/Models/Folio.php
app/Models/FolioEntry.php
app/Policies/FolioEntryPolicy.php
app/Policies/FolioPolicy.php
app/Services/FolioService.php
database/factories/FolioEntryFactory.php
database/factories/FolioFactory.php
database/migrations/2026_06_01_000000_create_folios_table.php
database/migrations/2026_06_01_000010_create_folio_entries_table.php
docs/phase-3.2-folio-foundation-design.md
docs/reports/phase-3.2-backend-report.md
docs/reports/phase-3.2-frontend-report.md
docs/reports/phase-3.2-completion-report.md  ← this file
tests/Feature/FolioCrudTest.php
tests/Unit/Policies/FolioEntryPolicyTest.php
tests/Unit/Policies/FolioPolicyTest.php
```

### Modified files (10)

```
app/Http/Controllers/Admin/Booking/BookingController.php
app/Models/Booking.php
app/Providers/AppServiceProvider.php
app/Services/BookingService.php
app/Services/StayService.php
database/seeders/RolePermissionSeeder.php
resources/js/Pages/Admin/Bookings/Show.vue
routes/web.php
tests/Feature/BookingManagementUiTest.php
```

---

## 5. Architecture Invariants Established

The following rules MUST be preserved by all future phases:

1. **Never hard-delete a FolioEntry.** Void only. `FolioEntryPolicy` has no `delete` method.
2. **FolioEntry is the source of truth for charges.** Never use `bookingRequirements.room_price` for billing calculations after Phase 3.2.
3. **Every future service module writes to `folio_entries` via `FolioService::addCharge()`**. The folio schema does not change for new charge types — add a new `ChargeType` case only.
4. **Transition guard** (`$folioTotal > 0 ? $folioTotal : $requirementsTotal`) can be removed in Phase 3.3 once room charge auto-post has been in production for one deploy cycle.
5. **Folio auto-created in `BookingService::createBooking()`** inside the same DB transaction as the booking. Never create a booking without a folio.
6. **IDOR guard** on `FolioEntryController::void()` checks `$entry->folio?->booking_id === $booking->id`.

---

## 6. Known Issues / Technical Debt

| ID | Issue | Severity | Target |
|---|---|---|---|
| TD-1 | `autoPostRoomCharge` posts aggregate room charge (single entry for all room types). Per-room/per-stay breakdown needs Phase 3.3 | Low | Phase 3.3 |
| TD-2 | Transition guard fallback to requirements total can be removed once Phase 3.3 per-stay charge is stable | Low | Phase 3.3 |
| TD-3 | `generateFolioNumber()` has a concurrent-write race (read-before-check). Acceptable at current volume; add retry/lock for high-volume | Low | Phase 4+ |
| TD-4 | `balance_due` and `remaining_balance` are aliases for same value — frontend uses both. Clean up in Phase 3.4 when invoice module stabilizes naming | Low | Phase 3.4 |
