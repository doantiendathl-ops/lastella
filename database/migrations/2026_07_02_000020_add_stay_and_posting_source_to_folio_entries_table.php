<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folio_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('folio_entries', 'stay_id')) {
                $table->foreignId('stay_id')
                    ->nullable()
                    ->after('folio_id')
                    ->constrained('stays')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('folio_entries', 'posting_source')) {
                $table->string('posting_source', 20)
                    ->default('MANUAL')
                    ->after('posting_key');

                $table->index(['folio_id', 'posting_source'], 'idx_folio_entries_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('folio_entries', function (Blueprint $table): void {
            if (Schema::hasColumn('folio_entries', 'posting_source')) {
                $table->dropIndex('idx_folio_entries_source');
                $table->dropColumn('posting_source');
            }

            if (Schema::hasColumn('folio_entries', 'stay_id')) {
                $table->dropForeign(['stay_id']);
                $table->dropColumn('stay_id');
            }
        });
    }
};
