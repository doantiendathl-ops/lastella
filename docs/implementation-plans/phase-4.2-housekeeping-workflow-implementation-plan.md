# Phase 4.2 — Housekeeping Workflow Implementation Plan

**Date:** 2026-07-05  
**Branch:** phase-3  
**Architecture Review:** PASS WITH CHANGES (approved)  
**ADRs:** ADR-84, ADR-85, ADR-86, ADR-87, ADR-88, ADR-89, ADR-90  
**Baseline Commit:** 31e840a (phase-4.1 docs)  
**Baseline Tag:** phase-4.1  
**Status:** READY FOR BACKEND IMPLEMENTATION

---

## 1. Executive Summary

### Objective

Phase 4.2 implements the complete **Housekeeping Workflow** for Lastella PMS — the operational system that tracks room cleanliness, assigns cleaning tasks to housekeepers, manages inspection outcomes, and exposes a dedicated Housekeeping Board for all relevant staff roles.

### Financial Isolation Guarantee

This is a **pure operational module**. The following are explicitly excluded from all Phase 4.2 code paths:

- **Zero `FolioEntry` records created** by any housekeeping action
- **Zero `BookingPayment` records touched**
- **Zero `NightAuditPipeline` jobs added**
- **`FolioService::calculateGuardedFolioTotal()` is never called**
- **Phase 3 Financial Foundation is completely unchanged**

### Key ADRs (summary)

| ADR | Decision |
|-----|---------|
| ADR-84 | Automatic room status: checkIn → OCCUPIED, checkOut → VACANT_DIRTY (non-throwing hooks) |
| ADR-85 | `housekeeping_assignments` as separate table (not columns on `rooms`) |
| ADR-86 | `cleaning_records` as typed table (not audit_logs JSON queries) |
| ADR-87 | Room lock with `SELECT FOR UPDATE` + pre-condition validation on every transition |
| ADR-88 | CLEANING status blocks room assignment availability |
| ADR-89 | HOUSEKEEPING permission revision: remove `rooms.manage/room.assign/room.unassign`, add 5 granular permissions |
| ADR-90 | Assignment strategy: Manual + Self-assignment in Phase 4.2; future auto-assignment reserved |

### Seed Audit Result

No test in `RoomAvailabilityCheckerTest` or `BookingManagementUiTest` sets a room to `CLEANING` status. Safe to modify `isRoomUnavailable()` without test breakage.

---

## 2. Architecture References

| Document | Role |
|----------|------|
| `docs/reports/phase-4.2-architecture-review.md` | Approved architecture review — all ADRs defined |
| `docs/architecture/phase-4.1-architecture-baseline.md` | Phase 4.1 baseline — permission matrix, test baseline (26 pre-existing failures), service patterns |
| `docs/implementation-plans/phase-4.1-room-setup-requests-implementation-plan.md` | Reference implementation plan structure and patterns |
| `docs/architecture/phase-3-architecture-baseline.md` | P3 canonical lock order; financial module isolation constraints |

---

## 3. Scope

### In Scope — Phase 4.2

| Component | Layer |
|-----------|-------|
| `create_housekeeping_assignments_table` migration | Database |
| `create_cleaning_records_table` migration | Database |
| `add_last_cleaned_at_to_rooms_table` migration | Database |
| `HousekeepingAssignmentStatus` PHP backed enum | Backend |
| `CleaningPriority` PHP backed enum (4 levels) | Backend |
| `CleaningReason` PHP backed enum (7 values) | Backend |
| `InspectionResult` PHP backed enum | Backend |
| `HousekeepingAssignment` Eloquent model + relationships | Backend |
| `CleaningRecord` Eloquent model + relationships | Backend |
| `HousekeepingAssignmentFactory`, `CleaningRecordFactory` | Backend |
| `HousekeepingService` — 9 methods | Backend |
| AuditObserver registration for new models | Backend |
| Integration hook: `StayService::checkIn()` | Backend |
| Integration hook: `StayService::checkOut()` | Backend |
| `RoomAvailabilityRuleService::isRoomUnavailable()` update | Backend |
| `RoomAvailabilityCheckerService::resolveAvailability()` update | Backend |
| `HousekeepingController` — 8 routes | Backend |
| `HousekeepingPolicy` (assignment gate) | Backend |
| `RolePermissionSeeder` revision (ADR-89) | Backend |
| `RoomPolicy` update (board access gate) | Backend |
| `Housekeeping/Index.vue` (Board) | Frontend |
| `RoomAvailability/Index.vue` cleaning badge update | Frontend |
| `AppLayout.vue` nav entry | Frontend |
| Unit tests: `HousekeepingServiceTest`, `HousekeepingPolicyTest` | Testing |
| Feature tests: `HousekeepingWorkflowTest`, `HousekeepingUiTest` | Testing |
| Feature tests: `StayServiceHookTest` (checkIn/checkOut) | Testing |
| Feature tests: `RoomAvailabilityRuleTest` (CLEANING blocks) | Testing |
| Race condition test: concurrent assignment claim | Testing |

### Out of Scope — Phase 4.2

| Component | Reason |
|-----------|--------|
| Auto-assignment (Round Robin, Workload, Floor-based) | ADR-90 — future extension |
| Housekeeping Dashboard (metrics, fail rate, avg time) | Future extension |
| Push notifications | Phase 5 |
| Mobile app | Phase 5 |
| Guest-facing request form | Phase 5 |
| Financial modules | Phase 3 — closed |

---

## 4. ADR-90: Housekeeping Assignment Strategy

> **New ADR — not in architecture review.** Created per ChatGPT feedback.

### Context

The architecture review defined `assignRoom()` as a generic method but did not specify the assignment strategy model. Real hotels use multiple strategies (manual dispatch, self-service, automated round-robin) and may switch between them.

### Alternatives Considered

| Strategy | Description | Phase |
|----------|-------------|-------|
| **Manual** | MANAGER or ADMIN assigns a specific housekeeper | 4.2 |
| **Self-Assignment** | HOUSEKEEPING claims an unassigned room from the pool | 4.2 |
| **Round Robin** | System assigns next available housekeeper in rotation | Future |
| **Workload** | System assigns the housekeeper with fewest active tasks | Future |
| **Floor-Based** | Rooms assigned to housekeeper responsible for that floor | Future |

### Decision

Phase 4.2 implements **Manual** and **Self-Assignment** only. The service layer is structured to accommodate future auto-assignment strategies without architectural change:

- `HousekeepingService::assignRoom($room, $assignee, $actor, $priority, $reason, $notes)` — `$assignee` is nullable (self-assignment passes `Auth::user()`)
- The strategy logic is isolated in `HousekeepingService` — controllers simply call `assignRoom()` regardless of how the assignee is determined
- Future strategies add a new method (e.g., `resolveAssignee(Room $room): ?User`) and call `assignRoom()` with the result
- No controller or route changes needed to add auto-assignment

### Self-Assignment Rule

A HOUSEKEEPING user can self-assign only to rooms in `VACANT_DIRTY` status with no existing active assignment. The service enforces this:

```
POST /admin/housekeeping/{room}/assign
  body: { assigned_to: null } → self-assign (assigned_to = Auth::id())
  body: { assigned_to: 5 }   → MANAGER assigns to user_id=5
```

HOUSEKEEPING users cannot assign to other housekeepers (service enforces: if `can('housekeeping.assign')` but not `can('room.maintenance')`, then `$assignee` must equal `Auth::id()`).

### Consequence

Phase 4.2 is deployed with Manual + Self-Assignment. Auto-assignment strategies can be added in Phase 4.3+ as service-layer additions without migrations or route changes.

---

## 5. Design Decisions (ChatGPT Feedback)

### 5.1 CleaningReason: Enum vs Lookup Table

**Decision: PHP backed enum.**

