<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_inspection_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checkout_inspection_id')->constrained('checkout_inspections')->cascadeOnDelete();
            $table->foreignId('product_service_id')->nullable()->constrained('product_services')->nullOnDelete();
            $table->string('product_code_snapshot', 40);
            $table->string('product_name_snapshot', 150);
            $table->string('unit_snapshot', 30);
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->unsignedInteger('free_quantity')->default(0);
            $table->unsignedInteger('actual_quantity')->default(0);
            $table->unsignedInteger('chargeable_quantity')->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('checkout_inspection_id');
            $table->index('product_service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_inspection_items');
    }
};
