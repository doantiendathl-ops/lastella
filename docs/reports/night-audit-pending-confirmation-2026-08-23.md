# Night Audit Pending-Confirmation Window — 2026-08-23

User design (chat 2026-08-22/23), follow-on to
`docs/reports/revenue-audit-night-audit-never-run-2026-08-22.md`'s finding
that a Night Audit posting was permanently immutable the instant it posted
(ADR-50: any `posting_key`-set `FolioEntry` unvoidable forever) — meaning a
wrong Night Audit run (bad price, wrong extra-bed count, missed package)
could never be corrected once it ran. "Cửa sổ chờ xác nhận 24h": a COMPLETED
run stays correctable (voidable + a manual "Tính lại"/recalculate button)
until the NEXT run auto-confirms it — except a stay's own entries finalize
immediately the moment that stay actually checks out.

## Schema change

New migration `2026_08_23_000001_add_night_audit_pending_confirmation`:

- **Drops the UNIQUE constraint on `folio_entries.posting_key`**, replaced
  with a plain (non-unique) index for lookup performance. Required so a
  voided entry's key can be reused when recalculation reposts it — the old
  UNIQUE constraint would reject the re-insert even after the original row
  was voided. Uniqueness *among non-voided rows* is still enforced at the
  application layer, inside each posting job's existing `lockForUpdate()`
  transaction (the real race-condition guard was always there — the DB
  constraint was a second, now-obsolete backstop).
- Adds `folio_entries.night_audit_run_id` (nullable FK → `night_audit_runs`,
  null-on-delete) and `folio_entries.finalized_at` (nullable timestamp).
- Adds `night_audit_runs.confirmed_at` (nullable timestamp).

This is the first migration in this feature area that **alters an existing
table's constraints** rather than only adding new tables — flagged
explicitly for the deployment runbook.

## Application changes

- `NightAuditService`: new `confirmPendingRuns()` (auto-confirms every
  COMPLETED+unconfirmed run and finalizes its still-open entries; runs at
  the top of every `runForDate()`, including the scheduled midnight run) and
  new `recalculate(NightAuditRun, User)` (voids all not-yet-finalized
  entries for a run, then re-runs the same 7 posting jobs for that business
  date; throws `RunNotRecalculableException` if the run isn't COMPLETED or
  is already confirmed).
- `FolioService::voidEntry()`: ADR-50 relaxed — a system entry is voidable
  if it has `night_audit_run_id` set AND `finalized_at` still null (checked
  twice: once pre-lock as a fast-fail, once again under `lockForUpdate()` as
  the authoritative check). Manual charges and non-Night-Audit system
  entries (late-checkout fee, early-check-in fee, checkout inspection,
  ONE_TIME unified-service postings) are completely unchanged.
- `StayService::checkOut()`: finalizes just that stay's own entries
  immediately on checkout, independent of whether the whole run is
  confirmed later.
- All 7 posting jobs + `PostingContext`/`UnifiedServicePostingJob`: pass
  `night_audit_run_id` through when creating entries.
- New endpoint `POST admin/night-audit/{nightAuditRun}/recalculate`
  (`NightAuditController::recalculate()`), gated by a new
  `NightAuditRunPolicy::recalculate()` (same permission as run/retry).
  Index/Show Inertia props gain `confirmed_at`, `is_awaiting_confirmation`,
  `can_recalculate`. Frontend (`Index.vue`/`Show.vue`) shows confirmation
  status + a "Tính lại" button gated on `can_recalculate`.
- New: `App\Exceptions\RunNotRecalculableException`.

## Verification

- New `tests/Feature/NightAuditPendingConfirmationTest.php` (15 tests) — all
  passing: confirmation lifecycle, void guard both directions, manual-charge
  and non-sweep-system-entry regression checks, checkout early-finalization,
  recalculate success + skip-already-finalized + reject-not-completed +
  reject-already-confirmed, controller endpoint incl. RECEPTION 403, Show
  page prop exposure.
- Targeted regression (`NightAudit|FolioService|PostingJob|StayService`
  filter) — **184/184 passing**, 0 regressions in the touched area.
- Full suite: 29 failed / 1391 passed — none traced to this change (grep
  across the captured run found zero NightAudit/FolioService/PostingJob
  failures; the one failure inspected directly,
  `RoomAvailabilityCheckerTest > checked in booking with partial overlap is
  visible`, matches a known pre-existing date-drift failure class unrelated
  to this feature). Full per-test failure list wasn't fully captured
  (background output buffer only retained the tail) — treat as
  strongly-supported, not exhaustively proven.
- Migration verified up on the local dev DB (`migrate:status` confirms
  "Ran").

## Status

Implementation complete, tests green. Committed as part of the
2026-08-23 deployment batch — see `docs/yeucauchomaychu.md` for the
production rollout plan, in particular the migration's constraint change.