| Option | Pros | Cons |
|--------|------|------|
| PHP enum | Type-safe, no JOIN, zero migration for new reasons | Requires deployment for new reason |
| Lookup table | Hotel staff can add reasons via admin UI | Requires admin UI, JOIN on every record, overkill for finite set |

**Rationale:** Cleaning reasons are operational constants, not hotel-configurable. The 7 defined values cover all realistic scenarios. A lookup table adds a migration, CRUD admin UI, and a JOIN to `cleaning_records` queries — all for a field that rarely changes. If a new reason is needed post-launch, a migration adding an enum case is simpler than a lookup table admin.

**Enum values:**

| Value | When Used |
|-------|-----------|
| `CHECKOUT` | Standard post-checkout cleaning (auto-created by hook) |
| `STAYOVER` | Mid-stay cleaning for checked-in guest |
| `VIP` | Priority cleaning for VIP guest room |
| `MAINTENANCE` | Cleaning after maintenance/repair work |
| `DEEP_CLEANING` | Scheduled deep clean (no guest occupancy) |
| `EARLY_CHECKIN` | Pre-arrival cleaning for early check-in guest |
| `SPECIAL_REQUEST` | Triggered by a guest special request flag |

### 5.2 CleaningPriority: Enum (not numeric 1-10)

**Decision: PHP backed enum with 4 levels.**

```
EMERGENCY → numeric 1 (highest priority)
HIGH      → numeric 2
NORMAL    → numeric 3  (default)
LOW       → numeric 4
```

`HousekeepingService` maps enum to integer only when building ORDER BY queries. The `housekeeping_assignments.priority` column stores the **enum string value** (VARCHAR, not integer), consistent with how `rooms.status`, `stays.status`, etc. are stored in this codebase.

Sorting: `ORDER BY FIELD(priority, 'EMERGENCY', 'HIGH', 'NORMAL', 'LOW')` or use a `priority_order` helper in the service.

**Rationale:** Numeric 1-10 is ambiguous (does 1 mean urgent or unimportant?). An enum is self-documenting, type-safe, and consistent with existing code patterns.

### 5.3 Inspection SKIP Workflow

**Decision: SKIP is a valid inspection result that transitions room to VACANT_CLEAN.**

```
INSPECTED → SKIP → VACANT_CLEAN
```

**Who can SKIP:** MANAGER and ADMIN only (`room.inspect` permission). HOUSEKEEPING cannot self-skip their own cleaning.

**Audit requirement:** `CleaningRecord.inspection_result = 'skip'`, `inspection_notes` is **required** (cannot be null for SKIP — the reason must be documented), `inspected_by` and `inspected_at` must be set.

**Valid use cases:**
- VIP guest returning to room, no time for inspection
- Low-occupancy period, manager trusts the housekeeper
- STAYOVER cleaning where light service was performed

**Service signature:**
```
skipInspection(room, inspector, reason_note) → CleaningRecord
  Precondition: room.status === INSPECTED
  Transition: room.status → VACANT_CLEAN
  Audit: inspection_result = 'skip', inspection_notes = reason_note (required, non-empty)
```

**Distinction from PASS:**
- PASS: Inspector physically verified the room
- SKIP: Manager waives inspection with documented justification
- Both result in VACANT_CLEAN — the outcome is the same, the audit trail differs

---

## 6. Database Implementation Plan

### 6.1 Migration 1: `create_housekeeping_assignments_table`

**File:** `database/migrations/YYYY_MM_DD_000000_create_housekeeping_assignments_table.php`

**Full DDL:**

```sql
CREATE TABLE housekeeping_assignments (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Room being cleaned
  room_id         BIGINT UNSIGNED NOT NULL,
                  -- FK RESTRICT: room cannot be deleted while assignment exists

  -- Housekeeper assigned (NULL = unassigned pool)
  assigned_to     BIGINT UNSIGNED NULL,
                  -- FK nullOnDelete: if user deleted, assignment becomes unassigned

  -- Who created the assignment (manager/admin/self)
  assigned_by     BIGINT UNSIGNED NULL,
                  -- FK nullOnDelete

  -- Priority: EMERGENCY | HIGH | NORMAL | LOW (CleaningPriority enum)
  priority        VARCHAR(20) NOT NULL DEFAULT 'NORMAL',

  -- Why cleaning is needed (CleaningReason enum)
  reason          VARCHAR(30) NOT NULL DEFAULT 'CHECKOUT',

  -- Free-form notes from assigner
  notes           TEXT NULL,

  -- Lifecycle timestamps
  started_at      TIMESTAMP NULL,   -- set when housekeeper starts cleaning
  completed_at    TIMESTAMP NULL,   -- set when cleaning marked done
  cancelled_at    TIMESTAMP NULL,
  cancelled_by    BIGINT UNSIGNED NULL,  -- FK nullOnDelete

  -- Assignment status (HousekeepingAssignmentStatus enum)
  -- pending | in_progress | done | cancelled
  status          VARCHAR(20) NOT NULL DEFAULT 'pending',

  created_at      TIMESTAMP NULL,
  updated_at      TIMESTAMP NULL,

  -- Foreign keys
  CONSTRAINT fk_hka_room         FOREIGN KEY (room_id)      REFERENCES rooms(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_hka_assigned_to  FOREIGN KEY (assigned_to)  REFERENCES users(id)  ON DELETE SET NULL,
  CONSTRAINT fk_hka_assigned_by  FOREIGN KEY (assigned_by)  REFERENCES users(id)  ON DELETE SET NULL,
  CONSTRAINT fk_hka_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id)  ON DELETE SET NULL,

  -- Indexes
  INDEX idx_hka_room              (room_id),
  INDEX idx_hka_assigned_to       (assigned_to),
  INDEX idx_hka_status            (status),
  INDEX idx_hka_room_status       (room_id, status),      -- "active assignment for room"
  INDEX idx_hka_assignee_status   (assigned_to, status),  -- "my tasks"
  INDEX idx_hka_priority_status   (priority, status),     -- board sort by priority
  INDEX idx_hka_created           (created_at)            -- history queries
);
```

**Rollback:** `Schema::dropIfExists('housekeeping_assignments')` — safe; no dependants except `cleaning_records.assignment_id` (SET NULL on delete).

### 6.2 Migration 2: `create_cleaning_records_table`

**File:** `database/migrations/YYYY_MM_DD_000010_create_cleaning_records_table.php`

**Full DDL:**

```sql
CREATE TABLE cleaning_records (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Room that was cleaned
  room_id               BIGINT UNSIGNED NOT NULL,
                        -- FK RESTRICT: cleaning history must be preserved

  -- Optional link to the assignment that triggered this cleaning
  assignment_id         BIGINT UNSIGNED NULL,
                        -- FK SET NULL: record survives if assignment is deleted

  -- Who cleaned the room (NOT NULL — always traceable)
  cleaned_by            BIGINT UNSIGNED NOT NULL,
                        -- FK RESTRICT: housekeeper record must be preserved

  -- Room status snapshot at start of cleaning
  room_status_before    VARCHAR(40) NOT NULL,

  -- Room status after outcome (set on complete/pass/fail/skip)
  room_status_after     VARCHAR(40) NULL,

  -- Why this cleaning was initiated (CleaningReason enum)
  reason                VARCHAR(30) NOT NULL DEFAULT 'CHECKOUT',

  -- Timing
  started_at            TIMESTAMP NOT NULL,
  completed_at          TIMESTAMP NULL,

  -- Duration computed by service: TIMESTAMPDIFF(MINUTE, started_at, completed_at)
  duration_minutes      SMALLINT UNSIGNED NULL,

  -- Housekeeper notes (optional)
  cleaning_notes        TEXT NULL,

  -- Inspection fields (null until inspection is performed)
  inspection_result     VARCHAR(10) NULL,
                        -- 'pass' | 'fail' | 'skip' (InspectionResult enum)
  inspected_by          BIGINT UNSIGNED NULL,
                        -- FK nullOnDelete
  inspected_at          TIMESTAMP NULL,
  inspection_notes      TEXT NULL,
                        -- REQUIRED (not null) when inspection_result = 'skip' (enforced at service layer)

  created_at            TIMESTAMP NULL,
  updated_at            TIMESTAMP NULL,

  -- Foreign keys
  CONSTRAINT fk_cr_room          FOREIGN KEY (room_id)       REFERENCES rooms(id)                    ON DELETE RESTRICT,
  CONSTRAINT fk_cr_assignment    FOREIGN KEY (assignment_id) REFERENCES housekeeping_assignments(id) ON DELETE SET NULL,
  CONSTRAINT fk_cr_cleaned_by    FOREIGN KEY (cleaned_by)    REFERENCES users(id)                    ON DELETE RESTRICT,
  CONSTRAINT fk_cr_inspected_by  FOREIGN KEY (inspected_by)  REFERENCES users(id)                    ON DELETE SET NULL,

  -- Indexes
  INDEX idx_cr_room              (room_id),
  INDEX idx_cr_cleaned_by        (cleaned_by),
  INDEX idx_cr_started_at        (started_at),
  INDEX idx_cr_room_date         (room_id, started_at),       -- room cleaning history
  INDEX idx_cr_result            (inspection_result),          -- fail rate queries
  INDEX idx_cr_inspected_by      (inspected_by)               -- inspector workload
);
```

