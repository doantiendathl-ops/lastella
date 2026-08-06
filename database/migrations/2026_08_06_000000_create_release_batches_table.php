<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room Demand and Room Board Unification — Milestone 4.
 *
 * Atomic bulk room release. One row per bulk-release request, shared reason/
 * note/reduce_demand for the whole batch (Architecture Review REVISION 3 Mục
 * 17). Created in the SAME transaction as the RoomAssignment updates it
 * covers — never a row without matching released assignments.
 *
 * Column naming: the Architecture Review's draft schema used `actor_id`, but
 * every other "who did this" column in this codebase follows the `<verb>_by`
 * convention (room_assignments.assigned_by/released_by, stays.checked_in_by/
 * checked_out_by, folio_entries.posted_by/voided_by, users.created_by).
 * `released_by` is used here instead, to match that established convention
 * (Implementation task Mục IX explicitly allows this substitution) — noted
 * in the Milestone 4 report as a deliberate, convention-driven deviation
 * from the Architecture Review's literal column name, not a design change.
 *
 * No destroy/update route or UI is ever built for this table (Product Owner
 * Decision #20) — it is an append-only audit trail of bulk releases.
 *
 * Final Gap Closure (before this migration was ever committed):
 *  - `reason` is NOT nullable — a bulk release without a reason must never be
 *    representable in the database, not just rejected by the FormRequest.
 *    `RoomAssignmentService::bulkReleaseAssignments()` independently rejects
 *    a null/blank/whitespace-only reason before writing anything, so this
 *    column constraint is a second, DB-level backstop, never the only guard.
 *  - `booking_id` uses `restrictOnDelete()`, not `cascadeOnDelete()` —
 *    `release_batches` is a business/audit history record with no
 *    destroy/update route (Product Owner Decision #20), so it must never be
 *    silently deleted as a side effect of deleting its booking. Booking
 *    currently has no SoftDeletes and no hard-delete route anywhere in this
 *    codebase (`Route::resource('bookings', ...)->except(['destroy'])`), so
 *    this has no observed behavioral effect today — it is a deliberate
 *    future-proofing choice, consistent with the same protective pattern
 *    already used for `room_assignments.booking_requirement_id` (Milestone 1)
 *    and `room_assignments.release_batch_id` (below in this same milestone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->text('note')->nullable();
            $table->boolean('reduce_demand')->default(false);
            $table->dateTime('released_at');
            $table->timestamps();
            $table->index(['booking_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_batches');
    }
};
