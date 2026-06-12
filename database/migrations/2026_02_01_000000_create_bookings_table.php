<?php

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('booking_code')->unique();
            $table->string('booking_color', 20)->nullable();
            $table->string('customer_name');
            $table->string('customer_phone', 50)->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_type', 30)->default(CustomerType::Individual->value)->index();
            $table->string('booking_type', 30)->default(BookingType::Overnight->value)->index();
            $table->dateTime('checkin_at')->index();
            $table->dateTime('checkout_at')->index();
            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children_under_6')->default(0);
            $table->unsignedSmallInteger('children_over_6')->default(0);
            $table->string('status', 40)->default(BookingStatus::PendingAssignment->value)->index();
            $table->foreignId('sales_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->text('note')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
