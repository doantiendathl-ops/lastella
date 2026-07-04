# ADR-83: requested_by Column — NOT NULL

**Date:** 2026-07-04
**Status:** Accepted
**Phase:** 4.1
**Decider:** Architecture Review Process — Blocker B-2 resolution

---

## Context

The `booking_special_requests` table includes a `requested_by` column that records which staff member created the request. The Phase 4.1 roadmap document specified this column as `BIGINT UNSIGNED NULL`, while the Phase 4.1 gap analysis (section 2.11) specified it as `BIGINT UNSIGNED NOT NULL`. This discrepancy was identified as Blocker B-2 in the Architecture Review.

The question is: can a request ever be created without a known actor?

### Phase 4.1 Creation Paths

The Phase 4.1 roadmap describes exactly one creation path:

> "Staff records via Booking Detail → Yêu cầu tab"
> "booking_id set, stay_id NULL (room not yet assigned)"

The "Yêu cầu" tab is accessible only to authenticated staff with `special_request.create` permission (ADMIN, MANAGER, RECEPTION). There is no guest-facing creation path, no API unauthenticated creation, and no system-automated creation in Phase 4.1 scope.

### Why NULL Was in the Original Roadmap

The NULL was likely a defensive default — leaving the door open for hypothetical future system-automated requests (e.g., auto-generated from a booking form field). However, Phase 4.1 has no such system path.

---

## Decision

`requested_by` is **NOT NULL** in Phase 4.1.

```sql
requested_by BIGINT UNSIGNED NOT NULL,  -- always staff; ADR-83
```

```sql
CONSTRAINT fk_bsr_req_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT
```

### Rationale

1. **Every Phase 4.1 creation path is staff-initiated** — `Auth::id()` is always available. The `StoreBookingSpecialRequestRequest` handler can require the authenticated user's ID at the service layer.

2. **Consistent with Phase 3 actor-tracking pattern** — Phase 3 consistently uses NOT NULL for actor columns on operations that are always staff-initiated (e.g., `folio_entries.created_by NOT NULL`, `booking_payments.created_by NOT NULL`).

3. **NULL as a signal of a gap** — If `requested_by` could be NULL, any query filtering by actor (e.g., "show me requests I created") becomes ambiguous. NOT NULL removes this ambiguity.

4. **Future system-created requests require an explicit schema decision** — If a future phase needs system-automated request creation, the correct approach is to create a system user record (a bot/service account user) and reference its ID, rather than storing NULL. This is a cleaner design and keeps the NOT NULL constraint.

---

## Consequences

**Positive:**
- Audit trail is complete — every request has a known human actor
- Queries filtering by `requested_by` are unambiguous
- `BookingSpecialRequestPolicy` can rely on `requested_by` being non-null for "own vs. any" cancel scope

**Negative / Trade-offs:**
- If a future phase introduces system-automated request creation (e.g., from a guest booking portal), a system/bot user account must be created to satisfy the NOT NULL constraint. This is a minor operational consideration, not a blocking concern.
- Seeder or test factories must always supply a valid `requested_by` user ID. The Phase 3 factory pattern (`User::factory()->create()` in tests) handles this automatically.

---

## Alternatives Considered

**NULL (original roadmap)**
Not selected. No Phase 4.1 creation path is actor-less. Allowing NULL without any current use case introduces complexity without benefit.

**Nullable with a sentinel value (e.g., user_id = 0 for "system")**
Not selected. Magic IDs are worse than a proper system user account. This approach creates a FK violation (user 0 does not exist) or requires a special non-deletable user row. The proper solution, if ever needed, is a bot user account.

**Separate `system_created` boolean flag**
Not selected. Over-engineering for a distinction that does not exist in Phase 4.1. YAGNI.
