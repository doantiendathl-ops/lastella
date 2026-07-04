# Phase 3.3.6 — Extra Charges Pipeline & Package Enrollment UI

**Status:** Architecture Design — Awaiting ChatGPT Architecture Review  
**Branch:** phase-3  
**Prerequisite commit:** `2f29914` (Phase 3.3.5)

---

## 1. Objectives

### Business Goals

1. **Automate city tax collection** — Vietnamese hotels are legally required to collect tourism tax (Thuế Du Lịch) from all checked-in guests. Currently, staff must post this manually, creating inconsistency and risk of omission.

2. **Automate extra bed and extra person fees** — When a booking includes extra beds or extra persons, a nightly surcharge applies per-stay. Currently there is no automated posting; staff post it manually or forget it entirely.

3. **Surface package enrollment as a first-class UI workflow** — Packages (Breakfast, Extra Person, Extra Bed) are enrolled today via direct API calls with no management UI. Staff cannot see enrolled packages or their last posting result without querying the database.

4. **Extend the Night Audit pipeline without modifying its core** — The `PostingJob` interface (ADR-69) and `NightAuditPipeline` registration pattern (ADR-70) were explicitly designed to accept new jobs. Phase 3.3.6 validates this extensibility with three new jobs.

### User Workflow

**At check-in or during the booking flow:**
1. Receptionist opens the Booking show page.
2. Navigates to the **Packages** tab / page.
3. Sees all available per-night packages with enrollment status.
4. Enrolls/unenrolls the booking in the relevant packages.
   - Extra Person: enters the count (1–4) as the quantity.
   - Extra Bed: enters the count (1–2).
   - Breakfast: existing enrollment (no change).
5. City Tax requires no enrollment — it applies hotel-wide when `city_tax_enabled = true`.

**Each Night Audit run:**
1. Pipeline processes all CHECKED_IN stays in dependency order.
2. RoomChargePostingJob fires first (existing).
3. BreakfastPostingJob fires if enrolled (existing).
4. **NEW**: ExtraPersonPostingJob fires if `EXTRA_PERSON_PER_NIGHT` flag exists.
5. **NEW**: ExtraBedPostingJob fires if `EXTRA_BED_PER_NIGHT` flag exists.
6. **NEW**: CityTaxPostingJob fires if `city_tax_enabled` hotel setting is true.
7. Each job is idempotent — re-running the audit for the same date is safe.

**Morning reconciliation:**
1. Night Audit show page displays per-job posting results.
2. Revenue Report already groups by `charge_type` — CITY_TAX entries appear automatically.
3. Posting Timeline shows the new entries with correct source badges.

### Problem Solved

| Problem | Current State | Phase 3.3.6 Solution |
|---------|--------------|---------------------|
| City tax omissions | Manual post only, often missed | CityTaxPostingJob auto-posts nightly for all checked-in stays |
| Extra person/bed inconsistency | Manual post, inconsistent amounts | ExtraPersonPostingJob / ExtraBedPostingJob with ServiceRate catalog |
| No package visibility for staff | No UI, no audit trail | Packages.vue with enrollment history and last post date |
| Package quantity not tracked | `value = '1'` hardcoded | `value` field stores quantity; PostingJob uses it for `quantity` field |

---

## 2. Scope

### Included

- `ChargeType::CityTax` enum case (new) and `label()` update
- `CityTaxPostingJob` — hotel-wide auto-apply per checked-in stay per night
- `ExtraPersonPostingJob` — per-stay, enrollment-based, quantity from flag value
- `ExtraBedPostingJob` — per-stay, enrollment-based, quantity from flag value
- `PackageEnrollmentService` additions: new constants, unenroll guards for new packages, `getEnrollmentSummary()` method
- `PackageEnrollmentController` — show / enroll / unenroll endpoints
- `Admin/Booking/Packages.vue` — package management page
- `hotel_settings` seed entry: `city_tax_enabled` (default `false`)
- `service_rates` seed entries: CITY_TAX, EXTRA_BED, EXTRA_PERSON catalog rows
- `NightAuditService` updated to register 3 new jobs
- Night Audit show page update: group logs by `job_class` for readability
- 3 new ADRs (ADR-79, ADR-80, ADR-81)
- Feature tests: ≥ 20 new tests

