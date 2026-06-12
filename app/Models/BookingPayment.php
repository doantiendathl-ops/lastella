<?php

namespace App\Models;

use App\Enums\PaymentType;
use Database\Factories\BookingPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPayment extends Model
{
    /** @use HasFactory<BookingPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'payment_type',
        'amount',
        'payment_method',
        'payment_at',
        'confirmed_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'payment_type' => PaymentType::class,
            'amount' => 'decimal:2',
            'payment_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
