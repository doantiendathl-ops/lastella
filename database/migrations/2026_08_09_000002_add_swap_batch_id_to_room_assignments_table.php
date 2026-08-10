<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links every RoomAssignment row created OR released by the swap engine back
 * to its batch (Mục XX). Nullable — every assignment created outside the
 * Room Operations Board swap flow leaves this NULL, exactly like
 * release_batch_id does for release_batches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_assignments', function (Blueprint $table): void {
            $table->foreignId('swap_batch_id')->nullable()->after('release_batch_id')
                ->constrained('room_swap_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('room_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('swap_batch_id');
        });
    }
};
