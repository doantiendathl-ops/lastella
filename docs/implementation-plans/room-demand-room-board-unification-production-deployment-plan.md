# Room Demand and Room Board Unification — Production Deployment Plan

## 1. Objective

Deploy Milestones 1–5 (`3a1d098`, `806dcc7`, `a331ef5`, `11f791a`, plus the Milestone 5 commit once made) of Room Demand and Room Board Unification to production, safely and reversibly.

## 2. Scope

Backend: `RoomAssignmentService`, `BookingService` (read-only touch by M1–M4, no M5 change), `StayService` (unchanged), 4 migrations (`booking_requirement_id`, `release_batches`, `release_batch_id`, plus schema already covered), FormRequests, policies. Frontend: `Show.vue` (demand-first, Room-Board-first, bulk-release, single-release, move-room panels; M5 ESC fix only).

## 3. Approved Checkpoints

`3a1d098`, `806dcc7`, `a331ef5`, `11f791a` (all pushed to `origin/phase-3`, git-verified). Milestone 5's commit is pending — not yet made as of this plan's writing (Milestone 5 has not been staged/committed/pushed).

## 4. Preconditions

- All two confirmed Architecture Gaps (Case 16, Case 3 — see Final Readiness Review §16) explicitly acknowledged by Product Owner/ChatGPT before deployment proceeds.
- Full suite (Milestone 5 report §25/§32) shows no new regression.
- ChatGPT final review of Milestone 5 completed.

## 5. Production Environment Verification

Before any deploy step: confirm `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` matches the real production URL. **Do not proceed if `APP_ENV` is not exactly `production`.**

## 6. Code Backup

Tag or record the exact pre-deploy production commit SHA before updating, so a code rollback has a precise target.

## 7. Database Backup

Full production database backup/snapshot completed and verified restorable **before** running any migration.

## 8. Git Update Procedure

`git fetch origin`, verify no divergence (`git log --oneline origin/phase-3..HEAD` and reverse both empty on the deploy target before pulling), then update to the approved checkpoint commit. Never `git reset --hard` on a shared branch; never force-push.

## 9. Maintenance Window

Recommended for the migration step (§10) given it adds a FK-constrained column; the application itself can otherwise run hot (no destructive migration).

## 10. Migration Order

Run in the existing, already-tested order: `2026_08_05_000000_add_booking_requirement_id_to_room_assignments_table`, `2026_08_06_000000_create_release_batches_table`, `2026_08_06_000001_add_release_batch_id_to_room_assignments_table`. No Milestone 5 migration exists to add to this sequence.

## 11. Backfill Decision

The `room-assignments:backfill-booking-requirements` command (M1) is **not** part of this deployment by default — it requires its own separate, explicit Product Owner approval and a dry-run review of the unique/ambiguous/no-match/already-mapped counts on the real production dataset first. Do not run `--apply` as part of this deployment.

## 12. Frontend Build

`npm run build` on the deploy target (or a build artifact promoted from CI), producing `public/build/*` — verified locally this milestone: success, no errors.

## 13. Laravel Cache Commands

Standard post-deploy sequence: `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache` (or equivalent for this project's existing deploy tooling) — matching whatever the existing production deploy process already does, not introducing anything new for this feature.

## 14. IIS/PHP/Cloudflare Tunnel Considerations

Production runs behind Cloudflare Tunnel terminating HTTPS at the edge, forwarding plain HTTP to `127.0.0.1` (per `bootstrap/app.php`'s existing `trustProxies()` configuration, unrelated to this feature but relevant to any redirect/URL generation this feature's controllers perform). No new proxy/trust configuration is needed for this feature specifically.

## 15. Production Smoke Test

After deploy: load a real booking's Room Board tab, confirm it renders without error.

## 16. Assignment Smoke Test

Demand-first and Room-Board-first: confirm both assignment flows complete successfully on a real (or synthetic, clearly-labeled) test booking.

## 17. Reverse Sync Smoke Test

Room-Board-first assignment on a room_type with no existing requirement: confirm a new `BookingRequirement` line is correctly created.

## 18. Bulk Release Smoke Test

Bulk-release at least 2 assignments with a reason; confirm exactly one `ReleaseBatch` row and correct `release_batch_id` linkage.

## 19. Check-In/Checkout Regression

Confirm check-in and checkout still work on an assignment created via either assignment path — both paths write standard `RoomAssignment`/`Stay` rows with no feature-specific check-in/checkout code.

## 20. Folio/Revenue/Night Audit Regression

Confirm a Room charge still posts correctly on check-in for a booking assigned via this feature — this feature never modifies Folio/Revenue/Night Audit calculation code.

## 21. Monitoring

No new monitoring was added or required by Milestones 1–5. Standard application error/exception logging already covers `ValidationException` rejections from every new endpoint.

## 22. Rollback Triggers

Any of: the Assignment/Reverse-Sync/Bulk-Release smoke tests failing; unexpected `RoomAssignment`/`BookingRequirement`/`ReleaseBatch` data anomalies observed within the first operational hours; a confirmed live occurrence of either documented Architecture Gap (Case 16 or Case 3) causing real customer-facing impact.

## 23. Code Rollback

**Before push to a shared branch:** drop the commit via a safe Git operation, only with Product Owner approval and a protected working tree.
**After push/shared branch:** `git revert <commit SHA>` only. Never `git reset` on `phase-3`. Never force-push.
**In production:** prefer redeploying the previous known-good commit/build artifact, or ship a forward fix. Never reset a shared branch from a production incident.

## 24. Database Rollback

`php artisan migrate:rollback` for these 3 migrations is **only safe before real release/assignment data exists** on production. Once real bookings have used bulk release (creating real `ReleaseBatch` rows) or real `booking_requirement_id` links exist, rolling back destroys that audit/traceability history. **Never roll back production migrations without a completed backup (§7) and an explicit data-impact assessment reviewed with the Product Owner first.**

## 25. Audit History Protection

`release_batches.booking_id` uses `restrictOnDelete()` specifically so a Booking can never be deleted while audit history references it (though no Booking hard-delete route exists in this codebase today — confirmed, unchanged since M4). This protection must not be weakened as part of any future change without a fresh review.

## 26. Post-Deployment Verification

Re-run the Assignment, Reverse-Sync, and Bulk-Release smoke tests (§16–18) against production data after the deployment completes, plus a spot-check of `audit_logs` entries for the smoke-test operations to confirm the audit trail is live in production.

## 27. Sign-Off Checklist

- [ ] Product Owner has explicitly acknowledged the two Architecture Gaps (Case 16, Case 3).
- [ ] ChatGPT final review of Milestone 5 completed with a recorded verdict.
- [ ] Full test suite green (no new regression) at the exact commit being deployed.
- [ ] Production database backup completed and verified restorable.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` correct — verified, not assumed.
- [ ] Maintenance window scheduled if required.

## 28. Go/No-Go Decision

**Not decided by this document.** This plan documents the mechanics of a safe deployment; the actual Go/No-Go call belongs to the Product Owner, made only after the Final Readiness Review's conditions (§18 of that document) are satisfied.
