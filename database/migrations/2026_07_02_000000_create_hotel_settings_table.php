<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value');
            $table->string('value_type', 20)->default('string');
            $table->string('description', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_settings');
    }
};
