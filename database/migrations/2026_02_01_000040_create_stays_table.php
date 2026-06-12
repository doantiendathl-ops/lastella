<?php

use App\Enums\StayStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('room_assignment_id')->constrained('room_assignments')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->dateTime('planned_checkin_at');
            $table->dateTime('planned_checkout_at');
            $table->dateTime('actual_checkin_at')->nullable();
            $table->dateTime('actual_checkout_at')->nullable();
            $table->string('status', 30)->default(StayStatus::Reserved->value)->index();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique('room_assignment_id');
            $table->index(['booking_id', 'status']);
            $table->index(['room_id', 'planned_checkin_at', 'planned_checkout_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stays');
    }
};
