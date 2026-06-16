# Phase 3.2 — Folio Foundation: Frontend & Tests Report

## 1. Summary

Phase 3.2.3 (Frontend) and Phase 3.2.4 (Tests) have been implemented following the approved design document exactly. All 115 tests pass with 596 assertions (+34 tests, +76 assertions vs Phase 3.2.2 baseline). No regressions.

---

## 2. Vue Files Changed

### Modified (1 file)

| File | Changes |
|---|---|
| `resources/js/Pages/Admin/Bookings/Show.vue` | Full Finance tab rewrite: 3-card financial summary, charge breakdown chips, folio entries table (with void workflow), Add Charge inline form, folio status footer (Close/Reopen), void reason modal. Existing Payments section preserved unchanged. |

**Key additions to `<script setup>`:**
- 5 new `ref`s: `showAddChargeForm`, `showVoidModal`, `voidingEntry`, `voidReasonInput`
- `chargeForm` via `useForm` (charge_type, description, quantity, unit_price, entry_date, amount)
- 2 new `computed`: `chargeBreakdown` (groups active entries by type), `chargeFormTotal` (quantity × unit_price)
- 7 new functions: `submitCharge`, `openVoidModal`, `closeVoidModal`, `confirmVoid`, `closeFolio`, `reopenFolio`, `folioStatusLabel`

### Modified (1 backend file for frontend data)

| File | Changes |
|---|---|
| `app/Http/Controllers/Admin/Booking/BookingController.php` | Added `ChargeType` import; added `'chargeTypes' => ChargeType::options()` to `options()`; renamed tab label "Thanh toán" → "Tài chính" |

---

## 3. Tests Added

### New test files (3 files, 34 tests)

| File | Tests | Description |
|---|---|---|
| `tests/Unit/Policies/FolioPolicyTest.php` | 9 | admin/manager/accountant can view; housekeeping cannot; manager can close open; manager cannot close closed; admin can reopen; manager cannot reopen; reception cannot close |
| `tests/Unit/Policies/FolioEntryPolicyTest.php` | 7 | admin voids any entry; manager voids today; manager cannot void old; reception cannot void; cannot create on closed folio; manager can create on open folio; accountant cannot create |
| `tests/Feature/FolioCrudTest.php` | 18 | folio auto-created; folio number format; admin adds charge; voided entry excluded from total; cannot void voided; void requires 5-char reason; invalid charge type rejected; cannot add to closed folio; manager closes; admin reopens; manager cannot reopen; charge audit log; void audit log; booking controller exposes folio; balance_due formula; IDOR guard; reception can add charge; housekeeping blocked |

### Modified test files (1 file, 1 assertion updated)

| File | Change |
|---|---|
| `tests/Feature/BookingManagementUiTest.php` | Updated tab label assertion "Thanh toán" → "Tài chính" to match backend change |

---

## 4. Finance Tab Features Implemented

### Financial Summary Section
- 3 cards: **Tổng phí phát sinh** (`total_charges`), **Đã thu** (`paid_total`), **Còn lại** (`balance_due`)
- Balance Due is red (`text-coral`) when > 0, green (`text-pine`) when 0 or negative

### Charge Breakdown Chips
- Groups active (non-voided) folio entries by `charge_type_label`
- Displays as horizontal chip row: "Tiền phòng 800,000 đ | Minibar 50,000 đ"
- Hidden when no active entries

### Charges Section (folio entries table)
- Columns: Ngày, Loại phí, Mô tả, SL, Đơn giá, Thành tiền, Người đăng, Thao tác
- Voided rows: `bg-gray-50`, `line-through` on cells, "VOIDED" badge + voided_by + void_reason inline
- "Hủy" button with `XCircle` icon, visible only when `entry.can_void === true`

### Add Charge Form (collapsible)
- Toggle button "Thêm phí" shows only when `can.createCharge && folio.status === 'OPEN'`
- Grid layout `md:grid-cols-5`: charge_type | description (×2) | quantity | unit_price
- Second row: entry_date | computed total display | Cancel button | Submit button
- Resets and collapses on success

### Void Charge Modal
- Opens via `openVoidModal(entry)` — shows entry details (type, description, amount, date)
- Requires void_reason textarea (min 5 chars to enable submit)
- Submit calls `router.patch()` to `/admin/bookings/{id}/folio/entries/{entryId}`
- Closes automatically on success

### Folio Status Controls
- Footer row showing folio number + status badge (green "Đang mở" / gray "Đã đóng")
- "Đóng folio" button: visible when `folio.can_close === true`, confirmed via `window.confirm`
- "Mở lại" button: visible when `folio.can_reopen === true` (ADMIN only)

### Payment Section (preserved unchanged)
- Add payment form (5-col grid), payment table with delete button — all intact

---

## 5. Inertia Routes Wired (from Phase 3.2.2, confirmed working)

```
POST   /admin/bookings/{booking}/folio/entries           → submitCharge()
PATCH  /admin/bookings/{booking}/folio/entries/{entry}   → confirmVoid()
PATCH  /admin/bookings/{booking}/folio/close             → closeFolio()
PATCH  /admin/bookings/{booking}/folio/reopen            → reopenFolio()
```

---

## 6. Test Results

```
Tests:    115 passed (596 assertions)
Duration: 93.56s
Status:   ALL PASS — 0 regressions

Pre-existing tests: 81 → updated 1 assertion (tab label rename)
New test files:     3 (FolioPolicyTest, FolioEntryPolicyTest, FolioCrudTest)
New tests added:    34
New assertions:     76
```

---

## 7. Git Diff Summary

```
9 files changed, 432 insertions(+), 90 deletions(-)

resources/js/Pages/Admin/Bookings/Show.vue               | 379 +++++++++----
app/Http/Controllers/Admin/Booking/BookingController.php |  53 +++++++++--
tests/Feature/BookingManagementUiTest.php                |   2 +-

Plus 3 new test files (unit policies x2, feature x1) — untracked.
Phase 3.2.2 backend files unchanged (already shown in backend report).
```

---

## 8. Ready For Review

- ✅ 115/115 tests pass, 596 assertions, 0 regressions
- ✅ Tab renamed "Thanh toán" → "Tài chính" (key 'payments' preserved for redirect compat)
- ✅ Finance tab: 3-card summary + breakdown chips + charges table + add form + void modal + folio controls
- ✅ Payment section preserved intact (form + table + delete)
- ✅ FolioPolicyTest: 9 tests covering all roles
- ✅ FolioEntryPolicyTest: 7 tests covering create + void scenarios
- ✅ FolioCrudTest: 18 feature tests (IDOR, audit, balance formula, all role gates)
- ✅ `chargeTypes` option exposed to frontend via `BookingController::options()`
- ✅ No database schema changes
- ✅ No backend API changes

**Awaiting review approval before commit.**
