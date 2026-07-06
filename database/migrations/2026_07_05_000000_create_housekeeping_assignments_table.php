<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('room_id')
                ->constrained('rooms')
                ->restrictOnDelete();

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('priority', 20)->default('NORMAL');
            $table->string('reason', 30)->default('CHECKOUT');
            $table->text('notes')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('status', 20)->default('pending');

            $table->timestamps();

            $table->index('room_id', 'idx_hka_room');
            $table->index('assigned_to', 'idx_hka_assigned_to');
            $table->index('status', 'idx_hka_status');
            $table->index(['room_id', 'status'], 'idx_hka_room_status');
            $table->index(['assigned_to', 'status'], 'idx_hka_assignee_status');
            $table->index(['priority', 'status'], 'idx_hka_priority_status');
            $table->index('created_at', 'idx_hka_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_assignments');
    }
};
