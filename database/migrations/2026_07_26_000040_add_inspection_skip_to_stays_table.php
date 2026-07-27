<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stays', function (Blueprint $table): void {
            $table->dateTime('inspection_skipped_at')->nullable()->after('note');
            $table->foreignId('inspection_skipped_by')->nullable()->after('inspection_skipped_at')->constrained('users')->nullOnDelete();
            $table->text('inspection_skip_reason')->nullable()->after('inspection_skipped_by');
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inspection_skipped_by');
            $table->dropColumn(['inspection_skipped_at', 'inspection_skip_reason']);
        });
    }
};
