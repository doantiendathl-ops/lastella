# ADR-81: HOUSEKEEPING Role — Restricted "Yêu cầu" Tab Access (Option A)

**Date:** 2026-07-04
**Status:** Accepted
**Phase:** 4.1
**Decider:** Architecture Review Process — Blocker B-1 resolution

---

## Context

Phase 4.1 Architecture Review (2026-07-04) identified a functional design gap:

- HOUSEKEEPING has the `special_request.fulfill` permission in the proposed permission matrix
- However, the original Phase 4.1 roadmap restricted the "Yêu cầu" tab to ADMIN, MANAGER, RECEPTION only
- The Phase 4.2 Housekeeping Board (which would provide HOUSEKEEPING's primary operational UI) is out of Phase 4.1 scope
- The Room Board indicator shows pending request counts but provides no action surface for Phase 4.1

This gap meant HOUSEKEEPING staff could see that requests exist (badge count on Room Board) but had no UI surface to acknowledge or fulfill them. ADMIN and MANAGER would need to act on their behalf for all request fulfillment, creating an operational bottleneck.

Three options were evaluated:
- **Option A:** Extend "Yêu cầu" tab visibility to HOUSEKEEPING with restricted access (view + acknowledge + fulfill only)
- **Option B:** Add a Room Board modal with a fulfill action for HOUSEKEEPING only
- **Option C:** Defer HOUSEKEEPING fulfill capability to Phase 4.2 Housekeeping Board; document as known Phase 4.1 limitation

---

## Decision

**Option A is accepted.**

HOUSEKEEPING is granted access to the "Yêu cầu" tab in Booking Detail (`Booking/Show.vue`) in **Restricted Mode**.

### HOUSEKEEPING Restricted Mode — Allowed Actions

| Action | ADMIN / MANAGER | RECEPTION | HOUSEKEEPING |
|---|---|---|---|
| View request list | ✅ | ✅ | ✅ |
| Create request | ✅ | ✅ | ❌ |
| Acknowledge request | ✅ | ❌ | ✅ |
| Fulfill request | ✅ | ❌ | ✅ |
| Cancel request | ✅ | ❌ | ❌ |
| Edit request details | ✅ | ❌ | ❌ |
| Delete request | ❌ (cancel only) | ❌ | ❌ |

### Permission Mapping

- `special_request.create` → ADMIN, MANAGER, RECEPTION
- `special_request.fulfill` → ADMIN, MANAGER, HOUSEKEEPING *(covers both acknowledge and fulfill transitions)*
- `special_request.cancel` → ADMIN, MANAGER

**`special_request.fulfill` covers both the `pending → acknowledged` and `acknowledged → fulfilled` transitions.** No separate `special_request.acknowledge` permission is introduced; the acknowledge action is considered part of the fulfill workflow.

### UI Implementation Guidance

The "Yêu cầu" tab renders for all of: ADMIN, MANAGER, RECEPTION, HOUSEKEEPING.

Within the tab, the Vue component conditionally renders action buttons based on the authenticated user's permissions:

- "Add Request" button: visible only if `hasPermission('special_request.create')` *(ADMIN, MANAGER, RECEPTION)*
- "Acknowledge" button per row: visible only if `hasPermission('special_request.fulfill')` AND request status is `pending` *(ADMIN, MANAGER, HOUSEKEEPING)*
- "Fulfill" button per row: visible only if `hasPermission('special_request.fulfill')` AND request status is `acknowledged` *(ADMIN, MANAGER, HOUSEKEEPING)*
- "Cancel" button per row: visible only if `hasPermission('special_request.cancel')` *(ADMIN, MANAGER)*

HOUSEKEEPING sees the tab as a read-only list with acknowledge/fulfill action buttons only. The "Add Request" form and the "Cancel" action are hidden.

### Backend Policy Enforcement

The `BookingSpecialRequestPolicy` enforces permissions server-side regardless of UI state:
- `create()` → checks `special_request.create`
- `update()` (status change: acknowledge/fulfill) → checks `special_request.fulfill`
- `cancel()` → checks `special_request.cancel`

Frontend conditional rendering is UX-layer only; policy is the authoritative gate.

---

## Consequences

**Positive:**
- HOUSEKEEPING can fulfill requests in Phase 4.1 without waiting for Phase 4.2
- No additional permission is needed — `special_request.fulfill` covers both acknowledge and fulfill
- Single tab implementation reduces code surface; no separate HOUSEKEEPING-specific page needed in Phase 4.1
- When Phase 4.2 Housekeeping Board ships, it can reuse the same permission and the same service methods
- The restricted-mode pattern is already established in the codebase (RECEPTION sees tabs that ACCOUNTANT does not)

**Negative / Trade-offs:**
- HOUSEKEEPING sees Booking Detail context they may not need (booking info, guest name, dates). This is acceptable — the tab is nested within the booking they are preparing for.
- The tab conditional rendering adds complexity to `Show.vue`. The complexity is manageable with a computed `canManageRequests` / `canFulfillRequests` flag.

---

## Alternatives Considered

**Option B: Room Board modal with fulfill action**
Not selected. Requires modifying the Phase 2.x Room Board component (additional scope) and creates a second UI surface with inconsistent UX. HOUSEKEEPING would fulfill from the Room Board while ADMIN/MANAGER fulfill from the Booking Detail — two surfaces for one workflow.

**Option C: Defer to Phase 4.2 Housekeeping Board**
Not selected. This would mean shipping `special_request.fulfill` as a dead permission in Phase 4.1 — a permission that exists but grants no capability. This is confusing and creates a false sense of completed functionality. If Phase 4.2 is delayed, HOUSEKEEPING has no operational path.