### Excluded

- Multi-folio / split billing (separate phase)
- Package bundling or discount logic
- Package application to historical nights (backfill)
- CityTax per-person calculation (designed for fixed-per-stay; per-person is a future ADR)
- Removing or modifying existing package (BreakfastPostingJob) behaviour
- PDF/printed receipt of tax breakdown
- Government reporting export (separate compliance phase)

### Dependencies

| Dependency | Status | Notes |
|-----------|--------|-------|
| `PostingJob` interface | Exists | `app/Services/Posting/PostingJob.php` |
| `PostingContext` / `PostingResult` | Exists | `app/Services/Posting/` |
| `NightAuditPipeline::register()` | Exists | additive only |
| `PackageEnrollmentService` | Exists | extend, do not replace |
| `BookingPackageFlag` model + table | Exists | `value` field used as quantity |
| `ServiceRateService::resolveFor()` | Exists | unchanged |
| `BusinessDateService` | Exists | unchanged |
| `FolioService::addCharge()` | Exists | NOT used by PostingJobs (jobs write FolioEntry directly with lockForUpdate) |
| `HotelSettingsService::get()` | Exists | for `city_tax_enabled` flag |
| `AuditLogService` | Exists | log enrollment mutations |

### Prerequisites

1. Phase 3.3.5 committed and tagged (✓ `2f29914`)
2. ServiceRate catalog must have entries for CITY_TAX, EXTRA_BED, EXTRA_PERSON before going live (seeded or created manually via existing ServiceRate UI)
3. `city_tax_enabled` must be set to `true` in hotel settings UI before city tax posts (default false — safe)

---

## 3. Architecture

### 3.1 New Enum Case

**`ChargeType::CityTax`**

```
File: app/Enums/ChargeType.php
Change: ADD case CityTax = 'CITY_TAX';
        ADD label: 'Thuế du lịch'
Impact: Non-breaking. New case only. options() automatically includes it.
        ServiceRates Index correctly blocks ROOM type but not CITY_TAX.
        Posting Timeline, Revenue Report, Reconciliation all group by charge_type string —
        CITY_TAX entries appear automatically with the new label.
```

### 3.2 New PostingJobs

All three jobs follow the exact `BreakfastPostingJob` pattern established in Phase 3.3.2. No interface changes. No pipeline changes beyond `register()` calls.

---

#### `CityTaxPostingJob`

```
Location:  app/Services/Posting/CityTaxPostingJob.php
Interface: PostingJob
Inject:    ServiceRateService, HotelSettingsService

shouldProcess(context):
  - folio.status === 'OPEN'
  - HotelSettingsService::getBool('city_tax_enabled') === true
  - stay is not null
  → True for ALL checked-in stays when enabled (no enrollment flag)

isAlreadyPosted(context):
  - FolioEntry::where('posting_key', 'CITY_TAX_{stay_id}_{YYYY-MM-DD}')
                ->whereNull('voided_at')
                ->exists()

execute(context):
  - rate = ServiceRateService::resolveFor(ChargeType::CityTax, businessDate)
  - If rate === null: return PostingResult::skipped('No active CITY_TAX rate')
  - quantity = 1 (fixed per stay per night; quantity extension is ADR-79 future)
  - DB::transaction:
      lockForUpdate folio
      check folio open
      lockForUpdate idempotency check
      FolioEntry::create([
        posting_key    => 'CITY_TAX_{stay_id}_{YYYY-MM-DD}',
        posting_source => 'NIGHT_AUDIT',
        charge_type    => ChargeType::CityTax,
        description    => 'Thuế du lịch đêm {DD/MM/YYYY}',
        quantity       => '1.00',
        unit_price     => rate->unit_price,
        amount         => rate->unit_price (bcmul '1.00')
        entry_date     => businessDate,
        posted_by      => null (system)
      ])
      return PostingResult::posted($entry)

rollback(context):
  - Delete FolioEntry by posting_key (hard delete for failed audit rollback)

dependsOn():
  - [RoomChargePostingJob::class]
  - Ensures room is posted first; city tax on room-confirmed nights only
```

