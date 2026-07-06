<?php

namespace App\Models;

use App\Enums\CleaningPriority;
use App\Enums\CleaningReason;
use App\Enums\HousekeepingAssignmentStatus;
use Database\Factories\HousekeepingAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HousekeepingAssignment extends Model
{
    /** @use HasFactory<HousekeepingAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'room_id',
        'assigned_to',
        'assigned_by',
        'priority',
        'reason',
        'notes',
        'started_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status'       => HousekeepingAssignmentStatus::class,
            'priority'     => CleaningPriority::class,
            'reason'       => CleaningReason::class,
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function cleaningRecord(): HasOne
    {
        return $this->hasOne(CleaningRecord::class, 'assignment_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', HousekeepingAssignmentStatus::activeValues());
    }

    public function scopeForRoom(Builder $query, int $roomId): void
    {
        $query->where('room_id', $roomId);
    }

    public function scopeForAssignee(Builder $query, int $userId): void
    {
        $query->where('assigned_to', $userId);
    }
}
