<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User request (2026-08-22/23 chat) — "cửa sổ chờ xác nhận 24h": Night
 * Audit postings stay correctable for a grace window instead of being
 * immediately, permanently immutable (see docs/reports/revenue-audit-
 * night-audit-never-run-2026-08-22.md — the "posting_key set -> nobody
 * can ever void it, ADR-50" rule was the exact reason a wrong Night Audit
 * run could never be fixed).
 *
 * Drops the UNIQUE constraint on folio_entries.posting_key — a HARD
 * blocker for "void the old entry, then re-post with the same logical
 * key" during recalculation, since the DB would reject the re-insert
 * even after the old row was voided. Uniqueness AMONG NON-VOIDED rows is
 * still enforced at the application layer (inside each posting job's
 * existing lockForUpdate() transaction, which already guards against the
 * real race-condition concern) — see the accompanying fix across all 7
 * posting jobs. A plain (non-unique) index is kept for lookup performance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folio_entries', function (Blueprint $table): void {
            $table->dropUnique(['posting_key']);
            $table->index('posting_key');

            $table->foreignId('night_audit_run_id')
                ->nullable()
                ->after('folio_id')
                ->constrained('night_audit_runs')
                ->nullOnDelete();

            // NULL = still correctable (pending confirmation, or not a Night
            // Audit posting at all). Set once and only once, either by
            // NightAuditService::confirmPendingRuns() (whole run, at the
            // start of the next run) or by StayService::checkOut() (just
            // this stay's own rows, the moment the guest leaves).
            $table->timestamp('finalized_at')->nullable()->after('voided_at');
        });

        Schema::table('night_audit_runs', function (Blueprint $table): void {
            // Whole-run confirmation timestamp — set by confirmPendingRuns()
            // when the NEXT run starts. Individual stays can finalize their
            // own rows earlier via checkout without this being set (a run
            // can have some finalized, some still-pending rows at once).
            $table->timestamp('confirmed_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('night_audit_runs', function (Blueprint $table): void {
            $table->dropColumn('confirmed_at');
        });

        Schema::table('folio_entries', function (Blueprint $table): void {
            $table->dropColumn('finalized_at');
            $table->dropConstrainedForeignId('night_audit_run_id');

            $table->dropIndex(['posting_key']);
            $table->unique('posting_key');
        });
    }
};
