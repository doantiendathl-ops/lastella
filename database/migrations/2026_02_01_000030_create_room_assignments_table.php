<?php

use App\Enums\AssignmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('status', 30)->default(AssignmentStatus::Assigned->value)->index();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->timestamps();
            $table->index(['booking_id', 'room_type_id']);
            $table->index(['room_id', 'status', 'start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_assignments');
    }
};
