<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('charge_type', 40);
            $table->decimal('unit_price', 12, 2);
            $table->date('effective_from')->default('2000-01-01');
            $table->string('unit_label', 30)->default('lần');
            $table->decimal('tax_rate', 5, 4)->default(0.0000);
            $table->string('gl_account_code', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('charge_type');
            $table->index(['charge_type', 'is_active', 'effective_from'], 'idx_service_rates_effective');
            $table->index(['is_active', 'display_order'], 'idx_service_rates_active_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_rates');
    }
};
