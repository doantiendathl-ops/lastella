<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stay_id')->constrained('stays')->restrictOnDelete();
            $table->string('event_type', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('event_type');
            $table->index(['stay_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_events');
    }
};
