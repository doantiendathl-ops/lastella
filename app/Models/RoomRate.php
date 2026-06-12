<?php

namespace App\Models;

use App\Enums\RateStatus;
use Database\Factories\RoomRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoomRate extends Model
{
    /** @use HasFactory<RoomRateFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'room_type_id',
        'valid_from',
        'valid_to',
        'overnight_price',
        'hourly_price',
        'extra_adult_price',
        'extra_child_price',
        'early_checkin_price',
        'late_checkout_price',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'overnight_price' => 'decimal:2',
            'hourly_price' => 'decimal:2',
            'extra_adult_price' => 'decimal:2',
            'extra_child_price' => 'decimal:2',
            'early_checkin_price' => 'decimal:2',
            'late_checkout_price' => 'decimal:2',
            'status' => RateStatus::class,
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
