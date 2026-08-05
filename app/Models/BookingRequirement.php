<?php

namespace App\Models;

use App\Enums\PriceSource;
use Database\Factories\BookingRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * Room Demand/Room Board Unification M1: assignments that consume this
     * demand line (any status, including Released) — used by the
     * deleteRequirement() reference guard and by tests. Not filtered by
     * status here on purpose: the guard must see released assignments too.
     */
    public function roomAssignments(): HasMany
    {
        return $this->hasMany(RoomAssignment::class);
    }
}
