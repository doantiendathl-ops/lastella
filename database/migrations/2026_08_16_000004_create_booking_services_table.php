<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt) — Slice 1.
 *
 * One booking-side "service transaction" row. Replaces, for services
 * migrated onto the new catalog, the combination of BookingPackageFlag
 * (enrollment) + implicit price resolution + implicit fulfillment that the
 * legacy Package Enrollment flow conflated.
 *
 * Scope (Section 7): `room_assignment_id` is nullable — required by
 * application-level validation when the Service's scope is ROOM/BOTH,
 * left null for BOOKING-scoped services. Not a DB-level conditional
 * constraint (Laravel/MySQL portability); enforced in
 * BookingServiceEnrollmentService.
 *
 * Pricing snapshot (Section 6): suggested_price/actual_price/reason are
 * captured at creation time and never recomputed — Admin changing the
 * Service's canonical price later must not alter this row.
 *
 * Fulfillment (Section 10/11): fulfillment_status is entirely separate
 * from billing. Billing correctness/idempotency lives in FolioEntry.posting_key
 * (see UnifiedServicePostingJob), never duplicated here as a redundant
 * "billing_status" column — mirrors how the legacy jobs already treat
 * FolioEntry existence as the single source of "was this posted".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->foreignId('room_assignment_id')->nullable()->constrained('room_assignments')->nullOnDelete();

            $table->unsignedInteger('quantity')->default(1);
            $table->string('billing_mode_selected', 20); // ONE_TIME | PER_NIGHT — snapshot of the mode actually used, even when Service.billing_mode = BOTH

            $table->decimal('suggested_price', 12, 2);
            $table->decimal('actual_price', 12, 2);
            $table->string('price_override_reason', 500)->nullable();

            $table->string('fulfillment_status', 20)->default('CREATED'); // CREATED | CONFIRMED | COMPLETED | CANCELLED

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['booking_id', 'fulfillment_status']);
            $table->index(['room_assignment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_services');
    }
};
