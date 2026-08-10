<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Daily Room Operations Board — ĐỔI PHÒNG (Room Swap) audit trail, Mục XX.
 * Append-only — no update/destroy route or UI, matching the ReleaseBatch
 * precedent.
 */
class RoomSwapBatch extends Model
{
    protected $fillable = [
        'executed_by',
        'pairs_summary',
        'warnings_acknowledged',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'pairs_summary' => 'array',
            'warnings_acknowledged' => 'boolean',
            'executed_at' => 'datetime',
        ];
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function roomAssignments(): HasMany
    {
        return $this->hasMany(RoomAssignment::class, 'swap_batch_id');
    }
}
