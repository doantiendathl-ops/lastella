<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Room Operations Board — ĐỔI PHÒNG (Room Swap) audit trail, Mục XX.
 *
 * One row per atomic multi-pair swap batch (Mục X/XXI: one confirm click may
 * cover several source→target pairs, all-or-nothing). Not tied to a single
 * booking — unlike release_batches, a swap batch can touch several bookings
 * at once (the swapping booking plus any displaced third parties) — so there
 * is deliberately no booking_id column here.
 *
 * `pairs_summary` is a denormalized JSON snapshot of the batch (source/target
 * rooms, booking ids, date ranges, warnings shown) for fast audit reading
 * without joining every RoomAssignment/StayEvent row it produced — the
 * per-row detail still lives in room_assignments.swap_batch_id (below) and
 * the matching StayEvent(ROOM_MOVE) rows, so this table is a convenience
 * index, never the only record of what happened.
 *
 * Append-only — no update/destroy route or UI, matching the release_batches
 * precedent (Product Owner Decision #20 there applies here too).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_swap_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('pairs_summary');
            $table->boolean('warnings_acknowledged')->default(false);
            $table->dateTime('executed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_swap_batches');
    }
};
