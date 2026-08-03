<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_package_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_package_id')->constrained('service_packages')->restrictOnDelete();
            $table->decimal('unit_price', 12, 2);
            $table->date('effective_from');
            $table->boolean('is_active')->default(true);
            $table->decimal('tax_rate', 5, 4)->default(0);
            $table->string('gl_account_code', 30)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_package_id', 'is_active', 'effective_from'], 'idx_spr_package_active_effective');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_package_rates');
    }
};
