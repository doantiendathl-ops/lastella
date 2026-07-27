<?php

use App\Enums\RoomStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Room Operations Simplification — Final Consistency Review: additive-only
     * column, backfilled from the existing `status` column. No existing column,
     * value, or row is altered or removed — RoomStatus and every current consumer
     * of it keep working unchanged.
     *
     * Mapping mirrors RoomStatus::impliedCleaningStatus() (the single centralized
     * mapping used everywhere else in the app — kept as literal values here, not a
     * call into app code, per migration best practice of staying self-contained).
     * Notably OUT_OF_ORDER/OUT_OF_SERVICE backfill to DIRTY, not CLEAN — a room that
     * was locked for maintenance must never be silently backfilled as guest-ready;
     * see the enum method's docblock for the full per-case rationale.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->string('cleaning_status', 10)->nullable()->after('status');
        });

        DB::table('rooms')
            ->whereIn('status', [
                RoomStatus::VacantDirty->value,
                RoomStatus::Cleaning->value,
                RoomStatus::OutOfOrder->value,
                RoomStatus::OutOfService->value,
            ])
            ->update(['cleaning_status' => 'DIRTY']);

        DB::table('rooms')
            ->whereIn('status', [
                RoomStatus::VacantClean->value,
                RoomStatus::Inspected->value,
                RoomStatus::Occupied->value,
                RoomStatus::Reserved->value,
            ])
            ->update(['cleaning_status' => 'CLEAN']);
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropColumn('cleaning_status');
        });
    }
};
