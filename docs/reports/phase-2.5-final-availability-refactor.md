# Phase 2.5 — Final Architecture Cleanup: RoomAvailabilityRuleService Refactor

## Objective

Eliminate duplicated conflict query logic in `RoomAvailabilityRuleService` and remove the now-unused `RoomAssignment` import from `BookingService`. No business behaviour changes.

## What Was Duplicated

Before this refactor three methods each contained inline copies of the same two query concerns:

| Concern | `hasConflict` | `findConflictForTimeChange` | `getBlockingAssignments` |
|---------|:---:|:---:|:---:|
| Base `RoomAssignment::query()` construction | via `blockingQuery()` | inline | inline factory |
| Assignment-ID exclusion | via `blockingQuery()` | — | — |
| Booking-ID exclusion | — | inline `where('booking_id', '!=', …)` | inline `when(…)` |
| Time-overlap predicates | inline | inline | inline (×2) |

## Changes Made

### `app/Services/RoomAvailabilityRuleService.php`

**`blockingQuery()` extended** — now accepts both exclusion modes through named parameters:

```php
private function blockingQuery(
    ?int $ignoreAssignmentId = null,
    ?int $excludeBookingId = null,
): Builder
```

Both `hasConflict` (excludes by assignment ID) and `findConflictForTimeChange` / `getBlockingAssignments` (exclude by booking ID) now share the same starting point.

**`applyOverlap()` extracted** — single private helper for the time-overlap predicate:

```php
private function applyOverlap(Builder $query, Carbon $startAt, Carbon $endAt): Builder
```

Replaces the three independent copies of:
```php
->where('start_at', '<', $endAt)
->where('end_at', '>', $startAt)
```

All three public methods (`hasConflict`, `findConflictForTimeChange`, `getBlockingAssignments`) now delegate to these two shared builders.

### `app/Services/BookingService.php`

Removed unused `use App\Models\RoomAssignment;` import. The model was imported by the old inline conflict query in `validateTimeChange()`; that query was replaced with a call to `RoomAvailabilityRuleService::findConflictForTimeChange()` in Phase 2.5, making the import dead code.

## Files Changed

- `app/Services/RoomAvailabilityRuleService.php`
- `app/Services/BookingService.php`

## Tests Passed

```
Tests: 260 passed (1788 assertions)
Duration: ~189s
```

No tests changed.

## Ready for Codex Review

**YES**
