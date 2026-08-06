<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room Demand and Room Board Unification — Milestone 4.
 *
 * Links a released RoomAssignment back to the ReleaseBatch it was released
 * in. Nullable — existing rows (released before this column existed, or
 * released singly via the pre-existing single-release flow, which does not
 * create a batch) stay NULL forever; never backfilled. `restrictOnDelete()`
 * mirrors the same precedent as booking_requirement_id (Milestone 1): a
 * ReleaseBatch is never hard-deleted by any route/UI in this codebase
 * (Product Owner Decision #20), so this is a belt-and-suspenders DB-level
 * guard, not a behavior anyone should ever hit in practice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('room_assignments', 'release_batch_id')) {
                $table->foreignId('release_batch_id')
                    ->nullable()
                    ->after('release_reason')
                    ->constrained('release_batches')
                    ->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('room_assignments', function (Blueprint $table): void {
            if (Schema::hasColumn('room_assignments', 'release_batch_id')) {
                $table->dropForeign(['release_batch_id']);
                $table->dropColumn('release_batch_id');
            }
        });
    }
};
