<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick Note Lifecycle addendum (Daily Room Operations Board) — Mục A/J.
 *
 * bookings.quick_note is a SEPARATE field from the existing `note` (long
 * text) — max 100 chars, used only as the DEFAULT/INITIAL value copied onto
 * a RoomAssignment when it is first created (Mục B). It is never
 * live-synced onto existing assignments afterward (Mục C) — that copy
 * happens once, at assignment-creation time, in
 * RoomAssignmentService::lockRoomRecheckAndCreateAssignment().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('quick_note', 100)->nullable()->after('internal_note');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('quick_note');
        });
    }
};
