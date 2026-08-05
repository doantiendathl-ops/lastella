<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room Demand and Room Board Unification — Milestone 1.
 *
 * Adds the Assignment→Requirement traceability link (Architecture Review
 * REVISION 3, Product Owner Decision #8/#13). Nullable to support legacy rows
 * that predate this column (backfilled separately — see
 * room-assignments:backfill-booking-requirements) and rows that stay
 * genuinely ambiguous after backfill. `restrictOnDelete()` is used instead of
 * `nullOnDelete()`/`cascadeOnDelete()` on purpose: once a RoomAssignment
 * (active or released) references a requirement line, that line must never
 * be hard-deleted — restrictOnDelete() is the database-level backstop for the
 * application guard added to BookingService::deleteRequirement() in this
 * same milestone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('room_assignments', 'booking_requirement_id')) {
                $table->foreignId('booking_requirement_id')
                    ->nullable()
                    ->after('room_type_id')
                    ->constrained('booking_requirements')
                    ->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('room_assignments', function (Blueprint $table): void {
            if (Schema::hasColumn('room_assignments', 'booking_requirement_id')) {
                $table->dropForeign(['booking_requirement_id']);
                $table->dropColumn('booking_requirement_id');
            }
        });
    }
};
