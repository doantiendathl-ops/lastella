<?php

namespace App\Models;

use App\Enums\CleaningReason;
use App\Enums\InspectionResult;
use Database\Factories\CleaningRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CleaningRecord extends Model
{
    /** @use HasFactory<CleaningRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'room_id',
        'assignment_id',
        'cleaned_by',
        'room_status_before',
        'room_status_after',
        'reason',
        'started_at',
        'completed_at',
        'duration_minutes',
        'cleaning_notes',
        'inspection_result',
        'inspected_by',
        'inspected_at',
        'inspection_notes',
    ];

    protected function casts(): array
    {
        return [
            'reason'            => CleaningReason::class,
            'inspection_result' => InspectionResult::class,
            'started_at'        => 'datetime',
            'completed_at'      => 'datetime',
            'inspected_at'      => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(HousekeepingAssignment::class, 'assignment_id');
    }

    public function cleanedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleaned_by');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
