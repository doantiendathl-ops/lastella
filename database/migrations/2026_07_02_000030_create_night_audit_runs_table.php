<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('night_audit_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('business_date')->unique();
            $table->string('status', 20)->default('PENDING');
            $table->integer('stays_processed')->default(0);
            $table->integer('entries_posted')->default(0);
            $table->integer('entries_skipped')->default(0);
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('night_audit_booking_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('night_audit_runs')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('stay_id')->nullable()->constrained('stays')->nullOnDelete();
            $table->string('job_class', 120);
            $table->string('result', 20);
            $table->string('posting_key', 120)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('night_audit_booking_logs');
        Schema::dropIfExists('night_audit_runs');
    }
};
