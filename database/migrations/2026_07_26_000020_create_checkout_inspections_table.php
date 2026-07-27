<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('stay_id')->constrained('stays')->restrictOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('folio_id')->nullable()->constrained('folios')->nullOnDelete();
            $table->string('status', 20)->default('DRAFT');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->string('posting_batch_key', 60)->nullable()->unique();
            $table->timestamps();

            $table->unique('stay_id');
            $table->index(['booking_id', 'status']);
            $table->index(['room_id', 'status']);
            $table->index('folio_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_inspections');
    }
};