**Rollback:** `Schema::dropIfExists('cleaning_records')` — safe; no dependants in Phase 4.2.

### 6.3 Migration 3: `add_last_cleaned_at_to_rooms_table`

**File:** `database/migrations/YYYY_MM_DD_000020_add_last_cleaned_at_to_rooms_table.php`

**Change:**

```sql
ALTER TABLE rooms
  ADD COLUMN last_cleaned_at TIMESTAMP NULL AFTER notes;
```

**Purpose:** Denormalized convenience column for Housekeeping Board "last cleaned" display. Updated by `HousekeepingService::completeCleaning()`. Avoids JOIN to `cleaning_records` on every board render.

**Rollback:** `Schema::table('rooms', fn($t) => $t->dropColumn('last_cleaned_at'))` — safe; no dependants.

### 6.4 No Changes to Existing Schema

The following existing tables are **unchanged**:

| Table | No change |
|-------|-----------|
| `rooms` | Status column (VARCHAR 40) is wide enough; only `last_cleaned_at` added |
| `stays` | Unchanged |
| `bookings` | Unchanged |
| `room_assignments` | Unchanged |
| `booking_special_requests` | Unchanged |
| `folio_entries` | Unchanged |
| `booking_payments` | Unchanged |

### 6.5 Index Strategy Summary

| Table | Index | Query Pattern |
|-------|-------|--------------|
| `housekeeping_assignments` | `(room_id, status)` | "Is this room actively assigned?" |
| `housekeeping_assignments` | `(assigned_to, status)` | "My active tasks" (HK Board) |
| `housekeeping_assignments` | `(priority, status)` | Board sorted by urgency |
| `cleaning_records` | `(room_id, started_at)` | Room cleaning history |
| `cleaning_records` | `(cleaned_by)` | Housekeeper performance |
| `cleaning_records` | `(inspection_result)` | Fail rate reporting |

---

## 7. Backend Implementation Plan

### 7.1 Enums

**Location:** `app/Enums/`

#### `HousekeepingAssignmentStatus` — `app/Enums/HousekeepingAssignmentStatus.php`

```php
enum HousekeepingAssignmentStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Done       = 'done';
    case Cancelled  = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done, self::Cancelled => true,
            default => false,
        };
    }

    public static function activeValues(): array
    {
        return [self::Pending->value, self::InProgress->value];
    }
}
```

#### `CleaningPriority` — `app/Enums/CleaningPriority.php`

```php
enum CleaningPriority: string
{
    case Emergency = 'EMERGENCY';
    case High      = 'HIGH';
    case Normal    = 'NORMAL';
    case Low       = 'LOW';

    public function sortOrder(): int
    {
        return match ($this) {
            self::Emergency => 1,
            self::High      => 2,
            self::Normal    => 3,
            self::Low       => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Emergency => 'Khẩn cấp',
            self::High      => 'Cao',
            self::Normal    => 'Thường',
            self::Low       => 'Thấp',
        };
    }
}
```

#### `CleaningReason` — `app/Enums/CleaningReason.php`

```php
enum CleaningReason: string
{
    case Checkout       = 'CHECKOUT';
    case Stayover       = 'STAYOVER';
    case Vip            = 'VIP';
    case Maintenance    = 'MAINTENANCE';
    case DeepCleaning   = 'DEEP_CLEANING';
    case EarlyCheckin   = 'EARLY_CHECKIN';
    case SpecialRequest = 'SPECIAL_REQUEST';

    public function label(): string
    {
        return match ($this) {
            self::Checkout       => 'Trả phòng',
            self::Stayover       => 'Dọn trong kỳ lưu trú',
            self::Vip            => 'VIP',
            self::Maintenance    => 'Sau bảo trì',
            self::DeepCleaning   => 'Vệ sinh sâu',
            self::EarlyCheckin   => 'Nhận phòng sớm',
            self::SpecialRequest => 'Yêu cầu đặc biệt',
        };
    }
}
```

#### `InspectionResult` — `app/Enums/InspectionResult.php`

```php
enum InspectionResult: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Skip = 'skip';   // Manager waives inspection; inspection_notes required

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Đạt',
            self::Fail => 'Không đạt',
            self::Skip => 'Bỏ qua kiểm tra',
        };
    }

    public function roomOutcome(): RoomStatus
    {
        return match ($this) {
            self::Pass, self::Skip => RoomStatus::VacantClean,
            self::Fail             => RoomStatus::VacantDirty,
        };
    }
}
```

### 7.2 Models

#### `HousekeepingAssignment` — `app/Models/HousekeepingAssignment.php`

**Key relationships:**
- `room(): BelongsTo` → Room
- `assignedTo(): BelongsTo` → User (housekeeper)
- `assignedBy(): BelongsTo` → User (manager/self)
- `cancelledBy(): BelongsTo` → User
- `cleaningRecord(): HasOne` → CleaningRecord

**Casts:** `status → HousekeepingAssignmentStatus`, `priority → CleaningPriority`, `reason → CleaningReason`

**Scopes:**
- `scopeActive(Builder $q)` → where status IN (pending, in_progress)
- `scopeForRoom(Builder $q, int $roomId)` → where room_id = $roomId
- `scopeForAssignee(Builder $q, int $userId)` → where assigned_to = $userId

**Fillable:** `room_id`, `assigned_to`, `assigned_by`, `priority`, `reason`, `notes`, `status`, `started_at`, `completed_at`, `cancelled_at`, `cancelled_by`

#### `CleaningRecord` — `app/Models/CleaningRecord.php`

**Key relationships:**
- `room(): BelongsTo` → Room
- `assignment(): BelongsTo` → HousekeepingAssignment
- `cleanedBy(): BelongsTo` → User
- `inspectedBy(): BelongsTo` → User

**Casts:** `reason → CleaningReason`, `inspection_result → InspectionResult`, `started_at / completed_at / inspected_at → datetime`

**Fillable:** `room_id`, `assignment_id`, `cleaned_by`, `room_status_before`, `room_status_after`, `reason`, `started_at`, `completed_at`, `duration_minutes`, `cleaning_notes`, `inspection_result`, `inspected_by`, `inspected_at`, `inspection_notes`

#### Additions to Existing Models

`Room` model: add relationships

```php
public function housekeepingAssignments(): HasMany
{
    return $this->hasMany(HousekeepingAssignment::class);
}

public function cleaningRecords(): HasMany
{
    return $this->hasMany(CleaningRecord::class);
}

public function activeHousekeepingAssignment(): HasOne
{
    return $this->hasOne(HousekeepingAssignment::class)
        ->whereIn('status', HousekeepingAssignmentStatus::activeValues());
}
```

