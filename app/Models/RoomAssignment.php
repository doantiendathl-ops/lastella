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
        'release_batch_id',
        'swap_batch_id',
        'quick_note',
        'extra_bed_quantity',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'status' => AssignmentStatus::class,
            'released_at' => 'datetime',
            'extra_bed_quantity' => 'integer',
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

    /**
     * Room Demand/Room Board Unification M4: the bulk-release batch this
     * assignment was released in. Nullable — legacy releases and single-room
     * releases (which never create a batch) leave this NULL.
     */
    public function releaseBatch(): BelongsTo
    {
        return $this->belongsTo(ReleaseBatch::class);
    }

    public function swapBatch(): BelongsTo
    {
        return $this->belongsTo(RoomSwapBatch::class, 'swap_batch_id');
    }

    public function stay(): HasOne
    {
        return $this->hasOne(Stay::class);
    }
}
