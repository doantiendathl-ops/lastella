<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_special_requests', function (Blueprint $table) {
            $table->id();

            // Booking link — always present; RESTRICT prevents silent cascade (ADR-82)
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();

            // Stay link — nullable bridge pattern mirrors folio_entries.stay_id (ADR-80)
            $table->foreignId('stay_id')->nullable()->constrained()->nullOnDelete();

            // Request classification
            $table->string('category', 32);            // RequestCategory enum, app-enforced
            $table->string('request_type', 64);        // string code — not a DB enum for extensibility
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->text('note')->nullable();

            // Status lifecycle — 4 states, 2 terminal
            $table->string('status', 32)->default('pending');

            // Actor tracking: pending → (acknowledged) → fulfilled / cancelled (ADR-80, ADR-83)
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete(); // NOT NULL (ADR-83)
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('booking_id');
            $table->index('stay_id');
            $table->index('status');
            $table->index('category');
            $table->index(['booking_id', 'status'], 'idx_bsr_booking_status'); // most frequent query
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_special_requests');
    }
};
