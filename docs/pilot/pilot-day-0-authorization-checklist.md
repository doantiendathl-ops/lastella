# LASTELLA Pilot Day 0 — Authorization Checklist

Status: evidence gathered 2026-07-17 (fourth closure pass — Security Hardening / Demo Account Closure completed this pass). See `docs/reports/pilot-day-0-blocker-closure-report.md` for full evidence.

## Critical Conditions

- [x] Demo passwords rotated/disabled — **CLOSED (2026-07-17)**: both `admin@lastella.local` and `manager@lastella.local` soft-deleted via the real `UserController::destroy()` endpoint; confirmed `User::where('email',...)->first()` returns `null` for both (login-lookup naturally blocked by Eloquent's `SoftDeletingScope`, no extra code needed); rows still physically exist (`withTrashed()` finds them) so no FK/orphan impact
- [x] Real accounts created and tested — **CLOSED (2026-07-17)**: 4 real, personally-attributable accounts created via the real Admin UI (Admin: Tien Dat Doan, Manager: Vũ Thành Trung, Reception: Nguyễn Thị Hồng, Housekeeping: Nguyễn Thị Hường), correct roles confirmed (`ADMIN+MANAGER`, `MANAGER+SALES`, `RECEPTION`, `HOUSEKEEPING`), correct resolved permissions confirmed including `stay.extend`/`stay.room_move` (see Security Hardening note below), predicted menu visibility per role verified against `AppLayout.vue` gating logic. Accountant explicitly out of Pilot Day 0 scope (owner-confirmed)
- [x] Business Settings corrected — **CLOSED (2026-07-17)**: `company_name`, `company_address`, `company_phone`, `currency`, `timezone` all updated to real values through the actual Admin UI endpoint (`SettingController::update()`), re-verified via Laravel's own DB connection and via `SettingService::values()` (the exact method the runtime/guest-facing layer reads) — no Thailand/Bangkok/THB/+66 placeholder remains active in the `settings` table
- [ ] Production environment verified — **OPEN**: no separately deployed Pilot environment exists; only local dev (`APP_ENV=local`, `APP_DEBUG=true`)
- [x] Backup created — **CLOSED**: fresh database + storage backups created 2026-07-17 01:01, confirmed non-zero
- [x] Restore rehearsal passed — **CLOSED**: restored into an isolated test database; verified via `migrate:status` equivalent, login simulation, sample Booking, Room Board, Folio, Payments, and the real `PaymentProjectionService` — all matched the live database exactly; test database dropped afterward
- [ ] Room/floor data confirmed — **OPEN (system side CLOSED)**: 59 rooms / 3 zero-room floors re-verified live; real hotel operator confirmation still not obtained
- [ ] Night Audit ownership assigned — **OPEN**: no real names supplied; fill-in form only
- [ ] Role walkthrough completed — **OPEN**: no browser/UI tool or real staff available in this review
- [ ] Emergency contacts completed — **OPEN**: no real names/numbers supplied

## Known Manual Controls

- [ ] Reception manually avoids `VacantDirty` (system does not block it yet)
- [ ] Manager runs Night Audit manually (no scheduler exists)
- [ ] Manager confirms daily backup (no automated backup exists)
- [ ] Shift handover uses manual notes because the StayEvent history UI is absent
- [ ] All findings use the official Operational Findings process

## Authorization

- Product recommendation: _(pending — see `docs/reports/pilot-day-0-blocker-closure-report.md`)_
- Hotel owner decision: _(not yet obtained)_
- Authorized Day 0 date: _(not yet set)_
- Conditions: _(to be copied from the closure report's Required Actions once each is closed)_
- Sign-off: _(blank — no approval given; do not fill without real evidence)_

*Authorization section intentionally left blank — only ChatGPT (Product) and the hotel owner may complete it, with real names, dates, and decisions.*
