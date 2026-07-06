<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('room_id')
                ->constrained('rooms')
                ->restrictOnDelete();

            $table->foreignId('assignment_id')
                ->nullable()
                ->constrained('housekeeping_assignments')
                ->nullOnDelete();

            $table->foreignId('cleaned_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('room_status_before', 40);
            $table->string('room_status_after', 40)->nullable();

            $table->string('reason', 30)->default('CHECKOUT');

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            $table->text('cleaning_notes')->nullable();

            $table->string('inspection_result', 10)->nullable();

            $table->foreignId('inspected_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('inspected_at')->nullable();
            $table->text('inspection_notes')->nullable();

            $table->timestamps();

            $table->index('room_id', 'idx_cr_room');
            $table->index('cleaned_by', 'idx_cr_cleaned_by');
            $table->index('started_at', 'idx_cr_started_at');
            $table->index(['room_id', 'started_at'], 'idx_cr_room_date');
            $table->index('inspection_result', 'idx_cr_result');
            $table->index('inspected_by', 'idx_cr_inspected_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_records');
    }
};