`Room` model: add `last_cleaned_at` to `$fillable` and casts.

### 7.3 Factories

#### `HousekeepingAssignmentFactory`

States: `pending()`, `inProgress()`, `done()`, `cancelled()`, `emergency()`, `forCheckout()`.

#### `CleaningRecordFactory`

States: `passed()`, `failed()`, `skipped()`, `awaitingInspection()`.

### 7.4 HousekeepingService

**Location:** `app/Services/HousekeepingService.php`

#### Constructor Dependencies

```php
public function __construct(
    private readonly BusinessDateService $businessDate,
) {}
```

No financial service injections. No FolioService, no BookingPaymentService.

#### Method Signatures and Contracts

```
assignRoom(
    Room $room,
    ?User $assignee,   // null = self-assign (caller is the assignee)
    User $actor,
    CleaningPriority $priority = CleaningPriority::Normal,
    CleaningReason $reason = CleaningReason::Checkout,
    ?string $notes = null,
): HousekeepingAssignment

  Preconditions:
    - room.status must be VACANT_DIRTY (throw ValidationException otherwise)
    - No active assignment exists for room (pending/in_progress)
    - If actor lacks room.maintenance perm AND assignee !== actor → throw 403

  Transaction:
    - SELECT FOR UPDATE on room (ADR-87)
    - Validate preconditions under lock
    - Create HousekeepingAssignment(status=pending, assigned_to=assignee??actor)

  Returns: HousekeepingAssignment
```

```
startCleaning(
    HousekeepingAssignment $assignment,
    User $actor,
): HousekeepingAssignment

  Preconditions:
    - assignment.status === pending
    - room.status === VACANT_DIRTY
    - actor === assignment.assigned_to OR actor has room.maintenance perm

  Transaction:
    - SELECT FOR UPDATE on room (ADR-87)
    - Validate preconditions under lock
    - assignment.update(status=in_progress, started_at=now())
    - room.update(status=CLEANING)
    - CleaningRecord::create(started_at=now(), cleaned_by=actor, reason=assignment.reason, room_status_before=old_status)
```

```
completeCleaning(
    HousekeepingAssignment $assignment,
    User $actor,
    ?string $notes = null,
): CleaningRecord

  Preconditions:
    - assignment.status === in_progress
    - room.status === CLEANING

  Transaction:
    - SELECT FOR UPDATE on room (ADR-87)
    - Validate preconditions under lock
    - now_ts = now()
    - record = assignment.cleaningRecord (from started transaction)
    - duration = TIMESTAMPDIFF(MINUTE, record.started_at, now_ts)
    - record.update(completed_at=now_ts, duration_minutes=duration, cleaning_notes=notes)
    - assignment.update(status=done, completed_at=now_ts)
    - room.update(status=INSPECTED, last_cleaned_at=now_ts)

  Returns: CleaningRecord
```

```
passInspection(
    Room $room,
    User $inspector,
    ?string $notes = null,
): CleaningRecord

  Preconditions:
    - room.status === INSPECTED
    - inspector has room.inspect permission

  Transaction:
    - SELECT FOR UPDATE on room (ADR-87)
    - Validate preconditions under lock
    - Find latest CleaningRecord for room where inspection_result IS NULL
    - record.update(inspection_result='pass', inspected_by=inspector, inspected_at=now(), inspection_notes=notes, room_status_after=VACANT_CLEAN)
    - room.update(status=VACANT_CLEAN)
```

```
failInspection(
    Room $room,
    User $inspector,
    ?string $notes = null,
): CleaningRecord

  Preconditions:
    - room.status === INSPECTED
    - inspector has room.inspect permission

  Transaction:
    - SELECT FOR UPDATE on room (ADR-87)
    - Validate preconditions under lock
    - Find latest CleaningRecord for room where inspection_result IS NULL
    - record.update(inspection_result='fail', inspected_by=inspector, inspected_at=now(), inspection_notes=notes, room_status_after=VACANT_DIRTY)
    - room.update(status=VACANT_DIRTY)
    - Create new HousekeepingAssignment(status=pending, reason=same as previous, priority=HIGH auto-escalate)
```

```
skipInspection(
    Room $room,
    User $inspector,
    string $reason_notes,     // REQUIRED — cannot be null/empty (ADR-90)
): CleaningRecord

  Preconditions:
    - room.status === INSPECTED
    - inspector has room.inspect permission (MANAGER/ADMIN only)
    - reason_notes is non-empty string

  Transaction:
    - SELECT FOR UPDATE on room (ADR-87)
    - Validate preconditions under lock
    - Find latest CleaningRecord for room where inspection_result IS NULL
    - record.update(inspection_result='skip', inspected_by=inspector, inspected_at=now(), inspection_notes=reason_notes, room_status_after=VACANT_CLEAN)
    - room.update(status=VACANT_CLEAN)

  Audit: inspection_notes is required and non-empty — the skip reason is documented
```

```
markOutOfOrder(
    Room $room,
    User $actor,
    string $reason,
): Room

  Precondition: room.status is NOT CLEANING (cannot interrupt active cleaning)
  Transaction:
    - SELECT FOR UPDATE on room (ADR-87)
    - room.update(status=OUT_OF_ORDER)
    - Cancel any pending/in_progress assignments for room
```

```
releaseFromOutOfOrder(
    Room $room,
    User $actor,
    RoomStatus $targetStatus = RoomStatus::VacantDirty,
): Room

  Precondition: room.status === OUT_OF_ORDER
  Transaction:
    - SELECT FOR UPDATE on room (ADR-87)
    - room.update(status=$targetStatus)
```

```
autoMarkOccupied(Stay $stay): void      [NON-THROWING — ADR-84]
  try {
    Room::whereKey($stay->room_id)->update(['status' => RoomStatus::Occupied]);
  } catch (Throwable $e) {
    Log::error('HousekeepingService::autoMarkOccupied failed', [...]);
    // Never rethrows
  }

autoMarkDirtyOnCheckout(Stay $stay): void   [NON-THROWING — ADR-84]
  try {
    DB::transaction(function() use ($stay) {
      Room::whereKey($stay->room_id)->update([
        'status' => RoomStatus::VacantDirty,
      ]);
      HousekeepingAssignment::create([
        'room_id'  => $stay->room_id,
        'status'   => HousekeepingAssignmentStatus::Pending,
        'priority' => CleaningPriority::Normal,
        'reason'   => CleaningReason::Checkout,
      ]);
    });
  } catch (Throwable $e) {
    Log::error('HousekeepingService::autoMarkDirtyOnCheckout failed', [...]);
    // Never rethrows
  }
```

### 7.5 StayService Integration Hooks

**File modified:** `app/Services/StayService.php`

**checkIn() hook** (after existing DML, before `return`):

```php
// Phase 4.2: auto-mark room OCCUPIED on check-in.
// autoMarkOccupied never throws — see HousekeepingService (ADR-84).
$this->housekeeping->autoMarkOccupied($lockedStay);
```

**checkOut() hook** (after `finaliseBookingCheckout` or `updateBookingStayStatus`, before `return`):

```php
// Phase 4.2: auto-mark room VACANT_DIRTY and create cleaning assignment on checkout.
// autoMarkDirtyOnCheckout never throws — see HousekeepingService (ADR-84).
$this->housekeeping->autoMarkDirtyOnCheckout($lockedStay);
```

**Lock order compliance:** Both hooks execute after all existing DML. `autoMarkOccupied` performs a single `UPDATE rooms` with no lock — acceptable because it's inside the existing transaction which already holds `Booking → Stay → RoomAssignment` locks. Room is a leaf with no downstream financial locks. `autoMarkDirtyOnCheckout` creates a nested transaction (savepoint) for the assignment — safe inside the outer transaction.

