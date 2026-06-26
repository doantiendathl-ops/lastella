<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_assignments', function (Blueprint $table): void {
            $table->index(['status', 'start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::table('room_assignments', function (Blueprint $table): void {
            $table->dropIndex(['status', 'start_at', 'end_at']);
        });
    }
};
