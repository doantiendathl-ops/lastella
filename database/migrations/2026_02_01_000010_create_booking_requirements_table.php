<?php

use App\Enums\PriceSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children_under_6')->default(0);
            $table->unsignedSmallInteger('children_over_6')->default(0);
            $table->decimal('room_price', 12, 2);
            $table->string('price_source', 30)->default(PriceSource::RateTable->value)->index();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['booking_id', 'room_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_requirements');
    }
};
