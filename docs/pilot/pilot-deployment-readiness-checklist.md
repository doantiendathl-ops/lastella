# LASTELLA Pilot — Deployment Plan & Runbook

Status: DRAFT — awaiting project owner review/approval before execution. This document is a plan and step-by-step runbook only; no deployment action was taken by this review (no tool access exists to the target machine).

## Decisions Confirmed by Project Owner (2026-07-17)

| Decision | Value |
|---|---|
| Deployment model | LAN nội bộ khách sạn only for Pilot Day 0. Cloudflare Tunnel (remote access) is a deliberate follow-up, not part of this plan. |
| Target machine | A separate Windows 11 PC (not the current dev machine) |
| Execution method | Owner self-executes this Runbook directly on the target machine; this review has no remote access to it |
| Database | MySQL running locally on the same Windows 11 machine (via Laragon) |
| Backup destination | A different drive/folder on the same machine (accepted as the Pilot starting point — see Known Risk below) |
| Infrastructure admin | Project owner, self-managed |
| Target timeline | As soon as this Runbook is ready |

**Known risk accepted by this decision:** storing backups on the same physical machine as the live data means a single disk/machine failure could destroy both. This is an acceptable, explicit tradeoff for Pilot start — recommended follow-up (not blocking Pilot Day 0): periodically copy backup files to a USB drive or another machine.

---

## 1. Server Requirements

| Item | Requirement | Note |
|---|---|---|
| OS | Windows 11 (Pro or Home) | Confirmed |
| PHP | 8.3.x | Matches `composer.json` (`^8.3`) and the current dev environment (PHP 8.3.30 via Laragon) |
| MySQL | 8.x | Matches current dev environment (MySQL 8.4.3 via Laragon) |
| Web server | Apache (bundled with Laragon) | Recommended over `php artisan serve` — `artisan serve` is single-threaded and explicitly not intended for anything beyond local development; a Pilot with several staff hitting it concurrently needs a real web server |
| PHP extensions | OpenSSL, PDO, pdo_mysql, Mbstring, Tokenizer, XML, Ctype, JSON, BCMath, Fileinfo | All bundled by default in Laragon's PHP 8.3 build — verify via `php -m` after install |
| Node.js | Only needed if building assets on that machine; otherwise copy the pre-built `public/build` folder from a machine that has Node | Recommended: build once (on any machine) and copy `public/build`, to avoid installing Node.js on the Pilot machine at all |

## 2. Installation Steps (run on the target Windows 11 machine)

```powershell
# 1. Install Laragon (manual download+install from the official Laragon site — not scriptable here)
#    Choose the "Full" package (includes PHP 8.3.x + MySQL 8.x + Apache)

# 2. Get the codebase onto the machine (choose ONE):
#    2a. If Git is available and the machine can reach the repo:
git clone <repo-url> C:\laragon\www\lastella
#    2b. Otherwise: copy the project folder (excluding vendor/, node_modules/, storage/logs, storage/backups)
#        from the dev machine via USB/network share into C:\laragon\www\lastella

cd C:\laragon\www\lastella

# 3. Install PHP dependencies (production-optimized, no dev tools)
composer install --no-dev --optimize-autoloader

# 4. Frontend assets — copy the pre-built public/build folder from the dev machine
#    (recommended: avoids installing Node.js on the Pilot machine entirely)
#    If building locally instead: npm ci && npm run build

# 5. Create the environment file
copy .env.production.example .env

# 6. Generate a FRESH application key — never reuse the dev machine's key
php artisan key:generate
```

## 3. `.env` Values for This Deployment (LAN-only, no HTTPS yet)

Edit `.env` on the target machine with these specific values (adjust `<LAN-IP>` once known — see §5):

```env
APP_NAME="Lastella PMS"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://<LAN-IP>:8000

APP_TIMEZONE=Asia/Ho_Chi_Minh
LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<pilot-database-name — set per the separate Pilot Database Preparation Plan>
DB_USERNAME=<create a dedicated MySQL user for this app, not root>
DB_PASSWORD=<strong password, set locally on this machine, never shared in chat>

SESSION_DRIVER=database
SESSION_LIFETIME=120
# SESSION_SECURE_COOKIE stays unset/false — there is no HTTPS on the LAN-only setup.
# This is acceptable ONLY because traffic never leaves the trusted internal network.
# MUST be set to true once Cloudflare Tunnel/HTTPS is added later (do not forget this step then).

CACHE_STORE=database
QUEUE_CONNECTION=database

VITE_APP_NAME="${APP_NAME}"
```