---

#### `ExtraPersonPostingJob`

```
Location:  app/Services/Posting/ExtraPersonPostingJob.php
Interface: PostingJob
Inject:    ServiceRateService, PackageEnrollmentService

shouldProcess(context):
  - folio.status === 'OPEN'
  - stay is not null
  - PackageEnrollmentService::isEnrolled(booking, 'EXTRA_PERSON_PER_NIGHT')

isAlreadyPosted(context):
  - FolioEntry::where('posting_key', 'EXTRA_PERSON_{stay_id}_{YYYY-MM-DD}')
                ->whereNull('voided_at')
                ->exists()

execute(context):
  - flag = BookingPackageFlag::where(booking_id, 'EXTRA_PERSON_PER_NIGHT')->first()
  - quantity = max(1, intval($flag->value ?? '1'))   [ADR-80: value stores quantity]
  - rate = ServiceRateService::resolveFor(ChargeType::ExtraPerson, businessDate)
  - If rate === null: return PostingResult::skipped('No active EXTRA_PERSON rate')
  - DB::transaction:
      lockForUpdate folio
      check folio open
      lockForUpdate idempotency check
      FolioEntry::create([
        posting_key    => 'EXTRA_PERSON_{stay_id}_{YYYY-MM-DD}',
        posting_source => 'NIGHT_AUDIT',
        charge_type    => ChargeType::ExtraPerson,
        description    => 'Người thêm ({quantity} người) đêm {DD/MM/YYYY}',
        quantity       => (string) $quantity,  [e.g. '2.00']
        unit_price     => rate->unit_price,
        amount         => bcmul(unit_price, quantity, 2)
        entry_date     => businessDate,
        posted_by      => null
      ])

rollback(context): Delete by posting_key

dependsOn(): [RoomChargePostingJob::class]
```

---

#### `ExtraBedPostingJob`

```
Location:  app/Services/Posting/ExtraBedPostingJob.php
Interface: PostingJob
Inject:    ServiceRateService, PackageEnrollmentService

Pattern:   Identical to ExtraPersonPostingJob
PackageKey: 'EXTRA_BED_PER_NIGHT'
ChargeType: ChargeType::ExtraBed
PostingKey: 'EXTRA_BED_{stay_id}_{YYYY-MM-DD}'
Description: 'Giường phụ ({quantity} giường) đêm {DD/MM/YYYY}'
dependsOn(): [RoomChargePostingJob::class]
```

---

### 3.3 PackageEnrollmentService Additions

```
File: app/Services/PackageEnrollmentService.php (MODIFY, additive only)

New constants:
  const EXTRA_PERSON_PER_NIGHT = 'EXTRA_PERSON_PER_NIGHT';
  const EXTRA_BED_PER_NIGHT    = 'EXTRA_BED_PER_NIGHT';

  const ALLOWED_PACKAGES = [
    self::BREAKFAST_PER_NIGHT,
    self::EXTRA_PERSON_PER_NIGHT,
    self::EXTRA_BED_PER_NIGHT,
  ];

New method: enroll() signature change (backward compatible)
  public function enroll(Booking $booking, string $packageKey, int $quantity = 1, ?User $enrolledBy = null): BookingPackageFlag
    - validates $packageKey in ALLOWED_PACKAGES → throws InvalidPackageKeyException if not
    - value = (string) max(1, $quantity)   [ADR-80]
    - firstOrCreate with value update if quantity changed

New method:
  public function getEnrollmentSummary(Booking $booking): array
    - Returns array keyed by packageKey with:
      { enrolled: bool, quantity: int, enrolled_at: string|null, enrolled_by: string|null }
    - Used by PackageEnrollmentController::show()

unenroll() guard extension:
  private function guardAlreadyPostedToday(Booking $booking, string $packageKey): void
    - Maps packageKey → expected posting_key prefix
    - BREAKFAST_PER_NIGHT → 'BREAKFAST_{stay_id}_{today}'
    - EXTRA_PERSON_PER_NIGHT → 'EXTRA_PERSON_{stay_id}_{today}'
    - EXTRA_BED_PER_NIGHT → 'EXTRA_BED_{stay_id}_{today}'
    - If today's entry exists → throw PackageAlreadyPostedException

  unenroll() updated: calls guardAlreadyPostedToday for all package keys
```

