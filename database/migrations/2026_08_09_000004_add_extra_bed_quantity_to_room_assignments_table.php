<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room-Scoped Bed Operations Correction — additive, nullable-equivalent
 * (defaulted), backward-compatible.
 *
 * Root cause this replaces: BookingPackageFlag(EXTRA_BED_PER_NIGHT) is
 * BOOKING-level (unique on booking_id+package_key — one row per booking, no
 * room dimension at all). For a multi-room booking this is not just a
 * display ambiguity — NightAuditPipeline::run() builds one PostingContext
 * PER STAY and runs every registered job (including ExtraBedPostingJob)
 * against each one; the old ExtraBedPostingJob read the booking-level flag
 * with no stay/room filter, so EVERY stay of the booking independently
 * matched it and posted its own extra-bed FolioEntry — a 3-room booking with
 * one "Giường phụ x1" enrollment was actually charged 3× (one full charge
 * per room), not once. See the "Room-Scoped Bed Operations Correction"
 * section of the implementation report for the full trace.
 *
 * extra_bed_quantity is the new canonical room-level source: WHICH physical
 * room-allocation (RoomAssignment row) has how many extra beds, for THIS
 * booking's stay in that room. ServicePackage/ServicePackageRate remain the
 * sole source for package identity and price — this column never stores a
 * price, only a quantity, matching the Commercial Source Principle already
 * used elsewhere on this table (room_type_id, booking_requirement_id).
 *
 * unsignedTinyInteger, default 0 — "0 extra beds" and "not enrolled" are the
 * same state for this table (no separate NULL-vs-0 semantic needed, unlike
 * quick_note where NULL vs "" matters for display).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_assignments', function (Blueprint $table): void {
            $table->unsignedTinyInteger('extra_bed_quantity')->default(0)->after('quick_note');
        });
    }

    public function down(): void
    {
        Schema::table('room_assignments', function (Blueprint $table): void {
            $table->dropColumn('extra_bed_quantity');
        });
    }
};