**Do not use `root` with no password for `DB_USERNAME`/`DB_PASSWORD`** (that's fine for a disposable local dev box, not for a Pilot machine with real guest data) — create a dedicated MySQL user scoped to only the Pilot database:

```sql
CREATE USER 'lastella_app'@'127.0.0.1' IDENTIFIED BY '<choose a strong password yourself>';
GRANT ALL PRIVILEGES ON <pilot-database-name>.* TO 'lastella_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

## 4. Database Setup

The actual database name, migration/seed sequence, and master-data entry are covered by the separate **Pilot Database Preparation Plan** (`docs/pilot/pilot-database-preparation-plan.md`, once approved — do not run migrations against a copy of the current QA-contaminated local database). This deployment plan only covers the *server-level* MySQL install; do not seed or migrate until that plan is approved.

## 5. Network Configuration (LAN-only)

```powershell
# 1. Find this machine's current LAN IP
ipconfig
# Look for "IPv4 Address" under the active Wi-Fi/Ethernet adapter, e.g. 192.168.1.50

# 2. Reserve that IP permanently for this machine in the hotel router's DHCP settings
#    (Router admin page — steps vary by router brand. Look up the machine's MAC address via:
getmac
#    then add a DHCP reservation there so the IP never changes.)

# 3. Allow inbound traffic on the app's port through Windows Firewall (LAN only)
New-NetFirewallRule -DisplayName "Lastella PMS (LAN)" -Direction Inbound -LocalPort 8000 -Protocol TCP -Action Allow -Profile Private
```

Update `APP_URL` in `.env` to match the reserved IP once confirmed (§3).

## 6. Running the Application

Recommended (real web server, not the dev server):

- In Laragon, set up a virtual host pointing to `C:\laragon\www\lastella\public`, bound to port 8000 (or 80) on all interfaces (`0.0.0.0`), not just `127.0.0.1` — this is what makes it reachable from other devices on the LAN, not just the server machine itself.
- Confirm from a second device on the same Wi-Fi: open `http://<LAN-IP>:8000` in a browser.

Fallback (simpler, acceptable for Pilot Day 0 only, not for later): `php artisan serve --host=0.0.0.0 --port=8000` — but this must be manually restarted after every machine reboot and is single-threaded; use Laragon's Apache for anything beyond the very first days.

## 7. Storage, Cache, Queue

- `php artisan storage:link` — run once (no upload feature exists yet in the product, but this is harmless and matches the original Go-Live checklist item).
- Cache/session: `CACHE_STORE=database`, `SESSION_DRIVER=database` — adequate at Pilot scale, no Redis/Memcached needed.
- Queue: `QUEUE_CONNECTION=database` is set but decorative — no `ShouldQueue` job exists in this codebase, no worker process needs to run.
- Cron/Scheduler: none exists in this codebase (`bootstrap/app.php` has no `->withSchedule()`) — Night Audit remains 100% manual, per the separate Night Audit Ownership assignment. No Windows Task Scheduler entry is needed for the app itself.

## 8. Backup

Recreate the backup mechanism on the target machine (the equivalent scripts were removed from this repo in an earlier session cleanup and need to be re-established for the real Pilot):

- Daily `mysqldump` of the Pilot database, gzipped, to the separate drive/folder the owner has designated (§ Decisions above).
- Daily archive of `storage/app` + `.env`, to the same backup location.
- **Windows Task Scheduler** entry to run both automatically once daily (recommended addition over the manual-trigger approach used during this review's testing — reduces reliance on remembering to run it by hand). This review can draft the exact `.bat`/PowerShell backup scripts and the Task Scheduler XML/command on request, once this deployment plan itself is approved.
- Retention: keep 14 daily + 8 weekly, consistent with the policy already established earlier in this project's pilot documentation.

## 9. Restore Procedure & Rollback

- Restore: `mysql <pilot-db-name> < backup.sql` (from the gzipped dump) into a **separate, disposable test database first** to verify before ever restoring over the live Pilot database — same discipline already demonstrated and verified earlier in this project (see `docs/reports/pilot-day-0-blocker-closure-report.md`, Restore Rehearsal Evidence).
- Rollback (bad deploy/update): take a fresh backup of the current (possibly broken) state first, then restore the last-known-good backup, then re-verify via the same Recovery Verification steps already established (migrate:status equivalent, login, sample Booking, Room Board, Folio, Payment Projection, Night Audit state).

## 10. Health Check (after deployment, before declaring ready)

- [ ] `http://<LAN-IP>:8000/login` loads correctly from a second device on the hotel Wi-Fi
- [ ] Login works for at least one of the 4 real accounts
- [ ] `php artisan migrate:status` shows all migrations run, none pending
- [ ] No debug/stack-trace output visible anywhere (confirms `APP_DEBUG=false` took effect)
- [ ] A test booking can be created, assigned, checked in (full smoke test comes later per the separate End-to-End Smoke Test plan)

## 11. Access Control

- Windows account on the machine: recommend a dedicated local Windows user (not shared with anything else), password-protected, auto-lock screen enabled.
- Application accounts: the 4 real accounts already created and role-verified (see `docs/reports/pilot-day-0-blocker-closure-report.md`) — these will need to be (re-)created on whatever fresh Pilot database is provisioned per the Database Preparation Plan, since accounts created on the current dev database do not automatically exist elsewhere.
- MySQL: dedicated `lastella_app` user scoped only to the Pilot database (§3), never use `root` for the application connection on this machine.

## 12. Log Retention

- `LOG_LEVEL=error` reduces volume significantly versus the dev default (`debug`).
- `config/logging.php` has no `daily` channel defined in this codebase (a one-line code change, out of Feature-Freeze scope unless separately approved) — for now, monitor `storage/logs/laravel.log` file size manually and archive/clear it periodically (e.g., weekly, alongside the backup routine) to prevent unbounded growth.

---

## Explicitly Not Covered by This Plan (separate, not-yet-approved plans)

- **Pilot database content** (fresh vs. cleaned-QA, master data entry) — `docs/pilot/pilot-database-preparation-plan.md`
- **Room/Floor operator confirmation** — `docs/pilot/pilot-master-data-confirmation.md`
- **Night Audit ownership** — `docs/pilot/night-audit-responsibility-assignment.md`
- **Role Walkthrough with real staff** — `docs/pilot/pilot-role-walkthrough-checklist.md`
- **Cloudflare Tunnel / remote access** — deliberately deferred, not part of Pilot Day 0

## Approval Required Before Execution

This is a plan only. No installation, configuration, or deployment action has been taken on the target Windows 11 machine — this review has no access to it. The project owner executes this Runbook themselves and reports back results (command output, screenshots, or a description) for remote verification.
