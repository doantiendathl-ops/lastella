<?php

use App\Enums\RateStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->date('valid_from')->index();
            $table->date('valid_to')->nullable()->index();
            $table->decimal('overnight_price', 12, 2);
            $table->decimal('hourly_price', 12, 2)->default(0);
            $table->decimal('extra_adult_price', 12, 2)->default(0);
            $table->decimal('extra_child_price', 12, 2)->default(0);
            $table->decimal('early_checkin_price', 12, 2)->default(0);
            $table->decimal('late_checkout_price', 12, 2)->default(0);
            $table->string('status', 30)->default(RateStatus::Active->value)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['room_type_id', 'valid_from', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_rates');
    }
};
