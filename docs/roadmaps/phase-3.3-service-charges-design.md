# Phase 3.3 — Service Charges: Architecture Design

**Date:** 2026-06-16
**Status:** Design — Pending Review
**Prerequisite:** Phase 3.2 Folio Foundation (committed `e1ac762`)

---

## Table of Contents

1. [Context](#1-context)
2. [Service Rate Catalog](#2-service-rate-catalog)
3. [Quick-Charge Workflow](#3-quick-charge-workflow)
4. [Per-Stay Charge Attribution](#4-per-stay-charge-attribution)
5. [Late Checkout / Early Check-in Fee Policy](#5-late-checkout--early-check-in-fee-policy)
6. [Transition Guard Removal](#6-transition-guard-removal)
7. [UI Design](#7-ui-design)
8. [Backend Design](#8-backend-design)
9. [Risks](#9-risks)
10. [Implementation Plan](#10-implementation-plan)

---

## 1. Context

### What Phase 3.2 Delivers

Phase 3.2 provides the full financial infrastructure:

| Capability | State |
|---|---|
| Folio auto-created per booking | ✅ |
| Room charge auto-posted at check-in (`autoPostRoomCharge`) | ✅ |
| Generic "Add Charge" form (select type, enter price manually) | ✅ |
| Void with reason + audit trail | ✅ |
| Balance formula: `total_charges − paid_total` | ✅ |
| Folio close/reopen | ✅ |

### What's Still Missing

**Problem 1 — No service price memory.** Front desk staff must remember every service price. A minibar Heineken costs 45,000đ? Staff types it. A late checkout fee? Staff calculates it. A breakfast buffet? Staff guesses. This leads to inconsistent pricing and errors.

**Problem 2 — No per-stay attribution.** All charges attach to the Folio (which attaches to the Booking). There's no way to say "this minibar charge is for room 301, not 205." When a booking has multiple stays, all charges are pooled at the booking level. This blocks per-room split billing.

**Problem 3 — Transition guard still active.** `BookingService::paymentSummary()` falls back to requirements total when `folio_total = 0`. Now that auto-post is stable (every check-in posts a room charge), this guard is dead code that obscures the real formula.

**Problem 4 — Room charge is a single aggregate.** `autoPostRoomCharge()` posts one `FolioEntry` for the sum of all requirements (`room_price × quantity` for each type). For bookings with multiple stays, there's no per-room breakdown on the folio.

### Phase 3.3 Goals

1. Introduce a **Service Rate Catalog** — admin-configurable list of services with default unit prices.
2. Provide a **Quick-Charge UI** — select from the catalog instead of typing from memory.
3. Add **per-stay attribution** (`stay_id` nullable FK on `folio_entries`) for future split-billing.
4. Implement **Late Checkout / Early Check-in fee auto-calculation** using the catalog.
5. Remove the transition guard now that auto-post is proven stable.
6. Split the room charge into **per-stay entries** at check-in.

---

## 2. Service Rate Catalog

### Data Model

```
service_rates
  id
  name            VARCHAR 100    NOT NULL   — "Bữa sáng buffet", "Trả phòng muộn", "Bia Heineken"
  charge_type     VARCHAR 40     NOT NULL   — FK value from ChargeType enum
  unit_price      DECIMAL(12,2)  NOT NULL   — default unit price
  unit_label      VARCHAR 30     NOT NULL   — "người", "giờ", "đêm", "cái", "lần"
  is_active       BOOLEAN        DEFAULT 1  — soft-disable without deleting
  display_order   INT            DEFAULT 0  — sort order for UI
  created_by      FK → users (nullable)
  timestamps
```

### Key Decisions

**Why not a config file?** Service rates change at runtime (price adjustments, seasonal pricing). A database table allows managers to update prices without a deployment. Config files require dev involvement.

**Why not extend `ChargeType`?** `ChargeType` is an enum — its cases require code changes and represent *categories*, not *specific services*. A minibar can have 20 items (water, beer, chips) all under `ChargeType::Minibar`. The catalog lists specific items; the enum provides the billing category.

**`unit_label` purpose.** Shown in the quick-charge UI and potentially on guest invoices. "150,000đ / người" reads better than just "150,000đ". Not used in any calculation — display only.

**Soft-disable, not delete.** `is_active = false` hides a rate from the quick-charge form but preserves it so that historical folio entries referencing it remain interpretable. No hard delete on service rates.

**`display_order`.** Front desk staff should see the most-used services at the top. Manager can reorder. Default 0 = unordered, sorted by name.

### Indexes

```sql
INDEX (charge_type)     -- filter by type in quick-charge UI
INDEX (is_active)       -- WHERE is_active = 1 (most queries)
```

### Admin CRUD

Service rates are managed by ADMIN and MANAGER only. A new admin page `Settings > Dịch vụ` (or alongside Room Rates) provides:
- List of all service rates (grouped by charge_type)
- Create / Edit / Toggle active
- No delete (soft-disable instead)

New permission: `service_rates.manage` → ADMIN + MANAGER only.

---

## 3. Quick-Charge Workflow

### Current Flow (Phase 3.2)

```
Staff opens "Tài chính" tab
→ Clicks "Thêm phí"
→ Manually selects charge type
→ Types description, quantity, unit price, date
→ Submits
```

### Proposed Flow (Phase 3.3)

```
Staff opens "Tài chính" tab
→ Clicks "Thêm phí"
→ Sees catalog of active service rates (grouped by type)
→ Clicks a rate (e.g., "Bữa sáng buffet — 150,000đ/người")
→ Form pre-fills: charge_type=FOOD_BEVERAGE, description="Bữa sáng buffet", unit_price=150000
→ Staff adjusts quantity (default 1), can override unit_price if needed
→ Live total shown (quantity × unit_price)
→ Submits
```

### UI Pattern: Catalog Picker + Form

The "Thêm phí" button opens a two-panel view:
- Left panel: catalog tiles grouped by charge_type (scrollable)
- Right panel: pre-filled charge form (same fields as Phase 3.2)

When no rate matches, staff can still fill in the form manually (free-form mode, same as Phase 3.2). The catalog is a shortcut, not a gate.

### Inertia Data

The `BookingController::show()` response gains a new optional `serviceRates` key in `options`:

```php
'serviceRates' => ServiceRate::where('is_active', true)
    ->orderBy('display_order')
    ->orderBy('name')
    ->get(['id', 'name', 'charge_type', 'unit_price', 'unit_label'])
    ->groupBy('charge_type')
```

This is read-only on the booking show page. No dedicated route needed for the picker.

---

## 4. Per-Stay Charge Attribution

### Motivation

A booking has one Folio but may have multiple Stays (rooms). Currently all charges on the Folio are pooled at the booking level. To support:
- Per-room billing breakdown ("Room 301 consumed 3 minibars, Room 205 consumed 1")
- Future split billing (each stay gets its own folio)
- Per-room checkout reports

We need to know which `Stay` a given `FolioEntry` relates to.

### Migration

Add a nullable FK to `folio_entries`:

```sql
ALTER TABLE folio_entries
  ADD COLUMN stay_id BIGINT UNSIGNED NULL AFTER folio_id,
  ADD CONSTRAINT fk_folio_entries_stay_id
    FOREIGN KEY (stay_id) REFERENCES stays(id) ON DELETE SET NULL;
ALTER TABLE folio_entries ADD INDEX idx_folio_entries_stay_id (stay_id);
```

`ON DELETE SET NULL` — if a Stay is deleted (unlikely post-check-in), the charge stays on the folio unattributed.

### Behavior

- `stay_id` is **optional**. Existing charges (room charge auto-posted) remain unattributed until migrated.
- `autoPostRoomCharge()` will be updated to pass `stay_id` (from the checked-in Stay).
- Quick-charge form gains an optional "Phòng" dropdown (Stay selector) when the booking has multiple active stays.
- `folioPayload()` exposes `stay_id` and `stay_room_number` on each entry.

### Split Per-Stay Room Charge

Currently `autoPostRoomCharge()` posts one aggregate entry. Phase 3.3 changes this to post one entry per Stay:

```php
// Before (Phase 3.2): one entry for all requirements
$amount = $requirements->sum(fn ($r) => $r->room_price * $r->quantity);
$this->addCharge($folio, ['charge_type' => ChargeType::Room, 'amount' => $amount, ...]);

// After (Phase 3.3): one entry per Stay being checked in
foreach ($stays as $stay) {
    $requirement = $this->matchRequirement($stay, $booking);
    $this->addCharge($folio, [
        'charge_type' => ChargeType::Room,
        'description' => "Tiền phòng {$stay->room->room_number}",
        'quantity'    => $nights,
        'unit_price'  => $requirement->room_price,
        'amount'      => $nights * $requirement->room_price,
        'stay_id'     => $stay->id,
        'entry_date'  => $stay->planned_checkin_at->toDateString(),
    ]);
}
```

**Night count calculation:** `$nights = max(1, $stay->planned_checkin_at->diffInDays($stay->planned_checkout_at))`.

**Matching stay to requirement:** A Stay is linked to a RoomAssignment, which has a `room_type_id`. Match to the requirement with the same `room_type_id`. If multiple requirements match, use the first unmatched one.

**Idempotency guard update:** Instead of checking `ChargeType::Room` existence globally, check per `stay_id`:
```php
// Idempotent: skip if this stay already has a ROOM charge
if ($folio->folioEntries()->where('charge_type', ChargeType::Room)->where('stay_id', $stay->id)->exists()) {
    return null;
}
```

---

## 5. Late Checkout / Early Check-in Fee Policy

### Problem

Late checkouts and early check-ins are common scenarios. Currently staff must:
1. Know the hotel's fee policy (e.g., 200,000đ/hour for late checkout)
2. Calculate the number of hours manually
3. Create a charge manually

### Proposed: Policy-Based Auto-Charge Trigger

A simplified policy approach (full policy engine is Phase 3.4+):

**Late Checkout trigger** — When `StayService::checkOut()` is called and `actual_checkout_at > planned_checkout_at` by more than a configurable grace period (e.g., 30 minutes):
1. Calculate hours late: `ceil(($actual - $planned)->totalMinutes / 60)`
2. Look up the active service rate for `ChargeType::LateCheckout`
3. Auto-post a `FolioEntry` with `quantity = hours_late, unit_price = rate->unit_price`
4. Show a flash notification: "Đã tự động tính phí trả phòng muộn X giờ"

**Early Check-in trigger** — When `StayService::checkIn()` is called and `actual_checkin_at < planned_checkin_at` by more than a grace period:
1. Similar pattern with `ChargeType::EarlyCheckin`
2. Hours early as quantity

### Policy Configuration

The hotel configures grace period and rates via the Service Rate Catalog:
- One service rate with `charge_type = LATE_CHECKOUT` = the fee per hour (or per block)
- If no active rate exists for that type, no auto-charge is posted

This keeps the policy engine simple: exactly zero or one active rate per `ChargeType`. If the hotel wants more complex pricing tiers (free < 1hr, 50% rate 1-3hr, full rate > 3hr), that's Phase 3.4.

### Settings

A new `settings` key `late_checkout_grace_minutes` (default 30) controls the grace period. Uses the existing `Setting` model and `SettingController`.

---

## 6. Transition Guard Removal

### What to Remove

In `BookingService::paymentSummary()`:

```php
// REMOVE THIS (Phase 3.3):
$folioTotal = $this->folios->getFolioTotal($booking);
$totalCharges = $folioTotal > 0.0 ? $folioTotal : $requirementsTotal;

// REPLACE WITH:
$totalCharges = $this->folios->getFolioTotal($booking);
```

### When It's Safe

The guard is safe to remove once:
1. Every booking created after Phase 3.2 deployment has a Folio (guaranteed by `createBooking()`).
2. Every check-in posts a room charge (guaranteed by `autoPostRoomCharge()`).
3. Per-stay charge split is in place (Phase 3.3) so checked-in bookings always have `folio_total > 0`.

The only remaining edge case: bookings created before Phase 3.2 that were never checked in. Their folios exist but have `folio_total = 0`. Removing the guard means their `total_charges = 0` instead of fallback to requirements. Acceptable — these are pre-migration bookings in terminal states.

### Test Update Required

`test_booking_detail_includes_payment_summary_and_refund_subtracts_from_paid_total` currently passes because the guard returns `requirementsTotal`. After guard removal, this test must assert `total_charges = 0` for a booking with no check-in and no manual charges. Update the assertion in Phase 3.3.4.

---

## 7. UI Design

### 7.1 Service Rate Admin Page

New page: `/admin/service-rates`

```
┌──────────────────────────────────────────────────────────────┐
│  Danh sách dịch vụ                        [ + Thêm dịch vụ ] │
│                                                              │
│  Ăn uống                                                     │
│  ┌──────────────────────────────────────────────────┐        │
│  │ Bữa sáng buffet      FOOD_BEVERAGE  150,000/người│[Sửa][X]│
│  │ Set ăn tối           FOOD_BEVERAGE  250,000/người│[Sửa][X]│
│  └──────────────────────────────────────────────────┘        │
│  Tiện ích                                                    │
│  ┌──────────────────────────────────────────────────┐        │
│  │ Bia Heineken         MINIBAR         45,000/cái  │[Sửa][X]│
│  │ Nước suối Lavie      MINIBAR         20,000/chai │[Sửa][X]│
│  └──────────────────────────────────────────────────┘        │
│  Phí phát sinh                                               │
│  ┌──────────────────────────────────────────────────┐        │
│  │ Trả phòng muộn       LATE_CHECKOUT  200,000/giờ  │[Sửa][X]│
│  │ Nhận phòng sớm       EARLY_CHECKIN  150,000/giờ  │[Sửa][X]│
│  └──────────────────────────────────────────────────┘        │
└──────────────────────────────────────────────────────────────┘
```

Toggle active/inactive with the [X] button (does not delete). Inactive rates are shown as grayed-out in the admin list but hidden from the booking charge picker.

### 7.2 Booking Finance Tab — Quick-Charge Panel

When "Thêm phí" is clicked, the form expands with a catalog section above the manual fields:

```
┌──────────────────────────────────────────────────────────────┐
│  THÊM PHÍ PHÁT SINH                                   [Đóng] │
│                                                              │
│  Chọn từ danh sách (hoặc điền thủ công bên dưới):           │
│                                                              │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐         │
│  │Bữa sáng      │ │Set ăn tối    │ │Bia Heineken  │         │
│  │150,000/người │ │250,000/người │ │45,000/cái    │         │
│  └──────────────┘ └──────────────┘ └──────────────┘         │
│  ┌──────────────┐ ┌──────────────┐                          │
│  │Trả muộn      │ │Nhận sớm      │                          │
│  │200,000/giờ   │ │150,000/giờ   │                          │
│  └──────────────┘ └──────────────┘                          │
│                                                              │
│  ─────────────────────────────────────────────────          │
│  Loại phí: [FOOD_BEVERAGE ▾]  Mô tả: [Bữa sáng buffet]      │
│  SL: [2]   Đơn giá: [150,000]   Phòng: [Tùy chọn ▾]        │
│  Ngày: [2026-07-01]   Thành tiền: 300,000 đ                  │
│                                   [Hủy]  [Thêm phí]         │
└──────────────────────────────────────────────────────────────┘
```

**Catalog tile click behavior:**
- Sets `charge_type`, `description`, `unit_price` from the selected rate
- Focuses the `quantity` field for immediate input
- A highlighted tile border shows which rate is selected
- Clicking the same tile again deselects (manual mode)

**"Phòng" dropdown** (optional, only shown when booking has >1 active stay):
- Lists `[Phòng 301 - TWIN, Phòng 205 - DOUBLE, Chung (không chọn phòng cụ thể)]`
- Maps to `stay_id` in the submitted form

### 7.3 Per-Stay Breakdown on Folio Entries Table

The existing folio entries table gains a "Phòng" column:

```
Ngày   │ Loại          │ Mô tả             │ SL │ Đơn giá │ Thành tiền │ Phòng │ Người đăng │ Thao tác
07-01  │ Tiền phòng    │ Tiền phòng 301     │ 1  │ 800,000 │    800,000 │ 301   │ System     │
07-01  │ Tiền phòng    │ Tiền phòng 205     │ 1  │ 750,000 │    750,000 │ 205   │ System     │
07-01  │ Minibar       │ Bia Heineken       │ 3  │  45,000 │    135,000 │ 301   │ Receptionist│ [Hủy]
07-02  │ Trả muộn      │ Tự động - 2 giờ   │ 2  │ 200,000 │    400,000 │ 301   │ System     │
```

"Phòng" column is hidden (via `v-if`) when all entries have no `stay_id`.

---

## 8. Backend Design

### 8.1 New Files

| File | Purpose |
|---|---|
| `app/Models/ServiceRate.php` | Model: name, charge_type (cast to ChargeType), unit_price, unit_label, is_active, display_order |
| `app/Policies/ServiceRatePolicy.php` | viewAny (service_rates.manage), create/update/delete (service_rates.manage) |
| `app/Http/Controllers/Admin/ServiceRateController.php` | Resource controller: index, store, update, toggle (PATCH /toggle) |
| `app/Http/Requests/ServiceRate/StoreServiceRateRequest.php` | Validates: name, charge_type (ChargeType enum), unit_price ≥0, unit_label, display_order |
| `app/Http/Requests/ServiceRate/UpdateServiceRateRequest.php` | Same rules |
| `database/migrations/…_create_service_rates_table.php` | Schema above |
| `database/migrations/…_add_stay_id_to_folio_entries_table.php` | Nullable stay_id FK + index |
| `database/factories/ServiceRateFactory.php` | For tests |

### 8.2 Modified Files

| File | Change |
|---|---|
| `app/Services/FolioService.php` | `autoPostRoomCharge()` rewritten to post per-stay entries with `stay_id`; `addCharge()` accepts optional `stay_id` |
| `app/Services/StayService.php` | `checkOut()` calls `autoPostLateCheckoutCharge()` if applicable; pass Stay to `autoPostRoomCharge()` |
| `app/Services/BookingService.php` | Remove transition guard from `paymentSummary()` |
| `app/Http/Controllers/Admin/Booking/BookingController.php` | `options()` includes `serviceRates`; `folioPayload()` includes `stay_id`, `stay_room_number` per entry |
| `database/seeders/RolePermissionSeeder.php` | Add `service_rates.manage` to ADMIN + MANAGER |
| `app/Providers/AppServiceProvider.php` | Register `ServiceRatePolicy` |
| `routes/web.php` | Add service-rates resource route + toggle route |
| `resources/js/Pages/Admin/Bookings/Show.vue` | Catalog picker UI, optional "Phòng" dropdown, `stay_id` in chargeForm |

### 8.3 `FolioService` Signature Changes

```php
// addCharge: accept optional stay_id
public function addCharge(Folio $folio, array $data): FolioEntry
// $data keys: charge_type, description, quantity, unit_price, amount, entry_date, [stay_id], [note]

// autoPostRoomCharge: per-stay, returns array of entries
public function autoPostRoomCharge(Booking $booking, Stay $stay): ?FolioEntry

// new method
public function autoPostLateCheckoutCharge(Stay $stay): ?FolioEntry
```

### 8.4 Balance Formula — Unchanged

The balance formula does not change. Adding `stay_id` to entries is attribution metadata; it does not affect the sum. The transition guard removal changes the fallback behavior but not the formula itself.

---

## 9. Risks

### R1 — Stay-to-Requirement Matching Ambiguity

**Risk:** A booking with 2 TWIN rooms has 2 Stays, both of type TWIN. When posting per-stay room charges, both match the same requirement. The algorithm must distribute requirements correctly.

**Impact:** Both stays might use the same unit_price, which is correct only if both requirements have the same price. If prices differ, misattribution occurs.

**Mitigation:** 
- Sort requirements by `room_price` descending; assign to stays in the same order.
- If exact matching is needed (e.g., promotions per room), Phase 3.4 can add a direct `stay_id` FK to `booking_requirements`.
- For Phase 3.3: log a warning if multiple requirements of the same type exist with different prices.

### R2 — Transition Guard Removal Breaks Pre-Phase-3.2 Bookings

**Risk:** Bookings created before Phase 3.2 that are still open have folios with `folio_total = 0` (no charges yet). After guard removal, `total_charges = 0` instead of `requirementsTotal`. The checkout gate (`remaining_balance <= 0`) might allow checkout for bookings that have unpaid requirements.

**Impact:** Guest leaves without paying.

**Mitigation:**
- The guard should only be removed after confirming all active (non-terminal) bookings have had room charges auto-posted (i.e., all have been checked in under Phase 3.2).
- Run a backfill script before removing the guard: `php artisan folio:backfill-room-charges` that posts room charges for all checked-in bookings with `folio_total = 0`.
- Gate the guard removal behind a `setting('folio_transition_complete', false)` flag during Phase 3.3 rollout.

### R3 — Late Checkout Auto-Charge Double-Firing

**Risk:** `checkOut()` is called twice (retry, accidental double-click). Two late checkout charges are posted.

**Impact:** Guest charged twice.

**Mitigation:** Same idempotency pattern as `autoPostRoomCharge()`: check if a `LateCheckout` entry with `stay_id = $stay->id` already exists before posting.

### R4 — Service Rate Price Drift

**Risk:** Manager updates a service rate's `unit_price`. Historical folio entries do NOT update — they captured the price at posting time. But if staff uses the catalog without submitting immediately and then the rate changes, they see stale price in the form.

**Impact:** One-off incorrect charge.

**Mitigation:** The form always fetches the current `unit_price` from the catalog. If the rate changes between opening the form and submitting, the submitted amount reflects the current rate. The risk is acceptable — it's a 1-request window. Document this behavior.

### R5 — `stay_id` FK with `ON DELETE SET NULL` on Folio Entries

**Risk:** If a Stay record is deleted (should not happen after check-in, but in edge cases such as an admin correction), the `stay_id` on affected folio entries becomes NULL silently.

**Impact:** Per-stay charge breakdown loses attribution. Balance is unaffected.

**Mitigation:** `StayPolicy` should prevent deletion of checked-in or checked-out stays. `ON DELETE SET NULL` is the right FK action (avoids cascade delete of financial records). Add a guard in `StayService::deleteStay()` if that method ever exists.

---

## 10. Implementation Plan

### Phase 3.3.1 — Service Rate Catalog

**Estimated effort:** 1 day

1. Migration: `create_service_rates_table`
2. Model: `ServiceRate` with ChargeType cast, `scopeActive()`
3. Factory: `ServiceRateFactory`
4. Policy: `ServiceRatePolicy` (service_rates.manage)
5. Request: `StoreServiceRateRequest`, `UpdateServiceRateRequest`
6. Controller: `ServiceRateController` (index, store, update, toggle active)
7. Route: `Route::resource('service-rates', ...)` + `Route::patch('service-rates/{rate}/toggle', ...)`
8. Frontend: `resources/js/Pages/Admin/ServiceRates/Index.vue` — list + create/edit form (same tab pattern)
9. Seed: add `service_rates.manage` permission; seed 5-6 example rates via `ServiceRateSeeder`
10. Tests: `ServiceRateCrudTest` (≥8 tests: admin CRUD, manager CRUD, reception blocked, toggle, soft-disable)

### Phase 3.3.2 — Per-Stay Attribution + Per-Stay Room Charge

**Estimated effort:** 1 day

1. Migration: `add_stay_id_to_folio_entries_table` (nullable FK + index)
2. Update `FolioEntry` model: add `stay_id` to fillable, add `belongsTo Stay` relationship
3. Update `FolioService::addCharge()`: accept `stay_id` in `$data`
4. Update `FolioService::autoPostRoomCharge()`: post per-stay entries with `stay_id`; update idempotency guard
5. Update `StoreFolioEntryRequest`: add optional `stay_id` validation (must belong to this booking's stays)
6. Update `BookingController::folioPayload()`: expose `stay_id`, `stay_room_number` on each entry
7. Update `BookingController::options()`: include active stays for "Phòng" dropdown
8. Update `Show.vue`: add optional "Phòng" dropdown in charge form; add "Phòng" column to entries table
9. Tests: update `FolioCrudTest`; new assertions for per-stay room charge split and stay attribution

### Phase 3.3.3 — Quick-Charge UI + Late/Early Fee Policy

**Estimated effort:** 1 day

1. Update `BookingController::options()`: include `serviceRates` grouped by charge_type
2. Update `Show.vue`: catalog picker tiles, auto-fill on click, clear on deselect
3. Update `FolioService`: add `autoPostLateCheckoutCharge(Stay $stay): ?FolioEntry`
4. Update `StayService::checkOut()`: call `autoPostLateCheckoutCharge()` after marking checkout
5. Add setting `late_checkout_grace_minutes` (default 30) via `SettingController`/`SettingSeeder`
6. Tests: feature tests for late checkout auto-charge (with and without active rate, within/outside grace)

### Phase 3.3.4 — Transition Guard Removal + Tests

**Estimated effort:** 0.5 day

1. Write and run `php artisan folio:backfill-room-charges` command (one-time, idempotent)
2. Remove transition guard from `BookingService::paymentSummary()`
3. Update `test_booking_detail_includes_payment_summary_and_refund_subtracts_from_paid_total` to assert `total_charges = 0` (no check-in, no manual charges)
4. Run full test suite — confirm all pass

### Recommended Implementation Order

```
3.3.1  Service Rate Catalog (database + admin CRUD) ← start here
3.3.2  Per-Stay Attribution + Per-Stay Room Charge
3.3.3  Quick-Charge UI + Late/Early Fee Policy
3.3.4  Transition Guard Removal + Test Cleanup
```

Each sub-phase is independently deployable. 3.3.1 adds a new admin feature with no impact on existing flows. 3.3.2 adds a nullable column (backward compatible). 3.3.3 is pure UI + a new auto-charge hook. 3.3.4 is safe once a backfill confirms no active booking has `folio_total = 0`.

---

## Appendix: Database Schema Additions

```sql
-- service_rates
CREATE TABLE service_rates (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100)   NOT NULL,
    charge_type     VARCHAR(40)    NOT NULL,
    unit_price      DECIMAL(12,2)  NOT NULL,
    unit_label      VARCHAR(30)    NOT NULL DEFAULT 'lần',
    is_active       BOOLEAN        NOT NULL DEFAULT 1,
    display_order   INT            NOT NULL DEFAULT 0,
    created_by      BIGINT UNSIGNED NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    INDEX idx_service_rates_charge_type (charge_type),
    INDEX idx_service_rates_is_active (is_active),
    CONSTRAINT fk_service_rates_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- folio_entries: add stay_id
ALTER TABLE folio_entries
    ADD COLUMN stay_id BIGINT UNSIGNED NULL AFTER folio_id,
    ADD CONSTRAINT fk_folio_entries_stay_id
        FOREIGN KEY (stay_id) REFERENCES stays(id) ON DELETE SET NULL,
    ADD INDEX idx_folio_entries_stay_id (stay_id);
```

---

## Appendix: Permission Matrix (Phase 3.3 additions)

| Permission | ADMIN | MANAGER | RECEPTION | ACCOUNTANT | SALES | HOUSEKEEPING |
|---|---|---|---|---|---|---|
| `service_rates.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |

Existing folio permissions (`folio.view`, `folio.close`, `charge.create`, `charge.void`) unchanged.
