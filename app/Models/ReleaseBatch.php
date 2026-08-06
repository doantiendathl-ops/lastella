<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Room Demand and Room Board Unification — Milestone 4.
 *
 * One row per atomic bulk-release request. Append-only audit trail — no
 * update/destroy route or UI is ever built for this model (Product Owner
 * Decision #20). Always created inside the SAME transaction as the
 * RoomAssignment rows it covers (RoomAssignmentService::bulkReleaseAssignments()).
 */
class ReleaseBatch extends Model
{
    protected $fillable = [
        'booking_id',
        'released_by',
        'reason',
        'note',
        'reduce_demand',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'reduce_demand' => 'boolean',
            'released_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function roomAssignments(): HasMany
    {
        return $this->hasMany(RoomAssignment::class);
    }
}