### 7.6 RoomAvailabilityRuleService Update

**File modified:** `app/Services/RoomAvailabilityRuleService.php`

```php
public function isRoomUnavailable(Room $room): bool
{
    return in_array($room->status, [
        RoomStatus::OutOfOrder,
        RoomStatus::OutOfService,
        RoomStatus::Cleaning,    // Phase 4.2: CLEANING blocks new assignments (ADR-88)
    ], true);
}
```

**`resolveAvailability()` update** — add new branch before the `out_of_order` check:

```php
// Phase 4.2: CLEANING rooms get their own distinct availability label
if ($room->status === RoomStatus::Cleaning) {
    return 'cleaning';
}
if ($isUnavailable) {    // OUT_OF_ORDER, OUT_OF_SERVICE
    return 'out_of_order';
}
```

`hasConflict()` is NOT changed — conflict detection is assignment-based, not status-based.

### 7.7 AuditObserver Registration

**File modified:** `app/Providers/AppServiceProvider.php`

```php
HousekeepingAssignment::observe(AuditObserver::class);
CleaningRecord::observe(AuditObserver::class);
```

---

## 8. Permission & Policy Plan

### 8.1 New Permissions (add to seeder)

| Permission | Purpose | Gate |
|-----------|---------|------|
| `housekeeping.view` | View Housekeeping Board | `HousekeepingController::index()` |
| `housekeeping.assign` | Create/claim assignments | `assignRoom()` gate |
| `room.status.update` | Start cleaning, complete cleaning | `startCleaning()`, `completeCleaning()` |
| `room.inspect` | Pass/fail/skip inspection | `passInspection()`, `failInspection()`, `skipInspection()` |
| `room.maintenance` | Mark/release OutOfOrder | `markOutOfOrder()`, `releaseFromOutOfOrder()` |

### 8.2 Seeder Changes

**Remove from HOUSEKEEPING:** `rooms.manage`, `room.assign`, `room.unassign`  
**Add to HOUSEKEEPING:** `housekeeping.view`, `housekeeping.assign`, `room.status.update`

**Final HOUSEKEEPING permissions:**

```
housekeeping.view
housekeeping.assign     (self-assign only — enforced at service)
room.status.update      (start/complete cleaning)
special_request.fulfill
```

**Add to MANAGER:** `housekeeping.view`, `housekeeping.assign`, `room.status.update`, `room.inspect`, `room.maintenance`  
**Add to ADMIN:** same as MANAGER (ADMIN already has all)  
**Add to RECEPTION:** `housekeeping.view` (read-only board access)

### 8.3 HousekeepingPolicy

**Location:** `app/Policies/HousekeepingPolicy.php`

```php
class HousekeepingPolicy
{
    public function view(User $user): bool
    {
        return $user->can('housekeeping.view');
    }

    public function assign(User $user): bool
    {
        return $user->can('housekeeping.assign');
    }

    public function updateStatus(User $user): bool
    {
        return $user->can('room.status.update');
    }

    public function inspect(User $user): bool
    {
        return $user->can('room.inspect');
    }

    public function maintenance(User $user): bool
    {
        return $user->can('room.maintenance');
    }
}
```

### 8.4 RoomPolicy Update

Add OR gate for board read access:

```php
public function viewAny(User $user): bool
{
    return $user->can('rooms.manage') || $user->can('housekeeping.view');
}

public function view(User $user, Room $room): bool
{
    return $user->can('rooms.manage') || $user->can('housekeeping.view');
}
```

CRUD actions (`create`, `update`, `delete`) remain gated on `rooms.manage` — HOUSEKEEPING cannot modify room records.

---

## 9. Controller & Routes Plan

### 9.1 HousekeepingController

**Location:** `app/Http/Controllers/Admin/HousekeepingController.php`

```
index()     → GET  /admin/housekeeping
              Gate: housekeeping.view
              Returns: Inertia 'Admin/Housekeeping/Index' with:
                - rooms[] (all rooms, with status, floor, room_type, activeAssignment, pendingRequestCount)
                - housekeepers[] (users with housekeeping.assign)
                - filters (floor_id, room_type_id, status, assigned_to)
                - can { assign, updateStatus, inspect, maintenance }

assign()    → POST /admin/housekeeping/{room}/assign
              Gate: housekeeping.assign
              Body: { assigned_to?: int|null, priority: string, reason: string, notes?: string }
              Delegates to: HousekeepingService::assignRoom()

start()     → POST /admin/housekeeping/assignments/{assignment}/start
              Gate: room.status.update
              Delegates to: HousekeepingService::startCleaning()

complete()  → POST /admin/housekeeping/assignments/{assignment}/complete
              Gate: room.status.update
              Body: { notes?: string }
              Delegates to: HousekeepingService::completeCleaning()

inspect()   → POST /admin/housekeeping/{room}/inspect
              Gate: room.inspect
              Body: { result: 'pass'|'fail'|'skip', notes?: string }
              Delegates to: HousekeepingService::passInspection() | failInspection() | skipInspection()
              Note: 'skip' requires non-empty notes (FormRequest validation)

outOfOrder() → POST /admin/housekeeping/{room}/out-of-order
               Gate: room.maintenance
               Body: { reason: string }
               Delegates to: HousekeepingService::markOutOfOrder()

release()   → POST /admin/housekeeping/{room}/release
              Gate: room.maintenance
              Body: { target_status?: string } default VACANT_DIRTY
              Delegates to: HousekeepingService::releaseFromOutOfOrder()

cancel()    → DELETE /admin/housekeeping/assignments/{assignment}
              Gate: room.maintenance
              Delegates to: HousekeepingService::cancelAssignment()
```

### 9.2 Routes

```php
// routes/web.php — inside admin middleware group
Route::prefix('housekeeping')->name('housekeeping.')->group(function () {
    Route::get('/',                                   [HousekeepingController::class, 'index'])->name('index');
    Route::post('/{room}/assign',                     [HousekeepingController::class, 'assign'])->name('assign');
    Route::post('/assignments/{assignment}/start',    [HousekeepingController::class, 'start'])->name('start');
    Route::post('/assignments/{assignment}/complete', [HousekeepingController::class, 'complete'])->name('complete');
    Route::post('/{room}/inspect',                    [HousekeepingController::class, 'inspect'])->name('inspect');
    Route::post('/{room}/out-of-order',               [HousekeepingController::class, 'outOfOrder'])->name('out-of-order');
    Route::post('/{room}/release',                    [HousekeepingController::class, 'release'])->name('release');
    Route::delete('/assignments/{assignment}',        [HousekeepingController::class, 'cancel'])->name('cancel');
});
```

Total: 8 routes under `admin.housekeeping.*`

### 9.3 Form Requests

```
StoreHousekeepingAssignmentRequest
  - assigned_to: nullable, integer, exists:users,id
  - priority:    required, in:EMERGENCY,HIGH,NORMAL,LOW
  - reason:      required, in:[CleaningReason values]
  - notes:       nullable, string, max:500

InspectRoomRequest
  - result:      required, in:pass,fail,skip
  - notes:       nullable|required_if:result,skip, string, max:500
    (notes is required when result = 'skip')
```

---

## 10. Frontend Implementation Plan

### 10.1 Housekeeping/Index.vue

**Location:** `resources/js/Pages/Admin/Housekeeping/Index.vue`

**Layout:** Full-width board with top filters bar + room grid.

**Props:**
```js
defineProps({
    rooms:             Array,    // [{room_id, room_number, floor, room_type, status, statusLabel,
                                 //   activeAssignment: {id, assigned_to, assigneeName, priority, reason, startedAt},
                                 //   pendingRequestCount: int, lastCleanedAt}]
    housekeepers:      Array,    // [{id, name}] for dropdown
    filters:           Object,
    can: {
        assign:        Boolean,
        updateStatus:  Boolean,
        inspect:       Boolean,
        maintenance:   Boolean,
    },
})
```

