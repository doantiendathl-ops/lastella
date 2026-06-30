<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folio_entries', function (Blueprint $table): void {
            $table->string('posting_key', 120)->nullable()->unique()->after('folio_id');
        });
    }

    public function down(): void
    {
        Schema::table('folio_entries', function (Blueprint $table): void {
            $table->dropUnique(['posting_key']);
            $table->dropColumn('posting_key');
        });
    }
};
