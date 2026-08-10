<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Room Operations Board — additive, nullable, backward-compatible.
 *
 * quick_note: the "Ghi chú nhanh" shown on the room-operations cell (spec Mục VII,
 * max 100 chars). Deliberately placed on room_assignments — NOT on the physical
 * Room, and NOT reusing stays.note (that field is a generic long text with
 * different semantics) — because the note must travel WITH the booking/stay
 * when the room changes (Mục VIII), and RoomAssignment is exactly the row that
 * a room swap releases/recreates. The swap engine (RoomSwapService) is
 * responsible for copying this value onto the new assignment row(s) it creates.
 *
 * Room-Scoped Bed Operations Correction: this migration ORIGINALLY also added
 * `bed_joined` here. Product Owner traced "Ghép giường" to an EXISTING,
 * already-lifecycle-managed source — BookingSpecialRequest (category
 * bed_config, request_type twin_to_double, keyed by stay_id) — that this
 * task's earlier analysis missed. `bed_joined` would have been a second,
 * un-synchronized source of truth for the same fact, so it was removed from
 * this migration entirely rather than shipped and then dropped in a follow-up
 * migration — safe only because this migration was never committed/pushed
 * (confirmed via `git status` before editing). See
 * docs/reports/daily-room-operations-board-implementation-report.md,
 * "Room-Scoped Bed Operations Correction".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_assignments', function (Blueprint $table): void {
            $table->string('quick_note', 100)->nullable()->after('release_reason');
        });
    }

    public function down(): void
    {
        Schema::table('room_assignments', function (Blueprint $table): void {
            $table->dropColumn(['quick_note']);
        });
    }
};
