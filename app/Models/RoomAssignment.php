<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Database\Factories\RoomAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RoomAssignment extends Model
{
    /** @use HasFactory<RoomAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'room_id',
        'room_type_id',
        'booking_requirement_id',
        'start_at',
        'end_at',
        'status',
        'assigned_by',
        'released_by',
        'released_at',
        'release_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'status' => AssignmentStatus::class,
            'released_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Room Demand/Room Board Unification M1: the demand line this assignment
     * consumes. Nullable — legacy rows created before this column existed, or
     * rows left ambiguous by the backfill command, have no value here.
     */
    public function bookingRequirement(): BelongsTo
    {
        return $this->belongsTo(BookingRequirement::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function stay(): HasOne
    {
        return $this->hasOne(Stay::class);
    }
}