### 3.4 New Controller

**`PackageEnrollmentController`**

```
Location: app/Http/Controllers/Admin/PackageEnrollmentController.php

Methods:

  show(Request, Booking): Response
    - Permission: abort_unless(can('folio.view'), 403)
    - Load booking with folio, active stays, package flags, last night audit logs
    - Returns Inertia 'Admin/Booking/Packages' with:
        booking: { id, booking_code, customer_name, status }
        enrollments: PackageEnrollmentService::getEnrollmentSummary(booking)
        available_packages: [{ key, label, charge_label, current_rate: float|null }]
        last_audit_logs: [ last NightAuditBookingLog per package for this booking ]
        can: { manage_packages: bool }

  enroll(Request, Booking): RedirectResponse
    - Permission: abort_unless(can('package.manage'), 403)
    - Validate: package_key (in ALLOWED_PACKAGES), quantity (int 1–4)
    - Guard: booking not terminal
    - PackageEnrollmentService::enroll(booking, package_key, quantity, user)
    - AuditLogService::log(...)
    - Redirect back with flash success

  unenroll(Request, Booking): RedirectResponse
    - Permission: abort_unless(can('package.manage'), 403)
    - Validate: package_key (in ALLOWED_PACKAGES)
    - PackageEnrollmentService::unenroll(booking, package_key)
      → may throw PackageAlreadyPostedException → redirect back with flash error
    - AuditLogService::log(...)
    - Redirect back with flash success
```

### 3.5 Routes

```
Route group: admin.* prefix, auth middleware

New routes (added after existing booking routes):
  GET  bookings/{booking}/packages          → PackageEnrollmentController::show()
                                              name: admin.bookings.packages
  POST bookings/{booking}/packages          → PackageEnrollmentController::enroll()
                                              name: admin.bookings.packages.enroll
  DELETE bookings/{booking}/packages/{key} → PackageEnrollmentController::unenroll()
                                              name: admin.bookings.packages.unenroll
```

### 3.6 Vue Page

**`Admin/Booking/Packages.vue`**

```
Route: GET /admin/bookings/{booking}/packages
Props:
  booking: { id, booking_code, customer_name, status }
  enrollments: { BREAKFAST_PER_NIGHT: { enrolled, quantity, enrolled_at, enrolled_by },
                 EXTRA_PERSON_PER_NIGHT: { ... },
                 EXTRA_BED_PER_NIGHT: { ... } }
  available_packages: [{ key, label, charge_label, current_rate }]
  last_audit_logs: [{ package_key, result, posted_at }]
  can: { manage_packages }

UI Layout:
  - Header: "Gói dịch vụ — {booking_code}"
  - Back link: → admin.bookings.show
  - Read-only note if !can.manage_packages

  For each available package:
    Card:
      - Package name + charge type label
      - Current rate (from active ServiceRate, or "Chưa có giá")
      - Enrollment toggle (green = enrolled, gray = not enrolled)
      - If enrolled: quantity badge, enrolled_by, enrolled_at
      - If enrolled: last posted date from last_audit_logs
      - If !can.manage_packages: toggle disabled

  City Tax note (informational, no toggle):
    - "Thuế du lịch tự động áp dụng theo cài đặt khách sạn."
    - Current hotel setting: enabled/disabled
    - Only visible to ADMIN/MANAGER

  Empty state: no available packages configured.
  Error state: flash error on PackageAlreadyPostedException.
```

### 3.7 NightAuditService Update

```
File: app/Services/NightAuditService.php (MODIFY)

Inject 3 new jobs:
  private readonly CityTaxPostingJob $cityTaxJob,
  private readonly ExtraPersonPostingJob $extraPersonJob,
  private readonly ExtraBedPostingJob $extraBedJob,

Register order in runForDate():
  $this->pipeline
    ->register($this->roomChargeJob)        // existing
    ->register($this->breakfastJob)          // existing
    ->register($this->extraPersonJob)        // NEW — depends on room_charge
    ->register($this->extraBedJob)           // NEW — depends on room_charge
    ->register($this->cityTaxJob)            // NEW — depends on room_charge
    ->run($auditRun, $businessDate);

Pipeline runs jobs in registration order. All three new jobs depend on
RoomChargePostingJob::class but not on each other, so order among them
is arbitrary. Registered ExtraPerson → ExtraBed → CityTax for determinism.
```

