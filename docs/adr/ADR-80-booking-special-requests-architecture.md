# ADR-80: booking_special_requests Table Architecture

**Date:** 2026-07-04
**Status:** Accepted
**Phase:** 4.1
**Decider:** Architecture Review Process

---

## Context

Phase 4.1 introduces Room Setup Requests — operational instructions for room preparation (bed configuration, extra items, decorations, accessibility). These requests exhibit a timing-context tension that no existing entity resolves:

- **Timing:** Requests are captured at booking creation time (guest communicates setup preference by phone or at booking)
- **Context:** Requests apply to a specific physical room (Housekeeping needs to know which room to prepare)

`Booking` exists at the right time but has no room context (no room assigned yet). `Stay` has room context but does not exist until after room assignment. `RoomAssignment` is intermediate but still too late for pre-assignment capture.

The system needs a table that can hold requests from the moment of booking, optionally bridging to a specific stay once a room is assigned.

A directly analogous pattern already exists in Phase 3: `folio_entries.stay_id` is nullable and is populated after stay creation. This pattern was proven viable in Phase 3.3 (migration `2026_07_02_000020`).

---

## Decision

Create a dedicated `booking_special_requests` table with the following design:

### Schema

```sql
CREATE TABLE booking_special_requests (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id       BIGINT UNSIGNED NOT NULL,
    stay_id          BIGINT UNSIGNED NULL,           -- nullable: set when room is known

    category         VARCHAR(32)     NOT NULL,       -- enum: see RequestCategory
    request_type     VARCHAR(64)     NOT NULL,       -- string code (not DB enum — extensibility)
    quantity         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    note             TEXT            NULL,

    status           VARCHAR(32)     NOT NULL DEFAULT 'pending',
                                                    -- pending | acknowledged | fulfilled | cancelled

    requested_by     BIGINT UNSIGNED NOT NULL,       -- always staff (ADR-83)
    acknowledged_by  BIGINT UNSIGNED NULL,
    acknowledged_at  TIMESTAMP       NULL,
    fulfilled_by     BIGINT UNSIGNED NULL,
    fulfilled_at     TIMESTAMP       NULL,
    cancelled_by     BIGINT UNSIGNED NULL,           -- actor tracking for terminal cancel state
    cancelled_at     TIMESTAMP       NULL,

    created_at       TIMESTAMP       NULL,
    updated_at       TIMESTAMP       NULL,

    CONSTRAINT fk_bsr_booking  FOREIGN KEY (booking_id)    REFERENCES bookings(id) ON DELETE RESTRICT,  -- ADR-82
    CONSTRAINT fk_bsr_stay     FOREIGN KEY (stay_id)       REFERENCES stays(id)    ON DELETE SET NULL,
    CONSTRAINT fk_bsr_req_by   FOREIGN KEY (requested_by)  REFERENCES users(id)    ON DELETE RESTRICT,
    CONSTRAINT fk_bsr_ack_by   FOREIGN KEY (acknowledged_by) REFERENCES users(id)  ON DELETE SET NULL,
    CONSTRAINT fk_bsr_ful_by   FOREIGN KEY (fulfilled_by)  REFERENCES users(id)    ON DELETE SET NULL,
    CONSTRAINT fk_bsr_can_by   FOREIGN KEY (cancelled_by)  REFERENCES users(id)    ON DELETE SET NULL,

    INDEX idx_bsr_booking        (booking_id),
    INDEX idx_bsr_stay           (stay_id),
    INDEX idx_bsr_status         (status),
    INDEX idx_bsr_category       (category),
    INDEX idx_bsr_booking_status (booking_id, status)
);
```

### Key Design Choices

**Nullable `stay_id` bridge** — mirrors the established `folio_entries.stay_id` pattern. NULL = request belongs to the booking but not yet attributed to a specific room. Non-null = request is attributed to a specific stay/room.

**`category` as VARCHAR(32), validated at application layer** — the small finite set (≤ 6 values) makes a DB enum unnecessary. The `RequestCategory` PHP enum enforces valid values. Adding a new category requires a code deploy, not a migration.

**`request_type` as VARCHAR(64), no DB enum** — the primary extensibility hook. New request types are added via the frontend catalog array only; no migration, no enum change. The backend validates `request_type` against the known catalog in the FormRequest class.

**Actor tracking on all terminal transitions** — `acknowledged_by/at`, `fulfilled_by/at`, `cancelled_by/at` follow the Phase 3 pattern established by `folio_entries.voided_by/voided_at` and `room_assignments.released_by/released_at`. All state transitions are auditable.

**Status machine: 4 states, 2 terminal**
```
pending → acknowledged → fulfilled (terminal)
pending → cancelled (terminal)
acknowledged → cancelled (terminal)
```

**No financial fields** — zero `FolioEntry`, zero balance impact. If a request incurs a charge (e.g., flower decoration), the charge is entered separately via `FolioService::addCharge()`.

**AuditObserver** — `BookingSpecialRequest` must be registered in `AppServiceProvider.php` alongside other observed models (line 92+).

---

## Consequences

**Positive:**
- New request types added with zero migration (frontend catalog change only)
- Full audit trail for all state transitions, consistent with Phase 3 actor-tracking pattern
- No risk to Phase 3 Financial Foundation — entirely separate domain
- `stay_id SET NULL` on stay deletion keeps requests on the booking for re-attribution
- Composite index `(booking_id, status)` optimises the most frequent query pattern

**Negative / Trade-offs:**
- Two string columns (`category`, `request_type`) rely on application-layer validation; invalid strings can enter if validation is bypassed (mitigated by FormRequest validation)
- `booking_id` RESTRICT FK means hard-delete of a booking is blocked while requests exist (by design — bookings are cancelled, not deleted)

---

## Alternatives Considered

**Attach to `bookings.notes` / JSON field**
Rejected: No structure, no status lifecycle, no actor tracking, no query capability.

**Extend `BookingRequirement`**
Rejected: `BookingRequirement` is in the inventory/pricing domain (room type, quantity, price). Operational setup instructions belong to a separate operational domain.

**Attach to `Stay`**
Rejected: Stay does not exist at booking creation time. Requests would be uncapturable until after room assignment, breaking the core use case (guest calls to make a request at booking time).

**Use `folio_entries` with a special charge type**
Rejected: Setup requests are not financial. Creating FolioEntries for operational instructions would corrupt the single-sided ledger and confuse the financial domain.

**Single nullable FK in `bookings` pointing to a separate `room_setup_requests` table**
Rejected: Many-to-one relationship is wrong; a booking can have many requests. The proposed design correctly models this as a 1-to-many from Booking.