**Features:**
1. **Filter bar:** Floor / Room Type / Status / Assigned To — Inertia GET with `preserveState: true`
2. **Room cards:** Color-coded by status (green=clean, yellow=dirty, amber=cleaning, teal=inspected, red=OOO)
3. **Quick actions per card:**
   - VACANT_DIRTY + `can.assign` → "Phân công" dropdown (housekeeper) + "Tự nhận" button
   - CLEANING + `can.updateStatus` → "Hoàn thành dọn"
   - INSPECTED + `can.inspect` → "Đạt / Không đạt / Bỏ qua"
   - Any + `can.maintenance` → "Khóa bảo trì" / "Mở khóa"
4. **Pending special request badge** — reuse amber `N yc` badge from Room Availability
5. **Last cleaned** — `lastCleanedAt` timestamp

**Color scheme:**

| Status | Background | Border |
|--------|-----------|--------|
| VACANT_CLEAN | green-50 | green-200 |
| VACANT_DIRTY | yellow-50 | yellow-300 |
| CLEANING | amber-50 | amber-400 |
| INSPECTED | teal-50 | teal-300 |
| OCCUPIED | blue-50 | blue-300 |
| OUT_OF_ORDER | red-50 | red-300 |
| OUT_OF_SERVICE | gray-100 | gray-300 |

### 10.2 RoomAvailability/Index.vue Update

Add `cleaning` to `availabilityStyles`:

```js
cleaning: {
    card: 'border-amber-300 bg-amber-50 cursor-default opacity-80',
    dot: 'bg-amber-500',
    badge: 'bg-amber-100 text-amber-800',
},
```

Add to legend section: `<span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>Đang dọn</span>`

### 10.3 AppLayout.vue Update

Add Housekeeping nav item for roles with `housekeeping.view`:

```html
<NavLink href="/admin/housekeeping" :active="route().current('admin.housekeeping.*')">
    <BrushIcon class="h-4 w-4" />
    Dọn phòng
</NavLink>
```

---

## 11. Transaction & Lock Strategy

### 11.1 All Housekeeping Writes — Lock Pattern

Every write operation in `HousekeepingService` (except non-throwing auto-hooks) follows this pattern:

```
DB::transaction(function() {
    $room = Room::whereKey($roomId)->lockForUpdate()->firstOrFail();  // ADR-87
    // Validate pre-condition on locked room
    if ($room->status !== $expected) {
        throw ValidationException::withMessages(['room' => '...']);
    }
    // Perform DML
    $room->update([...]);
    // Secondary DML (assignments, records)
    HousekeepingAssignment::create([...]);
});
```

### 11.2 Lock Order

Housekeeping-only transactions (not involving Booking/Stay):

```
Room (lockForUpdate) → HousekeepingAssignment → CleaningRecord
```

Housekeeping hooks inside `StayService` (checkIn/checkOut): non-throwing, no extra lock — executed after all existing locks are acquired.

### 11.3 No Deadlock Risk

Housekeeping transactions only lock `rooms`, `housekeeping_assignments`, and `cleaning_records`. None of these tables appear in the Phase 3 financial lock chain (`Booking → Folio → FolioEntries → BookingPayments`). No cross-domain deadlock is possible.

---

## 12. Rollback Strategy

### Migration Rollback Order

```
1. Drop cleaning_records (references housekeeping_assignments)
2. Drop housekeeping_assignments (referenced by cleaning_records)
3. Drop last_cleaned_at column from rooms
```

### Code Rollback

All Phase 4.2 service/controller files are new — revert by deleting them.  
Modified files (`StayService`, `RoomAvailabilityRuleService`, `RolePermissionSeeder`, `RoomPolicy`, `AppServiceProvider`):
- Changes are additive/minimal — each file has clear Phase 4.2 comments
- Rollback: revert those files to phase-4.1 tag state

### Permission Rollback

`RolePermissionSeeder` changes are tracked in git. Rollback via:
```
git checkout phase-4.1 -- database/seeders/RolePermissionSeeder.php
php artisan db:seed --class=RolePermissionSeeder
```

---

## 13. Regression Strategy

### 13.1 Regression Baseline

- Pre-existing failures: **26** (all date-sensitive, pre-Phase 4.1)
- Passing: **632**
- Target after Phase 4.2: **26 failed (same), ≥650 passed** (≥18 new tests)

### 13.2 Protected Modules (No Changes Permitted)

| Module | Verified Unchanged |
|--------|--------------------|
| `FolioService` | ✅ |
| `BookingPaymentService` | ✅ |
| `NightAuditPipeline` | ✅ |
| `NightAuditService` | ✅ |
| `RevenueReportService` | ✅ |
| `ReconciliationService` | ✅ |
| `PackageEnrollmentService` | ✅ |
| `SpecialRequestService` | ✅ |
| `BookingSpecialRequestController` | ✅ |

### 13.3 Modified Files — Regression Checklist

| File | Change | Risk | Test to run |
|------|--------|------|-------------|
| `StayService.php` | Add 2 non-throwing hooks | LOW | `StayServiceHookTest` |
| `RoomAvailabilityRuleService.php` | Add CLEANING to blocked | MEDIUM | `RoomAvailabilityRuleTest` |
| `RoomAvailabilityCheckerService.php` | New 'cleaning' case in `resolveAvailability()` | LOW | `RoomAvailabilityCheckerTest` |
| `RolePermissionSeeder.php` | Add 5 perms, remove 3 from HK | MEDIUM | Run seeder on test DB; verify HOUSEKEEPING 403 on room CRUD |
| `RoomPolicy.php` | Add `housekeeping.view` OR gate | LOW | `HousekeepingUiTest` |
| `AppServiceProvider.php` | Register 2 observers | LOW | Observers auto-tested via CRUD tests |

### 13.4 Seed Audit (Pre-Implementation)

Before implementing `isRoomUnavailable()` change:

```bash
php artisan test --filter RoomAvailabilityCheckerTest 2>&1 | grep "Tests:"
```

Baseline must match: 12 failed, 14 passed. If different, investigate before proceeding.

---

## 14. Testing Plan

### 14.1 Unit Tests

#### `tests/Unit/Services/HousekeepingServiceTest.php`

Target: 80%+ service coverage. All happy paths + key failure paths.

| Test | Scenario |
|------|---------|
| `test_assign_room_creates_pending_assignment` | VACANT_DIRTY → assignment created |
| `test_assign_room_throws_if_not_dirty` | VACANT_CLEAN → ValidationException |
| `test_assign_room_throws_if_already_assigned` | Existing active assignment → ValidationException |
| `test_self_assign_allowed_for_housekeeping` | assigned_to = actor → allowed |
| `test_hk_cannot_assign_to_other_user` | HK user + other assignee → 403 |
| `test_start_cleaning_transitions_room_to_cleaning` | pending → in_progress, room → CLEANING |
| `test_start_cleaning_throws_if_not_pending` | in_progress → ValidationException |
| `test_complete_cleaning_transitions_room_to_inspected` | in_progress → done, room → INSPECTED |
| `test_complete_cleaning_computes_duration` | duration_minutes computed correctly |
| `test_pass_inspection_transitions_to_vacant_clean` | INSPECTED → VACANT_CLEAN |
| `test_fail_inspection_transitions_to_vacant_dirty` | INSPECTED → VACANT_DIRTY + new assignment |
| `test_fail_inspection_auto_escalates_priority` | New assignment has HIGH priority |
| `test_skip_inspection_requires_notes` | Empty notes → ValidationException |
| `test_skip_inspection_transitions_to_vacant_clean` | INSPECTED → VACANT_CLEAN, result='skip' |
| `test_mark_out_of_order_cancels_active_assignment` | Pending assignment cancelled |
| `test_release_from_out_of_order_sets_target_status` | → VACANT_DIRTY by default |
| `test_auto_mark_occupied_never_throws` | DB failure → logged, not propagated |
| `test_auto_mark_dirty_on_checkout_never_throws` | DB failure → logged, not propagated |
| `test_auto_mark_dirty_creates_checkout_assignment` | Checkout creates pending assignment |