### 3.8 Night Audit Show Page Enhancement

```
File: resources/js/Pages/Admin/NightAudit/Show.vue (MODIFY)

New data grouping: NightAuditController::show() already passes booking logs.
Enhancement: group logs by job_class, show per-job totals (POSTED / SKIPPED / FAILED).

Controller change: NightAuditController::show() — aggregate logs by job_class:
  'job_summary' => NightAuditBookingLog::where('run_id', $run->id)
                    ->selectRaw('job_class, result, count(*) as count')
                    ->groupBy('job_class', 'result')
                    ->get()
                    ->groupBy('job_class')

This is an additive prop addition — existing template unaffected.
Vue: add a "Per-Job Summary" table above the per-booking log list.
```

### 3.9 Models

**No new tables.** All models are existing:
- `BookingPackageFlag` — `value` field re-interpreted as quantity (string, already TEXT)
- `FolioEntry` — no schema changes
- `NightAuditBookingLog` — no schema changes
- `HotelSettings` — no schema changes; new seed entry only

### 3.10 Policies & Permissions

**New permission: `package.manage`**

```
RolePermissionSeeder addition:
  ADMIN    → package.manage ✓
  MANAGER  → package.manage ✓
  RECEPTION → package.manage ✗
  ACCOUNTANT → package.manage ✗

Viewing packages (show page):
  folio.view (existing) — ADMIN, MANAGER, ACCOUNTANT, RECEPTION can VIEW
  package.manage (new) — only ADMIN, MANAGER can ENROLL/UNENROLL
```

**No new Policy class needed.** Permission checks via `abort_unless(can(...))` directly in controller, consistent with existing PostingTimelineController pattern.

### 3.11 New ADRs

#### ADR-79: CityTax Hotel-Wide Auto-Apply Strategy

**Decision:** City tax applies automatically to ALL checked-in stays when `hotel_settings.city_tax_enabled = true`. No per-booking enrollment flag is required.

**Rationale:** Vietnamese tourism tax is legally mandatory for all guests without exception. Requiring staff to opt-in each booking would create compliance risk. The hotel-wide setting provides an on/off switch for configuration or audit periods.

**Implications:**
- CityTaxPostingJob's `shouldProcess()` ignores `BookingPackageFlag` entirely.
- If `city_tax_enabled = false` (default), no entries are ever posted for CITY_TAX, maintaining backward compatibility.
- If a hotel does NOT charge city tax (e.g., certain property types), keep the setting `false`.
- The CITY_TAX ServiceRate must be configured before enabling the setting; if no rate exists, the job skips with `'No active CITY_TAX rate'`.

**Future extension:** ADR-79 reserves the option of a per-booking override flag (`city_tax_exempt = true`) for diplomatic guests, long-stay residents, etc. This is not implemented in Phase 3.3.6.

---

#### ADR-80: Package Quantity via BookingPackageFlag.value Field

**Decision:** The `value` column on `booking_package_flags` stores the quantity of the package as a string integer. The `PostingJob::execute()` reads `intval($flag->value)` to determine the `quantity` field on the `FolioEntry`.

**Rationale:** The `value` field was added as a generic TEXT field precisely to support future metadata. Encoding quantity here avoids a new column and preserves the `firstOrCreate` idempotency pattern.

**Contract:**
- `value` must be a string integer ≥ 1 (validation enforced in `PackageEnrollmentController`).
- `value = '1'` is the default and means "one unit per night" (same as breakfast today).
- `amount = bcmul(unit_price, value, 2)` — PostingJob computes amount with quantity.

**Breaking change risk:** Existing `BREAKFAST_PER_NIGHT` flags have `value = '1'`. `BreakfastPostingJob` ignores the `value` field (hardcodes quantity '1.00'). No change to breakfast behaviour in Phase 3.3.6.

