# ADR-82: booking_id Foreign Key — ON DELETE RESTRICT

**Date:** 2026-07-04
**Status:** Accepted
**Phase:** 4.1
**Decider:** Architecture Review Process

---

## Context

The original Phase 4.1 roadmap specified `booking_id` FK on `booking_special_requests` with `ON DELETE CASCADE`. This means hard-deleting a Booking record would silently delete all of its associated special requests.

Phase 3 Architecture Baseline (Section 5, Database Baseline) established the FK convention:
- `folios.booking_id` → **RESTRICT** (prevents booking deletion when folio exists)
- `folio_entries.folio_id` → CASCADE (acceptable within the folio domain)
- `booking_payments.booking_id` → CASCADE (flagged as TD-2 in Phase 3 technical debt)

The RESTRICT pattern on `folios.booking_id` is intentional: bookings in a hotel PMS are never hard-deleted. They are cancelled (status change), archived, or anonymised. A hard-DELETE of a booking row is an administrative operation that should fail fast if any dependent records exist, rather than silently cascading.

The CASCADE on `booking_payments.booking_id` was identified as technical debt (TD-2) in the Phase 3 final report.

Phase 4.1 introduces a new FK from `booking_special_requests.booking_id` → `bookings.id`. The choice of CASCADE vs RESTRICT must be consistent with Phase 3 convention.

---

## Decision

`booking_id` FK on `booking_special_requests` uses **ON DELETE RESTRICT**.

```sql
CONSTRAINT fk_bsr_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT
```

Additionally, `requested_by` FK uses RESTRICT (not SET NULL), consistent with the principle that every request must have a known actor:

```sql
CONSTRAINT fk_bsr_req_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT
```

### Rationale

1. **Consistency with Phase 3 FK convention** — `folios.booking_id` uses RESTRICT. Phase 4.1 follows the same convention for the same type of relationship (top-level entity FK from a booking-dependent table).

2. **Fail-fast on administrative mistakes** — If someone attempts to hard-delete a booking that has special requests, the database rejects the operation immediately. This is safer than silent data loss.

3. **Bookings are never hard-deleted in production** — The cancellation flow (`BookingService::cancelBooking()`) updates status to CANCELLED and triggers `SpecialRequestService::autoCancelForBooking()`. The booking row itself is not deleted. RESTRICT never blocks normal operational flows.

4. **Avoids repeating TD-2** — Phase 3 flagged `booking_payments.booking_id` CASCADE as technical debt. Phase 4.1 does not introduce the same pattern.

---

## Consequences

**Positive:**
- No silent data loss when booking rows are administratively removed
- Consistent FK convention across the codebase
- Does not affect any normal hotel PMS workflow (bookings are cancelled, not deleted)

**Negative / Trade-offs:**
- Any future database admin script that hard-deletes booking rows must also handle `booking_special_requests` first. This is the intended behaviour — the RESTRICT forces explicit cleanup.
- Integration tests that create and hard-delete test bookings must delete or cascade special requests first. Use `RefreshDatabase` / transaction rollback in tests to avoid this overhead.

---

## Alternatives Considered

**ON DELETE CASCADE (original roadmap)**
Not selected. Silently deletes all requests when a booking is hard-deleted. Inconsistent with Phase 3 FK convention. Risk of silent data loss during administrative or migration operations.

**ON DELETE SET NULL**
Not selected. `booking_id` is NOT NULL (a request cannot exist without a booking). SET NULL would violate the NOT NULL constraint and is not a valid option.

**ON DELETE CASCADE with a deletion audit log**
Not selected. Adding a separate audit table to compensate for CASCADE adds complexity without addressing the root issue. RESTRICT is simpler and safer.
