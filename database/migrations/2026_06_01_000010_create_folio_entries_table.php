<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('folio_id')
                  ->constrained('folios')
                  ->cascadeOnDelete();
            $table->string('charge_type', 40)->index();
            $table->string('description', 255);
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->date('entry_date')->index();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['folio_id', 'voided_at']);
            $table->index(['charge_type', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_entries');
    }
};