**IMPORTANT:** `BreakfastPostingJob` is NOT updated to use `value` as quantity in Phase 3.3.6. Breakfast is always 1 per guest per night by business rule. Only ExtraPerson and ExtraBed PostingJobs use `value` as quantity.

---

#### ADR-81: Posting Key Pattern for Per-Night Per-Stay Charges

**Decision:** Formalize the posting key convention for all per-night, per-stay charges:

```
{CHARGE_NAME}_{stay_id}_{YYYY-MM-DD}
```

Where `{CHARGE_NAME}` is:
- `ROOM_NIGHT` — room charge (ADR-58)
- `BREAKFAST` — breakfast
- `EXTRA_PERSON` — extra person per night
- `EXTRA_BED` — extra bed per night
- `CITY_TAX` — city tax

**Rationale:** Formalizes an implicit pattern. All per-night system charges use this format. Makes the posting key namespace predictable and collision-free across all job types.

**Lifecycle charges** (ADR-12, Phase 3.1) use a different format:
- `LATE_CHECKOUT_{stay_id}` (no date — one per stay, not per night)
- `EARLY_CHECKIN_{stay_id}` (no date — one per stay)

These lifecycle formats are unchanged.

---

### 3.12 Dependency Graph

```
Phase 3.3.6 Component Dependencies:

PackageEnrollmentController
  └── PackageEnrollmentService (extended)
        └── BookingPackageFlag (existing model)
        └── FolioEntry (read-only, guard check)
        └── BusinessDateService (guard date)

NightAuditService (extended)
  └── NightAuditPipeline (existing, additive)
        ├── RoomChargePostingJob (existing, unchanged)
        ├── BreakfastPostingJob (existing, unchanged)
        ├── ExtraPersonPostingJob (NEW)
        │     ├── ServiceRateService (existing)
        │     └── PackageEnrollmentService (existing)
        ├── ExtraBedPostingJob (NEW)
        │     ├── ServiceRateService (existing)
        │     └── PackageEnrollmentService (existing)
        └── CityTaxPostingJob (NEW)
              ├── ServiceRateService (existing)
              └── HotelSettingsService (existing)

Admin/Booking/Packages.vue
  └── PackageEnrollmentController::show()
  └── PackageEnrollmentController::enroll()   → POST
  └── PackageEnrollmentController::unenroll() → DELETE
```

### 3.13 Interaction with Existing Systems

| System | Interaction |
|--------|------------|
| **Folio** | PostingJobs write FolioEntry with lockForUpdate. No change to FolioService. |
| **Booking** | Terminal booking guard in PackageEnrollmentController::enroll() mirrors FolioService::addCharge() pattern. |
| **Stay** | PostingJobs receive Stay via PostingContext. stay_id written to FolioEntry. |
| **Night Audit** | NightAuditService registers 3 new jobs. Pipeline unchanged. NightAuditBookingLog gets new `job_class` values. |
| **Revenue** | RevenueReportService::dailySummary() already groups by charge_type string. CITY_TAX, EXTRA_PERSON, EXTRA_BED automatically appear in reports once entries exist. No code change. |
| **Reconciliation** | ReconciliationService reads folio_total from all non-voided entries. New charge types add to folio_total correctly. No code change. |
| **Posting Timeline** | PostingTimelineService::forFolio() returns all entries. New entries appear with correct charge_label from ChargeType. No code change. |
| **Service Rate History** | ServiceRateController::history() accepts any ChargeType. CITY_TAX rates visible once added to catalog. No code change. |
| **Night Audit Show page** | Enhanced with per-job summary (additive prop). |

---

## 4. Security

### Permissions

| Action | Required Permission | Roles |
|--------|-------------------|-------|
| View Packages page | `folio.view` | ADMIN, MANAGER, ACCOUNTANT, RECEPTION |
| Enroll/Unenroll | `package.manage` | ADMIN, MANAGER |
| City Tax auto-post | Hotel setting (system) | — |
| View Rate History (CITY_TAX) | `service_rates.manage` | ADMIN, MANAGER |

