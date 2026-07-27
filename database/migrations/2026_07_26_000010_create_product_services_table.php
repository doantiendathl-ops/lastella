<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('product_service_categories')->nullOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->string('type', 20)->default('product');
            $table->string('unit', 30)->default('cái');
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('free_quantity_default')->default(0);
            $table->boolean('use_in_checkout_inspection')->default(false);
            $table->boolean('can_add_to_booking')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'idx_ps_active_order');
            $table->index(['use_in_checkout_inspection', 'is_active'], 'idx_ps_inspection');
            $table->index(['can_add_to_booking', 'is_active'], 'idx_ps_bookable');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_services');
    }
};