#### `tests/Unit/Policies/HousekeepingPolicyTest.php`

| Test | Scenario |
|------|---------|
| `test_admin_can_do_all_actions` | All 5 policy methods return true |
| `test_manager_can_do_all_actions` | All 5 policy methods return true |
| `test_housekeeping_can_view_and_assign_and_update_status` | 3 of 5 true; inspect/maintenance false |
| `test_reception_can_only_view` | Only view true |
| `test_accountant_cannot_access_housekeeping` | All false |
| `test_sales_cannot_access_housekeeping` | All false |

### 14.2 Feature Tests

#### `tests/Feature/HousekeepingWorkflowTest.php`

| Test | Scenario |
|------|---------|
| `test_manager_can_assign_room` | POST assign → 302 redirect |
| `test_hk_can_self_assign` | POST assign with null assignee → own assignment |
| `test_hk_cannot_assign_to_other` | POST assign with other user_id → 403 |
| `test_hk_can_start_cleaning` | POST start → room CLEANING |
| `test_hk_can_complete_cleaning` | POST complete → room INSPECTED, CleaningRecord created |
| `test_manager_can_pass_inspection` | POST inspect result=pass → room VACANT_CLEAN |
| `test_manager_can_fail_inspection` | POST inspect result=fail → room VACANT_DIRTY, new assignment |
| `test_manager_can_skip_inspection_with_notes` | POST inspect result=skip, notes=... → VACANT_CLEAN |
| `test_skip_inspection_fails_without_notes` | POST inspect result=skip, no notes → 422 |
| `test_hk_cannot_inspect` | POST inspect as HOUSEKEEPING → 403 |
| `test_manager_can_mark_out_of_order` | POST out-of-order → room OUT_OF_ORDER |
| `test_manager_can_release_from_out_of_order` | POST release → room VACANT_DIRTY |
| `test_concurrent_assignment_race_condition` | Two requests claim same room → one 422 |
| `test_checkout_auto_creates_cleaning_assignment` | StayService::checkOut() → pending assignment |
| `test_checkin_auto_marks_room_occupied` | StayService::checkIn() → room OCCUPIED |

#### `tests/Feature/HousekeepingUiTest.php`

| Test | Scenario |
|------|---------|
| `test_admin_can_view_housekeeping_board` | GET /admin/housekeeping → 200, Inertia response |
| `test_manager_can_view_housekeeping_board` | Same |
| `test_housekeeping_can_view_board` | Same |
| `test_reception_can_view_board` | Same |
| `test_accountant_cannot_view_board` | GET → 403 |
| `test_sales_cannot_view_board` | GET → 403 |
| `test_can_flags_correct_for_admin` | All can flags true |
| `test_can_flags_correct_for_housekeeping` | assign=true, inspect=false, maintenance=false |
| `test_board_includes_pending_request_count` | pendingRequestCount in room data |
| `test_housekeeping_cannot_access_room_crud` | GET /admin/rooms → 403 for HOUSEKEEPING |

#### `tests/Feature/RoomAvailabilityRuleTest.php`

| Test | Scenario |
|------|---------|
| `test_cleaning_room_is_unavailable` | isRoomUnavailable() → true for CLEANING |
| `test_vacant_dirty_room_is_available` | isRoomUnavailable() → false for VACANT_DIRTY |
| `test_resolve_availability_returns_cleaning_for_cleaning_room` | resolveAvailability() → 'cleaning' |

### 14.3 Manual QA Checklist

**ADMIN / MANAGER:**
- [ ] Navigate to `/admin/housekeeping` → Board loads with room grid
- [ ] Filter by floor → only that floor's rooms shown
- [ ] Filter by status → only matching rooms shown
- [ ] Click "Phân công" on a dirty room → dropdown of housekeepers appears → assign → room card updates
- [ ] Assign to self → works
- [ ] Try to assign a non-dirty room → error message shown
- [ ] Board shows pending special request badge on rooms with active requests
- [ ] Mark room Out of Order → status badge changes to red
- [ ] Release from Out of Order → status reverts to VACANT_DIRTY
- [ ] Pass inspection → room → VACANT_CLEAN (green)
- [ ] Fail inspection → room → VACANT_DIRTY (yellow), new assignment auto-created
- [ ] Skip inspection with empty notes → validation error shown
- [ ] Skip inspection with notes → room → VACANT_CLEAN, audit shows 'skip' + manager name

**HOUSEKEEPING:**
- [ ] Navigate to `/admin/housekeeping` → Board loads (can view)
- [ ] No "Khóa bảo trì" button visible
- [ ] No "Kiểm tra" (inspect) buttons visible
- [ ] Click "Tự nhận" on dirty room → self-assigned
- [ ] Try to assign to another housekeeper → 403 / error shown
- [ ] Click "Bắt đầu dọn" → room moves to CLEANING
- [ ] Click "Hoàn thành" → room moves to INSPECTED, await inspection badge shown
- [ ] Navigate to `/admin/rooms` (room CRUD) → 403 Forbidden
- [ ] Navigate to `/admin/bookings` → 403 Forbidden

**RECEPTION:**
- [ ] Navigate to `/admin/housekeeping` → Board loads (read-only)
- [ ] No assignment / action buttons visible

**ACCOUNTANT / SALES:**
- [ ] Navigate to `/admin/housekeeping` → 403 Forbidden

**Checkout Integration:**
- [ ] Guest checks out → room status automatically becomes VACANT_DIRTY
- [ ] A pending housekeeping assignment is automatically created (reason=CHECKOUT)
- [ ] Board shows room as dirty with unassigned badge

**Check-in Integration:**
- [ ] Guest checks in → room status automatically becomes OCCUPIED
- [ ] Room Availability Board shows room as occupied (not cleaning)

**Financial Regression:**
- [ ] Check "Tài chính" tab on any booking → folio balance renders correctly
- [ ] Night Audit → runs without errors
- [ ] No console errors from housekeeping code on financial pages

---

## 15. Milestone Plan

### Milestone 1 — Foundation (Database + Enums + Models)

**Scope:** All database changes and new model layer. No service logic.

**Files to create:**
- `database/migrations/YYYY_create_housekeeping_assignments_table.php`
- `database/migrations/YYYY_create_cleaning_records_table.php`
- `database/migrations/YYYY_add_last_cleaned_at_to_rooms_table.php`
- `app/Enums/HousekeepingAssignmentStatus.php`
- `app/Enums/CleaningPriority.php`
- `app/Enums/CleaningReason.php`
- `app/Enums/InspectionResult.php`
- `app/Models/HousekeepingAssignment.php`
- `app/Models/CleaningRecord.php`
- `database/factories/HousekeepingAssignmentFactory.php`
- `database/factories/CleaningRecordFactory.php`

**Files to modify:**
- `app/Models/Room.php` — add 3 relationships + `last_cleaned_at` fillable/cast

**Regression risk:** NONE — purely additive.  
**Rollback:** Drop 2 tables, drop 1 column, delete enum/model files.  
**Review gate:** Run `php artisan migrate:fresh --seed` without errors. `php artisan test` — must match 26 failed, 632 passed.

**Expected result:** 3 migrations run successfully. 4 new enums. 2 new models with correct relationships. Factories generate valid records.

---

### Milestone 2 — Service Layer

**Scope:** `HousekeepingService` with all 9 methods. StayService hooks.

**Files to create:**
- `app/Services/HousekeepingService.php`

**Files to modify:**
- `app/Services/StayService.php` — inject `HousekeepingService`, add 2 hooks
- `app/Providers/AppServiceProvider.php` — register 2 AuditObservers