### Input Validation

```
PackageEnrollmentController::enroll() validation:
  package_key: required | string | in:BREAKFAST_PER_NIGHT,EXTRA_PERSON_PER_NIGHT,EXTRA_BED_PER_NIGHT
  quantity: required | integer | min:1 | max:4

PackageEnrollmentController::unenroll() validation:
  package_key: required | string | in:BREAKFAST_PER_NIGHT,EXTRA_PERSON_PER_NIGHT,EXTRA_BED_PER_NIGHT
```

The `package_key` whitelist is the critical security boundary. Free-form string input would allow injection of arbitrary keys into `BookingPackageFlag`, creating phantom package flags that PostingJobs would silently ignore.

### Audit Trail

- `PackageEnrollmentController::enroll()` logs via `AuditLogService` with action `'package_enrolled'`, model `Booking`, metadata `{ package_key, quantity }`.
- `PackageEnrollmentController::unenroll()` logs `'package_unenrolled'`.
- PostingJob entries carry `posting_source = 'NIGHT_AUDIT'` and `posting_key` — fully traceable.
- `NightAuditBookingLog` records per-job, per-stay result — full audit trail in Night Audit show page.

### Race Conditions

**Scenario 1: Concurrent enroll + Night Audit**
- Staff enrolls at 22:01; Night Audit reads `shouldProcess()` at 22:00 (before enroll).
- Result: job skips tonight (no flag at read time). Enrolls for tomorrow. Acceptable.

**Scenario 2: Concurrent unenroll + Night Audit**
- Night Audit reads `shouldProcess()` = true at 22:00; staff unenrolls at 22:01.
- Night Audit proceeds to `execute()` which re-checks idempotency inside `lockForUpdate` transaction.
- Flag is gone but transaction has already started; the FolioEntry is created.
- `PackageAlreadyPostedException` guard in unenroll fires at 22:01 (entry just posted).
- Result: tonight's entry is posted; unenroll blocked for tonight; staff can unenroll tomorrow morning.
- Acceptable: the posting happened under the lock, the entry is valid.

**Scenario 3: Double Night Audit run**
- Two Night Audit runs for same date (retry scenario).
- `posting_key UNIQUE` constraint and `isAlreadyPosted()` double-layer defence prevent double-posting. Existing pattern (ADR-61).

### Idempotency

All three new PostingJobs follow the existing double-layer pattern:
1. `isAlreadyPosted()` pre-check (outside transaction, early exit)
2. `lockForUpdate` on folio + `lockForUpdate` on FolioEntry posting_key inside transaction
3. `posting_key UNIQUE` DB constraint as final backstop

---

## 5. Regression Analysis

### Potential Regression Points

| Change | Risk | Mitigation |
|--------|------|-----------|
| ChargeType::CityTax new case | LOW — additive enum case | `options()` auto-includes; no existing switch/match covers ChargeType exhaustively |
| NightAuditService registers 3 new jobs | LOW — pipeline is additive | New jobs only fire when enrolled or city_tax_enabled; default = no change for existing bookings |
| PackageEnrollmentService::enroll() signature change (quantity param) | LOW — default `int $quantity = 1` preserves backward compatibility | Existing callers (none in current codebase) unaffected |
| PackageEnrollmentService::unenroll() guard extended | MEDIUM — unenroll now also checks ExtraPerson/ExtraBed | Only affects NEW package keys; BREAKFAST_PER_NIGHT guard logic is unchanged |
| NightAuditController::show() adds `job_summary` prop | LOW — additive Inertia prop; existing Vue template ignores unknown props | Show.vue gets new data; old template renders unchanged |
| `value` field of BookingPackageFlag re-interpreted as quantity | LOW — existing BREAKFAST_PER_NIGHT flags have `value='1'`; BreakfastPostingJob still hardcodes quantity '1.00' | BreakfastPostingJob is NOT changed; only new jobs use `value` |

### Backward Compatibility

