<?php

namespace App\Models;

use Database\Factories\BookingPackageFlagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPackageFlag extends Model
{
    /** @use HasFactory<BookingPackageFlagFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'package_key',
        'value',
        'created_by',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
