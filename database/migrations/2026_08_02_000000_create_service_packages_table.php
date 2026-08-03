<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('charge_type', 30);
            $table->string('calculation_strategy', 40);
            $table->string('quantity_mode', 30);
            $table->unsignedInteger('default_quantity')->default(1);
            $table->string('unit_label', 30);
            $table->string('posting_frequency', 20);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_bookable')->default(true);
            $table->integer('display_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'is_bookable', 'display_order'], 'idx_sp_active_bookable_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_packages');
    }
};
