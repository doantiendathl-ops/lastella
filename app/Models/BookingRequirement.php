<?php

namespace App\Models;

use App\Enums\PriceSource;
use Database\Factories\BookingRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingRequirement extends Model
{
    /** @use HasFactory<BookingRequirementFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'room_type_id',
        'quantity',
        'adults',
        'children_under_6',
        'children_over_6',
        'room_price',
        'price_source',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'adults' => 'integer',
            'children_under_6' => 'integer',
            'children_over_6' => 'integer',
            'room_price' => 'decimal:2',
            'price_source' => PriceSource::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