- **Existing bookings**: No new charges are posted unless staff explicitly enrolls in Extra Person / Extra Bed, OR the hotel enables city_tax_enabled (defaults to false).
- **Existing tests**: All existing PostingJob tests unaffected (no changes to RoomChargePostingJob, BreakfastPostingJob, LateCheckoutFeePostingJob, EarlyCheckinFeePostingJob).
- **Revenue/Reconciliation/Posting Timeline**: Additive — new charge types appear automatically when entries exist; no code changes needed.

---

## 6. Testing Strategy

### Unit Tests (per PostingJob)

For each of `CityTaxPostingJob`, `ExtraPersonPostingJob`, `ExtraBedPostingJob`:
1. `shouldProcess` returns false when folio closed
2. `shouldProcess` returns false when flag absent (ExtraPerson/ExtraBed) / city_tax_enabled false (CityTax)
3. `shouldProcess` returns true when enrolled / enabled
4. `isAlreadyPosted` returns true when posting_key exists
5. `execute` posts entry with correct fields and returns `PostingResult::posted()`
6. `execute` returns `alreadyPosted` on idempotency check
7. `execute` returns `skipped` when no active ServiceRate
8. `rollback` deletes the entry

### Feature Tests (controller + pipeline integration)

**PackageEnrollmentController:**
1. ADMIN can view packages page
2. RECEPTION can view packages page (folio.view)
3. RECEPTION cannot enroll (package.manage required)
4. ADMIN can enroll BREAKFAST_PER_NIGHT
5. ADMIN can enroll EXTRA_PERSON_PER_NIGHT with quantity 2
6. ADMIN can enroll EXTRA_BED_PER_NIGHT
7. Enrollment rejects invalid package_key
8. Enrollment rejects quantity 0 or > 4
9. ADMIN can unenroll when not yet posted today
10. Unenroll blocked when already posted today
11. Enrollment on terminal booking is rejected

**Night Audit pipeline integration:**
12. Night Audit posts EXTRA_PERSON entry for enrolled booking
13. Night Audit skips EXTRA_PERSON for non-enrolled booking
14. Night Audit posts CITY_TAX when city_tax_enabled=true
15. Night Audit skips CITY_TAX when city_tax_enabled=false
16. Night Audit posts EXTRA_BED with quantity=2 (amount = 2 × unit_price)
17. Retry audit run skips already-posted entries (idempotency)
18. No active ServiceRate → job skips, no error thrown
19. Folio closed → job skips
20. NightAuditBookingLog records correct job_class for new jobs

### Regression Tests

Run existing test suite in full after implementation:
- `NightAuditPipelineFeatureTest.php` — room charge + breakfast behaviour unchanged
- `ServiceRateCrudTest.php` — CITY_TAX can be created via existing UI
- `PostingTimelineTest.php` — timeline shows CITY_TAX, EXTRA_PERSON, EXTRA_BED entries correctly
- `RevenueReportTest.php` — new charge types appear in revenue breakdown
- `ReconciliationTest.php` — outstanding balances include new charge types in folio_total

### Manual Scenarios

1. Create booking, check in, enroll EXTRA_PERSON_PER_NIGHT (qty 2), run Night Audit → verify 2× rate amount posted.
2. Enable city_tax_enabled, run Night Audit → verify all checked-in stays get CITY_TAX entry.
3. Attempt to unenroll breakfast after today's Night Audit → verify blocked with Vietnamese error message.
4. Open Packages page as RECEPTION → toggles are visible but disabled.
5. Open Packages page as MANAGER → toggles are interactive.
6. Retry Night Audit after failure → new jobs only post once (idempotency).

---

## 7. Implementation Plan — See phase-3.3.6-implementation-plan.md

---

## 8. Ready for Review

**Architecture design complete. No code has been written or modified.**

This document is submitted for ChatGPT Architecture Review.

**Open questions for reviewer:**
1. Should CityTax quantity be configurable (e.g., per adult count) in Phase 3.3.6, or is flat per-stay the correct first step? (ADR-79 defers per-person to future.)
2. Should `BreakfastPostingJob` also be updated to use `value` as quantity for consistency? (Currently deferred to avoid regression risk.)
3. Is `max_quantity = 4` for ExtraPerson/ExtraBed the right cap, or should it be configurable via hotel_settings?
4. Should the Package Management page be a separate route or a tab inside Booking Show?