**Regression risk:** LOW — hooks are non-throwing. Financial modules untouched.  
**Rollback:** Delete `HousekeepingService.php`, revert 3 files.  
**Review gate:**
- Run `php artisan test tests/Unit/Services/HousekeepingServiceTest.php` — all passing
- Run `php artisan test tests/Feature/HousekeepingWorkflowTest.php` — all passing
- Run `php artisan test` — 26 failed, ≥644 passed (no regression)

**Expected result:** All service methods implemented. checkIn/checkOut hooks work. Race condition test passes.

---

### Milestone 3 — Permissions & Policy

**Scope:** Permission revision (ADR-89). HousekeepingPolicy. RoomPolicy update.

**Files to create:**
- `app/Policies/HousekeepingPolicy.php`

**Files to modify:**
- `database/seeders/RolePermissionSeeder.php` — add 5 perms, revise HOUSEKEEPING
- `app/Policies/RoomPolicy.php` — add `housekeeping.view` OR gate
- `app/Providers/AppServiceProvider.php` — register HousekeepingPolicy

**Regression risk:** MEDIUM — HOUSEKEEPING loses `rooms.manage`. Must verify HOUSEKEEPING cannot access room CRUD.  
**Rollback:** Revert seeder + policies, re-run seeder.  
**Review gate:**
- Run `php artisan db:seed --class=RolePermissionSeeder` — no errors
- Run `php artisan test tests/Unit/Policies/HousekeepingPolicyTest.php` — all passing
- Run `php artisan test tests/Feature/HousekeepingUiTest.php` (subset) — HOUSEKEEPING 403 on room CRUD passes
- Run `php artisan test` — 26 failed, ≥650 passed

**Expected result:** HOUSEKEEPING can view board, cannot CRUD rooms, cannot assign to others.

---

### Milestone 4 — Controller & Routes & Availability Update

**Scope:** HousekeepingController. Form requests. Routes. RoomAvailabilityRuleService/CheckerService update.

**Files to create:**
- `app/Http/Controllers/Admin/HousekeepingController.php`
- `app/Http/Requests/Housekeeping/StoreHousekeepingAssignmentRequest.php`
- `app/Http/Requests/Housekeeping/InspectRoomRequest.php`

**Files to modify:**
- `routes/web.php` — add 8 housekeeping routes
- `app/Services/RoomAvailabilityRuleService.php` — add CLEANING to `isRoomUnavailable()`
- `app/Services/RoomAvailabilityCheckerService.php` — add 'cleaning' case in `resolveAvailability()`

**Regression risk:** MEDIUM — `isRoomUnavailable()` change. Seed audit confirmed safe.  
**Rollback:** Revert routes, delete controller/requests, revert 2 service files.  
**Review gate:**
- Run `php artisan test tests/Feature/RoomAvailabilityRuleTest.php` — all passing
- Run `php artisan test tests/Feature/RoomAvailabilityCheckerTest.php` — must remain 12 failed, 14 passed
- Run `php artisan test` — 26 failed, ≥655 passed

**Expected result:** All 8 routes respond correctly. CLEANING rooms blocked from assignment. Availability board shows 'cleaning' state.

---

### Milestone 5 — Frontend

**Scope:** Housekeeping Board Vue page. Room Availability update. AppLayout nav.

**Files to create:**
- `resources/js/Pages/Admin/Housekeeping/Index.vue`

**Files to modify:**
- `resources/js/Pages/Admin/RoomAvailability/Index.vue` — add cleaning badge/style
- `resources/js/Layouts/AppLayout.vue` — add nav entry for housekeeping

**Regression risk:** LOW — new Vue component; minor additions to existing components.  
**Rollback:** Delete `Housekeeping/Index.vue`, revert 2 modified Vue files.  
**Review gate:**
- Run `npm run build` — must pass (0 errors, chunk warning pre-existing)
- Run `php artisan test tests/Feature/HousekeepingUiTest.php` — all Inertia tests pass
- Manual QA: all 3 role scenarios tested in browser

**Expected result:** Board renders. Filters work. Quick actions submit and redirect. Cleaning badge appears in Room Availability board.

---

### Milestone 6 — Testing & Final Verification

**Scope:** Write remaining tests. Run full suite. Manual QA.

**Files to create:**
- `tests/Unit/Services/HousekeepingServiceTest.php`
- `tests/Unit/Policies/HousekeepingPolicyTest.php`
- `tests/Feature/HousekeepingWorkflowTest.php`
- `tests/Feature/HousekeepingUiTest.php`
- `tests/Feature/StayServiceHookTest.php`
- `tests/Feature/RoomAvailabilityRuleTest.php`

**Regression risk:** NONE — test-only files.  
**Review gate:**
- All new tests pass
- `php artisan test` — 26 failed (same), ≥658 passed
- `npm run build` — pass
- Manual QA all 5 role scenarios completed

**Expected result:** Full coverage of all 9 service methods. Race condition test passes. No new regressions.

---

## 16. Future Extension (Reserved)

### 16.1 Housekeeping Dashboard

**Not implemented in Phase 4.2. Reserved for Phase 4.3.**

Data structure is in place (`cleaning_records` table). The following metrics can be computed without schema changes:

| Metric | Query Source |
|--------|-------------|
| Today's Tasks | `housekeeping_assignments` WHERE DATE(created_at) = today |
| Completed | WHERE status = 'done' AND DATE(completed_at) = today |
| Pending | WHERE status IN (pending, in_progress) |
| Average Cleaning Time | AVG(duration_minutes) FROM cleaning_records |
| Inspection Fail Rate | COUNT(result='fail') / COUNT(*) FROM cleaning_records |
| VIP Rooms | WHERE reason = 'VIP' AND status = pending |
| Urgent Rooms | WHERE priority = 'EMERGENCY' AND status != 'done' |

### 16.2 Auto-Assignment Strategies

**Not implemented in Phase 4.2. Reserved for Phase 4.3+ (ADR-90).**

Architecture supports addition without schema changes:

| Strategy | Implementation |
|----------|---------------|
| Round Robin | `assignNextAvailableHousekeeper(Room)` → picks by least-recently-assigned |
| Workload | `assignNextAvailableHousekeeper(Room)` → picks by fewest active assignments |
| Floor-Based | `assignNextAvailableHousekeeper(Room)` → picks housekeeper assigned to room's floor |

All strategies call `HousekeepingService::assignRoom()` with the resolved housekeeper. No controller/route changes required.

Implementation path: Add `HousekeepingAssignmentStrategy` interface + implementations in `Phase 4.3`. `HousekeepingService` resolves strategy from config.

---

## 17. Definition of Done

Phase 4.2 is **complete** when:

- [ ] All 3 migrations run without errors on fresh database
- [ ] All 4 enums implemented with correct values and methods
- [ ] All 2 models implemented with correct relationships, casts, and scopes
- [ ] `HousekeepingService` passes all unit tests (≥19 test cases)
- [ ] `HousekeepingPolicy` passes all policy unit tests (6 test cases)
- [ ] All 8 controller routes respond correctly
- [ ] `RoomAvailabilityRuleService` correctly blocks CLEANING rooms
- [ ] HOUSEKEEPING permission revision applied and verified
- [ ] StayService checkIn/checkOut hooks work non-throwingly
- [ ] Race condition test (concurrent assignment) passes
- [ ] `Housekeeping/Index.vue` renders board with all quick actions
- [ ] `npm run build` — PASS
- [ ] `php artisan test` — 26 failed (same baseline), ≥658 passed
- [ ] Manual QA: all 5 role scenarios completed
- [ ] Financial modules unchanged (git diff confirms)
- [ ] Architecture Baseline document updated for Phase 4.2

---

## Implementation Plan Result: READY FOR BACKEND IMPLEMENTATION
