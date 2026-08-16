<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt) — Slice 1.
 *
 * Section 5: the ONE canonical pricing source per Service. Same
 * additive-history shape as service_package_rates/service_rates (never
 * UPDATE a price row — a change inserts a new row, old rows stay forever)
 * so every runtime resolves price through one resolver
 * (App\Services\ServicePricingResolver) instead of two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->decimal('unit_price', 12, 2);
            $table->date('effective_from');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_id', 'is_active', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_prices');
    }
};
