# Phase 3.3.5 – Posting Timeline & Service Rate History: Backend Report

## Objective Completed

Built a read-only operational visibility layer for:
1. **Folio Posting Timeline** — chronological entry-by-entry audit of all charges posted to a folio, with running totals and room association
2. **Service Rate Version History** — full temporal history of all price versions for a given charge type

ADR-78 preserved: zero writes to any financial table. No mutation of folios, folio_entries, bookings, or service_rates.

---

## Files Changed

### New Files

| File | Purpose |
|------|---------|
| `app/Services/PostingTimelineService.php` | Builds chronological timeline for a folio; handles voided entry filtering and running total accumulation |
| `app/Http/Controllers/Admin/PostingTimelineController.php` | HTTP endpoint for booking folio timeline; permission-gated; determines `includeVoided` from `charge.void` permission |
| `tests/Feature/PostingTimelineTest.php` | 18 feature tests covering service, HTTP, permissions, and regressions |

### Modified Files

| File | Change |
|------|--------|
| `app/Http/Controllers/Admin/ServiceRateController.php` | Added `history(string $chargeType): Response` method; no existing methods changed |
| `routes/web.php` | Added 2 GET routes; added `PostingTimelineController` import |

---

## Services Added

### `PostingTimelineService::forFolio(Folio $folio, bool $includeVoided = true): array`

- Eager-loads `stay.room`, `postedBy`, `voidedBy` (3–4 queries total, no N+1)
- Orders by `entry_date ASC → created_at ASC → id ASC`
- Safety limit: `MAX_ENTRIES = 1000` (guard against runaway memory for high-volume folios)
- Voided entries: included if `$includeVoided = true`; excluded via `whereNull('voided_at')` if false
- Running total: computed via `bcadd()` (string precision, not float) — voided entries do not contribute
- `posting_source`: returns value from DB; `?? 'MANUAL'` is a defensive fallback for legacy null rows

---

## Controller / Routes Changed

### `PostingTimelineController::show()`

- Route: `GET /admin/bookings/{booking}/timeline` → `admin.bookings.timeline`
- Permission gate: `abort_unless($request->user()?->can('folio.view'), 403)`
- Voided visibility: `$includeVoided = $request->user()?->can('charge.void') ?? false`
  - ADMIN, MANAGER: `includeVoided = true`
  - RECEPTION, ACCOUNTANT: `includeVoided = false`
- Handles no-folio case: returns empty `timeline = []`

### `ServiceRateController::history()`

- Route: `GET /admin/service-rates/history/{chargeType}` → `admin.service-rates.history`
- Authorization: `$this->authorize('viewAny', ServiceRate::class)` → `service_rates.manage` permission
  - ADMIN, MANAGER: allowed
  - RECEPTION, ACCOUNTANT, SALES, HOUSEKEEPING: 403
- Validates `chargeType` via `ChargeType::tryFrom(strtoupper($chargeType))` → 422 on invalid value
- Safety limit: `->limit(500)` on rate history query
- Ordered: `effective_from DESC, id DESC`

---

## Permissions Used

| Permission | Who has it | Effect |
|------------|-----------|--------|
| `folio.view` | ADMIN, MANAGER, ACCOUNTANT, RECEPTION | Gate for accessing the timeline endpoint |
| `charge.void` | ADMIN, MANAGER | Determines whether voided entries are included in timeline |
| `service_rates.manage` | ADMIN, MANAGER | Gate for accessing the rate history endpoint via existing `ServiceRatePolicy::viewAny()` |

No new permissions added. No changes to `RolePermissionSeeder`.

---

## Timeline Data Contract

Each row returned by `PostingTimelineService::forFolio()`:

| Field | Type | Notes |
|-------|------|-------|
| `id` | `int` | FolioEntry PK |
| `entry_date` | `string` | `YYYY-MM-DD` |
| `created_at` | `string` | `YYYY-MM-DD HH:MM` |
| `charge_type` | `string\|null` | ChargeType enum value (e.g. `ROOM`) |
| `charge_label` | `string\|null` | Vietnamese label |
| `description` | `string\|null` | |
| `quantity` | `float` | |
| `unit_price` | `float` | |
| `amount` | `float` | |
| `posting_source` | `string` | `'MANUAL'` when DB default; `'NIGHT_AUDIT'`, `'BREAKFAST_JOB'`, etc. for system entries |
| `posting_key` | `string\|null` | Idempotency key for system-posted entries |
| `stay_id` | `int\|null` | |
| `room_number` | `string\|null` | Via `stay.room.room_number`; null if no stay |
| `posted_by` | `string` | User name or `'—'` |
| `is_voided` | `bool` | |
| `voided_at` | `string\|null` | `YYYY-MM-DD HH:MM` |
| `voided_by` | `string\|null` | User name |
| `void_reason` | `string\|null` | |
| `running_total` | `string` | bcadd precision string (e.g. `"300000.00"`); excludes voided entries |

---

## Service Rate History Data Contract

Each row returned by `ServiceRateController::history()`:

| Field | Type | Notes |
|-------|------|-------|
| `id` | `int` | ServiceRate PK |
| `charge_type` | `string` | Raw enum value |
| `charge_label` | `string` | Vietnamese label from `$type->label()` |
| `name` | `string` | Rate display name |
| `unit_price` | `float` | |
| `effective_from` | `string` | `YYYY-MM-DD` |
| `is_active` | `bool` | |
| `tax_rate` | `float` | |
| `gl_account_code` | `string\|null` | |
| `created_by` | `string` | User name or `'—'` |
| `created_at` | `string` | `YYYY-MM-DD HH:MM` |

Top-level Inertia props:

| Prop | Type | Value |
|------|------|-------|
| `chargeType` | `string` | e.g. `'SPA'` |
| `chargeLabel` | `string` | e.g. `'Spa'` |
| `rates` | `array` | Rate history rows, DESC order |

---

## Tests Added

**File:** `tests/Feature/PostingTimelineTest.php` — 18 tests, 108 assertions

| # | Test | Coverage |
|---|------|---------|
| 1 | `test_timeline_returns_entries_in_chronological_order` | `entry_date ASC, id ASC` ordering |
| 2 | `test_timeline_includes_posting_source` | posting_source from DB |
| 3 | `test_default_posting_source_returns_manual` | DB default 'MANUAL' flows through |
| 4 | `test_timeline_includes_room_number_for_stay_linked_entries` | `stay.room.room_number` eager load |
| 5 | `test_timeline_includes_voided_entries_when_include_voided_is_true` | voided entries visible |
| 6 | `test_timeline_excludes_voided_entries_when_include_voided_is_false` | voided entries hidden |
| 7 | `test_running_total_excludes_voided_entries` | void does not advance running total |
| 8 | `test_running_total_accumulates_non_voided_entries_in_order` | bcadd string precision |
| 9 | `test_timeline_service_does_not_mutate_folio_entries` | ADR-78 read-only compliance |
| 10 | `test_service_rate_history_returns_all_versions_for_charge_type` | all temporal rows |
| 11 | `test_service_rate_history_ordered_by_effective_from_desc` | correct ordering |
| 12 | `test_service_rate_history_includes_inactive_versions` | is_active=false rows included |
| 13 | `test_unauthorized_user_cannot_view_rate_history` | 403 for RECEPTION |
| 14 | `test_admin_can_access_timeline_and_sees_voided_entries` | HTTP + includeVoided=true |
| 15 | `test_reception_user_sees_timeline_without_voided_entries` | HTTP + includeVoided=false |
| 16 | `test_user_without_folio_view_cannot_access_timeline` | 403 for unprivileged user |
| 17 | `test_invalid_charge_type_returns_unprocessable_on_rate_history` | 422 on bad chargeType |
| 18 | `test_manager_can_access_rate_history` | MANAGER has service_rates.manage |

---

## Test Results

```
Tests:    18 passed (108 assertions)
Duration: 18.95s
```

---

## Regression Analysis

Regression suite run against all affected test files:

| Test file | Tests | Status |
|-----------|-------|--------|
| `ServiceRateCrudTest.php` | all passing | ✅ |
| `ServiceRateVersioningTest.php` | all passing | ✅ |
| `ReconciliationTest.php` | 24/24 | ✅ |
| `RevenueReportTest.php` | 18/18 | ✅ |
| `NightAuditOperationsTest.php` | all passing | ✅ |
| `NightAuditPipelineFeatureTest.php` | all passing | ✅ |
| `FolioCrudTest.php` | all passing | ✅ |
| `PaymentCrudTest.php` | all passing | ✅ |
| **Combined** | **137/137** | **✅ Zero regressions** |

---

## Code Review Results

Review conducted by `code-reviewer` agent before report.

| Severity | Issues | Resolution |
|----------|--------|-----------|
| CRITICAL | 0 | — |
| HIGH | 0 | — |
| MEDIUM | 2 | Fixed |
| LOW | 2 | Fixed |

**Fixes applied:**
- `running_total` now returned as `string` (bcadd precision preserved, not cast to float)
- Added `->limit(1000)` to `PostingTimelineService` and `->limit(500)` to `ServiceRateController::history()`
- Removed unused `use Illuminate\Http\Request;` import from `ServiceRateController`
- Replaced redundant `ChargeType::from($rate->charge_type)->label()` with `$type->label()` (the enum is already resolved before the query)

---

## Known Risks

- **Timeline truncation**: Folios with more than 1,000 entries (extremely unlikely for hotel stays) will be silently truncated. The frontend phase should surface a `timeline_truncated` indicator when `timeline.length === 1000`.
- **`posting_source` null-coalescing**: The `?? 'MANUAL'` fallback in the service is defensive code for historical rows; the current schema has `posting_source NOT NULL DEFAULT 'MANUAL'` so this path is never reached in practice.

---

## Architecture Deviations

None. All ADRs preserved:
- ADR-57–72 (Immutable Ledger): no FolioEntry writes
- ADR-73–78 (Financial Operations): no mutation of bookings, folios, payments, or service rates
- `ServiceRateService::resolveFor()` is unchanged

---

## Ready for Frontend

**YES** — backend implementation complete, code reviewed, all tests passing, zero regressions.

## Awaiting

- ChatGPT Backend Review
- Frontend implementation (blocked until Backend Review clears)
- Commit (blocked until ChatGPT Final Review clears)
