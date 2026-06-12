<?php

namespace App\Models;

use App\Enums\StayStatus;
use Database\Factories\StayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stay extends Model
{
    /** @use HasFactory<StayFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'room_assignment_id',
        'room_id',
        'planned_checkin_at',
        'planned_checkout_at',
        'actual_checkin_at',
        'actual_checkout_at',
        'status',
        'checked_in_by',
        'checked_out_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'planned_checkin_at' => 'datetime',
            'planned_checkout_at' => 'datetime',
            'actual_checkin_at' => 'datetime',
            'actual_checkout_at' => 'datetime',
            'status' => StayStatus::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function roomAssignment(): BelongsTo
    {
        return $this->belongsTo(RoomAssignment::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }
}
