<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt) — Slice 1.
 *
 * The single Service catalog record. Deliberately does not reuse
 * service_packages/product_services/service_rates — those stay untouched
 * and are migrated onto this table in later slices (Section 20: legacy
 * historical records may stay read-only, only active/open transactions
 * need migrating, as long as it is documented — this migration note is
 * that documentation for Slice 1's scope: only Extra Bed is migrated now).
 *
 * `code` is retained as a human-readable identifier for admin/reporting
 * only — Section 25 forbids hard-coding it for business behavior, so no
 * runtime code in this slice compares against a literal `code` string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('service_categories')->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();

            $table->boolean('is_chargeable')->default(true);
            $table->string('scope', 20)->default('BOOKING'); // ServiceScope: BOOKING | ROOM | BOTH
            $table->string('billing_mode', 20)->default('ONE_TIME'); // ServiceBillingMode: ONE_TIME | PER_NIGHT | BOTH
            $table->boolean('quantity_enabled')->default(false);
            $table->unsignedInteger('default_quantity')->default(1);
            $table->string('unit_label', 30)->default('lần');
            $table->boolean('fulfillment_required')->default(false);

            $table->boolean('is_active')->default(true);
            $table->boolean('is_bookable')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'is_bookable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
